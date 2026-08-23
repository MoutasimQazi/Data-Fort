/* audit.js — the append-only record.
 *
 * Read-only by design. There is no edit, no delete and no export, and
 * none of those are missing features. A log an admin can prune is not
 * evidence, and an export is an unmasked copy of every reveal the
 * tenant has ever made.
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var PAGE = 100;
  var offset = 0;

  var rowsEl  = document.getElementById('rows');
  var emptyEl = document.getElementById('emptyState');
  var countEl = document.getElementById('countLine');
  var qEl     = document.getElementById('q');
  var actEl   = document.getElementById('actionFilter');
  var whoEl   = document.getElementById('actorFilter');

  var entries = [];
  var total = 0;

  /* A reveal and a blocked action are the two rows an investigator
   * scans for, so they are the two that get colour. Everything else
   * stays neutral — colouring all of it colours none of it. */
  var TONE = {
    reveal:   'badge--new',
    blocked:  'badge--lost',
    import:   'badge--working',
    device:   'badge--working',
    settings: 'badge--working'
  };

  var LABEL = {
    reveal: 'Reveal', blocked: 'Blocked', import: 'Import', assign: 'Assign',
    login: 'Sign-in', view: 'View', status: 'Status', email: 'Relay email',
    device: 'Device', user: 'User', settings: 'Settings'
  };

  function render() {
    countEl.textContent = total.toLocaleString() + ' entr' + (total === 1 ? 'y' : 'ies');
    emptyEl.hidden = entries.length > 0;

    rowsEl.innerHTML = entries.map(function (a) {
      var when = String(a.at || '').replace(' ', 'T');

      return '<tr>' +
        '<td><div class="cellstack"><span>' + D.ago(a.at) + '</span>' +
          '<span class="sub">' + D.escape(new Date(when).toLocaleString()) + '</span></div></td>' +
        '<td>' + D.escape(a.actor || 'System') + '</td>' +
        '<td><span class="badge badge--plain ' + (TONE[a.action] || 'badge--idle') + '">' +
          D.escape(LABEL[a.action] || a.action) + '</span></td>' +
        '<td>' + D.escape(a.text || '') +
          (a.subject ? ' <strong>' + D.escape(a.subject) + '</strong>' : '') + '</td>' +
        '<td style="font-variant-numeric:tabular-nums">' + D.escape(a.ip || '—') + '</td>' +
        '<td style="font-family:ui-monospace,monospace;font-size:12px">' +
          D.escape(a.device || '—') + '</td>' +
      '</tr>';
    }).join('');
  }

  function params() {
    return {
      q: qEl.value.trim(),
      action: actEl.value,
      actor: whoEl.value,
      limit: PAGE,
      offset: offset
    };
  }

  function reload() {
    offset = 0;
    entries = [];
    fetchPage();
  }

  function fetchPage() {
    API.audit(params()).then(function (res) {
      entries = entries.concat(res.entries);
      total = res.total;
      render();
    }).catch(D.fail);
  }

  var searchTimer = null;
  qEl.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 280);
  });
  actEl.addEventListener('change', reload);
  whoEl.addEventListener('change', reload);

  D.ready(function () {
    /* user.js links here with ?actor=<id> so "full audit log" from a
     * person's page lands pre-filtered on that person. */
    var preset = new URLSearchParams(location.search).get('actor');

    API.users().then(function (res) {
      whoEl.innerHTML = '<option value="">All users</option>' +
        res.users.map(function (u) {
          return '<option value="' + u.userId + '">' + D.escape(u.name) + '</option>';
        }).join('');

      // Apply the preset only after the options exist, or the value
      // silently does not stick.
      if (preset) whoEl.value = preset;
      reload();
    }).catch(function () {
      /* The filter dropdown is optional; the log itself still loads. */
      reload();
    });
  });
})();
