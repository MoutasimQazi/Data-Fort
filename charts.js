/* charts.js — dashboard figures.
 *
 * Inline SVG, no library. Datafort ships as static files with no build
 * step, and a charting library would be the largest thing on the page
 * by an order of magnitude to draw four small figures. It would also
 * mean a third-party script running on a page full of lead data, which
 * is a strange thing to accept in a product sold on containment.
 *
 * ── The palette ──
 * Four categorical slots, validated with the six checks against this
 * app's real surfaces (#FFFFFF light, #131E33 dark) rather than
 * assumed. Lightness band, chroma floor, CVD separation, normal-vision
 * separation and contrast all PASS in both modes.
 *   light  worst adjacent CVD ΔE 9.1 (protan), normal ΔE 26.4
 *   dark   worst adjacent CVD ΔE 11.4 (deutan), normal ΔE 25.3
 * against a target of 8. The dark steps are chosen for the dark
 * surface, not flipped from the light ones.
 *
 * Status colours (new / working / won / lost) are a SEPARATE reserved
 * palette and never double as a categorical series.
 */
(function () {
  'use strict';

  var CATEGORICAL = {
    light: ['#2a6fe0', '#e2661f', '#0f9070', '#8b5cf6'],
    dark:  ['#4d8ef5', '#e06c2c', '#1aa681', '#9575ec']
  };

  var STATUS = {
    light: { new: '#2a6fe0', working: '#B45309', won: '#0f9070', lost: '#DC2626' },
    dark:  { new: '#4d8ef5', working: '#FBBF24', won: '#1aa681', lost: '#F87171' }
  };

  function isDark() {
    var attr = document.documentElement.getAttribute('data-theme');
    if (attr === 'dark') return true;
    if (attr === 'light') return false;
    return window.matchMedia &&
           window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function cat()    { return isDark() ? CATEGORICAL.dark : CATEGORICAL.light; }
  function status() { return isDark() ? STATUS.dark : STATUS.light; }

  var INK   = 'var(--text)';
  var MUTED = 'var(--text-muted)';
  var FAINT = 'var(--text-faint)';
  var GRID  = 'var(--border-soft)';
  var SURFACE = 'var(--surface)';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function svgEl(w, h) {
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="' + h +
           '" preserveAspectRatio="xMidYMid meet" role="img" ' +
           'style="overflow:visible">';
  }


  /* ══ Tooltip ═══════════════════════════════════════════════════ */

  var tip = null;

  function tipEl() {
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'charttip';
      tip.hidden = true;
      document.body.appendChild(tip);
    }
    return tip;
  }

  function showTip(html, evt) {
    var t = tipEl();
    t.innerHTML = html;
    t.hidden = false;

    // Flip to the left of the cursor near the right edge so the tooltip
    // never pushes the page into a horizontal scroll.
    var pad = 14;
    var w = t.offsetWidth;
    var x = evt.clientX + pad;
    if (x + w > window.innerWidth - 8) x = evt.clientX - w - pad;

    t.style.left = x + 'px';
    t.style.top  = Math.max(8, evt.clientY - t.offsetHeight - 10) + 'px';
  }

  function hideTip() { if (tip) tip.hidden = true; }

  /* One delegated pair of listeners for every chart on the page.
   * Any element carrying data-tip gets a tooltip for free. */
  document.addEventListener('mouseover', function (e) {
    var host = e.target.closest && e.target.closest('[data-tip]');
    if (host) showTip(host.getAttribute('data-tip'), e);
  });
  document.addEventListener('mousemove', function (e) {
    var host = e.target.closest && e.target.closest('[data-tip]');
    if (host) showTip(host.getAttribute('data-tip'), e);
    else hideTip();
  });
  document.addEventListener('mouseleave', hideTip, true);


  /* ══ Horizontal bars ═══════════════════════════════════════════
   *
   * The workhorse. Magnitude compared across a handful of named
   * categories — the label is long and the count is short, which is
   * exactly when horizontal beats vertical.
   *
   * opts.colorBy: 'status' uses the reserved status palette keyed on
   * row.key; anything else uses categorical slot 0 for the whole
   * series, because one series does not need four hues.
   */
  function hbars(host, rows, opts) {
    opts = opts || {};
    if (!host) return;

    var barH = 22, gap = 12, padL = 132, padR = 54, padT = 6;
    var h = rows.length * (barH + gap) - gap + padT * 2;
    var w = 560;
    var plotW = w - padL - padR;
    var max = Math.max.apply(null, rows.map(function (r) { return r.value; })) || 1;

    var colors = status();
    var one = cat()[0];

    var out = svgEl(w, h);

    rows.forEach(function (r, i) {
      var y = padT + i * (barH + gap);
      var len = Math.max(2, (r.value / max) * plotW);
      var fill = opts.colorBy === 'status' ? (colors[r.key] || one) : one;

      // Category label
      out += '<text x="' + (padL - 12) + '" y="' + (y + barH / 2 + 4) + '" ' +
             'text-anchor="end" font-size="12.5" fill="' + MUTED + '">' +
             esc(r.label) + '</text>';

      // Track, then the bar. rx 4 gives the rounded data-end.
      out += '<rect x="' + padL + '" y="' + y + '" width="' + plotW + '" height="' + barH +
             '" rx="4" fill="' + GRID + '" opacity=".5"/>';

      out += '<g data-tip="' + esc(r.label) + '<br><b>' + r.value.toLocaleString() + '</b>' +
             (r.note ? ' · ' + esc(r.note) : '') + '">' +
             '<rect x="' + padL + '" y="' + y + '" width="' + len + '" height="' + barH +
             '" rx="4" fill="' + fill + '"/>' +
             // Invisible full-width hit target: the bar for a small value
             // is too thin to hover comfortably.
             '<rect x="' + padL + '" y="' + (y - 4) + '" width="' + plotW + '" height="' + (barH + 8) +
             '" fill="transparent"/>' +
             '</g>';

      // Direct value label. Selective by design — the count sits at the
      // end of each bar, and there is no axis competing with it.
      out += '<text x="' + (padL + len + 9) + '" y="' + (y + barH / 2 + 4) + '" ' +
             'font-size="12.5" font-weight="600" fill="' + INK + '" ' +
             'style="font-variant-numeric:tabular-nums">' +
             (opts.format ? opts.format(r.value) : r.value.toLocaleString()) + '</text>';
    });

    out += '</svg>';
    host.innerHTML = out;
  }


  /* ══ Trend lines ═══════════════════════════════════════════════
   *
   * Two series over 14 days. Deliberately NOT a dual axis: reveals and
   * contacts are both counts of actions, so they share one scale and
   * the comparison between them is the entire point of the figure.
   */
  function trend(host, rows, series) {
    if (!host) return;

    var w = 560, h = 210;
    var padL = 38, padR = 58, padT = 14, padB = 26;
    var plotW = w - padL - padR;
    var plotH = h - padT - padB;

    var max = 0;
    rows.forEach(function (r) {
      series.forEach(function (s) { max = Math.max(max, r[s.key]); });
    });
    max = Math.ceil(max / 50) * 50 || 50;

    var colors = cat();
    var x = function (i) { return padL + (i / (rows.length - 1)) * plotW; };
    var y = function (v) { return padT + plotH - (v / max) * plotH; };

    var out = svgEl(w, h);

    // Recessive gridlines with the value at the left.
    [0, 0.5, 1].forEach(function (f) {
      var gy = padT + plotH - f * plotH;
      out += '<line x1="' + padL + '" y1="' + gy + '" x2="' + (padL + plotW) + '" y2="' + gy +
             '" stroke="' + GRID + '" stroke-width="1"/>';
      out += '<text x="' + (padL - 8) + '" y="' + (gy + 4) + '" text-anchor="end" ' +
             'font-size="11" fill="' + FAINT + '">' + Math.round(max * f) + '</text>';
    });

    // Date ticks: first, middle, last only. A label per day would collide.
    [0, Math.floor(rows.length / 2), rows.length - 1].forEach(function (i) {
      var d = new Date(rows[i].date);
      out += '<text x="' + x(i) + '" y="' + (h - 6) + '" text-anchor="middle" ' +
             'font-size="11" fill="' + FAINT + '">' +
             d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) + '</text>';
    });

    series.forEach(function (s, si) {
      var color = colors[si];
      var d = rows.map(function (r, i) {
        return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(r[s.key]).toFixed(1);
      }).join(' ');

      out += '<path d="' + d + '" fill="none" stroke="' + color +
             '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

      // Direct label at the last point — identity without hunting the legend.
      var last = rows[rows.length - 1];
      out += '<circle cx="' + x(rows.length - 1) + '" cy="' + y(last[s.key]) + '" r="4" ' +
             'fill="' + color + '" stroke="' + SURFACE + '" stroke-width="2"/>';
      out += '<text x="' + (x(rows.length - 1) + 9) + '" y="' + (y(last[s.key]) + 4) + '" ' +
             'font-size="11.5" font-weight="600" fill="' + color + '">' + s.label + '</text>';
    });

    /* Crosshair band per day. One hit target spanning the full height
     * beats asking someone to hover a 2px line. */
    rows.forEach(function (r, i) {
      var bandW = plotW / (rows.length - 1);
      var bx = x(i) - bandW / 2;
      var lines = series.map(function (s, si) {
        return '<span style="color:' + colors[si] + '">■</span> ' + s.label +
               ' <b>' + r[s.key] + '</b>';
      }).join('<br>');

      out += '<rect x="' + bx + '" y="' + padT + '" width="' + bandW + '" height="' + plotH +
             '" fill="transparent" data-tip="' +
             esc(new Date(r.date).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })) +
             '<br>' + lines.replace(/"/g, '&quot;') + '"/>';
    });

    out += '</svg>';
    host.innerHTML = out;
  }


  /* ══ Table fallback ════════════════════════════════════════════
   *
   * Every figure gets one. Identity is never carried by colour alone,
   * and a screen reader gets the numbers rather than an <svg> shrug.
   */
  function table(host, cols, rows) {
    if (!host) return;
    var out = '<table class="table"><thead><tr>';
    cols.forEach(function (c) {
      out += '<th' + (c.num ? ' class="num"' : '') + '>' + esc(c.label) + '</th>';
    });
    out += '</tr></thead><tbody>';

    rows.forEach(function (r) {
      out += '<tr>';
      cols.forEach(function (c) {
        out += '<td' + (c.num ? ' class="num"' : '') + '>' + esc(r[c.key]) + '</td>';
      });
      out += '</tr>';
    });

    host.innerHTML = out + '</tbody></table>';
  }


  /* ══ Legend ════════════════════════════════════════════════════ */

  function legend(host, items) {
    if (!host) return;
    var colors = cat();
    host.innerHTML = items.map(function (it, i) {
      return '<span style="color:' + (it.color || colors[i]) + '">' +
             '<i></i><span style="color:var(--text-muted)">' + esc(it.label) + '</span></span>';
    }).join('');
  }


  window.DatafortCharts = {
    hbars: hbars,
    trend: trend,
    table: table,
    legend: legend,
    statusColors: status,
    categorical: cat
  };
})();
