/* devices.js — laptops allowed into the platform panel itself. */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var rowsEl = document.getElementById('rows');
  var emptyEl = document.getElementById('emptyState');
  var denialRowsEl = document.getElementById('denialRows');

  var devices = [];
  var revokeTarget = null;

  var STATE_BADGE = {
    pending:  '<span class="badge badge--idle">Pending</span>',
    active:   '<span class="badge badge--won">Active</span>',
    disabled: '<span class="badge badge--working">Disabled</span>',
    revoked:  '<span class="badge badge--lost">Revoked</span>'
  };

  function render() {
    if (!devices.length) {
      rowsEl.innerHTML = '';
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    rowsEl.innerHTML = devices.map(function (d) {
      var actions = '';
      if (d.status === 'pending' || d.status === 'disabled') {
        actions += '<button class="btn btn--ghost btn--sm" data-act="activate" data-id="' + d.id + '">Activate</button> ';
      }
      if (d.status === 'active') {
        actions += '<button class="btn btn--ghost btn--sm" data-act="disable" data-id="' + d.id + '">Disable</button> ';
      }
      if (d.status !== 'revoked') {
        actions += '<button class="btn btn--ghost btn--sm" data-act="revoke" data-id="' + d.id + '" data-code="' + D.escape(d.code) + '" style="color:var(--red)">Revoke</button>';
      }

      return '<tr>' +
        '<td><code>' + D.escape(d.code) + '</code></td>' +
        '<td>' + D.escape(d.admin || '—') + '</td>' +
        '<td style="font-family:ui-monospace,monospace;font-size:12px">' + D.escape(d.serial) + '</td>' +
        '<td>' + (d.expiresAt ? D.escape(String(d.expiresAt).slice(0, 10)) : '—') + '</td>' +
        '<td>' + (STATE_BADGE[d.status] || d.status) + '</td>' +
        '<td>' + (d.lastSeenAt ? D.escape(D.ago(d.lastSeenAt)) : '<span style="color:var(--text-faint)">Never</span>') + '</td>' +
        '<td class="shrink">' + actions + '</td>' +
      '</tr>';
    }).join('');
  }

  function load() {
    API.devices().then(function (res) {
      devices = res.devices;
      render();

      document.getElementById('modeBadge').innerHTML =
        '<span class="badge badge--plain badge--idle">' + D.escape(res.mode) + '</span>';

      denialRowsEl.innerHTML = res.denials.length
        ? res.denials.map(function (den) {
            return '<tr>' +
              '<td>' + D.escape(den.device_code || '—') + '</td>' +
              '<td style="font-family:ui-monospace,monospace;font-size:12px">' + D.escape(den.certificate_serial || '—') + '</td>' +
              '<td>' + D.escape(den.reason) + '</td>' +
              '<td>' + D.escape(den.ip || '—') + '</td>' +
              '<td>' + D.escape(D.ago(den.at)) + '</td>' +
            '</tr>';
          }).join('')
        : '<tr><td colspan="5"><div class="empty" style="padding:20px"><p style="margin:0">No denials in the last 7 days.</p></div></td></tr>';
    }).catch(D.fail);
  }

  rowsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var act = btn.dataset.act;
    var id = btn.dataset.id;

    if (act === 'revoke') {
      revokeTarget = id;
      document.getElementById('revokeWho').textContent = 'Revoking ' + btn.dataset.code;
      D.openModal('revokeModal');
      return;
    }

    API.saveDevice({ action: act, id: id }).then(function () {
      D.toast('Updated.', 'ok');
      load();
    }).catch(D.fail);
  });

  document.getElementById('revokeGo').addEventListener('click', function () {
    if (!revokeTarget) return;
    var reason = document.getElementById('revokeReason').value;
    API.saveDevice({ action: 'revoke', id: revokeTarget, reason: reason }).then(function (res) {
      D.closeModal('revokeModal');
      D.toast(res.warning || 'Revoked.', 'ok', 8000);
      load();
    }).catch(D.fail);
  });

  document.getElementById('addGo').addEventListener('click', function () {
    var code = document.getElementById('devCode').value.trim();
    var serial = document.getElementById('devSerial').value.trim();
    var expiresAt = document.getElementById('devExpires').value;

    if (!code || !serial) { D.toast('Device code and serial are both required.', 'error'); return; }

    var btn = this;
    btn.disabled = true;
    API.saveDevice({ action: 'create', code: code, serial: serial, expiresAt: expiresAt || null })
      .then(function () {
        D.closeModal('addModal');
        document.getElementById('devCode').value = '';
        document.getElementById('devSerial').value = '';
        document.getElementById('devExpires').value = '';
        D.toast('Registered as pending. Activate it once ready.', 'ok');
        load();
      }).catch(D.fail).then(function () { btn.disabled = false; });
  });

  D.ready(load);
})();
