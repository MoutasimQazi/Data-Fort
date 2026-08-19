/* lead.js — one lead, worked by the rep it is assigned to.
 *
 * Same rules as my-leads: masked by default, reveal costs quota, every
 * action logged, watermark on screen. The addition here is the email
 * relay, which is how "block email exfiltration" is actually delivered
 * — not by trying to stop the rep using Gmail, but by never giving the
 * browser the address in the first place (requirements 7.1).
 */
(function () {
  'use strict';

  var M = window.MOCK;
  var D = window.Datafort;

  window.Datafort.session = M.repSession;
  var ME = window.Datafort.session;

  var me = M.users.filter(function (u) { return u.id === ME.id; })[0];
  var quota = { limit: me.quota, used: me.usedToday };

  var id = new URLSearchParams(location.search).get('id');
  var lead = M.leads.filter(function (l) { return l.id === id; })[0];

  /* A rep reaching a lead that is not theirs is not a 404 — it is an
   * access violation, and it is logged as one. Server-side the endpoint
   * must refuse on ownership before it refuses on existence, or the
   * error message itself tells the rep which lead IDs are real. */
  if (!lead || lead.ownerId !== ME.id) {
    D.securityEvent('lead_access_denied', id || 'none');
    document.getElementById('content').innerHTML =
      '<div class="card" style="grid-column:1/-1"><div class="empty">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">' +
        '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>' +
        '<h3>Not available</h3>' +
        '<p>This lead is not assigned to you. The attempt has been recorded.</p>' +
        '<a class="btn btn--ghost btn--sm" href="my-leads.html">Back to my leads</a>' +
      '</div></div>';
    return;
  }


  /* ══ Quota ═════════════════════════════════════════════════════ */

  function paintQuota() {
    var pct = quota.limit ? Math.min(100, (quota.used / quota.limit) * 100) : 0;
    document.getElementById('quotaText').textContent = quota.used + ' / ' + quota.limit;
    var fill = document.getElementById('quotaFill');
    fill.style.width = pct + '%';
    fill.dataset.level = pct >= 100 ? 'danger' : pct >= 80 ? 'warn' : 'ok';
  }


  /* ══ Reveal (mock — see my-leads.js for the production contract) ══ */

  function fakeValue(field) {
    if (field === 'phone') {
      var h = 0;
      for (var i = 0; i < lead.id.length; i++) h = (h * 31 + lead.id.charCodeAt(i)) | 0;
      var digits = String(Math.abs(h) % 100000000).padStart(8, '0');
      return '+91 ' + digits.slice(0, 5) + ' ' + digits.slice(5);
    }
    return lead.name.toLowerCase().replace(/[^a-z]/g, '.') + '@' +
           lead.company.toLowerCase().replace(/[^a-z]/g, '') + '.com';
  }

  function bakeImage(text) {
    var scale = window.devicePixelRatio || 1, pad = 6, size = 13;
    var probe = document.createElement('canvas').getContext('2d');
    probe.font = '600 ' + size + 'px Inter, Segoe UI, sans-serif';
    var w = Math.ceil(probe.measureText(text).width) + pad * 2;
    var h = size + pad * 2;

    var c = document.createElement('canvas');
    c.width = w * scale; c.height = h * scale;
    var ctx = c.getContext('2d');
    ctx.scale(scale, scale);

    var dark = document.documentElement.getAttribute('data-theme') === 'dark' ||
      (!document.documentElement.getAttribute('data-theme') &&
       matchMedia('(prefers-color-scheme: dark)').matches);

    ctx.fillStyle = dark ? '#18243C' : '#F4F6F8';
    ctx.fillRect(0, 0, w, h);
    ctx.fillStyle = dark ? '#EAF0F8' : '#14213D';
    ctx.font = '600 ' + size + 'px Inter, Segoe UI, sans-serif';
    ctx.textBaseline = 'middle';
    ctx.fillText(text, pad, h / 2);

    ctx.save();
    ctx.globalAlpha = .34;
    ctx.fillStyle = dark ? '#9CC0FC' : '#1E6BF1';
    ctx.font = '600 8px Inter, Segoe UI, sans-serif';
    ctx.rotate(-0.18);
    var tag = ME.name + ' · ' + ME.id;
    for (var y = -h; y < h * 2; y += 13) {
      for (var x = -20; x < w + 40; x += ctx.measureText(tag).width + 16) ctx.fillText(tag, x, y);
    }
    ctx.restore();
    return c.toDataURL('image/png');
  }

  var revealed = {};

  function contactCell(field) {
    var masked = field === 'phone' ? lead.phoneMasked : lead.emailMasked;

    if (revealed[field]) {
      return '<span class="revealed"><img src="' + bakeImage(fakeValue(field)) +
             '" alt="Revealed ' + field + '"></span>';
    }

    var spent = quota.used >= quota.limit;
    return '<span class="masked">' + D.escape(masked) +
      '<button class="reveal" data-field="' + field + '"' + (spent ? ' disabled' : '') +
      ' aria-label="Reveal ' + field + '" title="Uses one daily reveal">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>' +
      '</svg></button></span>';
  }


  /* ══ Render ════════════════════════════════════════════════════ */

  function badge(s) {
    var label = { new: 'New', working: 'Working', won: 'Won', lost: 'Lost' }[s] || s;
    return '<span class="badge badge--' + s + '">' + label + '</span>';
  }

  function render() {
    document.getElementById('leadName').textContent = lead.name;
    document.getElementById('leadSub').textContent =
      lead.company + ' · ' + lead.designation + ' · ' + lead.city;
    document.getElementById('statusBadge').innerHTML = badge(lead.status);
    document.getElementById('statusSelect').value = lead.status;

    var rows = [
      ['Phone',       contactCell('phone')],
      ['Email',       contactCell('email')],
      ['Company',     D.escape(lead.company)],
      ['Designation', D.escape(lead.designation)],
      ['Industry',    D.escape(lead.industry)],
      ['Company size',D.escape(lead.companySize)],
      ['City',        D.escape(lead.city)],
      ['Source',      D.escape(lead.source)],
      ['Acquired',    D.ago(lead.acquiredDate)],
      ['Last contacted', lead.lastContacted ? D.ago(lead.lastContacted) :
        '<span style="color:var(--text-faint)">Never</span>']
    ];

    document.getElementById('details').innerHTML = rows.map(function (r) {
      return '<dt style="color:var(--text-muted)">' + r[0] + '</dt><dd style="margin:0">' + r[1] + '</dd>';
    }).join('');

    document.getElementById('emailTo').innerHTML =
      D.escape(lead.emailMasked) +
      ' <span style="font-size:11.5px;color:var(--text-faint)">(hidden)</span>';

    paintQuota();
  }

  var activity = [
    { text: 'Lead assigned to you', at: lead.acquiredDate, level: 'grey' }
  ];
  if (lead.lastContacted) {
    activity.unshift({ text: 'Call logged — no answer', at: lead.lastContacted, level: 'grey' });
  }

  function renderActivity() {
    document.getElementById('activity').innerHTML = activity.map(function (a) {
      return '<div class="feed__item">' +
        '<div class="feed__dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
        'stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>' +
        '<div class="feed__text">' + D.escape(a.text) +
        '<div class="feed__meta">' + D.ago(a.at) + '</div></div></div>';
    }).join('');
  }


  /* ══ Actions ═══════════════════════════════════════════════════ */

  document.getElementById('details').addEventListener('click', function (e) {
    var btn = e.target.closest('.reveal');
    if (!btn || btn.disabled) return;

    if (quota.used >= quota.limit) {
      D.toast('Daily reveal quota spent.', 'error');
      return;
    }

    quota.used++;
    revealed[btn.dataset.field] = true;
    D.securityEvent('contact_revealed', lead.id + '/' + btn.dataset.field);
    render();
  });

  document.getElementById('saveStatus').addEventListener('click', function () {
    lead.status = document.getElementById('statusSelect').value;
    var note = document.getElementById('noteBox').value.trim();

    activity.unshift({ text: 'Status set to ' + lead.status + (note ? ' — ' + note : ''),
                       at: new Date().toISOString(), level: 'grey' });
    document.getElementById('noteBox').value = '';

    render();
    renderActivity();
    D.toast('Status updated. (Not saved — no API yet.)', 'ok');
  });

  document.getElementById('logCallBtn').addEventListener('click', function () {
    lead.lastContacted = new Date().toISOString();
    activity.unshift({ text: 'Call logged', at: lead.lastContacted, level: 'grey' });
    render();
    renderActivity();
    D.toast('Call logged.', 'ok');
  });

  document.getElementById('sendMail').addEventListener('click', function () {
    var subject = document.getElementById('mailSubject').value.trim();
    var body = document.getElementById('mailBody').value.trim();

    if (!subject || !body) {
      D.toast('Subject and message are both required.', 'error');
      return;
    }

    /* Production: POST to api/lead-email.php, which looks the address up
     * server-side, sends, and writes the audit row. The rep's browser
     * never sees the recipient. */
    activity.unshift({ text: 'Email sent via relay — "' + subject + '"',
                       at: new Date().toISOString(), level: 'grey' });
    document.getElementById('mailSubject').value = '';
    document.getElementById('mailBody').value = '';
    renderActivity();
    D.toast('Queued for delivery through Datafort. (Not sent — no API yet.)', 'ok');
  });

  // Single column on narrow screens; the two-column grid is desktop only.
  if (window.matchMedia('(max-width: 900px)').matches) {
    document.getElementById('content').style.gridTemplateColumns = '1fr';
  }

  render();
  renderActivity();
})();
