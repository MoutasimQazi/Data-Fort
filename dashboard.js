/* dashboard.js — the admin overview.
 *
 * Order on the page is deliberate: tiles, then anomalies, then charts.
 * An admin opens this because something might be wrong, not to admire a
 * funnel. The charts are context for the feed, not the other way round.
 */
(function () {
  'use strict';

  var M = window.MOCK;
  var D = window.Datafort;
  var C = window.DatafortCharts;

  document.getElementById('tenantLine').textContent =
    M.session.tenant + ' · ' + M.leads.length.toLocaleString() + ' leads under management';


  /* ══ Tiles ═════════════════════════════════════════════════════ */

  function tiles() {
    var reps = M.users.filter(function (u) { return u.role === 'rep'; });
    var assigned = M.leads.filter(function (l) { return l.ownerId; }).length;
    var revealsToday = reps.reduce(function (s, u) { return s + u.usedToday; }, 0);
    var quotaTotal   = reps.reduce(function (s, u) { return s + u.quota; }, 0);
    var dataCost = M.sourceRoi.reduce(function (s, r) { return s + r.cost; }, 0);
    var revenue  = M.sourceRoi.reduce(function (s, r) { return s + r.revenue; }, 0);

    var items = [
      {
        label: 'Leads under management',
        value: M.leads.length.toLocaleString(),
        note: (M.leads.length - assigned) + ' unassigned'
      },
      {
        label: 'Reveals today',
        value: revealsToday + ' / ' + quotaTotal,
        note: Math.round((revealsToday / quotaTotal) * 100) + '% of allocated quota'
      },
      {
        label: 'Active reps',
        value: String(reps.filter(function (u) { return u.status === 'active'; }).length),
        note: reps.filter(function (u) { return u.status !== 'active'; }).length +
              ' flagged or suspended'
      },
      {
        label: 'Data spend',
        value: D.money(dataCost),
        note: 'across ' + M.sourceRoi.length + ' sources'
      },
      {
        label: 'Return on data',
        value: (revenue / dataCost).toFixed(1) + '×',
        note: D.money(revenue) + ' attributed revenue'
      }
    ];

    document.getElementById('tiles').innerHTML = items.map(function (t) {
      return '<div class="tile">' +
        '<div class="tile__label">' + D.escape(t.label) + '</div>' +
        '<div class="tile__value">' + D.escape(t.value) + '</div>' +
        '<div class="tile__note">' + D.escape(t.note) + '</div>' +
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
    document.getElementById('feed').innerHTML = M.anomalies.map(function (a) {
      var cls = a.level === 'red' ? ' feed__dot--red'
              : a.level === 'amber' ? ' feed__dot--amber' : '';
      return '<div class="feed__item">' +
        '<div class="feed__dot' + cls + '">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
          'stroke-linecap="round" stroke-linejoin="round">' + ICONS[a.level] + '</svg>' +
        '</div>' +
        '<div class="feed__text">' +
          '<strong>' + D.escape(a.user) + '</strong> — ' + D.escape(a.text) +
          '<div class="feed__meta">' + D.ago(a.at) + '</div>' +
        '</div>' +
      '</div>';
    }).join('');

    document.getElementById('alertCount').textContent = M.anomalies.length;
  }


  /* ══ Charts ════════════════════════════════════════════════════ */

  function charts() {
    /* Leads by status — the reserved status palette, keyed on the status
     * itself. These four colours never appear as a categorical series. */
    var counts = M.byStatus();
    var statusRows = [
      { key: 'new',     label: 'New',     value: counts.new },
      { key: 'working', label: 'Working', value: counts.working },
      { key: 'won',     label: 'Won',     value: counts.won },
      { key: 'lost',    label: 'Lost',    value: counts.lost }
    ];
    C.hbars(document.getElementById('chartStatus'), statusRows, { colorBy: 'status' });
    C.table(document.getElementById('tableStatus'),
      [{ key: 'label', label: 'Status' }, { key: 'value', label: 'Leads', num: true }],
      statusRows);

    // Two series, one shared scale. Never a second y-axis.
    var series = [{ key: 'reveals', label: 'Reveals' }, { key: 'contacted', label: 'Contacted' }];
    C.legend(document.getElementById('legendTrend'), series);
    C.trend(document.getElementById('chartTrend'), M.trend, series);
    C.table(document.getElementById('tableTrend'),
      [{ key: 'date', label: 'Date' },
       { key: 'reveals', label: 'Reveals', num: true },
       { key: 'contacted', label: 'Contacted', num: true }],
      M.trend);

    // Per-rep reveals. One series, so one colour and no legend.
    var repRows = M.users
      .filter(function (u) { return u.role === 'rep'; })
      .sort(function (a, b) { return b.usedToday - a.usedToday; })
      .map(function (u) {
        return {
          label: u.name,
          value: u.usedToday,
          note: 'quota ' + u.quota + (u.usedToday >= u.quota ? ' · spent' : '')
        };
      });
    C.hbars(document.getElementById('chartReps'), repRows);
    C.table(document.getElementById('tableReps'),
      [{ key: 'label', label: 'Rep' }, { key: 'value', label: 'Reveals today', num: true },
       { key: 'note', label: 'Quota' }],
      repRows);

    /* Return by source. Cost and revenue are different scales, so they
     * are NOT plotted together — they are reduced to one derived measure
     * and shown on a single axis. */
    var roiRows = M.sourceRoi
      .filter(function (r) { return r.cost > 0; })
      .map(function (r) {
        return {
          label: r.source,
          value: Number((r.revenue / r.cost).toFixed(1)),
          note: D.money(r.cost) + ' spent · ' + r.won + ' won'
        };
      })
      .sort(function (a, b) { return b.value - a.value; });

    C.hbars(document.getElementById('chartRoi'), roiRows, {
      format: function (v) { return v.toFixed(1) + '×'; }
    });
    C.table(document.getElementById('tableRoi'),
      [{ key: 'label', label: 'Source' },
       { key: 'value', label: 'Return', num: true },
       { key: 'note', label: 'Detail' }],
      roiRows);
  }


  /* ══ Boot ══════════════════════════════════════════════════════ */

  function paint() {
    tiles();
    feed();
    charts();
  }

  paint();

  /* Charts read theme-dependent palettes at draw time, so they have to be
   * redrawn when the theme flips — CSS variables alone cannot restyle an
   * SVG fill that was written as a literal hex. */
  document.getElementById('themeBtn').addEventListener('click', function () {
    setTimeout(charts, 0);
  });

  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)')
      .addEventListener('change', function () { charts(); });
  }
})();
