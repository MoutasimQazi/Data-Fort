/* tenants.js — the enterprises grid. Mirrors ../users.js's structure:
 * client-side filter over an in-memory list, a create modal, row
 * click navigates to the detail page. */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var rowsEl = document.getElementById('rows');
  var emptyEl = document.getElementById('emptyState');
  var qEl = document.getElementById('q');
  var statusEl = document.getElementById('statusFilter');

  var tenants = [];

  function statusBadge(t) {
    switch (t.status) {
      case 'active':        return '<span class="badge badge--won">Active</span>';
      case 'pending':        return '<span class="badge badge--idle">Pending</span>';
      case 'provisioning':   return '<span class="badge badge--working">Provisioning</span>';
      case 'suspended':      return '<span class="badge badge--lost">Suspended</span>';
      default:                return '<span class="badge badge--idle">' + D.escape(t.status) + '</span>';
    }
  }

  function provisioningCell(t) {
    var p = t.provisioning || {};
    var steps = [p.dbCreated, p.adminSeeded, p.caScaffolded, p.vhostLive];
    var done = steps.filter(Boolean).length;
    if (done === 4) return '<span style="color:var(--text-faint)">Complete</span>';
    return '<span class="badge badge--plain badge--idle">' + done + ' / 4 steps</span>';
  }

  function render() {
    var term = qEl.value.trim().toLowerCase();
    var status = statusEl.value;

    var list = tenants.filter(function (t) {
      if (status && t.status !== status) return false;
      if (!term) return true;
      return (t.name + ' ' + t.slug + ' ' + (t.contactEmail || '')).toLowerCase().indexOf(term) !== -1;
    });

    if (!list.length) {
      rowsEl.innerHTML = '';
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    rowsEl.innerHTML = list.map(function (t) {
      return '<tr data-id="' + t.id + '" class="rowlink" tabindex="0" role="link" aria-label="Open ' + D.escape(t.name) + '">' +
        '<td><div class="cellstack"><span>' + D.escape(t.name) + '</span></div></td>' +
        '<td><code>' + D.escape(t.slug) + '</code></td>' +
        '<td>' + statusBadge(t) + '</td>' +
        '<td>' + (t.plan ? D.escape(t.plan) : '<span style="color:var(--text-faint)">—</span>') + '</td>' +
        '<td>' + (t.contactEmail ? D.escape(t.contactEmail) : '<span style="color:var(--text-faint)">—</span>') + '</td>' +
        '<td>' + provisioningCell(t) + '</td>' +
        '<td>' + D.ago(t.createdAt) + '</td>' +
      '</tr>';
    }).join('');
  }

  function open(id) { location.href = 'tenant.html?id=' + encodeURIComponent(id); }

  rowsEl.addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-id]');
    if (tr) open(tr.dataset.id);
  });
  rowsEl.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    e.preventDefault();
    open(tr.dataset.id);
  });

  document.getElementById('addGo').addEventListener('click', function () {
    var name  = document.getElementById('newName').value.trim();
    var slug  = document.getElementById('newSlug').value.trim().toLowerCase();
    var plan  = document.getElementById('newPlan').value.trim();
    var cName = document.getElementById('newContactName').value.trim();
    var cEmail = document.getElementById('newContactEmail').value.trim();

    if (!name || !slug) { D.toast('Company name and subdomain are required.', 'error'); return; }

    var btn = this;
    btn.disabled = true;

    API.saveTenant({
      action: 'create', name: name, slug: slug, plan: plan,
      contactName: cName, contactEmail: cEmail
    }).then(function (res) {
      D.closeModal('addModal');
      ['newName', 'newSlug', 'newPlan', 'newContactName', 'newContactEmail'].forEach(function (id) {
        document.getElementById(id).value = '';
      });
      D.toast('Registered. Next: ' + res.nextStep, 'ok', 9000);
      load();
    }).catch(D.fail).then(function () { btn.disabled = false; });
  });

  function load() {
    API.tenants().then(function (res) {
      tenants = res.tenants;
      render();
    }).catch(D.fail);
  }

  qEl.addEventListener('input', render);
  statusEl.addEventListener('change', render);

  D.ready(load);
})();
