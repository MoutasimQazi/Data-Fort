/* lead.js — one lead, worked by the rep it is assigned to.
 *
 * Same rules as my-leads: masked by default, reveal costs quota, every
 * action logged, watermark on screen. The addition is the email relay —
 * how "block email exfiltration" is actually delivered: the browser is
 * never given the address, so there is nothing to paste elsewhere.
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var ref = new URLSearchParams(location.search).get('id') || '';

  var lead = null;
  var quota = { limit: 0, used: 0 };
  var activity = [];

  /* Which fields are unmasked is not tracked here. Only one value is
   * ever on screen and reveal.js owns it — see the note at the top of
   * that file about why this is a display rule, not a control. */


  /* ══ Not available ═════════════════════════════════════════════ */

  /* A rep reaching a lead that is not theirs is an access violation,
   * not a 404, and the server logs it as one. It answers identically
   * whether the lead is unowned or does not exist — otherwise the error
   * message becomes a way to enumerate the lead table. */
  function notAvailable() {
    document.getElementById('content').innerHTML =
      '<div class="card" style="grid-column:1/-1"><div class="empty">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">' +
        '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>' +
        '<h3>Not available</h3>' +
        '<p>This lead is not assigned to you. The attempt has been recorded.</p>' +
        '<a class="btn btn--ghost btn--sm" href="my-leads.html">Back to my leads</a>' +
      '</div></div>';
  }


  /* ══ Quota ═════════════════════════════════════════════════════ */

  function paintQuota() {
    var pct = quota.limit ? Math.min(100, (quota.used / quota.limit) * 100) : 0;
    document.getElementById('quotaText').textContent = quota.used + ' / ' + quota.limit;
    var fill = document.getElementById('quotaFill');
    fill.style.width = pct + '%';
    fill.dataset.level = pct >= 100 ? 'danger' : pct >= 80 ? 'warn' : 'ok';
  }


  /* ══ Render ════════════════════════════════════════════════════ */

  function badge(s) {
    var label = { new: 'New', working: 'Working', won: 'Won', lost: 'Lost' }[s] || s;
    return '<span class="badge badge--' + D.escape(s) + '">' + label + '</span>';
  }

  function contactCell(field) {
    var masked = field === 'phone' ? lead.phoneMasked : lead.emailMasked;
    if (!masked) return '<span style="color:var(--text-faint)">Not on record</span>';

    var paid  = (lead.revealed || []).indexOf(field) !== -1;
    var spent = quota.limit <= 0 || quota.used >= quota.limit;

    return '<span class="masked">' + D.escape(masked) +
      // Already paid for stays clickable even with the quota spent —
      // the server does not charge twice.
      '<button class="reveal" data-field="' + field + '"' +
      (spent && !paid ? ' disabled' : '') +
      ' aria-label="Reveal ' + field +
        (paid ? ' — already revealed today, free to show again'
              : ' — uses one of your daily reveals') + '"' +
      ' title="' + (paid ? 'Already revealed today · free' : 'Uses one daily reveal') + '">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/>' +
      '<circle cx="12" cy="12" r="3"/></svg></button></span>';
  }

  function render() {
    document.getElementById('leadName').textContent = lead.name || 'Unnamed lead';
    document.getElementById('leadSub').textContent =
      [lead.company, lead.designation, lead.city].filter(Boolean).join(' · ');
    document.getElementById('statusBadge').innerHTML = badge(lead.status);
    document.getElementById('statusSelect').value = lead.status;

    // Third element tags the two contact rows so the reveal handler can
    // find the exact <dd> to swap without re-rendering the whole list.
    var rows = [
      ['Phone',          contactCell('phone'), 'phone'],
      ['Email',          contactCell('email'), 'email'],
      ['Company',        D.escape(lead.company || '—')],
      ['Designation',    D.escape(lead.designation || '—')],
      ['Industry',       D.escape(lead.industry || '—')],
      ['Company size',   D.escape(lead.companySize || '—')],
      ['City',           D.escape(lead.city || '—')],
      ['Source',         D.escape(lead.source || '—')],
      ['Acquired',       lead.acquiredDate ? D.ago(lead.acquiredDate) : '—'],
      ['Last contacted', lead.lastContacted ? D.ago(lead.lastContacted) :
        '<span style="color:var(--text-faint)">Never</span>']
    ];

    document.getElementById('details').innerHTML = rows.map(function (r) {
      return '<dt style="color:var(--text-muted)">' + r[0] + '</dt>' +
             '<dd style="margin:0"' + (r[2] ? ' data-field="' + r[2] + '"' : '') +
             '>' + r[1] + '</dd>';
    }).join('');

    document.getElementById('emailTo').innerHTML =
      D.escape(lead.emailMasked || 'No address on record') +
      (lead.emailMasked ? ' <span style="font-size:11.5px;color:var(--text-faint)">(hidden)</span>' : '');

    paintQuota();
  }

  function renderActivity() {
    document.getElementById('activity').innerHTML = activity.length
      ? activity.map(function (a) {
          return '<div class="feed__item">' +
            '<div class="feed__dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/>' +
            '<path d="M12 7v5l3 2"/></svg></div>' +
            '<div class="feed__text">' + D.escape(a.text) +
            '<div class="feed__meta">' + D.ago(a.at) + '</div></div></div>';
        }).join('')
      : '<div class="empty" style="padding:28px"><p>No activity recorded yet.</p></div>';
  }


  /* ══ Actions ═══════════════════════════════════════════════════ */

  document.getElementById('details').addEventListener('click', function (e) {
    var btn = e.target.closest('.reveal');
    if (!btn || btn.disabled) return;

    var field = btn.dataset.field;
    btn.disabled = true;

    API.reveal(lead.id, field).then(function (res) {
      if (res.quota) { quota.limit = res.quota.limit; quota.used = res.quota.used; }
      else quota.used++;

      // Mark it paid so re-showing stays free and the button stays live.
      lead.revealed = lead.revealed || [];
      if (lead.revealed.indexOf(field) === -1) lead.revealed.push(field);

      /* Retire the previously revealed field BEFORE painting this one.
       *
       * claim() tears down the old reveal as its first act, so painting
       * first meant the outgoing field's remask ran immediately after
       * and undid the paint. Reveal a phone, then an email, and the
       * email never appeared — the phone's teardown had wiped it. Doing
       * the teardown up front leaves claim()'s own clear() a no-op. */
      DatafortReveal.clear(true);

      var dd = document.querySelector('#details [data-field="' + field + '"]');

      /* A response with neither an image nor a value left the button
       * disabled forever, with nothing on screen to explain why — the
       * rep had spent a reveal and had no way to ask for it again
       * short of reloading. Put the cell back instead. */
      if (!dd || (!res.image && !res.value)) {
        if (dd) dd.innerHTML = contactCell(field);
        else btn.disabled = false;
        paintQuota();
        D.toast('That value could not be displayed. Try again.', 'error');
        return;
      }

      if (dd) {
        if (res.image) {
          dd.innerHTML = '<span class="revealed">' +
            '<img src="' + res.image + '" alt="Revealed ' + field + '">' +
            '<span class="revealed__ttl" data-countdown></span></span>';
        } else if (res.value) {
          dd.innerHTML = '<span class="revealed" data-plain="true">' +
            D.escape(res.value) +
            '<span class="revealed__ttl" data-countdown></span></span>';
        }

        /* Re-mask THIS field only, the way my-leads.js does it.
         *
         * This used to be render(), which rebuilt the entire detail
         * list. That was the root of the bug above, and it also threw
         * away an unsaved selection in the status dropdown every time
         * a reveal timed out. contactCell() re-reads lead.revealed, so
         * the restored button still says "already revealed today". */
        DatafortReveal.claim(lead.id + ':' + field, res.image || null,
          function () {
            var cell = document.querySelector('#details [data-field="' + field + '"]');
            if (cell) cell.innerHTML = contactCell(field);
          },
          function (left) {
            var el = dd.querySelector('[data-countdown]');
            if (el) el.textContent = left + 's';
          });
      }
      paintQuota();

    }).catch(function (err) {
      btn.disabled = false;
      if (err.status === 429) {
        if (err.payload && err.payload.quota) {
          quota.limit = err.payload.quota.limit;
          quota.used  = err.payload.quota.used;
        }
        paintQuota();
        /* 429 is returned by TWO different rules in lead-reveal.php: a
         * spent daily quota, and the one-reveal-per-2-seconds burst
         * guard. This hardcoded the quota message for both, so hitting
         * the burst guard — which is what revealing a phone and then an
         * email does — reported "Daily reveal quota spent" to a rep who
         * had used 2 of 500. The server already sends the right message
         * for each case; use it. my-leads.js has always done this. */
        D.toast(err.message || 'Reveal refused.', 'error');
        return;
      }
      D.fail(err);
    });
  });

  document.getElementById('saveStatus').addEventListener('click', function () {
    var status = document.getElementById('statusSelect').value;
    var note   = document.getElementById('noteBox').value.trim();
    var btn    = this;

    btn.disabled = true;

    API.updateLead({ lead: lead.id, status: status, note: note })
      .then(function () {
        lead.status = status;
        activity.unshift({
          text: 'Status set to ' + status + (note ? ' — ' + note : ''),
          at: new Date().toISOString()
        });
        document.getElementById('noteBox').value = '';
        render();
        renderActivity();
        D.toast('Saved.', 'ok');
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; });
  });

  document.getElementById('logCallBtn').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;

    API.updateLead({ lead: lead.id, logCall: true })
      .then(function () {
        lead.lastContacted = new Date().toISOString();
        activity.unshift({ text: 'Call logged', at: lead.lastContacted });
        render();
        renderActivity();
        D.toast('Call logged.', 'ok');
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; });
  });

  document.getElementById('sendMail').addEventListener('click', function () {
    var subject = document.getElementById('mailSubject').value.trim();
    var body    = document.getElementById('mailBody').value.trim();
    var btn     = this;

    if (!subject || !body) {
      D.toast('Subject and message are both required.', 'error');
      return;
    }

    btn.disabled = true;

    /* The address is looked up server-side and never sent here. Replies
     * land in the relay inbox, not the rep's own mailbox — a real
     * workflow cost, and the price of the address never leaving. */
    API.sendEmail({ lead: lead.id, subject: subject, body: body })
      .then(function () {
        activity.unshift({
          text: 'Email sent via relay — "' + subject + '"',
          at: new Date().toISOString()
        });
        document.getElementById('mailSubject').value = '';
        document.getElementById('mailBody').value = '';
        lead.lastContacted = new Date().toISOString();
        render();
        renderActivity();
        D.toast('Sent through Datafort.', 'ok');
      })
      .catch(D.fail)
      .then(function () { btn.disabled = false; });
  });


  /* ══ Load ══════════════════════════════════════════════════════ */

  // Blob release, blur handling and the re-mask timer live in reveal.js.

  if (window.matchMedia('(max-width: 900px)').matches) {
    document.getElementById('content').style.gridTemplateColumns = '1fr';
  }

  D.ready(function (session) {
    quota.limit = session.quota ? session.quota.limit : 0;
    quota.used  = session.quota ? session.quota.used  : 0;

    if (!ref) { notAvailable(); return; }

    /* There is no single-lead endpoint; the list endpoint already scopes
     * to the signed-in rep, so searching it by ref gives the same
     * guarantee without a second server-side ownership check to keep in
     * sync with the first. */
    API.leads({ q: ref, limit: 50 }).then(function (res) {
      lead = (res.leads || []).filter(function (l) { return l.id === ref; })[0];

      if (!lead) {
        D.securityEvent('lead_access_denied', ref);
        notAvailable();
        return;
      }

      activity = [];
      if (lead.acquiredDate) {
        activity.push({ text: 'Lead added to Datafort', at: lead.acquiredDate });
      }
      if (lead.lastContacted) {
        activity.unshift({ text: 'Last contacted', at: lead.lastContacted });
      }

      render();
      renderActivity();
    }).catch(D.fail);
  });
})();
