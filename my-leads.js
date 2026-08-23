/* my-leads.js — the rep's only screen.
 *
 * Where the security model meets the user: assignment scoping, masking,
 * the admin-set daily quota, the reveal audit trail and the baked-in
 * watermark. If the model has a design flaw it shows up here first.
 *
 * Everything sensitive is decided server-side. This file cannot widen
 * its own scope: leads-list.php pins a rep to their own rows, and
 * lead-reveal.php re-counts the quota on every call regardless of what
 * the counter below says.
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var rowsEl   = document.getElementById('rows');
  var emptyEl  = document.getElementById('emptyState');
  var countEl  = document.getElementById('leadCount');
  var qEl      = document.getElementById('q');
  var filterEl = document.getElementById('statusFilter');
  var quotaTxt = document.getElementById('quotaText');
  var quotaFill= document.getElementById('quotaFill');
  var quotaNote= document.getElementById('quotaNote');
  var quotaAlert = document.getElementById('quotaAlert');

  var quota = { limit: 0, used: 0 };
  var leads = [];
  var total = 0;

  /* Fields this rep has ALREADY spent a reveal on today, as "ref:field".
   * Comes from leads-list.php. Showing one of these again costs nothing,
   * because api/lead-reveal.php only charges the first time — so the
   * button stays live even once the daily quota is gone.
   *
   * Note this is the list of what was PAID FOR, not what is on screen.
   * Only one value is ever on screen, and DatafortReveal owns that. */
  var paidFor = [];


  /* ══ Quota ═════════════════════════════════════════════════════ */

  function paintQuota() {
    var left = Math.max(0, quota.limit - quota.used);
    var pct  = quota.limit ? Math.min(100, (quota.used / quota.limit) * 100) : 0;

    quotaTxt.textContent = quota.used + ' / ' + quota.limit;
    quotaFill.style.width = pct + '%';
    quotaFill.dataset.level = pct >= 100 ? 'danger' : pct >= 80 ? 'warn' : 'ok';

    quotaNote.textContent = left > 0
      ? left + ' left today · resets at midnight'
      : 'Quota spent · resets at midnight';

    if (left === 0 && quota.limit > 0) {
      quotaAlert.hidden = false;
      quotaAlert.textContent =
        'You have used all ' + quota.limit + ' contact reveals for today. ' +
        'Your remaining leads stay assigned to you and the quota resets at midnight. ' +
        'If you need more, ask your administrator to raise your daily limit.';
    } else if (quota.limit === 0) {
      quotaAlert.hidden = false;
      quotaAlert.textContent =
        'Your daily reveal quota is set to 0, so contact details cannot be ' +
        'unmasked. Ask your administrator to set a quota.';
    } else {
      quotaAlert.hidden = true;
    }

    /* A field already paid for today stays clickable even with the quota
     * spent — the server does not charge twice, and locking a rep out of
     * a number they already legitimately unmasked would push them
     * straight to screenshotting it next time. */
    document.querySelectorAll('.reveal').forEach(function (b) {
      var paid = paidFor.indexOf(b.dataset.lead + ':' + b.dataset.field) !== -1;
      b.disabled = (left === 0 || quota.limit <= 0) && !paid;
    });
  }


  /* ══ Reveal ════════════════════════════════════════════════════
   *
   * The server returns a PNG with the watermark rendered into the same
   * pixels — there is no text node here to select or copy, and cropping
   * the mark out of a screenshot crops the number with it.
   *
   * If GD is missing on the server, or the tenant has turned baking off
   * for accessibility, the response is JSON with a plain value and
   * watermarked:false. That is a genuine weakening, so it is rendered
   * differently rather than silently.
   */
  function reveal(btn) {
    var ref   = btn.dataset.lead;
    var field = btn.dataset.field;
    var cell  = btn.closest('td');
    var key   = ref + ':' + field;

    btn.disabled = true;

    API.reveal(ref, field).then(function (res) {
      /* Puts this cell back to its masked state. Handed to
       * DatafortReveal so it can undo this cell when something else is
       * revealed, or when the timer runs out. */
      function remask() {
        var row = rowsEl.querySelector('tr[data-ref="' + ref + '"]');
        if (!row) return;                      // list re-rendered underneath us
        var td = row.querySelector('td[data-field="' + field + '"]');
        if (td) td.innerHTML = maskedMarkup(ref, field, cellMask(ref, field));
      }

      if (res.image) {
        cell.innerHTML =
          '<span class="revealed">' +
            '<img src="' + res.image + '" alt="Revealed contact value">' +
            '<span class="revealed__ttl" data-countdown></span>' +
          '</span>';

        DatafortReveal.claim(key, res.image, remask, function (left) {
          var el = cell.querySelector('[data-countdown]');
          if (el) el.textContent = left + 's';
        });

      } else if (res.value) {
        /* Unwatermarked fallback — GD missing, or the tenant turned
         * baking off for accessibility. Marked in the DOM so it is
         * obvious in review that this path returns selectable text. */
        cell.innerHTML =
          '<span class="revealed" data-plain="true">' + D.escape(res.value) +
            '<span class="revealed__ttl" data-countdown></span></span>';

        DatafortReveal.claim(key, null, remask, function (left) {
          var el = cell.querySelector('[data-countdown]');
          if (el) el.textContent = left + 's';
        });
      }

      if (res.quota) {
        quota.limit = res.quota.limit;
        quota.used  = res.quota.used;
      }
      paintQuota();

    }).catch(function (err) {
      btn.disabled = false;

      if (err.status === 429) {
        // Server refused. Trust its numbers over ours — it may be a
        // spent daily quota or the burst limiter.
        if (err.payload && err.payload.quota) {
          quota.limit = err.payload.quota.limit;
          quota.used  = err.payload.quota.used;
        }
        paintQuota();
        D.toast(err.message || 'Daily reveal quota spent.', 'error');
        return;
      }
      D.fail(err);
    });
  }


  /* ══ Render ════════════════════════════════════════════════════ */

  function badge(status) {
    var label = { new: 'New', working: 'Working', won: 'Won', lost: 'Lost' }[status] || status;
    return '<span class="badge badge--' + D.escape(status) + '">' + label + '</span>';
  }

  /* The masked value for a field, looked up from the loaded list.
   * Needed by remask(), which fires long after the original render. */
  function cellMask(ref, field) {
    var lead = leads.filter(function (l) { return l.id === ref; })[0];
    if (!lead) return '';
    return field === 'phone' ? lead.phoneMasked : lead.emailMasked;
  }

  /* The masked cell, with its reveal button. One definition, used by
   * the first render and by every re-mask, so the two cannot drift. */
  function maskedMarkup(ref, field, masked) {
    if (!masked) return '<span style="color:var(--text-faint)">—</span>';

    // On this page a quota of 0 means blocked, not unlimited — that
    // reading is reserved for admins, who do not use my-leads.html.
    var spent = quota.limit <= 0 || quota.used >= quota.limit;
    var paid  = paidFor.indexOf(ref + ':' + field) !== -1;

    return '<span class="masked">' + D.escape(masked) +
      '<button class="reveal" data-lead="' + D.escape(ref) + '" data-field="' + field + '"' +
      // Something already paid for can always be shown again, even with
      // the daily quota spent — the server does not charge twice.
      (spent && !paid ? ' disabled' : '') +
      ' aria-label="Reveal ' + field +
        (paid ? ' — already revealed today, free to show again'
              : ' — uses one of your daily reveals') + '"' +
      ' title="' + (paid ? 'Already revealed today · free' : 'Uses one daily reveal') + '">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
        'stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/>' +
        '<circle cx="12" cy="12" r="3"/></svg>' +
      '</button></span>';
  }

  function contactCell(lead, field) {
    return maskedMarkup(lead.id, field,
      field === 'phone' ? lead.phoneMasked : lead.emailMasked);
  }

  function render() {
    countEl.textContent = leads.length
      ? leads.length + (total > leads.length ? ' of ' + total : '') +
        ' lead' + (total === 1 ? '' : 's') + ' assigned to you'
      : 'No leads assigned to you yet';

    emptyEl.hidden = leads.length > 0;

    // Any value on screen belongs to the list we are about to replace.
    DatafortReveal.clear(true);

    rowsEl.innerHTML = leads.map(function (l) {
      // data-ref / data-field let remask() find the exact cell again
      // after the row has been rebuilt.
      return '<tr data-ref="' + D.escape(l.id) + '">' +
        '<td><div class="cellstack"><span>' + D.escape(l.name || 'Unnamed') + '</span>' +
          '<span class="sub">' + D.escape(l.company || '') +
          (l.designation ? ' · ' + D.escape(l.designation) : '') + '</span></div></td>' +
        '<td data-field="phone">' + contactCell(l, 'phone') + '</td>' +
        '<td data-field="email">' + contactCell(l, 'email') + '</td>' +
        '<td>' + D.escape(l.city || '—') + '</td>' +
        '<td>' + badge(l.status) + '</td>' +
        '<td>' + (l.lastContacted ? D.ago(l.lastContacted) :
                  '<span style="color:var(--text-faint)">Never</span>') + '</td>' +
        '<td class="shrink table-action"><a class="btn btn--ghost btn--sm" href="lead.html?id=' +
          encodeURIComponent(l.id) + '">Open</a></td>' +
      '</tr>';
    }).join('');
  }


  /* ══ Load ══════════════════════════════════════════════════════ */

  var loading = false;

  function load() {
    if (loading) return;
    loading = true;

    API.leads({
      q: qEl.value.trim(),
      status: filterEl.value,
      limit: 200
    }).then(function (res) {
      leads = res.leads;
      total = res.total;

      /* leads-list.php returns `revealed` per lead: which fields this
       * rep already paid for today. The VALUE never comes with it — that
       * still costs a request to lead-reveal.php, it just does not cost
       * another unit of quota. */
      paidFor = [];
      leads.forEach(function (l) {
        (l.revealed || []).forEach(function (f) { paidFor.push(l.id + ':' + f); });
      });

      render();
      paintQuota();
    }).catch(D.fail).then(function () { loading = false; });
  }


  /* ══ Wiring ════════════════════════════════════════════════════ */

  rowsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.reveal');
    if (btn && !btn.disabled) reveal(btn);
  });

  // Debounced: this now hits the database rather than an array in memory.
  var searchTimer = null;
  qEl.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 280);
  });
  filterEl.addEventListener('change', load);

  // Blob release, blur handling and the re-mask timer all live in
  // reveal.js — nothing to clean up here.

  D.ready(function (session) {
    quota.limit = session.quota ? session.quota.limit : 0;
    quota.used  = session.quota ? session.quota.used  : 0;
    paintQuota();
    load();
  });
})();
