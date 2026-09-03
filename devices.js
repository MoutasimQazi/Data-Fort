/* devices.js — the company laptop register.
 *
 * The human end of the mTLS layer. Apache does the cryptography; what
 * an administrator does here is decide which certificate serials are
 * allowed, who holds them, and which ones to kill.
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var rowsEl    = document.getElementById('rows');
  var emptyEl   = document.getElementById('emptyState');
  var qEl       = document.getElementById('q');
  var statusEl  = document.getElementById('statusFilter');
  var modeSel   = document.getElementById('modeSelect');
  var modeHelp  = document.getElementById('modeHelp');
  var modeBadge = document.getElementById('modeBadge');

  var devices = [];
  var denials = [];
  var users = [];
  var thisSerial = null;
  var revoking = null;


  /* ══ Enforcement mode ══════════════════════════════════════════ */

  var MODE_HELP = {
    off:
      'No device check at all. Anyone with a valid password can sign in from any ' +
      'machine. Use this only before the certificate authority exists.',
    log:
      'Certificates are checked and every result is recorded, but nothing is ever ' +
      'blocked. This is the mode to run during enrolment — it tells you which ' +
      'laptops WOULD be locked out, before they actually are. Stay here until ' +
      'Recent denials is empty for a full working week.',
    enforce:
      'Unknown, pending, disabled, revoked and expired devices are refused. ' +
      'Before switching to this, confirm Apache has SSLVerifyClient set — without ' +
      'it every connection looks certificate-less and nobody gets in.'
  };

  function paintMode() {
    var mode = modeSel.value;
    modeHelp.textContent = MODE_HELP[mode] || '';

    var cls = mode === 'enforce' ? 'badge--won' : mode === 'log' ? 'badge--working' : 'badge--lost';
    var label = mode === 'enforce' ? 'Enforcing' : mode === 'log' ? 'Logging only' : 'Disabled';
    modeBadge.innerHTML = '<span class="badge ' + cls + '">' + label + '</span>';
  }

  var lastMode = null;

  modeSel.addEventListener('change', function () {
    var mode = modeSel.value;
    paintMode();

    if (mode === 'enforce' && !confirm(
      'Switch device enforcement to ENFORCE?\n\n' +
      'Any laptop without a registered, active certificate will be refused — ' +
      'including yours if it is not in the register.\n\n' +
      'Confirm Apache has SSLVerifyClient configured first.')) {
      modeSel.value = lastMode;
      paintMode();
      return;
    }

    API.saveSettings({ deviceEnforcement: mode })
      .then(function (res) {
        lastMode = mode;
        D.toast('Enforcement mode set to ' + mode + '.', 'ok');
        if (res.warning) D.toast(res.warning, 'error', 9000);
      })
      .catch(function (err) {
        modeSel.value = lastMode;
        paintMode();
        D.fail(err);
      });
  });


  /* ══ Tiles ═════════════════════════════════════════════════════ */

  function paintTiles() {
    var by = { pending: 0, active: 0, disabled: 0, revoked: 0 };
    devices.forEach(function (d) { if (by[d.status] !== undefined) by[d.status]++; });

    var expiringSoon = devices.filter(function (d) {
      if (d.status !== 'active' || !d.expiresAt) return false;
      var exp = D.parseTime(d.expiresAt);
      if (!exp) return false;
      var days = (exp - Date.now()) / 86400000;
      return days < 60 && days > 0;
    }).length;

    var items = [
      { label: 'Registered laptops', value: devices.length, note: by.active + ' active' },
      { label: 'Awaiting activation', value: by.pending, note: 'cannot sign in yet' },
      { label: 'Revoked', value: by.revoked, note: 'permanently blocked' },
      { label: 'Expiring within 60 days', value: expiringSoon, note: 'reissue before they lapse' }
    ];

    document.getElementById('tiles').innerHTML = items.map(function (t) {
      return '<div class="tile"><div class="tile__label">' + t.label + '</div>' +
        '<div class="tile__value">' + t.value + '</div>' +
        '<div class="tile__note">' + t.note + '</div></div>';
    }).join('');
  }


  /* ══ Table ═════════════════════════════════════════════════════ */

  function stateBadge(status) {
    var map = {
      active:   ['badge--won', 'Active'],
      pending:  ['badge--new', 'Pending'],
      disabled: ['badge--working', 'Disabled'],
      revoked:  ['badge--lost', 'Revoked']
    }[status] || ['badge--idle', status];
    return '<span class="badge ' + map[0] + '">' + D.escape(map[1]) + '</span>';
  }

  function expiryCell(d) {
    if (!d.expiresAt) return '<span style="color:var(--text-faint)">Not recorded</span>';

    var date = D.parseTime(d.expiresAt);
    if (!date) return '<span style="color:var(--text-faint)">Not recorded</span>';
    var days = Math.round((date - Date.now()) / 86400000);
    var when = D.day(date);

    // Flagged early: an expired certificate locks the employee out at
    // the TLS layer, where Datafort cannot show them a message at all.
    if (days < 0)  return '<span style="color:var(--red)">Expired ' + when + '</span>';
    if (days < 60) return '<span style="color:var(--amber)">' + when + ' · ' + days + 'd</span>';
    return when;
  }

  function actions(d) {
    if (d.status === 'revoked') {
      return '<span style="font-size:12px;color:var(--text-faint)">Permanent</span>';
    }
    var out = d.status !== 'active'
      ? '<button class="btn btn--ghost btn--sm" data-act="activate">Activate</button> '
      : '<button class="btn btn--ghost btn--sm" data-act="disable">Disable</button> ';
    return out + '<button class="btn btn--ghost btn--sm" data-act="revoke">Revoke</button>';
  }

  function render() {
    var term = qEl.value.trim().toLowerCase();
    var want = statusEl.value;

    var list = devices.filter(function (d) {
      if (want && d.status !== want) return false;
      if (!term) return true;
      return (d.code + ' ' + (d.employee || '') + ' ' + d.serial).toLowerCase().indexOf(term) !== -1;
    });

    emptyEl.hidden = list.length > 0;

    rowsEl.innerHTML = list.map(function (d) {
      var isThis = thisSerial && d.serial === thisSerial;

      return '<tr data-id="' + d.id + '">' +
        '<td><div class="cellstack"><span>' + D.escape(d.code) +
          (isThis ? ' <span class="badge badge--plain badge--new">this laptop</span>' : '') +
          '</span><span class="sub">' + D.escape(d.subject || '') + '</span></div></td>' +
        '<td>' + (d.employee ? D.escape(d.employee) :
          '<span style="color:var(--text-faint)">Unassigned</span>') + '</td>' +
        '<td style="font-family:ui-monospace,monospace;font-size:12.5px">' +
          D.escape(d.serial) + '</td>' +
        '<td>' + expiryCell(d) + '</td>' +
        '<td>' + stateBadge(d.status) + '</td>' +
        '<td>' + (d.lastSeenAt ? D.ago(d.lastSeenAt) :
          '<span style="color:var(--text-faint)">Never</span>') + '</td>' +
        '<td class="shrink" style="white-space:nowrap">' + actions(d) + '</td>' +
      '</tr>';
    }).join('');
  }


  /* ══ Denials ═══════════════════════════════════════════════════ */

  var REASON_TEXT = {
    no_certificate:      'No client certificate presented — not a company laptop, or enrolment failed',
    certificate_invalid: 'Certificate rejected by Apache — wrong CA or broken chain',
    certificate_expired: 'Certificate has expired',
    unknown_serial:      'Valid certificate, but the serial is not registered',
    cn_mismatch:         'Certificate identity does not match the registered device code',
    device_revoked:      'Device is revoked',
    device_disabled:     'Device is disabled',
    device_pending:      'Device registered but never activated',
    device_expired:      'Registered expiry date has passed',
    wrong_tenant:        'Certificate belongs to another organisation'
  };

  function paintDenials() {
    var host = document.getElementById('denials');

    if (!denials.length) {
      host.innerHTML = '<div class="empty" style="padding:32px">' +
        '<h3>No denials</h3><p>No device has been refused in the last 7 days.</p></div>';
      return;
    }

    host.innerHTML = denials.map(function (d) {
      return '<div class="feed__item">' +
        '<div class="feed__dot feed__dot--red">' +
          '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
          'stroke-linecap="round"><path d="M12 9v4M12 17h.01"/>' +
          '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>' +
          '</svg></div>' +
        '<div class="feed__text">' +
          D.escape(REASON_TEXT[d.reason] || d.reason) +
          '<div class="feed__meta">' +
            (d.device_code ? D.escape(d.device_code) + ' · ' : '') +
            (d.certificate_serial ? 'serial ' + D.escape(d.certificate_serial) + ' · ' : '') +
            D.escape(d.ip || '') + ' · ' + D.ago(d.at) +
          '</div>' +
        '</div>' +
      '</div>';
    }).join('');
  }


  /* ══ Actions ═══════════════════════════════════════════════════ */

  rowsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-act]');
    if (!btn) return;

    var id = parseInt(btn.closest('tr').dataset.id, 10);
    var device = devices.filter(function (d) { return d.id === id; })[0];
    if (!device) return;

    var act = btn.dataset.act;

    if (act === 'revoke') {
      revoking = device;
      document.getElementById('revokeWho').innerHTML =
        'Revoking <strong>' + D.escape(device.code) + '</strong>' +
        (device.employee ? ', assigned to ' + D.escape(device.employee) : '') + '.';
      D.openModal('revokeModal');
      return;
    }

    btn.disabled = true;

    API.saveDevice({ action: act, id: id })
      .then(function () {
        D.toast(device.code + ' ' + (act === 'activate' ? 'activated' :
          'disabled — live sessions on it ended') + '.', 'ok');
        load();
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; });
  });

  document.getElementById('revokeGo').addEventListener('click', function () {
    if (!revoking) return;

    var btn = this;
    var device = revoking;
    btn.disabled = true;

    API.saveDevice({
      action: 'revoke',
      id: device.id,
      reason: document.getElementById('revokeReason').value
    }).then(function (res) {
      revoking = null;
      D.closeModal('revokeModal');
      load();

      /* Deliberately long and loud. Revoking in Datafort while
       * forgetting the CA is the most likely mistake on this page, and
       * its consequence is a stolen laptop that still completes the TLS
       * handshake. */
      D.toast(res.warning || (device.code + ' revoked.'), 'error', 14000);
    }).catch(D.fail).then(function () { btn.disabled = false; });
  });

  document.getElementById('addGo').addEventListener('click', function () {
    var code   = document.getElementById('devCode').value.trim().toUpperCase();
    var serial = document.getElementById('devSerial').value.trim();

    if (!code || !serial) {
      D.toast('Device code and certificate serial are both required.', 'error');
      return;
    }

    var btn = this;
    btn.disabled = true;

    API.saveDevice({
      action: 'create',
      code: code,
      // The server normalises too; this is just so the admin sees what
      // will be stored if they look.
      serial: serial.toUpperCase().replace(/[^0-9A-F]/g, '').replace(/^0+/, ''),
      employeeId: document.getElementById('devEmployee').value || null,
      expiresAt: document.getElementById('devExpires').value || null
    }).then(function () {
      D.closeModal('addModal');
      document.getElementById('devCode').value = '';
      document.getElementById('devSerial').value = '';
      D.toast(code + ' registered as pending. Activate it to allow sign-in.', 'ok');
      load();
    }).catch(D.fail).then(function () { btn.disabled = false; });
  });


  /* ══ Load ══════════════════════════════════════════════════════ */

  function load() {
    API.devices().then(function (res) {
      devices    = res.devices;
      denials    = res.denials || [];
      thisSerial = res.thisDevice;

      if (lastMode === null) {
        lastMode = res.mode;
        modeSel.value = res.mode;
        paintMode();
      }

      paintTiles();
      paintDenials();
      render();
    }).catch(D.fail);
  }

  qEl.addEventListener('input', render);
  statusEl.addEventListener('change', render);

  D.ready(function () {
    API.users().then(function (res) {
      users = res.users;
      document.getElementById('devEmployee').innerHTML =
        '<option value="">Unassigned</option>' +
        users.map(function (u) {
          return '<option value="' + u.userId + '">' + D.escape(u.name) + '</option>';
        }).join('');
    }).catch(function () { /* assignment is optional at registration */ });

    load();
  });
})();
