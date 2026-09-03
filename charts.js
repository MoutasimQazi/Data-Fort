/* charts.js — dashboard figures.
 *
 * Inline SVG, no library. Datafort ships as static files with no build
 * step, and a charting library would be the largest thing on the page
 * by an order of magnitude to draw three small figures. It would also
 * mean a third-party script running on a page full of lead data, which
 * is a strange thing to accept in a product sold on containment.
 *
 * ── Responsiveness ──
 * Each figure is drawn at its host's real pixel width and redrawn when
 * that width changes, so one SVG unit is one CSS pixel at every
 * breakpoint. A viewBox scaled to fit would shrink the type along with
 * the chart — an 11px axis label becomes 6px on a phone. Drawing to
 * measure instead lets the layout adapt: fewer ticks, shorter labels,
 * tighter padding.
 *
 * ── The palette ──
 * Four categorical slots, validated with the six checks against this
 * app's real surfaces (#FFFFFF light, #151518 dark) rather than
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
  var AXIS  = 'var(--border)';
  var SURFACE = 'var(--surface)';

  /* Escapes for markup INCLUDING attributes. The tooltip text is built
   * into  data-tip="…"  and the whole SVG is then assigned via
   * innerHTML, so a source name or a rep name containing a quote would
   * otherwise close the attribute and inject a handler. Those names come
   * from imported spreadsheets and from the user table — not from us. */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /* Assembled tooltip markup keeps its own <b> and <i> tags — only the
   * quotes need neutralising to survive the attribute. Every dynamic
   * value inside has already been through esc(), and esc() turns a
   * quote into &quot; before this runs, so nothing is double-escaped. */
  function tipAttr(html) { return String(html).replace(/"/g, '&quot;'); }

  function num(v) { return Number(v || 0).toLocaleString(); }

  var uid = 0;
  function nextId() { return 'df' + (++uid); }


  /* ══ Scales ════════════════════════════════════════════════════
   *
   * The axis ends on a round number so the gridlines mean something.
   * Every series this dashboard plots is a count of things, so the step
   * is forced to a whole number — "1.5 leads" is not a tick. */
  function niceScale(max, count) {
    if (!isFinite(max) || max <= 0) return { top: 4, step: 1, values: [0, 1, 2, 3, 4] };

    /* Every candidate step around the magnitude of the data, then the
     * smallest one that still fits inside the tick budget. Deriving the
     * step from max/count directly rounds the wrong way at the band
     * edges — 64 over 3 ticks lands on 50, which tops the axis out at
     * 100 and throws away half the plot. Choosing by fit lands on 25. */
    var mag = Math.pow(10, Math.floor(Math.log(max) / Math.LN10));
    var steps = [];
    [0.1, 0.2, 0.25, 0.5, 1, 2, 2.5, 5, 10].forEach(function (m) {
      var v = Math.round(m * mag);
      if (v >= 1 && steps.indexOf(v) === -1) steps.push(v);
    });
    steps.sort(function (a, b) { return a - b; });

    var step = steps[steps.length - 1];
    for (var i = 0; i < steps.length; i++) {
      if (Math.ceil(max / steps[i]) <= count) { step = steps[i]; break; }
    }

    var top = Math.ceil(max / step) * step;
    var values = [];
    for (var v = 0; v <= top + 0.5; v += step) values.push(v);
    return { top: top, step: step, values: values };
  }

  /* No text metrics without a layout pass, so labels are budgeted by
   * average glyph width. A truncated name keeps its full text in the
   * tooltip and in the table under the figure, so nothing is lost. */
  var GLYPH = 12.5 * 0.55;   // average advance at the 12.5px label size

  function fit(label, px) {
    var maxChars = Math.max(3, Math.floor(px / GLYPH));
    var s = String(label == null ? '' : label);
    return s.length > maxChars ? s.slice(0, maxChars - 1).replace(/\s+$/, '') + '…' : s;
  }

  function svgOpen(w, h, title) {
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" width="' + w + '" height="' + h + '" ' +
           'class="chartsvg" role="img"' +
           (title ? ' aria-label="' + esc(title) + '"' : '') + '>' +
           (title ? '<title>' + esc(title) + '</title>' : '');
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

  /* Every figure builds the same shape: a heading, one row per series
   * with its swatch, and an optional derived line under a rule. One
   * tooltip that always looks the same is one thing to learn. */
  function tipHTML(title, rows, footer) {
    var out = '<div class="charttip__head">' + esc(title) + '</div>';

    if (rows && rows.length) {
      out += '<div class="charttip__rows">' + rows.map(function (r) {
        return '<div class="charttip__row">' +
          (r.color ? '<i style="background:' + esc(r.color) + '"></i>' : '<i class="charttip__gap"></i>') +
          '<span>' + esc(r.label) + '</span>' +
          '<b>' + esc(r.value) + '</b></div>';
      }).join('') + '</div>';
    }

    if (footer) {
      out += '<div class="charttip__foot"><span>' + esc(footer.label) + '</span>' +
             '<b>' + esc(footer.value) + '</b></div>';
    }
    return out;
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

    t.style.left = Math.max(8, x) + 'px';
    t.style.top  = Math.max(8, evt.clientY - t.offsetHeight - 12) + 'px';
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
  window.addEventListener('scroll', hideTip, true);


  /* ══ Responsive mounting ═══════════════════════════════════════
   *
   * A figure keeps its draw function on the host. One ResizeObserver
   * serves every chart on the page and repaints on a width change —
   * height changes are ignored, because the repaint itself changes the
   * height and would otherwise feed back into the observer forever. */
  var ro = null;

  function widthOf(host) {
    var w = host.clientWidth;
    if (!w && host.parentNode) w = host.parentNode.clientWidth;
    return Math.max(220, Math.round(w || 560));
  }

  function paint(host) {
    if (!host.__dfDraw) return;
    var w = widthOf(host);
    host.__dfWidth = w;
    host.innerHTML = host.__dfDraw(w);
    if (host.__dfWire) host.__dfWire(host);
  }

  function mount(host, draw, wire) {
    host.__dfDraw = draw;
    host.__dfWire = wire || null;

    if (window.ResizeObserver) {
      if (!ro) {
        ro = new ResizeObserver(function (entries) {
          entries.forEach(function (en) {
            var h = en.target;
            if (!h.__dfDraw) return;
            var w = widthOf(h);
            if (Math.abs(w - (h.__dfWidth || 0)) > 1) paint(h);
          });
        });
      }
      if (!host.__dfObserved) { ro.observe(host); host.__dfObserved = true; }
    }

    paint(host);
  }

  /* A figure that has gone empty or is reloading must stop answering
   * resize events, or the observer repaints the stale drawing over the
   * placeholder the caller just wrote. */
  function release(host) { host.__dfDraw = null; host.__dfWire = null; }


  /* ══ Placeholders ══════════════════════════════════════════════ */

  function emptyFigure(message, hint) {
    return '<div class="chartempty">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" ' +
      'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="m7 15 3.5-4 3 2.5L20 7"/></svg>' +
      '<p>' + esc(message) + '</p>' +
      (hint ? '<span>' + esc(hint) + '</span>' : '') + '</div>';
  }

  /* Skeleton rows rather than a spinner: the shape of the answer is
   * already known, so the card does not jump when the data lands. */
  function loading(host, rows) {
    if (!host) return;
    release(host);

    var n = rows || 4;
    var out = '<div class="chartload" aria-hidden="true">';
    for (var i = 0; i < n; i++) {
      out += '<div class="chartload__row">' +
             '<span class="chartload__label"></span>' +
             '<span class="chartload__bar" style="width:' + (92 - i * 16) + '%"></span></div>';
    }
    host.innerHTML = out + '</div>';
  }

  function empty(host, message, hint) {
    if (!host) return;
    release(host);
    host.innerHTML = emptyFigure(message, hint);
  }


  /* ══ Horizontal bars ═══════════════════════════════════════════
   *
   * The workhorse. Magnitude compared across a handful of named
   * categories — the label is long and the count is short, which is
   * exactly when horizontal beats vertical.
   *
   * opts.colorBy 'status' uses the reserved status palette keyed on
   *   row.key; anything else uses categorical slot 0 for the series,
   *   because one series does not need four hues.
   * opts.rank      sorts descending and marks the leader.
   * opts.axisLabel names the quantity under the tick row.
   * opts.unit      what one bar counts, used in the tooltip.
   *
   * Returns the rows in the order they were drawn, so the caller can
   * build the table fallback from the same array.
   */
  function hbars(host, rows, opts) {
    opts = opts || {};
    if (!host) return [];

    /* No rows means a zero-height SVG and a blank card. On a fresh
     * tenant that is every chart silently empty, which reads as a
     * broken page rather than as "nothing has happened yet". */
    if (!rows || !rows.length) {
      empty(host, opts.emptyText || 'No data yet', opts.emptyHint);
      return [];
    }

    /* `raw` carries the caller's original row through the sort, so the
     * table underneath can print columns the chart never drew without
     * matching rows back up by label — two reps can share a name. */
    var data = rows.map(function (r) {
      return { key: r.key, label: r.label, value: Number(r.value) || 0, note: r.note, raw: r };
    });

    /* Ranked figures are sorted here, not by the caller — the table
     * under the figure is built from the returned array, so the two
     * can never disagree about the order. */
    if (opts.rank) data.sort(function (a, b) { return b.value - a.value; });

    mount(host, function (w) { return drawBars(w, data, opts); });
    return data;
  }

  var STAR = 'M12 2.6l2.6 5.6 6 .8-4.4 4.2 1.1 6.1-5.3-2.9-5.3 2.9 1.1-6.1L3.4 9l6-.8z';

  function drawBars(w, rows, opts) {
    var compact = w < 400;
    var fmt = opts.format || num;

    /* The label column is sized to the labels, not to a fixed share of
     * the card. Four words like "Working" would otherwise reserve the
     * same 30% as a column of full names, and the bars pay for it. */
    var longest = 0;
    rows.forEach(function (r) {
      longest = Math.max(longest, String(r.label == null ? '' : r.label).length);
    });
    var starW  = opts.rank ? 16 : 0;

    /* GLYPH is shared with fit() below. If the column were budgeted at
     * one constant and the truncation at another, a label sized to fit
     * exactly would still lose its last character to rounding. The +4
     * is the slack that keeps Math.floor on the right side of that. */
    var labelW = Math.round(Math.min(
      Math.max(Math.ceil(longest * GLYPH) + starW + 4, 46),
      w * (compact ? 0.42 : 0.34)));
    var padL   = labelW + 14;
    var padR   = compact ? 42 : 54;
    var padT   = 6;
    var padB   = opts.axisLabel ? 42 : 28;

    var barH = compact ? 17 : 21;
    var gap  = compact ? 13 : 15;

    var plotW = Math.max(40, w - padL - padR);
    var plotH = rows.length * (barH + gap) - gap;
    var h     = padT + plotH + padB;

    /* Math.max.apply(null, []) is -Infinity, which is truthy — so the
     * usual `|| 1` fallback does not catch it. The caller guards the
     * empty case, and niceScale() floors the all-zeros one. */
    var max = Math.max.apply(null, rows.map(function (r) { return r.value; }));
    var scale = niceScale(max, compact ? 3 : 5);
    var x = function (v) { return padL + (v / scale.top) * plotW; };

    var colors = status();
    var one = cat()[0];
    var lead = 0;
    rows.forEach(function (r) { if (r.value > lead) lead = r.value; });

    var axisY = padT + plotH;
    var out = svgOpen(w, h, opts.aria);

    // Gridlines first, so every mark sits on top of them.
    scale.values.forEach(function (v) {
      var gx = x(v);
      out += '<line x1="' + gx.toFixed(1) + '" y1="' + padT + '" x2="' + gx.toFixed(1) +
             '" y2="' + axisY + '" stroke="' + (v === 0 ? AXIS : GRID) + '" stroke-width="1"/>';
      out += '<text x="' + gx.toFixed(1) + '" y="' + (axisY + 16) + '" text-anchor="middle" ' +
             'font-size="10.5" fill="' + FAINT + '" ' +
             'style="font-variant-numeric:tabular-nums">' + num(v) + '</text>';
    });

    if (opts.axisLabel) {
      out += '<text x="' + (padL + plotW / 2).toFixed(1) + '" y="' + (h - 6) + '" ' +
             'text-anchor="middle" font-size="10.5" fill="' + FAINT + '">' +
             esc(opts.axisLabel) + '</text>';
    }

    rows.forEach(function (r, i) {
      var y    = padT + i * (barH + gap);
      var mid  = y + barH / 2;
      var zero = r.value <= 0;
      var top  = !!opts.rank && i === 0 && !zero;
      var fill = opts.colorBy === 'status' ? (colors[r.key] || one) : one;

      /* Emphasis is carried by opacity on one hue rather than by a
       * second colour: the leader reads first, everything else stays
       * comparable, and a zero drops to a stub so an idle rep is
       * visibly idle rather than merely short. */
      var alpha = zero ? 0.3 : (r.value === lead) ? 1 : 0.72;

      if (top) {
        out += '<g transform="translate(0 ' + (mid - 6.5).toFixed(1) + ') scale(0.54)" ' +
               'fill="' + fill + '" opacity=".9" aria-hidden="true">' +
               '<path d="' + STAR + '"/></g>';
      }

      out += '<text x="' + (padL - 12) + '" y="' + (mid + 4).toFixed(1) + '" text-anchor="end" ' +
             'font-size="12.5" fill="' + (zero ? FAINT : MUTED) + '"' +
             (top ? ' font-weight="600"' : '') + '>' +
             esc(fit(r.label, labelW - starW)) + '</text>';

      var len = zero ? 3 : Math.max(3, (r.value / scale.top) * plotW);

      var tipRows = [{ label: opts.unit || 'Value', value: fmt(r.value), color: fill }];
      if (r.note) tipRows.push({ label: r.note.label || 'Detail', value: r.note.value || r.note });

      out += '<g data-tip="' + tipAttr(tipHTML(r.label, tipRows)) + '">' +
             '<rect x="' + padL + '" y="' + y + '" width="' + len.toFixed(1) + '" height="' + barH +
             '" rx="4" fill="' + fill + '" opacity="' + alpha + '"/>' +
             // Invisible full-row hit target: the bar for a small value
             // is too thin to hover comfortably.
             '<rect x="0" y="' + (y - gap / 2) + '" width="' + w + '" height="' + (barH + gap) +
             '" fill="transparent"/></g>';

      // Direct value label. Selective by design — the count sits at the
      // end of each bar and the axis is there only for scale.
      var text = String(fmt(r.value));
      var vx = padL + len + 8;
      var inside = vx + text.length * 7.4 > w - 2;

      out += '<text x="' + (inside ? padL + len - 8 : vx).toFixed(1) + '" y="' + (mid + 4).toFixed(1) + '" ' +
             (inside ? 'text-anchor="end" ' : '') +
             'font-size="12.5" font-weight="600" ' +
             'fill="' + (inside ? '#fff' : zero ? FAINT : INK) + '" ' +
             'style="font-variant-numeric:tabular-nums">' + esc(text) + '</text>';
    });

    return out + '</svg>';
  }


  /* ══ Trend lines ═══════════════════════════════════════════════
   *
   * Two series over 14 days. Deliberately NOT a dual axis: reveals and
   * contacts are both counts of actions, so they share one scale and
   * the comparison between them is the entire point of the figure.
   *
   * opts.footer(row) adds one derived line to the tooltip — the rate
   * between the two series, which is the number people actually want
   * and the only one not already drawn.
   */
  function trend(host, rows, series, opts) {
    opts = opts || {};
    if (!host) return;

    if (!rows || !rows.length) {
      empty(host, opts.emptyText || 'No activity recorded yet', opts.emptyHint);
      return;
    }

    /* A single point makes the x scale divide by (length - 1) = 0 and
     * every coordinate becomes NaN, which renders as nothing at all.
     * dashboard.php always sends 14 days, so this is a guard against a
     * future caller rather than a live bug. */
    if (rows.length < 2) {
      empty(host, 'Not enough history to plot a trend yet',
            'The line appears once there are two days of activity.');
      return;
    }

    mount(host, function (w) { return drawTrend(w, rows, series, opts); }, wireTrend);
  }

  /* "2026-08-19" handed to new Date() is parsed as midnight UTC and
   * then rendered in the viewer's zone, so every chart label read one
   * day early for anyone west of UTC. These are calendar dates from
   * DATE(at)/reveal_date, not instants — built field by field they
   * stay the day the server meant. Kept local rather than reaching for
   * Datafort.parseTime so charts.js stays dependency-free, as its
   * header promises. */
  function localDate(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
    var d = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date(value);
    return isNaN(d.getTime()) ? null : d;
  }

  function dayLabel(d) {
    var parsed = localDate(d);
    return parsed
      ? parsed.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
      : '';
  }

  function drawTrend(w, rows, series, opts) {
    var compact = w < 400;

    var padL = compact ? 26 : 34;
    var padR = 14;
    var padT = 12;
    var padB = 28;

    var plotW = Math.max(40, w - padL - padR);
    var plotH = compact ? 130 : 158;
    var h = padT + plotH + padB;

    var max = 0;
    rows.forEach(function (r) {
      series.forEach(function (s) { max = Math.max(max, Number(r[s.key]) || 0); });
    });
    var scale = niceScale(max, compact ? 3 : 4);

    var colors = cat();
    var x = function (i) { return padL + (i / (rows.length - 1)) * plotW; };
    var y = function (v) { return padT + plotH - ((Number(v) || 0) / scale.top) * plotH; };

    var gid = nextId();
    var out = svgOpen(w, h, opts.aria);

    // Recessive gridlines with the value at the left.
    scale.values.forEach(function (v) {
      var gy = y(v);
      out += '<line x1="' + padL + '" y1="' + gy.toFixed(1) + '" x2="' + (padL + plotW) +
             '" y2="' + gy.toFixed(1) + '" stroke="' + (v === 0 ? AXIS : GRID) + '" stroke-width="1"/>';
      out += '<text x="' + (padL - 8) + '" y="' + (gy + 3.5).toFixed(1) + '" text-anchor="end" ' +
             'font-size="10.5" fill="' + FAINT + '" ' +
             'style="font-variant-numeric:tabular-nums">' + num(v) + '</text>';
    });

    /* Date ticks are thinned to what fits rather than fixed at three:
     * a phone gets the two ends, a wide card gets five readable dates.
     * The ends are anchored inward so neither clips the plot. */
    var want = Math.max(2, Math.min(5, Math.floor(plotW / (compact ? 74 : 92))));
    var seen = {};
    for (var t = 0; t < want; t++) {
      var i = Math.round(t * (rows.length - 1) / (want - 1));
      if (seen[i]) continue;
      seen[i] = 1;
      var anchor = i === 0 ? 'start' : i === rows.length - 1 ? 'end' : 'middle';
      out += '<text x="' + x(i).toFixed(1) + '" y="' + (h - 8) + '" text-anchor="' + anchor + '" ' +
             'font-size="10.5" fill="' + FAINT + '">' + esc(dayLabel(rows[i].date)) + '</text>';
    }

    /* A soft wash under the leading series gives the figure depth
     * without adding a second encoding — it carries no value of its
     * own, so only the first series gets one and it stays under 20%.
     * Two overlapping washes would read as a third quantity. */
    var head = series[0];
    if (head) {
      var area = rows.map(function (r, i) {
        return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(r[head.key]).toFixed(1);
      }).join(' ') +
        ' L' + x(rows.length - 1).toFixed(1) + ' ' + (padT + plotH) +
        ' L' + padL + ' ' + (padT + plotH) + ' Z';

      out += '<defs><linearGradient id="' + gid + 'a" x1="0" y1="0" x2="0" y2="1">' +
             '<stop offset="0%" stop-color="' + colors[0] + '" stop-opacity=".18"/>' +
             '<stop offset="100%" stop-color="' + colors[0] + '" stop-opacity="0"/>' +
             '</linearGradient></defs>' +
             '<path d="' + area + '" fill="url(#' + gid + 'a)"/>';
    }

    series.forEach(function (s, si) {
      var color = colors[si % colors.length];
      var d = rows.map(function (r, i) {
        return (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(r[s.key]).toFixed(1);
      }).join(' ');

      out += '<path d="' + d + '" fill="none" stroke="' + color +
             '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

      // Every day gets a point on a 14-day series. A longer range would
      // turn them into a dotted band, so they drop out above 20.
      if (rows.length <= 20 && !compact) {
        rows.forEach(function (r, i) {
          out += '<circle cx="' + x(i).toFixed(1) + '" cy="' + y(r[s.key]).toFixed(1) +
                 '" r="2.4" fill="' + color + '"/>';
        });
      }

      // The most recent day is the one being asked about, so it keeps a
      // ringed marker whatever else is drawn.
      var last = rows[rows.length - 1];
      out += '<circle cx="' + x(rows.length - 1).toFixed(1) + '" cy="' + y(last[s.key]).toFixed(1) +
             '" r="4" fill="' + color + '" stroke="' + SURFACE + '" stroke-width="2"/>';
    });

    /* Crosshair, parked hidden until a band is hovered. wireTrend moves
     * it by attribute rather than redrawing, so hovering never touches
     * the DOM beyond a handful of coordinates. */
    out += '<g data-role="crosshair" style="visibility:hidden;pointer-events:none">' +
           '<line data-role="cross-line" x1="0" y1="' + padT + '" x2="0" y2="' + (padT + plotH) +
           '" stroke="' + MUTED + '" stroke-width="1" stroke-dasharray="3 3" opacity=".55"/>';
    series.forEach(function (s, si) {
      out += '<circle data-role="cross-dot" cx="0" cy="0" r="4.5" fill="' +
             colors[si % colors.length] + '" stroke="' + SURFACE + '" stroke-width="2"/>';
    });
    out += '</g>';

    /* One hit band per day, spanning the full plot height. Asking
     * someone to hover a 2px line is not a hover target. */
    var bandW = plotW / (rows.length - 1);
    rows.forEach(function (r, i) {
      var tipRows = series.map(function (s, si) {
        return { label: s.label, value: num(r[s.key]), color: colors[si % colors.length] };
      });

      var pts = series.map(function (s) { return y(r[s.key]).toFixed(1); }).join(',');
      var titleDate = localDate(r.date);
      var title = titleDate
        ? titleDate.toLocaleDateString(undefined,
            { weekday: 'short', day: 'numeric', month: 'short' })
        : String(r.date || '');

      out += '<rect data-band="1" data-cx="' + x(i).toFixed(1) + '" data-pts="' + pts + '" ' +
             'x="' + Math.max(0, x(i) - bandW / 2).toFixed(1) + '" y="' + padT + '" ' +
             'width="' + bandW.toFixed(1) + '" height="' + plotH + '" fill="transparent" ' +
             'data-tip="' + tipAttr(tipHTML(title, tipRows, opts.footer ? opts.footer(r) : null)) + '"/>';
    });

    return out + '</svg>';
  }

  /* Listeners go on the <svg>, which is replaced wholesale on every
   * repaint — binding to the host instead would stack a fresh pair of
   * handlers on top of the old ones at every resize. */
  function wireTrend(host) {
    var svg = host.querySelector('svg');
    if (!svg) return;

    var cross = svg.querySelector('[data-role="crosshair"]');
    var line  = svg.querySelector('[data-role="cross-line"]');
    var dots  = svg.querySelectorAll('[data-role="cross-dot"]');
    if (!cross || !line) return;

    svg.addEventListener('mouseover', function (e) {
      var band = e.target.closest && e.target.closest('[data-band]');
      if (!band) return;

      var cx  = band.getAttribute('data-cx');
      var pts = (band.getAttribute('data-pts') || '').split(',');

      line.setAttribute('x1', cx);
      line.setAttribute('x2', cx);
      for (var i = 0; i < dots.length; i++) {
        dots[i].setAttribute('cx', cx);
        dots[i].setAttribute('cy', pts[i] || 0);
      }
      cross.style.visibility = 'visible';
    });

    svg.addEventListener('mouseleave', function () {
      cross.style.visibility = 'hidden';
    });
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
      out += '<th scope="col"' + (c.num ? ' class="num"' : '') + '>' + esc(c.label) + '</th>';
    });
    out += '</tr></thead><tbody>';

    (rows || []).forEach(function (r) {
      out += '<tr>';
      cols.forEach(function (c) {
        var v = r[c.key];
        out += '<td' + (c.num ? ' class="num"' : '') + '>' +
               esc(c.num && typeof v === 'number' ? num(v) : v) + '</td>';
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
      return '<span style="color:' + (it.color || colors[i % colors.length]) + '">' +
             '<i></i><span style="color:var(--text-muted)">' + esc(it.label) + '</span></span>';
    }).join('');
  }


  window.DatafortCharts = {
    hbars: hbars,
    trend: trend,
    table: table,
    legend: legend,
    loading: loading,
    empty: empty,
    statusColors: status,
    categorical: cat
  };
})();
