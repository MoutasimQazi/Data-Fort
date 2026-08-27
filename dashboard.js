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

  /* Hosts are looked up once. charts() runs again on every theme flip,
   * and getElementById in a loop is the kind of thing that is free
   * until the day someone adds a fourth figure. */
  var HOSTS = {
    status: 'chartStatus',
    trend:  'chartTrend',
    reps:   'chartReps'
  };

  function host(name) { return document.getElementById(HOSTS[name]); }

  /* Skeletons go up before the request leaves, so the cards have their
   * shape from the first paint instead of collapsing and then jumping
   * when the payload lands. */
  function chartsLoading() {
    C.loading(host('status'), 4);
    C.loading(host('trend'), 3);
    C.loading(host('reps'), 5);
  }

  function pct(part, whole) {
    return whole > 0 ? (Math.round((part / whole) * 1000) / 10) + '%' : '—';
  }

  function charts() {
    if (!data) return;

    /* ── Leads by status ──
     * The reserved status palette, keyed on the status itself. These
     * four colours never appear as a categorical series anywhere else.
     * Order is the lifecycle — new, working, won, lost — not the count,
     * because a funnel that reorders itself daily cannot be read. */
    var s = data.byStatus;
    var totalLeads = (s.new || 0) + (s.working || 0) + (s.won || 0) + (s.lost || 0);

    var statusRows = [
      { key: 'new',     label: 'New',     value: s.new },
      { key: 'working', label: 'Working', value: s.working },
      { key: 'won',     label: 'Won',     value: s.won },
      { key: 'lost',    label: 'Lost',    value: s.lost }
    ].map(function (r) {
      return {
        key: r.key, label: r.label, value: r.value,
        note: { label: 'Share of pipeline', value: pct(r.value, totalLeads) }
      };
    });

    C.hbars(host('status'), statusRows, {
      colorBy: 'status',
      axisLabel: 'Leads',
      unit: 'Leads',
      aria: 'Leads by status: ' + statusRows.map(function (r) {
        return r.label + ' ' + r.value;
      }).join(', '),
      emptyText: 'No leads yet',
      emptyHint: 'Import a list to see the pipeline break down by status.'
    });

    C.table(document.getElementById('tableStatus'),
      [{ key: 'label', label: 'Status' },
       { key: 'value', label: 'Leads', num: true },
       { key: 'share', label: 'Share' }],
      statusRows.map(function (r) {
        return { label: r.label, value: r.value, share: r.note.value };
      }));

    /* ── Reveals vs contacts ──
     * Two series, one shared scale. Never a second y-axis. The tooltip
     * carries the contact rate because it is the number the gap between
     * the lines is really asking about, and it is the one quantity that
     * is not already drawn. */
    var series = [{ key: 'reveals', label: 'Reveals' }, { key: 'contacted', label: 'Contacted' }];

    C.legend(document.getElementById('legendTrend'), series);
    C.trend(host('trend'), data.trend, series, {
      aria: 'Reveals and contacts per day over the last 14 days',
      footer: function (r) {
        return { label: 'Contact rate', value: pct(r.contacted, r.reveals) };
      },
      emptyText: 'No activity recorded yet',
      emptyHint: 'Reveals and contacts appear here as reps work the list.'
    });

    C.table(document.getElementById('tableTrend'),
      [{ key: 'date', label: 'Date' },
       { key: 'reveals', label: 'Reveals', num: true },
       { key: 'contacted', label: 'Contacted', num: true },
       { key: 'rate', label: 'Contact rate' }],
      (data.trend || []).map(function (r) {
        return { date: r.date, reveals: r.reveals, contacted: r.contacted,
                 rate: pct(r.contacted, r.reveals) };
      }));

    /* ── Reveals today by rep ──
     * One series, so one colour and no legend. Ranked: the question is
     * who is working the list hardest today, and a fixed alphabetical
     * order makes that a reading exercise. hbars() sorts and returns
     * the rows so the table underneath matches the chart exactly. */
    var repRows = (data.reps || []).map(function (r) {
      var spent = r.quota > 0 && r.usedToday >= r.quota;
      return {
        label: r.name,
        value: r.usedToday,
        quota: r.quota > 0 ? r.quota : 'uncapped',
        note: {
          label: 'Daily quota',
          value: r.quota > 0
            ? r.usedToday + ' of ' + r.quota + (spent ? ' · spent' : '')
            : 'uncapped'
        }
      };
    });

    var ranked = C.hbars(host('reps'), repRows, {
      rank: true,
      axisLabel: 'Reveals',
      unit: 'Reveals today',
      aria: 'Reveals today by rep, highest first',
      emptyText: 'No reps yet',
      emptyHint: 'Reveals appear here once a rep is added and starts working.'
    });

    C.table(document.getElementById('tableReps'),
      [{ key: 'label', label: 'Rep' },
       { key: 'value', label: 'Reveals today', num: true },
       { key: 'quota', label: 'Daily quota' }],
      ranked.map(function (r) {
        return { label: r.label, value: r.value, quota: r.raw.quota };
      }));
  }


  /* ══ Load ══════════════════════════════════════════════════════ */

  function load() {
    chartsLoading();

    API.dashboard().then(function (res) {
      data = res;
      document.getElementById('tenantLine').textContent =
        res.tenant + ' · ' + res.totals.leads.toLocaleString() + ' leads under management';
      tiles();
      feed();
      charts();
    }).catch(function (err) {
      /* D.fail raises the toast. The skeletons would otherwise shimmer
       * forever, which reads as "still loading" rather than "failed". */
      C.empty(host('status'), 'Could not load', 'Reload the page to try again.');
      C.empty(host('trend'),  'Could not load', 'Reload the page to try again.');
      C.empty(host('reps'),   'Could not load', 'Reload the page to try again.');
      D.fail(err);
    });
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
