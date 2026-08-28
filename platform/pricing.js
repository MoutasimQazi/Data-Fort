/* pricing.js — the real, editable plan catalog. Mirrors admins.js's
 * list/create/modal pattern. */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var rowsEl = document.getElementById('rows');
  var emptyEl = document.getElementById('emptyState');
  var editingId = null;

  var plans = [];

  function render() {
    if (!plans.length) {
      rowsEl.innerHTML = '';
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    rowsEl.innerHTML = plans.map(function (p) {
      var actions = '<button class="btn btn--ghost btn--sm" data-act="edit" data-id="' + p.id + '">Edit</button> ';
      if (p.isActive) {
        actions += '<button class="btn btn--ghost btn--sm" data-act="retire" data-id="' + p.id + '">Retire</button> ';
      } else {
        actions += '<button class="btn btn--ghost btn--sm" data-act="restore" data-id="' + p.id + '">Restore</button> ';
      }
      if (p.tenantCount === 0) {
        actions += '<button class="btn btn--ghost btn--sm" data-act="delete" data-id="' + p.id + '" style="color:var(--red)">Delete</button>';
      }

      return '<tr>' +
        '<td><strong>' + D.escape(p.name) + '</strong></td>' +
        '<td>' + D.escape(p.priceLabel) + '</td>' +
        '<td class="num">' + (p.maxReps === null ? '<span style="color:var(--text-faint)">Unlimited</span>' : p.maxReps) + '</td>' +
        '<td>' + (p.stripePriceId
          ? '<span class="badge badge--won">Self-serve</span>'
          : '<span class="badge badge--idle">Talk to us</span>') + '</td>' +
        '<td class="num">' + p.tenantCount + '</td>' +
        '<td>' + (p.isActive ? '<span class="badge badge--won">Active</span>' : '<span class="badge badge--idle">Retired</span>') + '</td>' +
        '<td class="shrink">' + actions + '</td>' +
      '</tr>';
    }).join('');
  }

  function load() {
    API.plans().then(function (res) {
      plans = res.plans;
      render();
    }).catch(D.fail);
  }

  function openForCreate() {
    editingId = null;
    document.getElementById('planTitle').textContent = 'New plan';
    document.getElementById('pName').value = '';
    document.getElementById('pPrice').value = '';
    document.getElementById('pMaxReps').value = '';
    document.getElementById('pFeatures').value = '';
    document.getElementById('pSort').value = '0';
    document.getElementById('pStripePriceId').value = '';
    D.openModal('planModal');
  }

  function openForEdit(p) {
    editingId = p.id;
    document.getElementById('planTitle').textContent = 'Edit ' + p.name;
    document.getElementById('pName').value = p.name;
    document.getElementById('pPrice').value = p.priceLabel;
    document.getElementById('pMaxReps').value = p.maxReps === null ? '' : p.maxReps;
    document.getElementById('pFeatures').value = p.features.join('\n');
    document.getElementById('pSort').value = p.sortOrder;
    document.getElementById('pStripePriceId').value = p.stripePriceId || '';
    D.openModal('planModal');
  }

  document.getElementById('newPlanBtn').addEventListener('click', openForCreate);

  rowsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var act = btn.dataset.act;
    var id = btn.dataset.id;

    if (act === 'edit') {
      var p = plans.filter(function (x) { return String(x.id) === id; })[0];
      if (p) openForEdit(p);
      return;
    }
    if (act === 'delete' && !confirm('Delete this plan permanently? Only allowed while no tenant is on it.')) return;

    API.savePlan({ action: act, id: id }).then(function () {
      D.toast('Updated.', 'ok');
      load();
    }).catch(D.fail);
  });

  document.getElementById('planGo').addEventListener('click', function () {
    var name = document.getElementById('pName').value.trim();
    var priceLabel = document.getElementById('pPrice').value.trim();
    var maxReps = document.getElementById('pMaxReps').value.trim();
    var features = document.getElementById('pFeatures').value.trim();
    var sortOrder = document.getElementById('pSort').value.trim();
    var stripePriceId = document.getElementById('pStripePriceId').value.trim();

    if (!name || !priceLabel) { D.toast('Plan name and price label are required.', 'error'); return; }
    if (stripePriceId && stripePriceId.indexOf('price_') !== 0) {
      D.toast('The Stripe Price ID should start with price_ — copy it from the Product\'s pricing section, not the Payment Link.', 'error');
      return;
    }

    var payload = {
      action: editingId ? 'update' : 'create',
      name: name, priceLabel: priceLabel,
      maxReps: maxReps === '' ? null : maxReps,
      features: features, sortOrder: sortOrder || 0,
      stripePriceId: stripePriceId
    };
    if (editingId) payload.id = editingId;

    var btn = this;
    btn.disabled = true;
    API.savePlan(payload).then(function () {
      D.closeModal('planModal');
      D.toast('Saved.', 'ok');
      load();
    }).catch(D.fail).then(function () { btn.disabled = false; });
  });

  D.ready(load);
})();
