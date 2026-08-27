/* audit.js — the platform-level append-only record. Same read-only,
 * no-export discipline as ../audit.js, applied to registry actions. */
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

  var entries = [];
  var total = 0;

  var TONE = {
    tenant_suspend: 'badge--lost', blocked: 'badge--lost',
    tenant_create: 'badge--won', tenant_provisioned: 'badge--won',
    tenant_reactivate: 'badge--won'
  };

  function render() {
    countEl.textContent = total.toLocaleString() + ' entr' + (total === 1 ? 'y' : 'ies');
    emptyEl.hidden = entries.length > 0;

    rowsEl.innerHTML = entries.map(function (a) {
      return '<tr>' +
        '<td><div class="cellstack"><span>' + D.escape(D.ago(a.at)) + '</span></div></td>' +
        '<td>' + D.escape(a.actor || 'System') + '</td>' +
        '<td><span class="badge badge--plain ' + (TONE[a.action] || 'badge--idle') + '">' + D.escape(a.action) + '</span></td>' +
        '<td>' + (a.tenant ? '<code>' + D.escape(a.tenant) + '</code>' : '<span style="color:var(--text-faint)">—</span>') + '</td>' +
        '<td>' + D.escape(a.text || '') + (a.subject ? ' <strong>' + D.escape(a.subject) + '</strong>' : '') + '</td>' +
        '<td style="font-variant-numeric:tabular-nums">' + D.escape(a.ip || '—') + '</td>' +
      '</tr>';
    }).join('');
  }

  function params() {
    return { q: qEl.value.trim(), action: actEl.value, limit: PAGE, offset: offset };
  }

  function load() {
    API.audit(params()).then(function (res) {
      entries = res.entries;
      total = res.total;
      render();
    }).catch(D.fail);
  }

  qEl.addEventListener('input', function () { offset = 0; load(); });
  actEl.addEventListener('change', function () { offset = 0; load(); });

  D.ready(load);
})();
