/* app.js — shared shell behaviour for every signed-in page.
 *
 * Nav state, mobile sidebar, theme toggle, modals, toasts, and the
 * small helpers pages reach for. Page-specific logic lives in its own
 * file (leads.js, dashboard.js, ...).
 */
(function () {
  'use strict';

  var Datafort = window.Datafort = window.Datafort || {};


  /* ══ Session ═══════════════════════════════════════════════════
   *
   * MOCK until api/session.php exists. Read by watermark.js and by the
   * quota widgets, so it has to be present before those run.
   */
  Datafort.session = (window.MOCK && window.MOCK.session) || {
    id: 'u-000', name: 'Unknown User', role: 'rep', tenant: 'Unknown'
  };


  /* ══ Nav ═══════════════════════════════════════════════════════ */

  function markCurrentNav() {
    var here = location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.navlink').forEach(function (a) {
      var target = a.getAttribute('href');
      if (target === here) a.setAttribute('aria-current', 'page');
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

    open.addEventListener('click', function () {
      set(side.dataset.open !== 'true');
    });

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
      // The watermark paints with currentColor, so it has to be redrawn
      // when the resolved theme changes underneath it.
      if (window.DatafortWatermark) window.DatafortWatermark.repaint();
    });
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
    // Return focus where it was, or keyboard users get dumped at the top.
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  };

  function wireModals() {
    document.addEventListener('click', function (e) {
      var open = e.target.closest('[data-modal-open]');
      if (open) { Datafort.openModal(open.dataset.modalOpen); return; }

      var close = e.target.closest('[data-modal-close]');
      if (close) { Datafort.closeModal(close.closest('.modal').id); return; }

      // Click on the backdrop, but not inside the box.
      if (e.target.classList.contains('modal')) {
        Datafort.closeModal(e.target.id);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      document.querySelectorAll('.modal:not([hidden])').forEach(function (m) {
        Datafort.closeModal(m.id);
      });
    });
  }


  /* ══ Security events ═══════════════════════════════════════════ */

  /* Thin wrapper so pages outside guard.js (which batches its own) can
   * post a single event — a reveal, a quota breach attempt. */
  Datafort.securityEvent = function (type, detail) {
    var body = JSON.stringify({ events: [{
      type: type, detail: detail || null,
      at: new Date().toISOString(), page: location.pathname
    }]});

    if (navigator.sendBeacon) {
      navigator.sendBeacon('api/security-event.php',
        new Blob([body], { type: 'application/json' }));
    }
  };


  /* ══ Helpers ═══════════════════════════════════════════════════ */

  Datafort.initials = function (name) {
    return String(name).trim().split(/\s+/).slice(0, 2)
      .map(function (w) { return w[0] || ''; }).join('').toUpperCase();
  };

  Datafort.escape = function (str) {
    var d = document.createElement('div');
    d.textContent = str == null ? '' : str;
    return d.innerHTML;
  };

  /* "3 minutes ago". Falls back to the date past a week, because
   * "412 hours ago" is not information. */
  Datafort.ago = function (iso) {
    var then = new Date(iso).getTime();
    if (isNaN(then)) return '—';

    var secs = Math.round((Date.now() - then) / 1000);
    if (secs < 60)    return 'just now';
    if (secs < 3600)  return Math.floor(secs / 60) + 'm ago';
    if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
    if (secs < 604800) return Math.floor(secs / 86400) + 'd ago';

    return new Date(then).toLocaleDateString(undefined,
      { day: 'numeric', month: 'short', year: 'numeric' });
  };

  Datafort.money = function (n) {
    return '₹' + Number(n || 0).toLocaleString('en-IN');
  };

  /* Fills the sidebar footer from the session. Every page has the same
   * markup, so this runs once here rather than in nine page scripts. */
  function paintWhoami() {
    var s = Datafort.session;
    var name = document.querySelector('[data-who="name"]');
    var role = document.querySelector('[data-who="role"]');
    var av   = document.querySelector('[data-who="avatar"]');

    if (name) name.textContent = s.name;
    if (role) role.textContent = s.role === 'admin' ? 'Administrator · ' + s.tenant : 'Sales · ' + s.tenant;
    if (av)   av.textContent   = Datafort.initials(s.name);
  }


  /* ══ Boot ══════════════════════════════════════════════════════ */

  function boot() {
    markCurrentNav();
    wireSidebar();
    wireTheme();
    wireModals();
    paintWhoami();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
