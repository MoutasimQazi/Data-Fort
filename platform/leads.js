/* leads.js — inbound sales inquiries from pricing.html's contact form. */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var rowsEl = document.getElementById('rows');
  var emptyEl = document.getElementById('emptyState');
  var statusEl = document.getElementById('statusFilter');

  var STATUS_BADGE = {
    new:       '<span class="badge badge--working">New</span>',
    contacted: '<span class="badge badge--idle">Contacted</span>',
    closed:    '<span class="badge badge--won">Closed</span>'
  };

  function render(leads) {
    if (!leads.length) {
      rowsEl.innerHTML = '';
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    rowsEl.innerHTML = leads.map(function (l) {
      var actions = '';
      if (l.status !== 'contacted') {
        actions += '<button class="btn btn--ghost btn--sm" data-act="contacted" data-id="' + l.id + '">Contacted</button> ';
      }
      if (l.status !== 'closed') {
        actions += '<button class="btn btn--ghost btn--sm" data-act="closed" data-id="' + l.id + '">Closed</button> ';
      }
      actions += '<button class="btn btn--ghost btn--sm" data-act="delete" data-id="' + l.id + '" style="color:var(--red)">Delete</button>';

      return '<tr>' +
        '<td>' + D.escape(D.ago(l.createdAt)) + '</td>' +
        '<td>' + D.escape(l.name) + '</td>' +
        '<td><a href="mailto:' + encodeURIComponent(l.email) + '">' + D.escape(l.email) + '</a></td>' +
        '<td>' + (l.company ? D.escape(l.company) : '<span style="color:var(--text-faint)">—</span>') + '</td>' +
        '<td>' + (l.planInterest ? D.escape(l.planInterest) : '<span style="color:var(--text-faint)">—</span>') + '</td>' +
        '<td style="max-width:260px;white-space:normal">' + (l.message ? D.escape(l.message) : '<span style="color:var(--text-faint)">—</span>') + '</td>' +
        '<td>' + (STATUS_BADGE[l.status] || l.status) + '</td>' +
        '<td class="shrink">' + actions + '</td>' +
      '</tr>';
    }).join('');
  }

  function load() {
    API.leads({ status: statusEl.value }).then(function (res) {
      render(res.leads);
      if (!statusEl.value) {
        var newCount = res.leads.filter(function (l) { return l.status === 'new'; }).length;
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

    if (act === 'delete' && !confirm('Delete this lead permanently?')) return;

    API.saveLead({ action: act, id: id }).then(function () {
      D.toast('Updated.', 'ok');
      load();
    }).catch(D.fail);
  });

  statusEl.addEventListener('change', load);

  D.ready(load);
})();
