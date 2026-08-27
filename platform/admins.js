/* admins.js — the platform team grid: invite, resend, suspend, reactivate. */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var rowsEl = document.getElementById('rows');
  var emptyEl = document.getElementById('emptyState');

  var admins = [];

  function accessCell(a) {
    if (a.pendingInvite) return '<span class="badge badge--working">Invite pending</span>';
    if (a.liveSessions > 0) return '<span class="badge badge--won">Signed in</span>';
    return '<span style="color:var(--text-faint)">No active session</span>';
  }

  function render() {
    if (!admins.length) {
      rowsEl.innerHTML = '';
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    rowsEl.innerHTML = admins.map(function (a) {
      var actions = '';
      if (a.pendingInvite) {
        actions += '<button class="btn btn--ghost btn--sm" data-act="resend_invite" data-id="' + a.id + '">Resend invite</button> ';
      }
      if (a.status === 'active' && !a.isYou) {
        actions += '<button class="btn btn--ghost btn--sm" data-act="suspend" data-id="' + a.id + '" style="color:var(--red)">Suspend</button> ';
      }
      if (a.status === 'suspended') {
        actions += '<button class="btn btn--ghost btn--sm" data-act="reactivate" data-id="' + a.id + '">Reactivate</button>';
      }

      return '<tr>' +
        '<td><div style="display:flex;gap:10px;align-items:center">' +
          '<span class="avatar" style="width:30px;height:30px;font-size:11.5px">' + D.escape(D.initials(a.name)) + '</span>' +
          '<span>' + D.escape(a.name) + (a.isYou ? ' <span style="color:var(--text-faint);font-size:12px">(you)</span>' : '') + '</span>' +
        '</div></td>' +
        '<td>' + D.escape(a.email) + '</td>' +
        '<td>' + (a.status === 'suspended' ? '<span class="badge badge--lost">Suspended</span>' : '<span class="badge badge--won">Active</span>') + '</td>' +
        '<td>' + accessCell(a) + '</td>' +
        '<td>' + (a.lastSeenAt ? D.escape(D.ago(a.lastSeenAt)) : '<span style="color:var(--text-faint)">Never</span>') + '</td>' +
        '<td class="shrink">' + actions + '</td>' +
      '</tr>';
    }).join('');
  }

  function showLink(link) {
    document.getElementById('linkOut').value = link;
    D.openModal('linkModal');
  }

  function load() {
    API.admins().then(function (res) {
      admins = res.admins;
      render();
    }).catch(D.fail);
  }

  rowsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var act = btn.dataset.act;
    var id = btn.dataset.id;

    if (act === 'suspend' && !confirm('Suspend this teammate\'s access to the platform panel?')) return;

    API.saveAdmin({ action: act, id: id }).then(function (res) {
      if (res.inviteLink) { showLink(res.inviteLink); }
      else { D.toast('Updated.', 'ok'); }
      load();
    }).catch(D.fail);
  });

  document.getElementById('inviteGo').addEventListener('click', function () {
    var name = document.getElementById('newName').value.trim();
    var email = document.getElementById('newEmail').value.trim();
    if (!name || !email) { D.toast('Name and email are required.', 'error'); return; }

    var btn = this;
    btn.disabled = true;
    API.saveAdmin({ action: 'create', name: name, email: email }).then(function (res) {
      D.closeModal('inviteModal');
      document.getElementById('newName').value = '';
      document.getElementById('newEmail').value = '';
      showLink(res.inviteLink);
      load();
    }).catch(D.fail).then(function () { btn.disabled = false; });
  });

  D.ready(load);
})();
