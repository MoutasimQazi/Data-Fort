/* leads.js — the admin's master lead table.
 *
 * The only screen showing every lead at once, which is why it is
 * admin-only. Assignment happens here, and assignment is the primary
 * containment mechanism (requirements section 4).
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var PAGE = 50;
  var offset = 0;

  var rowsEl    = document.getElementById('rows');
  var emptyEl   = document.getElementById('emptyState');
  var countLine = document.getElementById('countLine');
  var shownLine = document.getElementById('shownLine');
  var moreBtn   = document.getElementById('moreBtn');

  var qEl      = document.getElementById('q');
  var ownerEl  = document.getElementById('ownerFilter');
  var statusEl = document.getElementById('statusFilter');
  var sourceEl = document.getElementById('sourceFilter');

  var selbar   = document.getElementById('selbar');
  var selCount = document.getElementById('selCount');
  var selAll   = document.getElementById('selAll');

  var selected = new Set();
  var loaded = [];      // rows currently rendered
  var total = 0;
  var users = [];


  /* ══ Filters ═══════════════════════════════════════════════════ */

  function fillFilters() {
    var reps = users.filter(function (u) { return u.role === 'rep'; });

    ownerEl.innerHTML =
      '<option value="">All owners</option>' +
      '<option value="__none">Unassigned</option>' +
      reps.map(function (u) {
        return '<option value="' + u.userId + '">' + D.escape(u.name) + '</option>';
      }).join('');

    document.getElementById('assignTo').innerHTML = reps.length
      ? reps.map(function (u) {
          return '<option value="' + u.userId + '">' + D.escape(u.name) +
                 ' — ' + u.assigned + ' assigned, quota ' + u.quota + '/day</option>';
        }).join('')
      : '<option value="">No sales reps exist yet</option>';
  }

  function fillSources() {
    // Derived from what came back rather than a separate endpoint.
    var seen = [];
    loaded.forEach(function (l) {
      if (l.source && seen.indexOf(l.source) === -1) seen.push(l.source);
    });

    var current = sourceEl.value;
    sourceEl.innerHTML = '<option value="">All sources</option>' +
      seen.map(function (s) {
        return '<option value="' + D.escape(s) + '">' + D.escape(s) + '</option>';
      }).join('');
    if (current) sourceEl.value = current;
  }


  /* ══ Render ════════════════════════════════════════════════════ */

  function badge(s) {
    var label = { new: 'New', working: 'Working', won: 'Won', lost: 'Lost' }[s] || s;
    return '<span class="badge badge--' + D.escape(s) + '">' + label + '</span>';
  }

  function render() {
    countLine.textContent = total.toLocaleString() + ' lead' + (total === 1 ? '' : 's');
    emptyEl.hidden = loaded.length > 0;
    moreBtn.hidden = loaded.length >= total;
    shownLine.textContent = total
      ? 'Showing ' + loaded.length + ' of ' + total.toLocaleString()
      : '';

    rowsEl.innerHTML = loaded.map(function (l) {
      var owner = l.ownerName
        ? D.escape(l.ownerName)
        : '<span style="color:var(--text-faint)">Unassigned</span>';

      /* Decoys are marked for the admin only. leads-list.php returns
       * honeytoken:false for reps — a rep who can spot a seeded record
       * simply avoids it, and the attribution trick stops working. */
      var flag = l.honeytoken
        ? ' <span class="badge badge--plain badge--idle" title="Seeded decoy record — do not delete">decoy</span>'
        : '';

      return '<tr data-id="' + D.escape(l.id) + '">' +
        '<td class="shrink"><input type="checkbox" class="rowsel" value="' + D.escape(l.id) + '"' +
          (selected.has(l.id) ? ' checked' : '') +
          ' aria-label="Select ' + D.escape(l.name || l.id) + '"></td>' +
        '<td><div class="cellstack"><span>' + D.escape(l.name || 'Unnamed') + flag + '</span>' +
          '<span class="sub">' + D.escape(l.company || '') +
          (l.city ? ' · ' + D.escape(l.city) : '') + '</span></div></td>' +
        // Masked even for the admin: no operational reason to read 240
        // numbers, and every unmasking in the product goes through one door.
        '<td><div class="cellstack"><span class="masked">' +
          D.escape(l.phoneMasked || '—') + '</span>' +
          '<span class="sub">' + D.escape(l.emailMasked || '') + '</span></div></td>' +
        '<td>' + owner + '</td>' +
        '<td><div class="cellstack"><span>' + D.escape(l.source || '—') + '</span>' +
          '<span class="sub">' + (l.acquiredDate ? D.ago(l.acquiredDate) : '') + '</span></div></td>' +
        '<td>' + badge(l.status) + '</td>' +
        '<td class="num">' + D.money(l.sourceCost) + '</td>' +
        // Admins had no way to open a single lead at all. The detail page
        // is shared with reps; lead.js scopes what it shows by role.
        '<td class="shrink table-action"><a class="btn btn--ghost btn--sm" href="lead.html?id=' +
          encodeURIComponent(l.id) + '">Open</a></td>' +
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
    if (box.checked) selected.add(box.value); else selected.delete(box.value);
    syncSelectionUi();
  });

  selAll.addEventListener('change', function () {
    rowsEl.querySelectorAll('.rowsel').forEach(function (b) {
      b.checked = selAll.checked;
      if (selAll.checked) selected.add(b.value); else selected.delete(b.value);
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
    var rep = users.filter(function (u) {
      return String(u.userId) === assignTo.value;
    })[0];

    if (!rep || !selected.size || !rep.quota) { assignWarn.hidden = true; return; }

    var days = Math.ceil(selected.size / rep.quota);
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
    if (!assignTo.value) { D.toast('Choose a rep first.', 'error'); return; }

    var btn = this;
    btn.disabled = true;

    API.assignLeads({
      leads: Array.from(selected),
      userId: parseInt(assignTo.value, 10)
    }).then(function (res) {
      selected.clear();
      D.closeModal('assignModal');
      D.toast(res.changed + ' leads assigned.', 'ok');
      if (res.warning) D.toast(res.warning, 'error', 8000);
      reload();
    }).catch(D.fail).then(function () { btn.disabled = false; });
  });

  document.getElementById('recallBtn').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;

    /* Recall does not undo anything the rep already saw — the reveal
     * ledger and the audit log stay exactly as they are. Taking the
     * leads back does not un-see the numbers. */
    API.assignLeads({ leads: Array.from(selected), userId: null })
      .then(function (res) {
        selected.clear();
        D.toast(res.changed + ' leads recalled to the pool.', 'ok');
        reload();
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; });
  });


  /* ══ Load ══════════════════════════════════════════════════════ */

  function params() {
    return {
      q: qEl.value.trim(),
      owner: ownerEl.value,
      status: statusEl.value,
      source: sourceEl.value,
      limit: PAGE,
      offset: offset
    };
  }

  function reload() {
    offset = 0;
    loaded = [];
    fetchPage();
  }

  function fetchPage() {
    API.leads(params()).then(function (res) {
      loaded = loaded.concat(res.leads);
      total = res.total;
      fillSources();
      render();
    }).catch(D.fail);
  }

  var searchTimer = null;
  qEl.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(reload, 280);
  });
  [ownerEl, statusEl, sourceEl].forEach(function (el) {
    el.addEventListener('change', reload);
  });

  moreBtn.addEventListener('click', function () {
    offset += PAGE;
    fetchPage();
  });

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-modal-open="assignModal"]')) checkQuotaFit();
  });

  D.ready(function () {
    API.users().then(function (res) {
      users = res.users;
      fillFilters();
      reload();
    }).catch(D.fail);
  });
})();
