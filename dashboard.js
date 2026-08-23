/* dashboard.js — the admin overview.
 *
 * Order on the page is deliberate: tiles, then anomalies, then charts.
 * An admin opens this because something might be wrong, not to admire a
 * funnel. The charts are context for the feed, not the other way round.
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;
  var C = window.DatafortCharts;

  var data = null;


  /* ══ Tiles ═════════════════════════════════════════════════════ */

  function tiles() {
    var t = data.totals;

    var quotaPct = t.quotaTotal > 0
      ? Math.round((t.revealsToday / t.quotaTotal) * 100) + '% of allocated quota'
      : 'no quota allocated';

    var items = [
      { label: 'Leads under management', value: t.leads.toLocaleString(),
        note: t.unassigned.toLocaleString() + ' unassigned' },
      { label: 'Reveals against quota', value: t.revealsToday + ' / ' + t.quotaTotal,
        note: quotaPct },
      { label: 'Active reps', value: String(t.activeReps),
        note: t.otherReps + ' flagged or suspended' },
      { label: 'Data spend', value: D.money(t.dataSpend),
        note: 'across ' + data.sources.length + ' source' + (data.sources.length === 1 ? '' : 's') }
    ];

    /* Uncapped reveals get their own tile rather than being folded into
     * the meter above — an administrator has no daily cap, so counting
     * them in the numerator against a denominator they never contribute
     * to would read as "52 of 40".
     *
     * Shown only when it is non-zero, because a tile that says 0 every
     * day trains people to stop reading it. */
    if (t.adminReveals > 0) {
      items.splice(2, 0, {
        label: 'Uncapped reveals',
        value: String(t.adminReveals),
        note: 'by administrators · no daily limit'
      });
    }

    document.getElementById('tiles').innerHTML = items.map(function (x) {
      return '<div class="tile">' +
        '<div class="tile__label">' + D.escape(x.label) + '</div>' +
        '<div class="tile__value">' + D.escape(x.value) + '</div>' +
        '<div class="tile__note">' + D.escape(x.note) + '</div>' +
      '</div>';
    }).join('');
  }


  /* ══ Anomaly feed ══════════════════════════════════════════════ */

  var ICONS = {
    red:   '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
    amber: '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>',
    grey:  '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'
  };

  function feed() {
    var host = document.getElementById('feed');
    var list = data.anomalies || [];

    /* The badge counts what is NEW since this admin last opened the
     * dashboard, not everything in the last 24 hours. A badge that is
     * permanently red is a badge people stop reading, and the whole
     * value of that number is being noticed on the day it matters. */
    var unread = data.unreadAlerts || 0;
    var badge = document.getElementById('alertCount');
    badge.textContent = unread;
    badge.hidden = unread === 0;

    if (!list.length) {
      host.innerHTML = '<div class="empty" style="padding:34px">' +
        '<h3>Nothing needs attention</h3>' +
        '<p>No unusual access patterns in the last 24 hours.</p></div>';
      return;
    }

    host.innerHTML = list.map(function (a) {
      var cls = a.level === 'red' ? ' feed__dot--red'
              : a.level === 'amber' ? ' feed__dot--amber' : '';
      return '<div class="feed__item">' +
        '<div class="feed__dot' + cls + '">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
          'stroke-linecap="round" stroke-linejoin="round">' +
          (ICONS[a.level] || ICONS.grey) + '</svg>' +
        '</div>' +
        '<div class="feed__text">' +
          '<strong>' + D.escape(a.user) + '</strong> — ' + D.escape(a.text) +
          (a.count > 1
            ? ' <span class="badge badge--plain badge--idle">' + a.count + '×</span>'
            : '') +
          '<div class="feed__meta">' + D.ago(a.at) +
            (a.count > 1 ? ' · most recent of ' + a.count : '') + '</div>' +
        '</div>' +
      '</div>';
    }).join('');
  }


  /* ══ Charts ════════════════════════════════════════════════════ */

  function charts() {
    if (!data) return;

    /* Leads by status — the reserved status palette, keyed on the
     * status itself. These four colours never appear as a categorical
     * series anywhere else. */
    var s = data.byStatus;
    var statusRows = [
      { key: 'new',     label: 'New',     value: s.new },
      { key: 'working', label: 'Working', value: s.working },
      { key: 'won',     label: 'Won',     value: s.won },
      { key: 'lost',    label: 'Lost',    value: s.lost }
    ];
    C.hbars(document.getElementById('chartStatus'), statusRows, { colorBy: 'status' });
    C.table(document.getElementById('tableStatus'),
      [{ key: 'label', label: 'Status' }, { key: 'value', label: 'Leads', num: true }],
      statusRows);

    // Two series, one shared scale. Never a second y-axis.
    var series = [{ key: 'reveals', label: 'Reveals' }, { key: 'contacted', label: 'Contacted' }];
    C.legend(document.getElementById('legendTrend'), series);
    C.trend(document.getElementById('chartTrend'), data.trend, series);
    C.table(document.getElementById('tableTrend'),
      [{ key: 'date', label: 'Date' },
       { key: 'reveals', label: 'Reveals', num: true },
       { key: 'contacted', label: 'Contacted', num: true }],
      data.trend);

    // Per-rep reveals. One series, so one colour and no legend.
    var repRows = data.reps.map(function (r) {
      return {
        label: r.name,
        value: r.usedToday,
        note: 'quota ' + r.quota + (r.quota > 0 && r.usedToday >= r.quota ? ' · spent' : '')
      };
    });
    C.hbars(document.getElementById('chartReps'), repRows);
    C.table(document.getElementById('tableReps'),
      [{ key: 'label', label: 'Rep' },
       { key: 'value', label: 'Reveals today', num: true },
       { key: 'note', label: 'Quota' }],
      repRows);

    /* Cost per won lead, not "ROI". There is no revenue column in the
     * schema, so a return multiple would be invented. This reports what
     * the data actually supports. LOWER is better here — the caption
     * says so, because a bar chart where the longest bar is the worst
     * result is otherwise read backwards. */
    var roiRows = data.sources
      .filter(function (r) { return r.costPerWon !== null; })
      .map(function (r) {
        return {
          label: r.source,
          value: r.costPerWon,
          note: D.money(r.cost) + ' spent · ' + r.won + ' won of ' + r.leads
        };
      })
      .sort(function (a, b) { return a.value - b.value; });

    C.hbars(document.getElementById('chartRoi'), roiRows, {
      format: function (v) { return D.money(v); }
    });
    C.table(document.getElementById('tableRoi'),
      [{ key: 'label', label: 'Source' },
       { key: 'value', label: 'Cost per won lead', num: true },
       { key: 'note', label: 'Detail' }],
      roiRows);
  }


  /* ══ Load ══════════════════════════════════════════════════════ */

  function load() {
    API.dashboard().then(function (res) {
      data = res;
      document.getElementById('tenantLine').textContent =
        res.tenant + ' · ' + res.totals.leads.toLocaleString() + ' leads under management';
      tiles();
      feed();
      charts();
    }).catch(D.fail);
  }

  /* Charts read theme-dependent palettes at draw time, so they have to
   * be redrawn when the theme flips — CSS variables cannot restyle an
   * SVG fill that was written as a literal hex. */
  var themeBtn = document.getElementById('themeBtn');
  if (themeBtn) themeBtn.addEventListener('click', function () { setTimeout(charts, 0); });

  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)')
      .addEventListener('change', function () { charts(); });
  }

  D.ready(load);
})();
