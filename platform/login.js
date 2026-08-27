/* login.js — platform sign-in form. Trimmed from ../login.js: same
 * fingerprinting/validation/UX, endpoint re-pointed, no 'next' replay
 * or no-device redirect (platform_device_enforcement ships 'log', so a
 * 403 here is shown inline rather than sent to a dedicated page). */
(function () {
  'use strict';

  var API = '../api/platform/login.php';

  var form      = document.getElementById('loginForm');
  var email     = document.getElementById('email');
  var password  = document.getElementById('password');
  var emailErr  = document.getElementById('emailErr');
  var pwErr     = document.getElementById('pwErr');
  var alertBox  = document.getElementById('formAlert');
  var submitBtn = document.getElementById('submitBtn');
  var deviceFp  = document.getElementById('deviceFp');
  var pwToggle  = document.getElementById('pwToggle');
  var themeBtn  = document.getElementById('themeBtn');

  themeBtn.addEventListener('click', function () { window.DatafortTheme.toggle(); });

  pwToggle.addEventListener('click', function () {
    var showing = pwToggle.getAttribute('aria-pressed') === 'true';
    pwToggle.setAttribute('aria-pressed', String(!showing));
    pwToggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    password.type = showing ? 'password' : 'text';
    password.focus();
  });

  function fingerprint() {
    var bits = [
      navigator.userAgent, navigator.language, (navigator.languages || []).join(','),
      screen.width + 'x' + screen.height + 'x' + screen.colorDepth,
      new Date().getTimezoneOffset(),
      (Intl.DateTimeFormat().resolvedOptions() || {}).timeZone || '',
      navigator.hardwareConcurrency || '', navigator.maxTouchPoints || 0
    ].join('|');
    var h = 0x811c9dc5;
    for (var i = 0; i < bits.length; i++) {
      h ^= bits.charCodeAt(i);
      h = (h + (h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24)) >>> 0;
    }
    return ('00000000' + h.toString(16)).slice(-8);
  }
  deviceFp.value = fingerprint();

  function setError(input, slot, message) {
    slot.textContent = message || '';
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    return !message;
  }
  function validEmail(value) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value); }
  function validate() {
    var ok = true;
    if (!email.value.trim())           ok = setError(email, emailErr, 'Enter your email.') && ok;
    else if (!validEmail(email.value)) ok = setError(email, emailErr, 'That does not look like a valid email.') && ok;
    else                                setError(email, emailErr, '');
    if (!password.value)               ok = setError(password, pwErr, 'Enter your password.') && ok;
    else                                setError(password, pwErr, '');
    return ok;
  }
  [[email, emailErr], [password, pwErr]].forEach(function (pair) {
    pair[0].addEventListener('input', function () {
      if (pair[1].textContent) setError(pair[0], pair[1], '');
      hideAlert();
    });
  });

  function showAlert(message) { alertBox.textContent = message; alertBox.hidden = false; }
  function hideAlert() { alertBox.hidden = true; alertBox.textContent = ''; }
  function busy(state) {
    submitBtn.dataset.busy = String(state);
    submitBtn.disabled = state;
    email.disabled = state;
    password.disabled = state;
    submitBtn.querySelector('.btn__label').textContent = state ? 'Signing in…' : 'Sign in';
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    hideAlert();
    if (!validate()) {
      var firstBad = form.querySelector('[aria-invalid="true"]');
      if (firstBad) firstBad.focus();
      return;
    }
    busy(true);
    deviceFp.value = fingerprint();

    fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        email: email.value.trim().toLowerCase(),
        password: password.value,
        trust_device: document.getElementById('trustDevice').checked,
        device_fp: deviceFp.value
      })
    })
      .then(function (res) {
        return res.json().catch(function () { throw new Error('BACKEND_UNAVAILABLE'); })
          .then(function (data) { return { ok: res.ok, status: res.status, data: data }; });
      })
      .then(function (r) {
        if (r.ok && r.data.redirect) {
          window.location.assign(r.data.redirect);
          return;
        }
        busy(false);

        if (r.status === 403 && r.data.device_error) {
          showAlert((r.data.message || 'This device is not recognised.') +
            ' Platform device enforcement is separate from any tenant’s — ' +
            'enroll this laptop from the Devices page once signed in from an already-trusted machine.');
        } else if (r.status >= 500) {
          showAlert((r.data.error || 'The server failed to handle the request.') +
            ' Check that scripts/init-platform-db.php has been run.');
        } else if (r.status === 429) {
          showAlert(r.data.message || 'Too many attempts. Try again shortly.');
        } else if (r.status === 423) {
          showAlert(r.data.message || 'This account is suspended.');
        } else {
          showAlert(r.data.message || r.data.error || 'Email or password is incorrect.');
        }

        password.value = '';
        password.focus();
      })
      .catch(function (err) {
        busy(false);
        showAlert(err.message === 'BACKEND_UNAVAILABLE'
          ? 'Sign-in is not available yet — the API endpoint is not connected.'
          : 'Could not reach the server. Check your connection and try again.');
      });
  });

  if (!email.value) email.focus();
})();
