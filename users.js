/* users.js — accounts, roles and the daily reveal quota.
 *
 * The quota field on this page is the single most important control in
 * the product. Everything else deters; this one caps.
 */
(function () {
  'use strict';

  var M = window.MOCK;
  var D = window.Datafort;

  var rowsEl  = document.getElementById('rows');
  var qEl     = document.getElementById('q');
  var roleEl  = document.getElementById('roleFilter');

  var editing = null;   // user being edited in the quota modal


  /* ══ Render ════════════════════════════════════════════════════ */

  function stateBadge(u) {
    if (u.status === 'suspended') return '<span class="badge badge--lost">Suspended</span>';
    if (u.status === 'flagged')   return '<span class="badge badge--working">Flagged</span>';
    return '<span class="badge badge--won">Active</span>';
  }

  function usageCell(u) {
    if (u.role === 'admin') {
      // Admins have no quota. They are the tenant's trusted party, and a
      // quota on the person who sets quotas is theatre.
      return '<span style="color:var(--text-faint)">Not applicable</span>';
    }

    var pct = u.quota ? Math.min(100, (u.usedToday / u.quota) * 100) : 0;
    var level = pct >= 100 ? 'danger' : pct >= 80 ? 'warn' : 'ok';

    return '<div class="quota" style="min-width:140px">' +
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

    var list = M.users.filter(function (u) {
      if (role && u.role !== role) return false;
      if (!term) return true;
      return (u.name + ' ' + u.email).toLowerCase().indexOf(term) !== -1;
    });

    rowsEl.innerHTML = list.map(function (u) {
      return '<tr data-id="' + u.id + '">' +
        '<td><div style="display:flex;gap:10px;align-items:center">' +
          '<span class="avatar" style="width:30px;height:30px;font-size:11.5px">' +
            D.initials(u.name) + '</span>' +
          '<div class="cellstack"><span>' + D.escape(u.name) + '</span>' +
          '<span class="sub">' + D.escape(u.email) + '</span></div>' +
        '</div></td>' +
        '<td>' + (u.role === 'admin'
          ? '<span class="badge badge--plain badge--idle">Administrator</span>'
          : '<span class="badge badge--plain badge--idle">Sales rep</span>') + '</td>' +
        '<td class="num">' + (u.role === 'admin' ? '—' : u.assigned.toLocaleString()) + '</td>' +
        '<td>' + usageCell(u) + '</td>' +
        '<td class="num">' + (u.role === 'admin' ? '—' :
          '<strong>' + u.quota + '</strong>') + '</td>' +
        '<td>' + stateBadge(u) + '</td>' +
        '<td>' + D.ago(u.lastSeen) + '</td>' +
        '<td class="shrink" style="white-space:nowrap">' +
          (u.role === 'rep'
            ? '<button class="btn btn--ghost btn--sm" data-act="quota">Quota</button> '
            : '') +
          '<button class="btn btn--ghost btn--sm" data-act="toggle">' +
            (u.status === 'suspended' ? 'Restore' : 'Suspend') + '</button>' +
        '</td>' +
      '</tr>';
    }).join('');
  }


  /* ══ Quota editing ═════════════════════════════════════════════ */

  var quotaInput = document.getElementById('quotaInput');
  var quotaErr   = document.getElementById('quotaErr');
  var quotaHint  = document.getElementById('quotaHint');

  function openQuota(user) {
    editing = user;
    document.getElementById('quotaWho').textContent =
      user.name + ' currently has ' + user.assigned.toLocaleString() +
      ' leads assigned and has used ' + user.usedToday + ' reveals today.';
    quotaInput.value = user.quota;
    quotaErr.textContent = '';
    hint();
    D.openModal('quotaModal');
  }

  /* Translates the abstract number into working days, which is how a
   * sales manager actually thinks about it. A quota that looks cautious
   * on paper can mean a rep never gets through their book. */
  function hint() {
    var n = parseInt(quotaInput.value, 10);
    if (!editing || isNaN(n) || n <= 0) {
      quotaHint.hidden = n === 0 ? false : true;
      if (n === 0) {
        quotaHint.textContent =
          'A quota of 0 blocks every reveal. The rep keeps their assigned ' +
          'leads but cannot unmask any contact details.';
      }
      return;
    }

    var days = Math.ceil(editing.assigned / n);
    quotaHint.hidden = false;
    quotaHint.textContent =
      'At ' + n + ' reveals a day, ' + editing.name + ' would need about ' +
      days + ' working day' + (days === 1 ? '' : 's') +
      ' to work through their ' + editing.assigned.toLocaleString() + ' assigned leads.';
  }

  quotaInput.addEventListener('input', hint);

  document.getElementById('quotaSave').addEventListener('click', function () {
    var n = parseInt(quotaInput.value, 10);

    if (isNaN(n) || n < 0) {
      quotaErr.textContent = 'Enter a number of 0 or more.';
      return;
    }
    if (n > 500) {
      quotaErr.textContent = 'Cap is 500 a day. Above that the quota stops being a control.';
      return;
    }

    editing.quota = n;
    D.closeModal('quotaModal');
    render();
    D.toast('Quota for ' + editing.name + ' set to ' + n + '/day. (Not saved — no API yet.)', 'ok');
  });


  /* ══ Row actions ═══════════════════════════════════════════════ */

  rowsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-act]');
    if (!btn) return;

    var id = btn.closest('tr').dataset.id;
    var user = M.users.filter(function (u) { return u.id === id; })[0];
    if (!user) return;

    if (btn.dataset.act === 'quota') { openQuota(user); return; }

    if (btn.dataset.act === 'toggle') {
      /* Suspension does NOT unassign the leads. Recalling them is a
       * separate, deliberate act on the Leads page — a suspended rep
       * whose book is silently emptied makes the leak investigation
       * harder, not easier. */
      user.status = user.status === 'suspended' ? 'active' : 'suspended';
      render();
      D.toast(user.name + ' ' + (user.status === 'suspended' ? 'suspended' : 'restored') +
              '. Assigned leads are unchanged.', 'ok');
    }
  });


  /* ══ Invite ════════════════════════════════════════════════════ */

  document.getElementById('inviteGo').addEventListener('click', function () {
    var name  = document.getElementById('newName').value.trim();
    var email = document.getElementById('newEmail').value.trim();
    var role  = document.getElementById('newRole').value;
    var quota = parseInt(document.getElementById('newQuota').value, 10) || 0;

    if (!name || !email) {
      D.toast('Name and email are required.', 'error');
      return;
    }

    M.users.push({
      id: 'u-' + Math.floor(Math.random() * 900 + 100),
      name: name, email: email, role: role,
      quota: role === 'admin' ? 0 : quota,
      usedToday: 0, assigned: 0, status: 'active',
      lastSeen: new Date().toISOString()
    });

    D.closeModal('inviteModal');
    render();
    D.toast('Invite queued for ' + name + '. (Not sent — no API yet.)', 'ok');
  });

  // Quota field is meaningless for an admin, so it hides itself.
  document.getElementById('newRole').addEventListener('change', function (e) {
    document.getElementById('newQuota').closest('.field').hidden = e.target.value === 'admin';
  });


  qEl.addEventListener('input', render);
  roleEl.addEventListener('change', render);

  render();
})();
