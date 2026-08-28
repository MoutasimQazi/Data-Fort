/* tenant.js — one enterprise's registry row, editable, plus its
 * provisioning checklist and its slice of the platform audit log. */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var id = new URLSearchParams(location.search).get('id');
  if (!id) { location.replace('index.html'); return; }

  var STATUS_BADGE = {
    active:       '<span class="badge badge--won">Active</span>',
    pending:      '<span class="badge badge--idle">Pending</span>',
    provisioning: '<span class="badge badge--working">Provisioning</span>',
    suspended:    '<span class="badge badge--lost">Suspended</span>',
    deprovisioned:'<span class="badge badge--idle">Deprovisioned</span>'
  };

  var current = null;
  var plans = [];

  function loadPlans() {
    return API.plans().then(function (res) {
      plans = res.plans;
      var sel = document.getElementById('fPlanId');
      sel.innerHTML = '<option value="">— no catalog plan (use custom label) —</option>' +
        plans.map(function (p) {
          var reps = p.maxReps === null ? 'unlimited reps' : ('up to ' + p.maxReps + ' reps');
          return '<option value="' + p.id + '">' + D.escape(p.name) + ' — ' + D.escape(p.priceLabel) + ' · ' + reps +
            (p.isActive ? '' : ' (retired)') + '</option>';
        }).join('');
    }).catch(D.fail);
  }

  function paintChecklist(p) {
    var steps = [
      ['Database created', p.dbCreated],
      ['First admin seeded', p.adminSeeded],
      ['CA folder scaffolded', p.caScaffolded],
      ['Apache vhost confirmed live', p.vhostLive]
    ];
    document.getElementById('provisioningBody').innerHTML = steps.map(function (s) {
      var done = !!s[1];
      return '<div style="display:flex;align-items:center;gap:10px">' +
        '<span class="badge ' + (done ? 'badge--won' : 'badge--idle') + '" style="min-width:70px;text-align:center">' +
          (done ? 'Done' : 'Pending') + '</span>' +
        '<span>' + D.escape(s[0]) + '</span>' +
        (done ? '<span style="color:var(--text-faint);font-size:12px">' + D.escape(D.ago(s[1])) + '</span>' : '') +
      '</div>';
    }).join('');
  }

  function load() {
    return API.tenantDetail(id).then(function (t) {
      current = t;
      var planLabel = t.planName || t.plan;
      document.getElementById('tName').textContent = t.name;
      document.getElementById('tSlug').textContent = t.slug + (planLabel ? ' · ' + planLabel : '');
      document.getElementById('tStatusBadge').innerHTML = STATUS_BADGE[t.status] || t.status;

      document.getElementById('fName').value = t.name || '';
      document.getElementById('fPlanId').value = t.planId || '';
      document.getElementById('fPlan').value = t.plan || '';
      document.getElementById('fContactName').value = t.contactName || '';
      document.getElementById('fContactEmail').value = t.contactEmail || '';

      paintChecklist(t.provisioning || {});

      var dbNote = document.getElementById('dbSavedNote');
      if (t.database.name) {
        dbNote.hidden = false;
        document.getElementById('dbSavedLine').textContent = t.database.user + '@' + t.database.host + '/' + t.database.name;
        document.getElementById('dbHost').value = t.database.host || 'localhost';
        document.getElementById('dbName').value = t.database.name || '';
        document.getElementById('dbUser').value = t.database.user || '';
      } else {
        dbNote.hidden = true;
      }

      var hasDb = !!t.database.name;
      var hasSchema = !!t.provisioning.dbCreated;
      document.getElementById('schemaGo').disabled = !hasDb;
      document.getElementById('adminGo').disabled = !hasSchema;

      var suspendGo = document.getElementById('suspendGo');
      var reactivateGo = document.getElementById('reactivateGo');
      var canChangeStatus = t.status === 'active' || t.status === 'suspended';
      suspendGo.disabled = !canChangeStatus || t.status === 'suspended';
      reactivateGo.disabled = !canChangeStatus || t.status === 'active';
    }).catch(D.fail);
  }

  function loadAudit() {
    API.audit({ tenant: id, limit: 50 }).then(function (res) {
      var rows = document.getElementById('auditRows');
      if (!res.entries.length) {
        rows.innerHTML = '<tr><td colspan="4"><div class="empty" style="padding:24px"><p style="margin:0">No activity yet.</p></div></td></tr>';
        return;
      }
      rows.innerHTML = res.entries.map(function (a) {
        return '<tr>' +
          '<td>' + D.escape(D.ago(a.at)) + '</td>' +
          '<td>' + D.escape(a.actor || 'System') + '</td>' +
          '<td><span class="badge badge--plain badge--idle">' + D.escape(a.action) + '</span></td>' +
          '<td>' + D.escape(a.text || '') + '</td>' +
        '</tr>';
      }).join('');
    }).catch(D.fail);
  }

  document.getElementById('saveGo').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    API.saveTenant({
      action: 'update', id: id,
      name: document.getElementById('fName').value.trim(),
      planId: document.getElementById('fPlanId').value,
      plan: document.getElementById('fPlan').value.trim(),
      contactName: document.getElementById('fContactName').value.trim(),
      contactEmail: document.getElementById('fContactEmail').value.trim()
    }).then(function () {
      D.toast('Saved.', 'ok');
      load();
    }).catch(D.fail).then(function () { btn.disabled = false; });
  });

  document.getElementById('suspendGo').addEventListener('click', function () {
    if (!confirm('Suspend ' + (current ? current.name : 'this tenant') + '? Their subdomain will stop resolving. Their database is untouched.')) return;
    API.saveTenant({ action: 'suspend', id: id }).then(function () {
      D.toast('Suspended.', 'ok');
      load(); loadAudit();
    }).catch(D.fail);
  });

  document.getElementById('reactivateGo').addEventListener('click', function () {
    API.saveTenant({ action: 'reactivate', id: id }).then(function () {
      D.toast('Reactivated.', 'ok');
      load(); loadAudit();
    }).catch(D.fail);
  });


  /* ══ Provisioning steps ══════════════════════════════════════════ */

  document.getElementById('dbSaveGo').addEventListener('click', function () {
    var host = document.getElementById('dbHost').value.trim();
    var port = document.getElementById('dbPort').value.trim();
    var name = document.getElementById('dbName').value.trim();
    var user = document.getElementById('dbUser').value.trim();
    var pass = document.getElementById('dbPass').value;

    if (!host || !name || !user) { D.toast('Host, database name and user are required.', 'error'); return; }

    var btn = this;
    btn.disabled = true;
    btn.textContent = 'Testing connection…';
    API.saveTenant({ action: 'set_database', id: id, dbHost: host, dbPort: port, dbName: name, dbUser: user, dbPass: pass })
      .then(function () {
        document.getElementById('dbPass').value = '';
        D.toast('Connected and saved.', 'ok');
        load();
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; btn.textContent = 'Save & test connection'; });
  });

  document.getElementById('schemaGo').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    var statusEl = document.getElementById('schemaStatus');
    statusEl.textContent = 'Applying schema…';
    API.saveTenant({ action: 'provision_schema', id: id })
      .then(function () {
        statusEl.textContent = '';
        D.toast('Schema applied.', 'ok');
        load();
      })
      .catch(function (err) { statusEl.textContent = ''; D.fail(err); })
      .then(function () { btn.disabled = false; });
  });

  document.getElementById('adminGo').addEventListener('click', function () {
    if (!current || !current.contactEmail) {
      D.toast('Set a contact email in Registry details first, and save it.', 'error');
      return;
    }
    var btn = this;
    btn.disabled = true;
    API.saveTenant({ action: 'seed_admin', id: id })
      .then(function (res) {
        D.toast('Admin ready. Send this link — send it yourself, it is shown once here:', 'ok', 9000);
        document.getElementById('adminStatus').innerHTML =
          '<span style="font-family:ui-monospace,monospace;font-size:12px;user-select:all">' + D.escape(res.inviteLink) + '</span>';
        load();
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; });
  });

  document.getElementById('caGo').addEventListener('click', function () {
    if (!confirm('Confirm you have generated the CA offline and placed company-ca.crt on the server for this tenant?')) return;
    API.saveTenant({ action: 'mark_ca_ready', id: id }).then(function () {
      D.toast('Marked.', 'ok');
      load();
    }).catch(D.fail);
  });

  document.getElementById('vhostGo').addEventListener('click', function () {
    if (!confirm('Confirm the Apache vhost for this tenant\'s subdomain is created and reloaded?')) return;
    API.saveTenant({ action: 'mark_vhost_live', id: id }).then(function () {
      D.toast('Marked.', 'ok');
      load();
    }).catch(D.fail);
  });

  D.ready(function () { loadPlans().then(load); loadAudit(); });
})();
