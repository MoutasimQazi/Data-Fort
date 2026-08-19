/* my-leads.js — the rep's only screen.
 *
 * This is where the whole security model meets the user: assignment
 * scoping, masking, the admin-set daily quota, the reveal audit trail
 * and the baked-in watermark. If the model has a design flaw it shows
 * up on this page first.
 */
(function () {
  'use strict';

  /* This page is the rep view, so it runs as the rep rather than the
   * admin in MOCK.session. Set before DOMContentLoaded so the watermark
   * and the sidebar footer paint with the right identity. */
  window.Datafort.session = window.MOCK.repSession;

  var ME = window.Datafort.session;

  var rowsEl   = document.getElementById('rows');
  var emptyEl  = document.getElementById('emptyState');
  var countEl  = document.getElementById('leadCount');
  var qEl      = document.getElementById('q');
  var filterEl = document.getElementById('statusFilter');
  var quotaTxt = document.getElementById('quotaText');
  var quotaFill= document.getElementById('quotaFill');
  var quotaNote= document.getElementById('quotaNote');
  var quotaAlert = document.getElementById('quotaAlert');

  var me = window.MOCK.users.filter(function (u) { return u.id === ME.id; })[0];

  /* Quota state. Server-authoritative in production — this copy exists
   * only so the UI can grey out a button before the round trip. The
   * API must re-check on every reveal; a client that lies about its
   * remaining count must still be refused. */
  var quota = { limit: me.quota, used: me.usedToday };

  var leads = window.MOCK.forUser(ME.id);

  /* Which fields this session has already revealed. Server-side this is
   * a row in the audit log, not a variable — reloading the page must
   * not hand back a free look. */
  var revealed = {};


  /* ══ Quota meter ═══════════════════════════════════════════════ */

  function paintQuota() {
    var left = Math.max(0, quota.limit - quota.used);
    var pct  = quota.limit ? Math.min(100, (quota.used / quota.limit) * 100) : 0;

    quotaTxt.textContent = quota.used + ' / ' + quota.limit;
    quotaFill.style.width = pct + '%';

    quotaFill.dataset.level = pct >= 100 ? 'danger' : pct >= 80 ? 'warn' : 'ok';
    quotaNote.textContent = left > 0
      ? left + ' left today · resets at midnight'
      : 'Quota spent · resets at midnight';

    if (left === 0) {
      quotaAlert.hidden = false;
      quotaAlert.textContent =
        'You have used all ' + quota.limit + ' contact reveals for today. ' +
        'Your remaining leads stay assigned to you and the quota resets at midnight. ' +
        'If you need more, ask your administrator to raise your daily limit.';
    } else {
      quotaAlert.hidden = true;
    }

    /* Reflect exhaustion on every remaining button without a full
     * re-render. Already-revealed cells have no button left — the whole
     * cell was replaced by the baked image. */
    document.querySelectorAll('.reveal').forEach(function (b) {
      b.disabled = left === 0;
    });
  }


  /* ══ Mock reveal ═══════════════════════════════════════════════
   *
   * PRODUCTION: api/lead-reveal.php checks the quota, writes the audit
   * row, and responds with a PNG of the value with the watermark
   * rendered into the same pixels. The browser never receives the value
   * as text, so there is no node to select, copy, or read out of the
   * DOM. See requirements 7.3, "Baked-in watermark".
   *
   * MOCK: the same picture is drawn here with canvas so the interaction
   * can be reviewed. The plain value below only exists because there is
   * no server yet — it must NOT survive into the real client.
   */
  function fakeValue(lead, field) {
    if (field === 'phone') {
      var digits = String(Math.abs(hash(lead.id)) % 100000000).padStart(8, '0');
      return '+91 ' + digits.slice(0, 5) + ' ' + digits.slice(5);
    }
    var slug = lead.name.toLowerCase().replace(/[^a-z]/g, '.');
    return slug + '@' + lead.company.toLowerCase().replace(/[^a-z]/g, '') + '.com';
  }

  function hash(str) {
    var h = 0;
    for (var i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) | 0;
    return h;
  }

  function bakeImage(text) {
    var scale = window.devicePixelRatio || 1;
    var pad = 6, fontSize = 13;

    var probe = document.createElement('canvas').getContext('2d');
    probe.font = '600 ' + fontSize + 'px Inter, Segoe UI, sans-serif';
    var w = Math.ceil(probe.measureText(text).width) + pad * 2;
    var h = fontSize + pad * 2;

    var c = document.createElement('canvas');
    c.width = w * scale;
    c.height = h * scale;
    var ctx = c.getContext('2d');
    ctx.scale(scale, scale);

    var dark = document.documentElement.getAttribute('data-theme') === 'dark' ||
               (!document.documentElement.getAttribute('data-theme') &&
                matchMedia('(prefers-color-scheme: dark)').matches);

    ctx.fillStyle = dark ? '#18243C' : '#F4F6F8';
    ctx.fillRect(0, 0, w, h);

    // The value.
    ctx.fillStyle = dark ? '#EAF0F8' : '#14213D';
    ctx.font = '600 ' + fontSize + 'px Inter, Segoe UI, sans-serif';
    ctx.textBaseline = 'middle';
    ctx.fillText(text, pad, h / 2);

    /* The watermark, drawn over the value in the same bitmap. Cropping
     * the mark out of a screenshot crops the number with it. */
    ctx.save();
    ctx.globalAlpha = 0.34;
    ctx.fillStyle = dark ? '#9CC0FC' : '#1E6BF1';
    ctx.font = '600 8px Inter, Segoe UI, sans-serif';
    ctx.rotate(-0.18);
    var tag = ME.name + ' · ' + ME.id;
    for (var y = -h; y < h * 2; y += 13) {
      for (var x = -20; x < w + 40; x += ctx.measureText(tag).width + 16) {
        ctx.fillText(tag, x, y);
      }
    }
    ctx.restore();

    return c.toDataURL('image/png');
  }


  /* ══ Reveal ════════════════════════════════════════════════════ */

  function reveal(btn) {
    var leadId = btn.dataset.lead;
    var field  = btn.dataset.field;
    var key    = leadId + ':' + field;

    if (quota.used >= quota.limit) {
      window.Datafort.toast('Daily reveal quota spent.', 'error');
      return;
    }

    var lead = leads.filter(function (l) { return l.id === leadId; })[0];
    if (!lead) return;

    quota.used++;
    revealed[key] = true;

    /* Logged as a security event, not just an analytics ping. The reveal
     * IS the audit trail — a lead list leaking is reconstructed from
     * exactly these rows. */
    window.Datafort.securityEvent('contact_revealed', leadId + '/' + field);

    var cell = btn.closest('td');
    cell.innerHTML =
      '<span class="revealed">' +
        '<img src="' + bakeImage(fakeValue(lead, field)) + '" alt="Revealed contact value">' +
      '</span>';

    paintQuota();
  }


  /* ══ Render ════════════════════════════════════════════════════ */

  function badge(status) {
    var label = { new: 'New', working: 'Working', won: 'Won', lost: 'Lost' }[status] || status;
    return '<span class="badge badge--' + status + '">' + label + '</span>';
  }

  function maskCell(lead, field) {
    var key = lead.id + ':' + field;
    var masked = field === 'phone' ? lead.phoneMasked : lead.emailMasked;

    if (revealed[key]) {
      return '<span class="revealed"><img src="' +
             bakeImage(fakeValue(lead, field)) + '" alt="Revealed contact value"></span>';
    }

    var spent = quota.used >= quota.limit;
    return '<span class="masked">' + window.Datafort.escape(masked) +
      '<button class="reveal" data-lead="' + lead.id + '" data-field="' + field + '"' +
      (spent ? ' disabled' : '') +
      ' aria-label="Reveal ' + field + ' — uses one of your daily reveals" title="Uses one daily reveal">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>' +
      '</button></span>';
  }

  function render() {
    var term = qEl.value.trim().toLowerCase();
    var want = filterEl.value;

    var list = leads.filter(function (l) {
      if (want && l.status !== want) return false;
      if (!term) return true;
      return (l.name + ' ' + l.company).toLowerCase().indexOf(term) !== -1;
    });

    countEl.textContent = list.length + ' of ' + leads.length + ' leads assigned to you';
    emptyEl.hidden = list.length > 0;

    rowsEl.innerHTML = list.map(function (l) {
      return '<tr>' +
        '<td><div class="cellstack"><span>' + window.Datafort.escape(l.name) + '</span>' +
          '<span class="sub">' + window.Datafort.escape(l.company) + ' · ' +
          window.Datafort.escape(l.designation) + '</span></div></td>' +
        '<td>' + maskCell(l, 'phone') + '</td>' +
        '<td>' + maskCell(l, 'email') + '</td>' +
        '<td>' + window.Datafort.escape(l.city) + '</td>' +
        '<td>' + badge(l.status) + '</td>' +
        '<td>' + (l.lastContacted ? window.Datafort.ago(l.lastContacted) :
                  '<span style="color:var(--text-faint)">Never</span>') + '</td>' +
        '<td class="shrink"><a class="btn btn--ghost btn--sm" href="lead.html?id=' +
          encodeURIComponent(l.id) + '">Open</a></td>' +
      '</tr>';
    }).join('');
  }


  /* ══ Wiring ════════════════════════════════════════════════════ */

  rowsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.reveal');
    if (btn && !btn.disabled) reveal(btn);
  });

  // Re-render on input rather than debouncing: 240 rows of client-side
  // filtering is nothing. Revisit when this list comes from the API.
  qEl.addEventListener('input', render);
  filterEl.addEventListener('change', render);

  render();
  paintQuota();
})();
