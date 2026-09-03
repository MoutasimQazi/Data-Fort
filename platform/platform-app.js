/* platform-app.js — shell behaviour for the platform panel.
 * Trimmed from ../app.js: same modal/toast/nav/theme machinery
 * (copied verbatim, it doesn't know what a tenant is), session
 * bootstrap re-pointed at the platform's own session shape. No
 * data-requires role routing — every platform_admins row is equally
 * privileged, so there is nothing to route between. */
(function () {
  'use strict';

  var Datafort = window.Datafort = window.Datafort || {};
  var API = window.DatafortAPI;

  Datafort.session = { id: '—', name: 'Loading…' };

  var readyQueue = [];
  var sessionLoaded = false;

  Datafort.ready = function (fn) {
    if (sessionLoaded) { fn(Datafort.session); return; }
    readyQueue.push(fn);
  };

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
          (hint ? '<p style="margin-top:8px;font-size:12.5px;color:var(--text-faint);max-width:52ch">' + hint + '</p>' : '') +
        '</div>' +
      '</div></div></div>';
  }

  function loadSession() {
    return API.session().catch(function (err) {
      if (err.status === 401) throw err;
      fatal('Platform panel cannot reach its data', err.message || 'The server did not respond as expected.',
        'Check that this vhost has multi_tenant.enabled and multi_tenant.platform_db set in api/config.php, ' +
        'and that scripts/init-platform-db.php has been run.');
      throw err;
    }).then(function (s) {
      Datafort.session = { id: s.id, name: s.name, email: s.email, device: s.device };
      sessionLoaded = true;
      paintWhoami();
      readyQueue.forEach(function (fn) {
        try { fn(Datafort.session); } catch (e) { console.error('[datafort-platform] page init failed:', e); }
      });
      readyQueue = [];
    }).catch(function () { /* already surfaced on the page */ });
  }

  function markCurrentNav() {
    var here = location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.navlink').forEach(function (a) {
      if (a.getAttribute('href') === here) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
    });
  }

  function wireSidebar() {
    var side = document.querySelector('.side');
    var open = document.getElementById('menuBtn');
    var scrim = document.getElementById('scrim');
    if (!side || !open) return;
    function set(state) { side.dataset.open = String(state); if (scrim) scrim.hidden = !state; }
    open.addEventListener('click', function () { set(side.dataset.open !== 'true'); });
    if (scrim) scrim.addEventListener('click', function () { set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
  }

  function wireTheme() {
    var btn = document.getElementById('themeBtn');
    if (!btn) return;
    btn.addEventListener('click', function () { window.DatafortTheme.toggle(); });
  }

  function wireWhoami() {
    var btn = document.querySelector('.whoami');
    if (!btn) return;
    var out = document.createElement('button');
    out.type = 'button';
    out.className = 'signout';
    out.title = 'Sign out';
    out.setAttribute('aria-label', 'Sign out');
    out.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>' +
      '<path d="m16 17 5-5-5-5M21 12H9"/></svg>';
    out.addEventListener('click', function (e) {
      e.stopPropagation();
      if (!confirm('Sign out of the platform panel?')) return;
      API.logout().catch(function () {}).then(function () { location.href = 'login.html'; });
    });
    btn.parentNode.appendChild(out);
  }

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

  Datafort.fail = function (err) {
    console.error('[datafort-platform]', err);
    Datafort.toast(err && err.message ? err.message : 'Something went wrong.', 'error', 6000);
  };

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
      document.querySelectorAll('.modal:not([hidden])').forEach(function (m) { Datafort.closeModal(m.id); });
    });
  }

  Datafort.initials = function (name) {
    return String(name || '').trim().split(/\s+/).slice(0, 2).map(function (w) { return w[0] || ''; }).join('').toUpperCase();
  };

  Datafort.escape = function (str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  /* Mirror of Datafort.parseTime in the tenant app.js. The platform
   * panel is served from a different directory and does not load
   * app.js, so the two are separate copies on purpose — but they must
   * agree, because they read the same kind of column. See the long
   * note in app.js for why a zoneless string cannot be handed to
   * Date(): it is read as local time, which put every platform
   * timestamp out by the viewer's UTC offset. */
  Datafort.parseTime = function (value) {
    if (!value) return null;
    if (value instanceof Date) return isNaN(value.getTime()) ? null : value;

    var s = String(value).trim();
    var d;

    var dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s);
    if (dateOnly) {
      d = new Date(+dateOnly[1], +dateOnly[2] - 1, +dateOnly[3]);
      return isNaN(d.getTime()) ? null : d;
    }

    if (/(Z|[+-]\d{2}:?\d{2})$/.test(s)) {
      d = new Date(s.replace(' ', 'T'));
      return isNaN(d.getTime()) ? null : d;
    }

    var m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/.exec(s);
    if (m) {
      d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)));
      return isNaN(d.getTime()) ? null : d;
    }

    d = new Date(s);
    return isNaN(d.getTime()) ? null : d;
  };

  Datafort.when = function (value, opts) {
    var d = Datafort.parseTime(value);
    return d ? d.toLocaleString(undefined, opts) : '—';
  };

  Datafort.day = function (value, opts) {
    var d = Datafort.parseTime(value);
    return d ? d.toLocaleDateString(undefined,
      opts || { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
  };

  Datafort.ago = function (iso) {
    var parsed = Datafort.parseTime(iso);
    if (!parsed) return '—';
    var then = parsed.getTime();
    var secs = Math.round((Date.now() - then) / 1000);
    // Future timestamps are surfaced rather than flattened to "just
    // now" — see the long note on the same branch in app.js.
    if (secs < -60) return 'in ' + magnitude(-secs) + ' — check server clock';
    if (secs < 60) return 'just now';
    if (secs < 604800) return magnitude(secs) + ' ago';
    return new Date(then).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  };

  function magnitude(secs) {
    if (secs < 3600)  return Math.floor(secs / 60) + 'm';
    if (secs < 86400) return Math.floor(secs / 3600) + 'h';
    return Math.floor(secs / 86400) + 'd';
  }

  function paintWhoami() {
    var s = Datafort.session;
    var name = document.querySelector('[data-who="name"]');
    var role = document.querySelector('[data-who="role"]');
    var av = document.querySelector('[data-who="avatar"]');
    if (name) name.textContent = s.name;
    if (role) role.textContent = 'Platform owner';
    if (av) av.textContent = Datafort.initials(s.name);
  }

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
