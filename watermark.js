/* watermark.js — per-user forensic watermark. Requirements section 7.3.
 *
 * Tiles the signed-in rep's name, user ID and the current timestamp
 * across every screen that shows lead data. A phone photo of the
 * monitor then identifies exactly whose session it was taken from.
 *
 * NOT the logo. The watermark is forensic, not decorative — see
 * brand/BRAND.txt, "Do not confuse the logo with the security
 * watermark". If someone swaps this for the Datafort mark because it
 * looks tidier, the entire attribution layer becomes worthless.
 *
 * Honest about its limits: this is a DOM layer, and a DOM layer can be
 * deleted in devtools. It raises the cost of a casual photo. The thing
 * that actually survives an attacker is the baked-in watermark on the
 * revealed contact value, where removing the mark removes the data.
 */
(function () {
  'use strict';

  var LAYER_ID = 'dfWatermark';
  var REFRESH_MS = 60000;   // timestamp granularity: one minute

  function session() {
    /* Populated by app.js from the session endpoint. The fallback is
     * deliberately alarming rather than blank — an unattributed screen
     * showing lead data is a bug, and it should look like one. */
    var s = (window.Datafort && window.Datafort.session) || {};
    return {
      name: s.name || 'UNIDENTIFIED SESSION',
      id:   s.id   || 'no-user-id'
    };
  }

  function stamp() {
    var d = new Date();
    function p(n) { return String(n).padStart(2, '0'); }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) +
           ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  /* Rendered as an SVG data URI and used as a repeating background.
   * A tiled background has no per-tile DOM node, so there is nothing to
   * delete one instance of — it is removed wholesale or not at all,
   * which is a much more visible act. */
  function tile(color) {
    var s = session();
    var line1 = esc(s.name + ' · ' + s.id);
    var line2 = esc(stamp());
    var ink = esc(color || '#0B0B0C');

    var svg =
      '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="180">' +
        '<g transform="rotate(-24 150 90)" ' +
           'font-family="Inter, Segoe UI, sans-serif" font-size="13" ' +
           'font-weight="600" fill="' + ink + '" text-anchor="middle">' +
          '<text x="150" y="84">' + line1 + '</text>' +
          '<text x="150" y="102" font-size="11" font-weight="400">' + line2 + '</text>' +
        '</g>' +
      '</svg>';

    return 'url("data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg) + '")';
  }

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function paint() {
    var layer = document.getElementById(LAYER_ID);

    if (!layer) {
      layer = document.createElement('div');
      layer.id = LAYER_ID;
      layer.className = 'watermark';
      layer.setAttribute('aria-hidden', 'true');
      document.body.appendChild(layer);
    }

    // Data-URI SVGs do not reliably inherit currentColor from the element,
    // so embed the resolved theme colour directly in the tile.
    var color = getComputedStyle(document.documentElement)
      .getPropertyValue('--text').trim();
    layer.style.color = color;
    layer.style.backgroundImage = tile(color);
  }

  function start() {
    paint();
    setInterval(paint, REFRESH_MS);

    /* If the layer is removed — devtools, or an extension — put it back
     * and log the removal. Re-adding is trivially defeated by anyone
     * persistent; the audit event is the part that matters. */
    if (window.MutationObserver) {
      new MutationObserver(function () {
        if (!document.getElementById(LAYER_ID)) {
          paint();
          if (window.Datafort && window.Datafort.securityEvent) {
            window.Datafort.securityEvent('watermark_removed');
          }
        }
      }).observe(document.body, { childList: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  window.DatafortWatermark = { repaint: paint };
})();
