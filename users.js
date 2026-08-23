/* users.js — the team grid.
 *
 * Every row is a link into user.html, where the admin sets that rep's
 * daily leads, reads their logs and sees whether yesterday's batch was
 * finished. This page stays a scannable overview: the one screen that
 * answers "is anyone falling behind" without clicking anything.
 *
 * Two numbers per rep, and they are NOT the same thing:
 *
 *   Daily leads   how many land in their queue each day   (workload)
 *   Reveal quota  how many contacts they may unmask       (exposure)
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var rowsEl = document.getElementById('rows');
  var qEl    = document.getElementById('q');
  var roleEl = document.getElementById('roleFilter');

  var users = [];


  /* ══ Render ════════════════════════════════════════════════════ */

  function stateBadge(u) {
    if (u.status === 'suspended') return '<span class="badge badge--lost">Suspended</span>';
    if (u.status === 'flagged')   return '<span class="badge badge--working">Flagged</span>';
    return '<span class="badge badge--won">Active</span>';
  }

  /* Yesterday, at a glance.
   *
   * The point of this column is that an admin should not have to open
   * five users to find the one who is behind. "Cleared" and "8 left"
   * are both short enough to scan down a list. */
  function yesterdayCell(u) {
    if (u.role === 'admin') return '<span style="color:var(--text-faint)">—</span>';

    var y = u.yesterday || {};

    if (!y.assigned) {
      return '<span style="color:var(--text-faint)">None assigned</span>';
    }
    if (y.pending === 0) {
      return '<span class="badge badge--won">Cleared</span>';
    }

    var cls = y.percent >= 60 ? 'badge--working' : 'badge--lost';
    return '<span class="badge ' + cls + '">' + y.pending + ' left</span>' +
           ' <span style="color:var(--text-faint);font-size:12px">' +
           y.worked + '/' + y.assigned + '</span>';
  }

  function usageCell(u) {
    /* Admins have no CAP — a quota on the person who sets quotas is
     * theatre — but their reveals ARE counted, so the number is shown.
     * Saying "not applicable" while lead_reveals filled up with their
     * activity was the blind spot that let an admin account read the
     * whole book unmeasured. */
    if (u.role === 'admin') {
      return u.usedToday > 0
        ? '<span style="font-variant-numeric:tabular-nums">' + u.usedToday +
          ' <span style="color:var(--text-faint);font-size:12px">· no cap</span></span>'
        : '<span style="color:var(--text-faint)">None today · no cap</span>';
    }

    var pct = u.quota ? Math.min(100, (u.usedToday / u.quota) * 100) : 0;
    var level = pct >= 100 ? 'danger' : pct >= 80 ? 'warn' : 'ok';

    return '<div class="quota" style="min-width:130px">' +
      '<div class="quota__row">' +
        '<span class="quota__count">' + u.usedToday + ' / ' + u.quota + '</span>' +
        '<span style="color:var(--text-faint)">' + Math.round(pct) + '%</span>' +
      '</div>' +
      '<div class="meter"><div class="meter__fill" data-level="' + level +
        '" style="width:' + pct + '%"></div></div>' +
    '</div>';
  }

  function render() {
    var term = qEl.value.trim().toLowerCase();
    var role = roleEl.value;

    var list = users.filter(function (u) {
      if (role && u.role !== role) return false;
      if (!term) return true;
      return (u.name + ' ' + u.email).toLowerCase().indexOf(term) !== -1;
    });

    if (!list.length) {
      rowsEl.innerHTML =
        '<tr><td colspan="9"><div class="empty" style="padding:34px">' +
        '<p style="margin:0">No users match.</p></div></td></tr>';
      return;
    }

    rowsEl.innerHTML = list.map(function (u) {
      return '<tr data-id="' + u.userId + '" class="rowlink" tabindex="0" ' +
             'role="link" aria-label="Open ' + D.escape(u.name) + '">' +
        '<td><div style="display:flex;gap:10px;align-items:center">' +
          '<span class="avatar" style="width:30px;height:30px;font-size:11.5px">' +
            D.escape(D.initials(u.name)) + '</span>' +
          '<div class="cellstack"><span>' + D.escape(u.name) + '</span>' +
          '<span class="sub">' + D.escape(u.email) + '</span></div>' +
        '</div></td>' +
        '<td>' + (u.role === 'admin'
          ? '<span class="badge badge--plain badge--idle">Administrator</span>'
          : '<span class="badge badge--plain badge--idle">Sales rep</span>') + '</td>' +
        '<td class="num">' + (u.role === 'admin' ? '—' : u.assigned.toLocaleString()) + '</td>' +
        '<td>' + yesterdayCell(u) + '</td>' +
        '<td>' + usageCell(u) + '</td>' +
        '<td class="num">' + (u.role === 'admin' ? '—' :
          '<strong>' + (u.dailyTarget || 0) + '</strong>') + '</td>' +
        '<td class="num">' + (u.role === 'admin' ? '—' : '<strong>' + u.quota + '</strong>') + '</td>' +
        '<td>' + stateBadge(u) + '</td>' +
        '<td>' + (u.lastSeen ? D.ago(u.lastSeen) :
          '<span style="color:var(--text-faint)">Never</span>') + '</td>' +
      '</tr>';
    }).join('');
  }


  /* ══ Row navigation ════════════════════════════════════════════ */

  function open(id) {
    location.href = 'user.html?id=' + encodeURIComponent(id);
  }

  rowsEl.addEventListener('click', function (e) {
    var tr = e.target.closest('tr[data-id]');
    if (tr) open(tr.dataset.id);
  });

  // Keyboard parity. A grid that only responds to a mouse is a grid an
  // admin cannot use one-handed while on a call.
  rowsEl.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    e.preventDefault();
    open(tr.dataset.id);
  });


  /* ══ Invite ════════════════════════════════════════════════════ */

  document.getElementById('inviteGo').addEventListener('click', function () {
    var name  = document.getElementById('newName').value.trim();
    var email = document.getElementById('newEmail').value.trim();
    var role  = document.getElementById('newRole').value;
    var quota = parseInt(document.getElementById('newQuota').value, 10) || 0;

    if (!name || !email) { D.toast('Name and email are required.', 'error'); return; }

    var btn = this;
    btn.disabled = true;

    API.saveUser({ action: 'create', name: name, email: email, role: role, quota: quota })
      .then(function () {
        D.closeModal('inviteModal');
        document.getElementById('newName').value = '';
        document.getElementById('newEmail').value = '';
        D.toast('Invite sent to ' + email + '. The link sets their first password.', 'ok');
        load();
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; });
  });

  // Quota is meaningless for an admin.
  document.getElementById('newRole').addEventListener('change', function (e) {
    document.getElementById('newQuota').closest('.field').hidden = e.target.value === 'admin';
  });


  /* ══ Load ══════════════════════════════════════════════════════ */

  function load() {
    API.users().then(function (res) {
      users = res.users;
      render();
    }).catch(D.fail);
  }

  qEl.addEventListener('input', render);
  roleEl.addEventListener('change', render);

  D.ready(load);
})();
