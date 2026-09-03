/* account.js — the signed-in user's own page.
 *
 * Two jobs: let someone change their own password without going via the
 * forgot-password email, and show them plainly what Datafort records
 * about them.
 *
 * Works for both roles, so the sidebar is built from the session rather
 * than written twice in the markup — shipping every admin link to every
 * rep's browser and hiding it with CSS is not a division of access.
 */
(function () {
  'use strict';

  var D = window.Datafort;
  var API = window.DatafortAPI;

  var ICONS = {
    dash:   '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>',
    leads:  '<path d="M3 6h18M3 12h18M3 18h18"/>',
    import: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
    users:  '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
    device: '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M2 20h20"/>',
    audit:  '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 15h6M9 11h3"/>',
    cog:    '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'
  };

  function link(href, icon, label) {
    return '<a class="navlink" href="' + href + '">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
      'stroke-linecap="round" stroke-linejoin="round">' + ICONS[icon] + '</svg>' +
      label + '</a>';
  }

  function buildNav(role) {
    var nav = document.getElementById('sideNav');

    nav.innerHTML = role === 'admin'
      ? link('index.html', 'dash', 'Dashboard') +
        link('leads.html', 'leads', 'Leads') +
        link('import.html', 'import', 'Import') +
        link('users.html', 'users', 'Users') +
        link('devices.html', 'device', 'Devices') +
        '<div class="side__label">Oversight</div>' +
        link('audit.html', 'audit', 'Audit Log') +
        link('settings.html', 'cog', 'Settings')
      : link('my-leads.html', 'users', 'My Leads');
  }


  /* ══ Facts ═════════════════════════════════════════════════════ */

  function paintFacts(s) {
    document.getElementById('whoLine').textContent = s.name + ' · ' + s.tenant;

    var quota = s.quota || {};
    var quotaText;

    if (s.role === 'admin' && quota.left === -1) {
      // Uncapped, but counted — see api/lead-reveal.php.
      quotaText = 'No daily cap · ' + (quota.used || 0) + ' revealed today';
    } else if (!quota.limit) {
      quotaText = 'No reveals allowed — ask your administrator';
    } else {
      quotaText = quota.used + ' of ' + quota.limit + ' used today · ' +
                  Math.max(0, quota.left) + ' left';
    }

    var rows = [
      ['Name',  D.escape(s.name)],
      ['Role',  s.role === 'admin' ? 'Administrator' : 'Sales rep'],
      ['Organisation', D.escape(s.tenant)],
      ['Daily reveals', quotaText]
    ];

    if (s.device) {
      rows.push(['Company laptop', D.escape(s.device.code)]);
      if (s.device.expires) {
        rows.push(['Certificate expires', D.day(s.device.expires)]);
      }
    } else if (s.deviceMode && s.deviceMode !== 'off') {
      rows.push(['Company laptop',
        '<span style="color:var(--amber)">Not identified — device checks are in ' +
        D.escape(s.deviceMode) + ' mode</span>']);
    }

    document.getElementById('facts').innerHTML = rows.map(function (r) {
      return '<dt style="color:var(--text-muted)">' + r[0] + '</dt>' +
             '<dd style="margin:0">' + r[1] + '</dd>';
    }).join('');
  }


  /* ══ Password ══════════════════════════════════════════════════ */

  var current = document.getElementById('current');
  var pw1 = document.getElementById('pw1');
  var pw2 = document.getElementById('pw2');
  var pw2Err = document.getElementById('pw2Err');
  var bars = document.querySelectorAll('.strength__bars i');
  var rulesEl = document.getElementById('rules');
  var alertBox = document.getElementById('pwAlert');
  var okBox = document.getElementById('pwOk');

  var COLORS = ['var(--red)', 'var(--amber)', 'var(--amber)', 'var(--green)'];

  /* Counts rules met, not entropy. Honest about what it measures —
   * whether the password meets policy — rather than implying a security
   * guarantee a meter cannot make. */
  function score(v) {
    var checks = {
      len:  v.length >= 12,
      case: /[a-z]/.test(v) && /[A-Z]/.test(v),
      num:  /\d/.test(v),
      sym:  /[^\w\s]/.test(v)
    };

    rulesEl.querySelectorAll('[data-rule]').forEach(function (li) {
      var ok = checks[li.dataset.rule];
      li.dataset.ok = String(!!ok);
      li.querySelector('.mark').textContent = ok ? '✓' : '○';
    });

    return Object.keys(checks).filter(function (k) { return checks[k]; }).length;
  }

  pw1.addEventListener('input', function () {
    var n = score(pw1.value);
    bars.forEach(function (b, i) {
      b.style.background = (i < n && pw1.value) ? COLORS[n - 1] : 'var(--border-soft)';
    });
    if (pw2.value) checkMatch();
    hideAlerts();
  });

  function checkMatch() {
    var same = pw1.value === pw2.value;
    pw2Err.textContent = same ? '' : 'Passwords do not match.';
    pw2.setAttribute('aria-invalid', String(!same));
    return same;
  }

  pw2.addEventListener('input', checkMatch);
  current.addEventListener('input', hideAlerts);

  function hideAlerts() {
    alertBox.hidden = true;
    okBox.hidden = true;
  }

  function fail(msg) {
    alertBox.textContent = msg;
    alertBox.hidden = false;
    okBox.hidden = true;
  }

  document.getElementById('savePw').addEventListener('click', function () {
    hideAlerts();

    if (!current.value) { fail('Enter your current password.'); current.focus(); return; }
    if (score(pw1.value) < 4) { fail('The new password does not meet all four requirements yet.'); pw1.focus(); return; }
    if (!checkMatch()) { pw2.focus(); return; }

    var btn = this;
    btn.disabled = true;

    API.request('auth-change-password.php', {
      method: 'POST',
      body: { current: current.value, password: pw1.value }
    }).then(function () {
      current.value = pw1.value = pw2.value = '';
      score('');
      bars.forEach(function (b) { b.style.background = 'var(--border-soft)'; });

      okBox.textContent = 'Password changed. Every other session has been signed out.';
      okBox.hidden = false;

    }).catch(function (err) {
      fail(err.message || 'Could not change the password.');
    }).then(function () {
      btn.disabled = false;
    });
  });


  D.ready(function (session) {
    buildNav(session.role);
    paintFacts(session);
  });
})();
