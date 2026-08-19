/* guard.js — client hardening. Implements requirements section 7.2.
 *
 * ─────────────────────────────────────────────────────────────────
 * READ THIS BEFORE TRUSTING ANY OF IT
 *
 * Nothing in this file is a security control. Every line can be
 * defeated by opening devtools and deleting a listener, or by fetching
 * the API directly with curl and never loading the page at all.
 *
 * What it actually buys:
 *   1. It stops the casual leak — the rep who would have selected the
 *      table and hit Ctrl+C without really thinking about it.
 *   2. It generates audit signal. Every block below reports to the
 *      server, so "tried to copy 40 times this morning" becomes a row
 *      the admin can see.
 *
 * The real containment is server-side: masking, the admin-set daily
 * quota, and the fact that no export endpoint exists (section 7.1).
 * The real deterrent is attribution: watermark and honeytokens (7.3).
 * If those are weak, nothing here saves the product.
 *
 * Loaded on REP pages only. Admins are the tenant's trusted party and
 * blocking copy on the audit log would just stop them working.
 * ─────────────────────────────────────────────────────────────────
 */
(function () {
  'use strict';

  var REPORT_URL = 'api/security-event.php';

  /* Events are batched rather than sent one-per-keystroke. Someone
   * mashing Ctrl+C twenty times should cost one request, not twenty. */
  var queue = [];
  var flushTimer = null;

  function report(type, detail) {
    queue.push({
      type: type,
      detail: detail || null,
      at: new Date().toISOString(),
      page: location.pathname
    });

    if (flushTimer) return;
    flushTimer = setTimeout(flush, 4000);
  }

  function flush() {
    flushTimer = null;
    if (!queue.length) return;

    var batch = queue.splice(0, queue.length);

    /* sendBeacon so the report still lands when this fires during
     * pagehide — a normal fetch is cancelled on navigation, which is
     * exactly when someone leaving with data would trigger it. */
    var body = JSON.stringify({ events: batch });
    if (navigator.sendBeacon) {
      navigator.sendBeacon(REPORT_URL, new Blob([body], { type: 'application/json' }));
    } else {
      fetch(REPORT_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: body,
        keepalive: true
      }).catch(function () { /* the audit trail is server-side too; a lost beacon is not fatal */ });
    }
  }

  window.addEventListener('pagehide', flush);


  /* ══ Copy, cut, context menu, selection ════════════════════════ */

  ['copy', 'cut'].forEach(function (evt) {
    document.addEventListener(evt, function (e) {
      // Inputs are exempt: a rep typing a note must be able to edit it.
      if (isEditable(e.target)) return;
      e.preventDefault();
      report('clipboard_blocked', evt);
      toast('Copying is disabled. This attempt has been logged.');
    });
  });

  document.addEventListener('contextmenu', function (e) {
    if (isEditable(e.target)) return;
    e.preventDefault();
    report('contextmenu_blocked');
  });

  document.addEventListener('dragstart', function (e) {
    // Dragging selected text into another window is a clipboard bypass.
    if (isEditable(e.target)) return;
    e.preventDefault();
    report('drag_blocked');
  });

  function isEditable(el) {
    if (!el || !el.tagName) return false;
    var tag = el.tagName.toLowerCase();
    return tag === 'input' || tag === 'textarea' || el.isContentEditable;
  }


  /* ══ Keyboard ══════════════════════════════════════════════════ */

  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toLowerCase();
    var mod = e.ctrlKey || e.metaKey;

    // Ctrl+P print, Ctrl+S save page, Ctrl+U view source.
    if (mod && (k === 'p' || k === 's' || k === 'u')) {
      e.preventDefault();
      report('key_blocked', 'ctrl+' + k);
      toast(k === 'p' ? 'Printing is disabled on lead data.' : 'This shortcut is disabled.');
      return;
    }

    // Ctrl+A outside an input selects the whole lead table.
    if (mod && k === 'a' && !isEditable(e.target)) {
      e.preventDefault();
      report('key_blocked', 'ctrl+a');
      return;
    }

    // Devtools shortcuts. Blocking these does not stop the menu route,
    // it just means the attempt is deliberate and gets logged as such.
    if (k === 'f12' || (mod && e.shiftKey && (k === 'i' || k === 'j' || k === 'c'))) {
      e.preventDefault();
      report('devtools_shortcut', k);
      return;
    }

    /* PrintScreen does not steal focus and cannot be prevented. All we
     * can do is note that the key was pressed while lead data was on
     * screen. Chrome fires this; some browsers never will. */
    if (k === 'printscreen') {
      report('printscreen_pressed');
      toast('Screen capture attempts are recorded against your account.');
    }
  }, true);


  /* ══ Blur on focus loss ════════════════════════════════════════
   *
   * The strongest screenshot deterrent available in a browser. Windows
   * Snipping Tool and Snip & Sketch take focus when they open, so the
   * data is already blurred by the time the capture is drawn.
   *
   * It does NOT stop the raw PrintScreen key, which takes no focus.
   * See requirements 7.2, "Accepted limitation".
   */
  var veil = null;

  function shroud(on) {
    if (on && !veil) {
      veil = document.createElement('div');
      veil.className = 'shroud';
      veil.setAttribute('aria-hidden', 'true');
      veil.innerHTML =
        '<div class="shroud__box">' +
          '<strong>Lead data hidden</strong>' +
          '<span>Return to this window to continue.</span>' +
        '</div>';
      document.body.appendChild(veil);
      document.body.classList.add('is-shrouded');
    } else if (!on && veil) {
      veil.remove();
      veil = null;
      document.body.classList.remove('is-shrouded');
    }
  }

  window.addEventListener('blur', function () { shroud(true); });
  window.addEventListener('focus', function () { shroud(false); });

  document.addEventListener('visibilitychange', function () {
    shroud(document.visibilityState !== 'visible');
  });


  /* ══ Devtools detection ════════════════════════════════════════
   *
   * A size heuristic: docked devtools shrink the viewport relative to
   * the window. Undocked panels defeat it, and so does anyone who
   * cares. It is here for the audit event, not the block.
   */
  var devtoolsOpen = false;

  setInterval(function () {
    var gap = 170;
    var open = (window.outerWidth  - window.innerWidth  > gap) ||
               (window.outerHeight - window.innerHeight > gap);

    if (open && !devtoolsOpen) {
      devtoolsOpen = true;
      report('devtools_opened');
      document.body.classList.add('is-inspected');
      toast('Developer tools detected. Your administrator has been notified.');
    } else if (!open && devtoolsOpen) {
      devtoolsOpen = false;
      document.body.classList.remove('is-inspected');
    }
  }, 1500);


  /* ══ Toast ═════════════════════════════════════════════════════ */

  /* Uses the shared toast if app.js has loaded, otherwise stays silent
   * rather than throwing — guard.js must never break a page. */
  var lastToast = 0;
  function toast(message) {
    var now = Date.now();
    if (now - lastToast < 2500) return;   // do not stack on key-mashing
    lastToast = now;
    if (window.Datafort && window.Datafort.toast) {
      window.Datafort.toast(message, 'error');
    }
  }


  // Marks the page as hardened so CSS can react (see the .is-shrouded
  // and print rules in app.css / guard styles below).
  document.documentElement.setAttribute('data-guarded', 'true');
})();
