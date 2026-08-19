/* leads.js — the admin's master lead table.
 *
 * The only screen in Datafort that shows every lead at once, which is
 * exactly why it is admin-only. Assignment happens here, and assignment
 * is the primary containment mechanism (requirements section 4).
 */
(function () {
  'use strict';

  var M = window.MOCK;
  var D = window.Datafort;

  var PAGE = 50;          // rows rendered at a time
  var shown = PAGE;

  var rowsEl    = document.getElementById('rows');
  var emptyEl   = document.getElementById('emptyState');
  var countLine = document.getElementById('countLine');
  var shownLine = document.getElementById('shownLine');
  var moreBtn   = document.getElementById('moreBtn');

  var qEl       = document.getElementById('q');
  var ownerEl   = document.getElementById('ownerFilter');
  var statusEl  = document.getElementById('statusFilter');
  var sourceEl  = document.getElementById('sourceFilter');

  var selbar    = document.getElementById('selbar');
  var selCount  = document.getElementById('selCount');
  var selAll    = document.getElementById('selAll');

  var selected = new Set();

  var reps = M.users.filter(function (u) { return u.role === 'rep'; });


  /* ══ Filter options ════════════════════════════════════════════ */

  ownerEl.innerHTML =
    '<option value="">All owners</option>' +
    '<option value="__none">Unassigned</option>' +
    reps.map(function (u) {
      return '<option value="' + u.id + '">' + D.escape(u.name) + '</option>';
    }).join('');

  var sources = [];
  M.leads.forEach(function (l) {
    if (sources.indexOf(l.source) === -1) sources.push(l.source);
  });
  sourceEl.innerHTML = '<option value="">All sources</option>' +
    sources.map(function (s) {
      return '<option value="' + D.escape(s) + '">' + D.escape(s) + '</option>';
    }).join('');

  document.getElementById('assignTo').innerHTML = reps.map(function (u) {
    return '<option value="' + u.id + '">' + D.escape(u.name) +
           ' — ' + u.assigned + ' assigned, quota ' + u.quota + '/day</option>';
  }).join('');


  /* ══ Filtering ═════════════════════════════════════════════════ */

  function filtered() {
    var term = qEl.value.trim().toLowerCase();
    var owner = ownerEl.value;
    var status = statusEl.value;
    var source = sourceEl.value;

    return M.leads.filter(function (l) {
      if (status && l.status !== status) return false;
      if (source && l.source !== source) return false;
      if (owner === '__none' && l.ownerId) return false;
      if (owner && owner !== '__none' && l.ownerId !== owner) return false;
      if (!term) return true;
      return (l.name + ' ' + l.company + ' ' + l.id).toLowerCase().indexOf(term) !== -1;
    });
  }


  /* ══ Render ════════════════════════════════════════════════════ */

  function badge(s) {
    var label = { new: 'New', working: 'Working', won: 'Won', lost: 'Lost' }[s] || s;
    return '<span class="badge badge--' + s + '">' + label + '</span>';
  }

  function render() {
    var list = filtered();
    var page = list.slice(0, shown);

    countLine.textContent = list.length.toLocaleString() + ' leads' +
      (list.length !== M.leads.length ? ' of ' + M.leads.length.toLocaleString() : '');

    emptyEl.hidden = list.length > 0;
    moreBtn.hidden = shown >= list.length;
    shownLine.textContent = list.length
      ? 'Showing ' + page.length + ' of ' + list.length.toLocaleString()
      : '';

    rowsEl.innerHTML = page.map(function (l) {
      var owner = l.ownerName
        ? D.escape(l.ownerName)
        : '<span style="color:var(--text-faint)">Unassigned</span>';

      /* Honeytokens are marked for the admin only. A rep must never be
         able to tell a seeded record from a real one, or the whole
         attribution trick stops working. */
      var flag = l.honeytoken
        ? ' <span class="badge badge--plain badge--idle" title="Seeded decoy record — do not delete">decoy</span>'
        : '';

      return '<tr data-id="' + l.id + '">' +
        '<td class="shrink"><input type="checkbox" class="rowsel" value="' + l.id + '"' +
          (selected.has(l.id) ? ' checked' : '') + ' aria-label="Select ' + D.escape(l.name) + '"></td>' +
        '<td><div class="cellstack"><span>' + D.escape(l.name) + flag + '</span>' +
          '<span class="sub">' + D.escape(l.company) + ' · ' + D.escape(l.city) + '</span></div></td>' +
        // Masked even for the admin. An admin has no operational reason to
        // read 240 phone numbers, and the audit trail is cleaner if every
        // unmasking in the product goes through the same door.
        '<td><div class="cellstack"><span class="masked">' + D.escape(l.phoneMasked) + '</span>' +
          '<span class="sub">' + D.escape(l.emailMasked) + '</span></div></td>' +
        '<td>' + owner + '</td>' +
        '<td><div class="cellstack"><span>' + D.escape(l.source) + '</span>' +
          '<span class="sub">' + D.ago(l.acquiredDate) + '</span></div></td>' +
        '<td>' + badge(l.status) + '</td>' +
        '<td class="num">' + D.money(l.sourceCost) + '</td>' +
      '</tr>';
    }).join('');

    syncSelectionUi();
  }


  /* ══ Selection ═════════════════════════════════════════════════ */

  function syncSelectionUi() {
    selCount.textContent = selected.size;
    selbar.hidden = selected.size === 0;
    document.getElementById('assignCount').textContent = selected.size;

    var boxes = rowsEl.querySelectorAll('.rowsel');
    var all = boxes.length > 0;
    boxes.forEach(function (b) { if (!b.checked) all = false; });
    selAll.checked = all;
  }

  rowsEl.addEventListener('change', function (e) {
    var box = e.target.closest('.rowsel');
    if (!box) return;
    if (box.checked) selected.add(box.value);
    else selected.delete(box.value);
    syncSelectionUi();
  });

  selAll.addEventListener('change', function () {
    rowsEl.querySelectorAll('.rowsel').forEach(function (b) {
      b.checked = selAll.checked;
      if (selAll.checked) selected.add(b.value);
      else selected.delete(b.value);
    });
    syncSelectionUi();
  });

  document.getElementById('clearSel').addEventListener('click', function () {
    selected.clear();
    render();
  });


  /* ══ Assign ════════════════════════════════════════════════════ */

  var assignTo = document.getElementById('assignTo');
  var assignWarn = document.getElementById('assignWarn');

  function checkQuotaFit() {
    var rep = reps.filter(function (u) { return u.id === assignTo.value; })[0];
    if (!rep || !selected.size) { assignWarn.hidden = true; return; }

    var days = Math.ceil(selected.size / Math.max(1, rep.quota));
    if (days > 5) {
      assignWarn.hidden = false;
      assignWarn.textContent =
        rep.name + ' has a ' + rep.quota + '/day reveal quota. At that rate these ' +
        selected.size + ' leads would take about ' + days +
        ' working days to get through. Consider splitting the batch or raising the quota.';
    } else {
      assignWarn.hidden = true;
    }
  }

  assignTo.addEventListener('change', checkQuotaFit);

  document.getElementById('assignGo').addEventListener('click', function () {
    var rep = reps.filter(function (u) { return u.id === assignTo.value; })[0];
    if (!rep) return;

    var n = selected.size;
    M.leads.forEach(function (l) {
      if (selected.has(l.id)) { l.ownerId = rep.id; l.ownerName = rep.name; }
    });

    selected.clear();
    D.closeModal('assignModal');
    render();
    D.toast(n + ' leads assigned to ' + rep.name + '. (Not saved — no API yet.)', 'ok');
  });

  document.getElementById('recallBtn').addEventListener('click', function () {
    var n = selected.size;
    M.leads.forEach(function (l) {
      if (selected.has(l.id)) { l.ownerId = null; l.ownerName = null; }
    });
    selected.clear();
    render();
    D.toast(n + ' leads recalled to the unassigned pool.', 'ok');
  });


  /* ══ Wiring ════════════════════════════════════════════════════ */

  [qEl, ownerEl, statusEl, sourceEl].forEach(function (el) {
    el.addEventListener('input', function () { shown = PAGE; render(); });
    el.addEventListener('change', function () { shown = PAGE; render(); });
  });

  moreBtn.addEventListener('click', function () { shown += PAGE; render(); });

  // Keep the modal's warning honest when the selection changes underneath it.
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-modal-open="assignModal"]')) checkQuotaFit();
  });

  render();
})();
