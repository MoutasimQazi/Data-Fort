/* orders.js — paid Stripe Checkout sessions, recorded by the webhook. */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var rowsEl = document.getElementById('rows');
  var emptyEl = document.getElementById('emptyState');
  var statusEl = document.getElementById('statusFilter');

  var STATUS_BADGE = {
    paid:        '<span class="badge badge--working">Awaiting provisioning</span>',
    provisioned: '<span class="badge badge--won">Provisioned</span>'
  };

  function money(amountTotal, currency) {
    if (amountTotal === null) return '<span style="color:var(--text-faint)">—</span>';
    return (currency ? currency.toUpperCase() + ' ' : '') + (amountTotal / 100).toFixed(2);
  }

  function render(orders) {
    if (!orders.length) {
      rowsEl.innerHTML = '';
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    rowsEl.innerHTML = orders.map(function (o) {
      var actions = '';
      if (o.status !== 'provisioned') {
        actions += '<button class="btn btn--ghost btn--sm" data-act="provisioned" data-id="' + o.id + '">Mark provisioned</button>';
      } else {
        actions += '<button class="btn btn--ghost btn--sm" data-act="paid" data-id="' + o.id + '">Reopen</button>';
      }

      return '<tr>' +
        '<td>' + D.escape(D.ago(o.createdAt)) + '</td>' +
        '<td>' + (o.planName ? D.escape(o.planName) : '<span style="color:var(--text-faint)">—</span>') + '</td>' +
        '<td>' + (o.customerEmail ? '<a href="mailto:' + encodeURIComponent(o.customerEmail) + '">' + D.escape(o.customerEmail) + '</a>' : '<span style="color:var(--text-faint)">—</span>') + '</td>' +
        '<td>' + money(o.amountTotal, o.currency) + '</td>' +
        '<td>' + (o.livemode ? '<span class="badge badge--won">Live</span>' : '<span class="badge badge--idle">Test</span>') + '</td>' +
        '<td>' + (STATUS_BADGE[o.status] || o.status) + '</td>' +
        '<td class="shrink">' + actions + '</td>' +
      '</tr>';
    }).join('');
  }

  function load() {
    API.orders({ status: statusEl.value }).then(function (res) {
      render(res.orders);
      if (!statusEl.value) {
        var newCount = res.orders.filter(function (o) { return o.status === 'paid'; }).length;
        var badge = document.getElementById('newCount');
        badge.textContent = newCount;
        badge.hidden = newCount === 0;
      }
    }).catch(D.fail);
  }

  rowsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var act = btn.dataset.act;
    var id = btn.dataset.id;

    API.saveOrder({ action: act, id: id }).then(function () {
      D.toast('Updated.', 'ok');
      load();
    }).catch(D.fail);
  });

  statusEl.addEventListener('change', load);

  D.ready(load);
})();
