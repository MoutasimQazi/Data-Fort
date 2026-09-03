/* user.js — one user, everything about them.
 *
 * Reached by clicking a row on the Users page. Answers, in the order an
 * admin actually asks:
 *
 *   Did they finish yesterday's leads?
 *   What are they doing today?
 *   How many leads should they get, and how many may they unmask?
 *   What have they been doing lately?
 *   What can they sign in from?
 *   ...and only then, the account controls.
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;
  var C = window.DatafortCharts;

  var userId = parseInt(new URLSearchParams(location.search).get('id'), 10);
  var data = null;

  function notFound(msg) {
    document.getElementById('content').innerHTML =
      '<div class="card"><div class="empty" style="padding:48px 24px">' +
        '<h3>User not available</h3><p>' + D.escape(msg) + '</p>' +
        '<a class="btn btn--ghost btn--sm" href="users.html">Back to users</a>' +
      '</div></div>';
  }


  /* ══ Yesterday ═════════════════════════════════════════════════
   *
   * The headline. "Worked" means the status moved off New, or the lead
   * was contacted after it was assigned — a reveal on its own does not
   * count. Someone who unmasked forty numbers and called none of them
   * has done no work, and that is precisely the case this has to show.
   */
  function paintYesterday() {
    var y = data.yesterday;
    var verdict = document.getElementById('yesterdayVerdict');
    var body = document.getElementById('yesterdayBody');

    if (y.assigned === 0) {
      verdict.innerHTML = '<span class="badge badge--idle">Nothing assigned</span>';
      body.innerHTML =
        '<p style="margin:0;font-size:13.5px;color:var(--text-muted)">' +
        'No leads were assigned to this rep yesterday, so there was nothing ' +
        'to finish. That is not the same as falling behind.</p>';
      return;
    }

    var done = y.pending === 0;
    verdict.innerHTML = done
      ? '<span class="badge badge--won">Cleared</span>'
      : '<span class="badge badge--working">' + y.pending + ' left</span>';

    var level = y.percent >= 100 ? 'ok' : y.percent >= 60 ? 'warn' : 'danger';

    body.innerHTML =
      '<div class="quota" style="max-width:520px">' +
        '<div class="quota__row">' +
          '<span>' + y.worked + ' of ' + y.assigned + ' worked</span>' +
          '<span class="quota__count">' + y.percent + '%</span>' +
        '</div>' +
        '<div class="meter"><div class="meter__fill" data-level="' + level +
          '" style="width:' + Math.min(100, y.percent) + '%"></div></div>' +
      '</div>' +
      '<p style="margin:14px 0 0;font-size:13.5px;color:var(--text-muted);line-height:1.6">' +
        (done
          ? 'Every lead assigned yesterday was contacted or moved off New. ' +
            y.won + ' won, ' + y.lost + ' lost.'
          : '<strong style="color:var(--text)">' + y.pending + ' leads from yesterday ' +
            'have not been touched.</strong> They are still assigned to this rep and ' +
            'still count against the pool.') +
      '</p>' +
      '<p style="margin:8px 0 0;font-size:12.5px;color:var(--text-faint);line-height:1.6">' +
        'Worked means the status changed or the lead was contacted. Revealing a ' +
        'phone number is not work — that is the gap worth watching.' +
      '</p>';
  }


  /* ══ Today ═════════════════════════════════════════════════════ */

  function paintToday() {
    var t = data.today;
    var host = document.getElementById('todayBody');

    if (t.assigned === 0) {
      host.innerHTML =
        '<div class="empty" style="padding:28px 12px">' +
        '<p style="margin:0">No leads assigned today yet. Use ' +
        '<strong>Assign today’s leads</strong> to give them their batch.</p></div>';
      return;
    }

    var level = t.percent >= 100 ? 'ok' : t.percent >= 50 ? 'warn' : 'danger';

    host.innerHTML =
      '<div class="quota">' +
        '<div class="quota__row">' +
          '<span>' + t.worked + ' of ' + t.assigned + ' worked</span>' +
          '<span class="quota__count">' + t.percent + '%</span>' +
        '</div>' +
        '<div class="meter"><div class="meter__fill" data-level="' + level +
          '" style="width:' + Math.min(100, t.percent) + '%"></div></div>' +
      '</div>' +
      '<dl style="margin:16px 0 0;display:grid;grid-template-columns:auto 1fr;' +
        'gap:9px 18px;font-size:13.5px">' +
        row('Still to work', t.pending) +
        row('Won', t.won) +
        row('Lost', t.lost) +
        row('Contacts revealed', data.reveals.today) +
      '</dl>';
  }

  function row(label, value) {
    return '<dt style="color:var(--text-muted)">' + label + '</dt>' +
           '<dd style="margin:0;font-variant-numeric:tabular-nums">' + value + '</dd>';
  }


  /* ══ Tiles ═════════════════════════════════════════════════════ */

  function paintTiles() {
    var t = data.totals;
    var u = data.user;

    var items = [
      { label: 'Leads held', value: t.held.toLocaleString(),
        note: t.untouched + ' never touched' },
      { label: 'Working', value: String(t.working), note: 'conversations open' },
      { label: 'Won', value: String(t.won), note: t.lost + ' lost' },
      { label: 'Reveals today', value: u.quota > 0
          ? data.reveals.today + ' / ' + u.quota
          : String(data.reveals.today),
        note: u.quota > 0 ? 'against daily quota' : 'no cap set' },
      { label: 'Unassigned pool', value: data.poolAvailable.toLocaleString(),
        note: 'available to hand out' }
    ];

    document.getElementById('tiles').innerHTML = items.map(function (x) {
      return '<div class="tile"><div class="tile__label">' + D.escape(x.label) + '</div>' +
        '<div class="tile__value">' + D.escape(x.value) + '</div>' +
        '<div class="tile__note">' + D.escape(x.note) + '</div></div>';
    }).join('');
  }


  /* ══ Trend ═════════════════════════════════════════════════════ */

  function paintTrend() {
    var series = [
      { key: 'assigned', label: 'Assigned' },
      { key: 'worked',   label: 'Worked' }
    ];
    C.legend(document.getElementById('legendTrend'), series);
    C.trend(document.getElementById('chartTrend'), data.trend, series);
    C.table(document.getElementById('tableTrend'),
      [{ key: 'date', label: 'Date' },
       { key: 'assigned', label: 'Assigned', num: true },
       { key: 'worked', label: 'Worked', num: true },
       { key: 'reveals', label: 'Reveals', num: true }],
      data.trend);
  }


  /* ══ Tables ════════════════════════════════════════════════════ */

  function paintDevices() {
    var host = document.getElementById('devicesTable');

    if (!data.devices.length) {
      host.innerHTML = '<div class="empty" style="padding:28px">' +
        '<p style="margin:0">No company laptop is registered to this user.</p></div>';
      return;
    }

    host.innerHTML = '<table class="table"><thead><tr>' +
      '<th>Device</th><th>Serial</th><th>State</th><th>Last seen</th>' +
      '</tr></thead><tbody>' +
      data.devices.map(function (d) {
        var cls = { active: 'badge--won', pending: 'badge--new',
                    disabled: 'badge--working', revoked: 'badge--lost' }[d.status] || 'badge--idle';
        return '<tr><td>' + D.escape(d.device_code) + '</td>' +
          '<td style="font-family:ui-monospace,monospace;font-size:12.5px">' +
            D.escape(d.certificate_serial) + '</td>' +
          '<td><span class="badge ' + cls + '">' + D.escape(d.status) + '</span></td>' +
          '<td>' + (d.last_seen_at ? D.ago(d.last_seen_at) :
            '<span style="color:var(--text-faint)">Never</span>') + '</td></tr>';
      }).join('') + '</tbody></table>';
  }

  function paintSessions() {
    var host = document.getElementById('sessionsTable');

    if (!data.sessions.length) {
      host.innerHTML = '<div class="empty" style="padding:28px">' +
        '<p style="margin:0">Not signed in anywhere right now.</p></div>';
      return;
    }

    host.innerHTML = '<table class="table"><thead><tr>' +
      '<th>Session</th><th>IP</th><th>Started</th><th>Last active</th>' +
      '</tr></thead><tbody>' +
      data.sessions.map(function (s) {
        return '<tr>' +
          // A short hash, never the session id — that id IS the credential.
          '<td style="font-family:ui-monospace,monospace;font-size:12.5px">' +
            D.escape(s.ref) + '</td>' +
          '<td style="font-variant-numeric:tabular-nums">' + D.escape(s.ip || '—') + '</td>' +
          '<td>' + D.ago(s.createdAt) + '</td>' +
          '<td>' + D.ago(s.lastSeenAt) + '</td></tr>';
      }).join('') + '</tbody></table>';
  }

  var ACTION_LABEL = {
    reveal: 'Reveal', blocked: 'Blocked', import: 'Import', assign: 'Assign',
    login: 'Sign-in', view: 'View', status: 'Status', email: 'Relay email',
    device: 'Device', user: 'User', settings: 'Settings'
  };

  function paintLog() {
    var host = document.getElementById('logTable');

    if (!data.log.length) {
      host.innerHTML = '<div class="empty" style="padding:28px">' +
        '<p style="margin:0">Nothing recorded for this user yet.</p></div>';
      return;
    }

    host.innerHTML = '<table class="table"><thead><tr>' +
      '<th>When</th><th>Action</th><th>Detail</th><th>Device</th><th>IP</th>' +
      '</tr></thead><tbody>' +
      data.log.map(function (a) {
        var tone = a.action === 'reveal' ? 'badge--new'
                 : a.action === 'blocked' ? 'badge--lost' : 'badge--idle';
        return '<tr>' +
          '<td><div class="cellstack"><span>' + D.ago(a.at) + '</span>' +
            '<span class="sub">' +
            D.escape(D.when(a.at)) +
            '</span></div></td>' +
          '<td><span class="badge badge--plain ' + tone + '">' +
            D.escape(ACTION_LABEL[a.action] || a.action) + '</span></td>' +
          '<td>' + D.escape(a.detail || '') +
            (a.subject ? ' <strong>' + D.escape(a.subject) + '</strong>' : '') + '</td>' +
          '<td style="font-family:ui-monospace,monospace;font-size:12px">' +
            D.escape(a.device_code || '—') + '</td>' +
          '<td style="font-variant-numeric:tabular-nums">' + D.escape(a.ip || '—') + '</td>' +
        '</tr>';
      }).join('') + '</tbody></table>';
  }


  /* ══ Controls ══════════════════════════════════════════════════ */

  function paintControls() {
    var u = data.user;

    document.getElementById('targetInput').value = u.dailyTarget;
    document.getElementById('quotaInput').value = u.quota;

    document.getElementById('targetHint').textContent = u.role === 'admin'
      ? 'Administrators are not given a daily book of leads.'
      : 'How many new leads land in their queue each day. Workload, not exposure.';

    document.getElementById('toggleStatus').textContent =
      u.status === 'suspended' ? 'Restore account' : 'Suspend account';

    document.getElementById('fullLogLink').href =
      'audit.html?actor=' + encodeURIComponent(u.id);
  }

  document.getElementById('saveLimits').addEventListener('click', function () {
    var btn = this;
    var target = parseInt(document.getElementById('targetInput').value, 10);
    var quota  = parseInt(document.getElementById('quotaInput').value, 10);

    if (isNaN(target) || target < 0 || isNaN(quota) || quota < 0) {
      D.toast('Both limits must be 0 or more.', 'error');
      return;
    }

    btn.disabled = true;

    // Two separate actions so each is audited with its own before/after.
    API.saveUser({ action: 'target', userId: userId, target: target })
      .then(function () {
        return API.saveUser({ action: 'quota', userId: userId, quota: quota });
      })
      .then(function () {
        D.toast('Limits saved.', 'ok');
        load();
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; });
  });

  document.getElementById('assignToday').addEventListener('click', function () {
    var btn = this;
    var target = parseInt(document.getElementById('targetInput').value, 10) || 0;

    if (target <= 0) {
      D.toast('Set a daily lead target above 0 first.', 'error');
      return;
    }

    btn.disabled = true;

    API.saveUser({ action: 'assign_daily', userId: userId, count: target })
      .then(function (res) {
        var note = document.getElementById('assignNote');
        note.hidden = false;
        note.textContent = res.message;
        if (res.shortfall > 0) note.className = 'alert alert--error';
        else note.className = 'alert alert--info';
        load();
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; });
  });

  document.getElementById('setPw').addEventListener('click', function () {
    var btn = this;
    var pw = document.getElementById('newPw').value;
    var alertBox = document.getElementById('pwAlert');
    var okBox = document.getElementById('pwOk');

    alertBox.hidden = okBox.hidden = true;

    if (!confirm('Set a new password for ' + data.user.name + '?\n\n' +
                 'Every session they have open will end immediately, and this ' +
                 'is recorded against your name.')) return;

    btn.disabled = true;

    API.saveUser({ action: 'set_password', userId: userId, password: pw })
      .then(function (res) {
        document.getElementById('newPw').value = '';
        okBox.textContent = res.message;
        okBox.hidden = false;
        load();
      })
      .catch(function (err) {
        alertBox.textContent = err.message || 'Could not set the password.';
        alertBox.hidden = false;
      })
      .then(function () { btn.disabled = false; });
  });

  document.getElementById('toggleStatus').addEventListener('click', function () {
    var btn = this;
    var suspending = data.user.status !== 'suspended';

    if (suspending && !confirm('Suspend ' + data.user.name + '?\n\n' +
        'Their sessions end immediately. Assigned leads stay with them.')) return;

    btn.disabled = true;

    API.saveUser({ action: suspending ? 'suspend' : 'restore', userId: userId })
      .then(function () {
        D.toast(data.user.name + ' ' + (suspending ? 'suspended' : 'restored') + '.', 'ok');
        load();
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; });
  });


  /* ══ Load ══════════════════════════════════════════════════════ */

  function load() {
    API.userDetail(userId)
      .then(function (res) {
        data = res;

        document.getElementById('userName').textContent = res.user.name;
        document.getElementById('userSub').textContent =
          res.user.email + ' · ' +
          (res.user.role === 'admin' ? 'Administrator' : 'Sales rep') +
          (res.user.status !== 'active' ? ' · ' + res.user.status : '');

        paintYesterday();
        paintTiles();
        paintToday();
        paintTrend();
        paintDevices();
        paintSessions();
        paintLog();
        paintControls();
      })
      .catch(function (err) { notFound(err.message || 'Could not load this user.'); });
  }

  // Charts read theme-dependent palettes at draw time.
  var themeBtn = document.getElementById('themeBtn');
  if (themeBtn) themeBtn.addEventListener('click', function () {
    setTimeout(function () { if (data) paintTrend(); }, 0);
  });

  D.ready(function () {
    if (!userId) { notFound('No user was specified.'); return; }
    load();
  });
})();
