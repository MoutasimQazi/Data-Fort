/* desktop-only.js — Datafort does not run on phones or tablets.
 *
 * Loaded FIRST in <head> on every page, before any markup renders. If it
 * ran later, a phone would paint a screenful of lead data and then be
 * told to go away, which is the opposite of the point.
 *
 * ─────────────────────────────────────────────────────────────────
 * WHY BLOCK MOBILE AT ALL
 *
 * The product's containment story assumes a company laptop:
 *
 *   - mTLS. A client certificate cannot practically live on a personal
 *     phone, so a phone can never satisfy the device layer anyway. This
 *     just says so plainly instead of failing at the TLS handshake with
 *     an unreadable browser error.
 *   - Screenshots. Every mobile OS makes capture a two-button reflex,
 *     and none of the browser-side deterrents in guard.js work there —
 *     no blur-on-focus-loss, no meaningful clipboard block.
 *   - Personal devices. A phone is the one machine an employer cannot
 *     wipe, inspect, or take back on the day someone resigns.
 *
 * WHAT THIS IS NOT
 *
 * It is NOT a security control. User-Agent strings are trivially
 * spoofed and any phone can request the desktop site. This is policy
 * enforcement — it stops the person who idly opens Datafort on the bus,
 * not the person deliberately trying to.
 *
 * The real enforcement is the client certificate. When device
 * enforcement is on, a phone is refused because it has no certificate,
 * regardless of what its User-Agent claims. api/db.php carries a
 * matching server-side check so a spoofed browser still cannot reach
 * the API.
 * ─────────────────────────────────────────────────────────────────
 */
(function () {
  'use strict';

  var ua = navigator.userAgent || '';

  /* Phones and tablets alike. A tablet is not a safer place for a
   * purchased lead list than a phone — it is the same capture surface
   * with a bigger screen. */
  var mobileUA = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Windows Phone|Mobile|Tablet|Silk|Kindle/i.test(ua);

  /* iPadOS 13+ reports itself as "Macintosh" and is otherwise
   * indistinguishable from a desktop Safari by User-Agent alone. The
   * touch-point count is what gives it away: a real Mac reports 0. */
  var iPadAsMac = /Macintosh/.test(ua) &&
                  typeof navigator.maxTouchPoints === 'number' &&
                  navigator.maxTouchPoints > 1;

  /* Deliberately NOT a screen-width test. A narrow browser window on a
   * laptop is still a laptop, and blocking it would punish anyone
   * working in a split screen. Width tells you about the window; it
   * tells you nothing about the machine. */
  if (!mobileUA && !iPadAsMac) return;

  /* Stop the rest of the page before it can run. Replacing the document
   * discards the pending markup, so no lead data is ever painted. */
  document.documentElement.innerHTML =
    '<head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width, initial-scale=1">' +
    '<title>Datafort — desktop only</title>' +
    '<style>' +
      'html,body{margin:0;height:100%;font-family:-apple-system,BlinkMacSystemFont,' +
        '"Segoe UI",Roboto,sans-serif;background:#16161A;color:#fff;' +
        '-webkit-text-size-adjust:100%}' +
      '.w{min-height:100%;display:flex;align-items:center;justify-content:center;padding:28px}' +
      '.c{max-width:380px;text-align:center}' +
      'svg{width:52px;height:52px;margin-bottom:20px;opacity:.85}' +
      'h1{font-size:21px;font-weight:600;margin:0 0 12px;line-height:1.3}' +
      'p{font-size:15px;line-height:1.6;color:rgba(255,255,255,.72);margin:0 0 14px}' +
      '.n{font-size:12.5px;color:rgba(255,255,255,.42);line-height:1.6;' +
        'margin-top:24px;padding-top:18px;border-top:1px solid rgba(255,255,255,.14)}' +
    '</style></head>' +
    '<body><div class="w"><div class="c">' +
      '<svg viewBox="0 0 120 128" fill="#fff" aria-hidden="true">' +
        '<path fill-rule="evenodd" d="M12 18 h22 v14 h12 V18 h28 v14 h12 V18 h22 v66 ' +
        'l-48 38 -48 -38 Z M30 40 v36 l30 24 30 -24 V40 Z"/>' +
        '<path d="M38 46 L60 57 L82 46 L82 57 L60 68 L38 57 Z"/>' +
        '<path d="M38 60 L60 71 L82 60 L82 71 L60 82 L38 71 Z"/>' +
        '<path d="M38 74 L60 85 L82 74 L82 85 L60 96 L38 85 Z"/>' +
      '</svg>' +
      '<h1>Datafort only runs on a company laptop</h1>' +
      '<p>Customer data is not available on phones or tablets. Sign in from ' +
      'the computer your organisation issued you.</p>' +
      '<p class="n">This is not a limitation we are working around. A phone ' +
      'cannot carry the device certificate Datafort requires, and it is the ' +
      'one machine your employer cannot wipe or take back. Lead data stays ' +
      'on managed hardware.</p>' +
      /* No link onward, deliberately — a phone should not proceed. But
       * the detection is a User-Agent guess, so a laptop misread as a
       * tablet would otherwise be stuck with no way to say so. */
      '<p class="n" style="margin-top:10px">If this IS a company laptop, ' +
      'tell your administrator that Datafort is misreading it as a mobile ' +
      'device.</p>' +
    '</div></div></body>';

  /* Nothing else on this page should execute. Halting the parser here
   * is what guarantees app.js never fetches a session and never puts a
   * lead on a phone screen. */
  if (window.stop) window.stop();
})();
