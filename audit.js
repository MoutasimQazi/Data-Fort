/* audit.js — the append-only record.
 *
 * This page is read-only by design. There is no edit, no delete and no
 * export button, and none of those are missing features. A log an admin
 * can prune is not evidence, and an export is an unmasked copy of every
 * reveal the tenant has ever made — the exact artefact the product
 * exists to prevent.
 */
(function () {
  'use strict';

  var M = window.MOCK;
  var D = window.Datafort;

  var rowsEl  = document.getElementById('rows');
  var emptyEl = document.getElementById('emptyState');
  var countEl = document.getElementById('countLine');
  var qEl     = document.getElementById('q');
  var actEl   = document.getElementById('actionFilter');
  var whoEl   = document.getElementById('actorFilter');

  whoEl.innerHTML = '<option value="">All users</option>' +
    M.users.map(function (u) {
      return '<option value="' + u.id + '">' + D.escape(u.name) + '</option>';
    }).join('');

  /* Actions carry weight, not just a label. A reveal and a blocked copy
   * are the two rows an investigator scans for, so they are the two that
   * get colour; everything else stays neutral. */
  var TONE = {
    reveal:  'badge--new',
    blocked: 'badge--lost',
    import:  'badge--working',
    assign:  'badge--idle',
    login:   'badge--idle',
    view:    'badge--idle',
    status:  'badge--idle',
    email:   'badge--idle'
  };

  var LABEL = {
    reveal: 'Reveal', blocked: 'Blocked', import: 'Import', assign: 'Assign',
    login: 'Sign-in', view: 'View', status: 'Status', email: 'Relay email'
  };

  function render() {
    var term = qEl.value.trim().toLowerCase();
    var action = actEl.value;
    var actor = whoEl.value;

    var list = M.audit.filter(function (a) {
      if (action && a.action !== action) return false;
      if (actor && a.actorId !== actor) return false;
      if (!term) return true;
      return (a.actor + ' ' + a.subject + ' ' + a.ip + ' ' + a.device)
        .toLowerCase().indexOf(term) !== -1;
    });

    countEl.textContent = list.length.toLocaleString() + ' entries' +
      (list.length !== M.audit.length ? ' of ' + M.audit.length.toLocaleString() : '') +
      ' · retained 7 years';

    emptyEl.hidden = list.length > 0;

    rowsEl.innerHTML = list.map(function (a) {
      return '<tr>' +
        '<td><div class="cellstack"><span>' + D.ago(a.at) + '</span>' +
          '<span class="sub">' + new Date(a.at).toLocaleString() + '</span></div></td>' +
        '<td>' + D.escape(a.actor) + '</td>' +
        '<td><span class="badge badge--plain ' + (TONE[a.action] || 'badge--idle') + '">' +
          (LABEL[a.action] || a.action) + '</span></td>' +
        '<td>' + D.escape(a.text) + (a.subject ? ' <strong>' + D.escape(a.subject) + '</strong>' : '') + '</td>' +
        '<td style="font-variant-numeric:tabular-nums">' + D.escape(a.ip) + '</td>' +
        '<td style="font-family:ui-monospace,monospace;font-size:12px">' + D.escape(a.device) + '</td>' +
      '</tr>';
    }).join('');
  }

  qEl.addEventListener('input', render);
  actEl.addEventListener('change', render);
  whoEl.addEventListener('change', render);

  render();
})();
