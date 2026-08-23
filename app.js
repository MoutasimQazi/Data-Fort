/* app.js — shared shell behaviour for every signed-in page.
 *
 * Nav state, mobile sidebar, theme, modals, toasts, helpers, and the
 * session bootstrap that every page waits on.
 *
 * Page scripts do NOT fetch the session themselves. They call
 * Datafort.ready(fn) and are handed it once — otherwise nine pages make
 * nine identical requests and each has to handle the signed-out case
 * separately.
 */
(function () {
  'use strict';

  var Datafort = window.Datafort = window.Datafort || {};
  var API = window.DatafortAPI;


  /* ══ Session ═══════════════════════════════════════════════════ */

  /* Placeholder until the real session lands. Deliberately alarming
   * rather than blank: if this ever renders, something failed and a
   * screen is showing lead data with no identity attached to it. */
  Datafort.session = { id: '—', name: 'Loading…', role: 'rep', tenant: '' };

  var readyQueue = [];
  var sessionLoaded = false;

  /**
   * Run fn once the session is known. If it is already known, runs
   * immediately.
   */
  Datafort.ready = function (fn) {
    if (sessionLoaded) { fn(Datafort.session); return; }
    readyQueue.push(fn);
  };

  /**
   * Replaces the page content with a readable explanation.
   *
   * Without this, a failed session load leaves the shell rendered and
   * every panel empty — sidebar, headings, card frames, no data, no
   * error. That is the worst possible failure mode because it looks
   * like a working app with no records in it, and the actual cause
   * (missing config.php, un-run migration) is only visible in the
   * browser console.
   */
  function fatal(title, detail, hint) {
    var main = document.querySelector('.main');
    if (!main) return;

    main.innerHTML =
      '<div class="content"><div class="card"><div class="card__body">' +
        '<div class="empty" style="padding:48px 24px">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" ' +
            'stroke-linecap="round" stroke-linejoin="round" style="color:var(--red)">' +
            '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>' +
          '<h3>' + Datafort.escape(title) + '</h3>' +
          '<p>' + Datafort.escape(detail) + '</p>' +
          (hint ? '<p style="margin-top:8px;font-size:12.5px;color:var(--text-faint);' +
                  'max-width:52ch">' + hint + '</p>' : '') +
        '</div>' +
      '</div></div></div>';
  }

  function loadSession() {
    return API.session().catch(function (err) {
      /* api.js already redirected on 401 and on a device refusal, so
       * anything arriving here is a server-side problem the user cannot
       * fix by signing in again. Name it. */
      if (err.status === 401 || err.status === 403) throw err;

      fatal(
        'Datafort cannot reach its data',
        err.message || 'The server did not respond as expected.',
        'On a fresh deployment this is almost always one of three things:<br>' +
        '1. <code>api/config.php</code> is missing on the server — it is ' +
        'gitignored, so <strong>it does not deploy with a git push</strong>. ' +
        'Upload it manually.<br>' +
        '2. The schema has not been loaded — run ' +
        '<code>api/migrations/001_schema.sql</code>.<br>' +
        '3. The database credentials in <code>config.php</code> are wrong.<br>' +
        'Open <code>/api/setup.php</code> — it checks all three and tells you which.'
      );
      throw err;
    }).then(function (s) {
      Datafort.session = {
        id:     s.id,
        userId: s.userId,
        name:   s.name,
        role:   s.role,
        tenant: s.tenant,
        quota:  s.quota,
        device: s.device,
        deviceMode: s.deviceMode
      };

      /* ── Role routing ──
       *
       * Each page declares who it is for via <body data-requires>. A rep
       * who lands on index.html and an admin who lands on my-leads.html
       * both get sent to their own home instead of an empty screen.
       *
       * This is NAVIGATION, not access control. The endpoints already
       * refuse the wrong role — dashboard.php and every admin route call
       * requireAuth(..., 'admin'). Without that server check this would
       * be worthless, because anyone can edit an attribute in devtools.
       * What it fixes is the confusing middle state: a rep on the
       * dashboard used to see the full admin chrome with every panel
       * failing quietly behind it. */
      var needs = document.body.getAttribute('data-requires');

      if (needs && needs !== 'any' && needs !== s.role) {
        location.replace(s.role === 'admin' ? 'index.html' : 'my-leads.html');
        return;
      }

      sessionLoaded = true;
      paintWhoami();

      // The watermark reads the session, so it can only paint now.
      if (window.DatafortWatermark) window.DatafortWatermark.repaint();

      readyQueue.forEach(function (fn) {
        try { fn(Datafort.session); }
        catch (e) { console.error('[datafort] page init failed:', e); }
      });
      readyQueue = [];

    // The rejection is re-thrown by the catch above, after fatal() has
    // painted it. Absorbed here so it does not ALSO log as an unhandled
    // rejection — the page already says what went wrong.
    }).catch(function () { /* already surfaced on the page */ });
  }


  /* ══ Nav ═══════════════════════════════════════════════════════ */

  function markCurrentNav() {
    var here = location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.navlink').forEach(function (a) {
      if (a.getAttribute('href') === here) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
    });
  }


  /* ══ Mobile sidebar ════════════════════════════════════════════ */

  function wireSidebar() {
    var side  = document.querySelector('.side');
    var open  = document.getElementById('menuBtn');
    var scrim = document.getElementById('scrim');
    if (!side || !open) return;

    function set(state) {
      side.dataset.open = String(state);
      if (scrim) scrim.hidden = !state;
    }

    open.addEventListener('click', function () { set(side.dataset.open !== 'true'); });
    if (scrim) scrim.addEventListener('click', function () { set(false); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') set(false);
    });
  }


  /* ══ Theme ═════════════════════════════════════════════════════ */

  function wireTheme() {
    var btn = document.getElementById('themeBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
      window.DatafortTheme.toggle();
      // The watermark paints with currentColor and has to be redrawn.
      if (window.DatafortWatermark) window.DatafortWatermark.repaint();
    });
  }


  /* ══ Sign out ══════════════════════════════════════════════════ */

  /* The whoami button in the sidebar footer. Clicking it goes to the
   * account page; signing out is a separate, smaller control beside it.
   *
   * It used to sign out on click, which meant the single most destructive
   * action on the page was also the easiest to hit by accident while
   * reaching for the theme toggle. */
  function wireWhoami() {
    var btn = document.querySelector('.whoami');
    if (!btn) return;

    btn.addEventListener('click', function () {
      if (location.pathname.split('/').pop() !== 'account.html') {
        location.href = 'account.html';
      }
    });

    var out = document.createElement('button');
    out.type = 'button';
    out.className = 'signout';
    out.title = 'Sign out';
    out.setAttribute('aria-label', 'Sign out');
    out.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>' +
      '<path d="m16 17 5-5-5-5M21 12H9"/></svg>';

    out.addEventListener('click', function (e) {
      e.stopPropagation();          // do not also navigate to the account page
      if (!confirm('Sign out of Datafort?')) return;
      API.logout()
        .catch(function () { /* clear locally regardless */ })
        .then(function () { location.href = 'login.html'; });
    });

    btn.parentNode.appendChild(out);
  }


  /* ══ Toasts ════════════════════════════════════════════════════ */

  var toastHost = null;

  Datafort.toast = function (message, kind, ms) {
    if (!toastHost) {
      toastHost = document.createElement('div');
      toastHost.className = 'toasts';
      document.body.appendChild(toastHost);
    }

    var el = document.createElement('div');
    el.className = 'toast' + (kind ? ' toast--' + kind : '');
    el.setAttribute('role', 'status');
    el.textContent = message;
    toastHost.appendChild(el);

    setTimeout(function () { el.remove(); }, ms || 4200);
  };

  /** Standard way to show a failed request. */
  Datafort.fail = function (err) {
    console.error('[datafort]', err);
    Datafort.toast(err && err.message ? err.message : 'Something went wrong.', 'error', 6000);
  };


  /* ══ Modals ════════════════════════════════════════════════════ */

  var lastFocus = null;

  Datafort.openModal = function (id) {
    var m = document.getElementById(id);
    if (!m) return;
    lastFocus = document.activeElement;
    m.hidden = false;
    var first = m.querySelector('input, select, textarea, button');
    if (first) first.focus();
  };

  Datafort.closeModal = function (id) {
    var m = document.getElementById(id);
    if (!m) return;
    m.hidden = true;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  };

  function wireModals() {
    document.addEventListener('click', function (e) {
      var open = e.target.closest('[data-modal-open]');
      if (open) { Datafort.openModal(open.dataset.modalOpen); return; }

      var close = e.target.closest('[data-modal-close]');
      if (close) { Datafort.closeModal(close.closest('.modal').id); return; }

      if (e.target.classList.contains('modal')) Datafort.closeModal(e.target.id);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      document.querySelectorAll('.modal:not([hidden])').forEach(function (m) {
        Datafort.closeModal(m.id);
      });
    });
  }


  /* ══ Helpers ═══════════════════════════════════════════════════ */

  Datafort.securityEvent = function (type, detail) {
    API.securityEvent(type, detail);
  };

  Datafort.initials = function (name) {
    return String(name || '').trim().split(/\s+/).slice(0, 2)
      .map(function (w) { return w[0] || ''; }).join('').toUpperCase();
  };

  /**
   * HTML-escapes a value for use in markup — including inside an
   * ATTRIBUTE.
   *
   * The obvious implementation, textContent -> innerHTML, escapes
   * & < > and leaves quotes alone. That is fine for text between tags
   * and quietly broken inside an attribute, which is where most of this
   * codebase uses it:
   *
   *     aria-label="Select ' + escape(lead.name) + '"
   *
   * A lead imported with the name  " onmouseover="…  closes the
   * attribute and injects a handler. Lead names come from a customer's
   * spreadsheet, so they are attacker-controlled the moment anyone
   * imports a file they were sent — and the payload then runs in the
   * ADMIN's session, which can read every lead and change every quota.
   *
   * Both quote styles are escaped, so this is safe in text nodes,
   * double-quoted attributes and single-quoted attributes alike.
   */
  Datafort.escape = function (str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  Datafort.ago = function (iso) {
    if (!iso) return '—';
    // MySQL DATETIME comes back as "2026-08-19 07:03:58", which Safari
    // will not parse. Normalise to ISO before handing it to Date.
    var normalised = String(iso).indexOf('T') === -1
      ? String(iso).replace(' ', 'T')
      : String(iso);

    var then = new Date(normalised).getTime();
    if (isNaN(then)) return '—';

    var secs = Math.round((Date.now() - then) / 1000);
    if (secs < 0)     return 'just now';
    if (secs < 60)    return 'just now';
    if (secs < 3600)  return Math.floor(secs / 60) + 'm ago';
    if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
    if (secs < 604800) return Math.floor(secs / 86400) + 'd ago';

    return new Date(then).toLocaleDateString(undefined,
      { day: 'numeric', month: 'short', year: 'numeric' });
  };

  Datafort.money = function (n) {
    return '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  };

  function paintWhoami() {
    var s = Datafort.session;
    var name = document.querySelector('[data-who="name"]');
    var role = document.querySelector('[data-who="role"]');
    var av   = document.querySelector('[data-who="avatar"]');

    if (name) name.textContent = s.name;
    if (role) {
      role.textContent = (s.role === 'admin' ? 'Administrator' : 'Sales') +
                         (s.tenant ? ' · ' + s.tenant : '');
    }
    if (av) av.textContent = Datafort.initials(s.name);
  }


  /* ══ Boot ══════════════════════════════════════════════════════ */

  function boot() {
    markCurrentNav();
    wireSidebar();
    wireTheme();
    wireModals();
    wireWhoami();
    loadSession();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
