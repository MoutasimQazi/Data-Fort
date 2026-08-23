/* reveal.js — one contact value visible at a time.
 *
 * Revealing a second contact re-masks the first. A screenshot, a phone
 * photo or someone reading over a shoulder therefore captures ONE
 * number, not the forty a rep might unmask across a working day.
 *
 * ─────────────────────────────────────────────────────────────────
 * WHAT THIS IS, HONESTLY
 *
 * This is a DISPLAY rule — requirements 7.2, the deterrent tier, not
 * 7.1. The browser already received the pixels; devtools can hold on to
 * them, and nothing here stops that. The daily quota in
 * api/lead-reveal.php remains the actual cap on exposure.
 *
 * What it genuinely buys is that the *window of opportunity* for a
 * casual capture is one value wide instead of a whole screen wide. That
 * is a large reduction for a small cost, which is why it is worth
 * doing — but it must not be described as prevention.
 *
 * The one piece with teeth: revoking the object URL actually destroys
 * the browser's handle on the image data. The old <img> breaks rather
 * than merely being hidden, so re-showing it means a fresh request that
 * the server sees and logs.
 * ─────────────────────────────────────────────────────────────────
 *
 * WHY AUTO RE-MASKING IS NOT CRUEL
 *
 * api/lead-reveal.php charges quota only the FIRST time a given rep
 * unmasks a given field — the "already paid" check against
 * lead_reveals. Re-revealing something you already spent a reveal on is
 * free. Without that, a 60-second timer would burn a rep's daily
 * allowance every time they looked away, and they would start
 * screenshotting to protect themselves. That is the exact behaviour
 * this product exists to discourage.
 */
window.DatafortReveal = (function () {
  'use strict';

  // How long a value stays on screen before it re-masks itself.
  var TTL_MS = 60000;
  var TICK_MS = 1000;

  var active = null;   // { key, url, remask, expires, timer }


  /**
   * Tears down whatever is currently revealed.
   * `silent` suppresses the toast — used when the user is deliberately
   * revealing something else and does not need to be told why the last
   * one went away.
   */
  function clear(silent) {
    if (!active) return;

    var was = active;
    active = null;

    clearInterval(was.timer);

    // The part that actually removes the data from the page rather than
    // hiding it. After this the old <img src="blob:…"> resolves to
    // nothing.
    if (was.url) URL.revokeObjectURL(was.url);

    try { was.remask(); } catch (e) { console.error('[datafort] remask failed:', e); }

    if (!silent && window.Datafort && window.Datafort.toast) {
      window.Datafort.toast('Contact hidden. Revealing it again is free.', null, 3000);
    }
  }


  /**
   * Registers a newly revealed value as the single active one.
   *
   *   key    — "L-4231:phone", used to spot a repeat reveal
   *   url    — the blob URL from api.js, or null for the plain-text fallback
   *   remask — called to put that cell back to its masked state
   *   onTick — optional, receives seconds remaining so the UI can count down
   */
  function claim(key, url, remask, onTick) {
    clear(true);

    var expires = Date.now() + TTL_MS;

    active = {
      key: key,
      url: url,
      remask: remask,
      expires: expires,
      timer: setInterval(function () {
        var left = Math.ceil((active.expires - Date.now()) / 1000);

        if (left <= 0) { clear(false); return; }
        if (onTick) onTick(left);
      }, TICK_MS)
    };

    if (onTick) onTick(Math.ceil(TTL_MS / 1000));
  }


  /** Is this exact field the one currently on screen? */
  function isActive(key) {
    return !!active && active.key === key;
  }


  /* ══ Re-mask when the rep looks away ═══════════════════════════
   *
   * guard.js already blurs the whole screen behind a shroud on blur.
   * This goes further and actually clears the value, so returning to
   * the tab does not put a phone number back on screen that has been
   * sitting there unattended.
   *
   * Safe to be aggressive precisely because re-revealing is free.
   */
  window.addEventListener('blur', function () { clear(true); });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible') clear(true);
  });

  // Release the blob on navigation so it is not left pinned in memory.
  window.addEventListener('pagehide', function () { clear(true); });


  return {
    claim: claim,
    clear: clear,
    isActive: isActive,
    ttlSeconds: Math.round(TTL_MS / 1000)
  };
})();
