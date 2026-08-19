/* login.js — sign-in form behaviour.
 *
 * Everything here is convenience and feedback. None of it is security.
 * Validation, rate limiting, lockout and the decision about whether a
 * device is trusted all happen server-side in api/auth-login.php; a
 * caller with curl never runs a line of this file.
 */
(function () {
  'use strict';

  var API = 'api/auth-login.php';

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


  /* ══ Theme toggle ══════════════════════════════════════════════ */

  themeBtn.addEventListener('click', function () {
    window.DatafortTheme.toggle();
  });


  /* ══ Password reveal ═══════════════════════════════════════════ */

  pwToggle.addEventListener('click', function () {
    var showing = pwToggle.getAttribute('aria-pressed') === 'true';
    pwToggle.setAttribute('aria-pressed', String(!showing));
    pwToggle.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    password.type = showing ? 'password' : 'text';
    password.focus();
  });


  /* ══ Device fingerprint ════════════════════════════════════════
   *
   * A coarse, stable-ish signature of the browser and machine. It is NOT
   * an identity and NOT a security control — it is trivially spoofed by
   * anyone who cares to. Its job is to let the server notice "this
   * account signed in from a shape it has never used before" and raise
   * an audit event, per requirements 7.3.
   *
   * Deliberately excludes canvas and font probing: those raise the
   * entropy but read as tracking, and this is an employee tool.
   */
  function fingerprint() {
    var bits = [
      navigator.userAgent,
      navigator.language,
      (navigator.languages || []).join(','),
      screen.width + 'x' + screen.height + 'x' + screen.colorDepth,
      new Date().getTimezoneOffset(),
      (Intl.DateTimeFormat().resolvedOptions() || {}).timeZone || '',
      navigator.hardwareConcurrency || '',
      navigator.maxTouchPoints || 0
    ].join('|');

    // FNV-1a. Not cryptographic — just a short stable id for a log line.
    var h = 0x811c9dc5;
    for (var i = 0; i < bits.length; i++) {
      h ^= bits.charCodeAt(i);
      h = (h + (h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24)) >>> 0;
    }
    return ('00000000' + h.toString(16)).slice(-8);
  }

  deviceFp.value = fingerprint();


  /* ══ Validation ════════════════════════════════════════════════ */

  function setError(input, slot, message) {
    slot.textContent = message || '';
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    return !message;
  }

  function validEmail(value) {
    // Intentionally loose. The server is the authority on whether an
    // address exists; over-strict client regexes only ever reject real
    // addresses that happen to look unusual.
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
  }

  function validate() {
    var ok = true;

    if (!email.value.trim())            ok = setError(email, emailErr, 'Enter your work email.') && ok;
    else if (!validEmail(email.value))  ok = setError(email, emailErr, 'That does not look like a valid email.') && ok;
    else                                setError(email, emailErr, '');

    if (!password.value)                ok = setError(password, pwErr, 'Enter your password.') && ok;
    else                                setError(password, pwErr, '');

    return ok;
  }

  // Clear a field's error as soon as the user starts fixing it, but never
  // show a new one mid-typing — validating on every keystroke tells people
  // their half-typed email is wrong, which is just noise.
  [[email, emailErr], [password, pwErr]].forEach(function (pair) {
    pair[0].addEventListener('input', function () {
      if (pair[1].textContent) setError(pair[0], pair[1], '');
      hideAlert();
    });
  });


  /* ══ Alerts ════════════════════════════════════════════════════ */

  function showAlert(message) {
    alertBox.textContent = message;
    alertBox.hidden = false;
  }

  function hideAlert() {
    alertBox.hidden = true;
    alertBox.textContent = '';
  }

  function busy(state) {
    submitBtn.dataset.busy = String(state);
    submitBtn.disabled = state;
    email.disabled = state;
    password.disabled = state;
    submitBtn.querySelector('.btn__label').textContent = state ? 'Signing in…' : 'Sign in';
  }


  /* ══ Submit ════════════════════════════════════════════════════ */

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
        email:        email.value.trim().toLowerCase(),
        password:     password.value,
        trust_device: document.getElementById('trustDevice').checked,
        device_fp:    deviceFp.value
      })
    })
      .then(function (res) {
        return res.json().catch(function () {
          // A non-JSON body means the endpoint is missing or the server
          // returned an error page. Say so plainly rather than showing
          // the user a parser error.
          throw new Error('BACKEND_UNAVAILABLE');
        }).then(function (data) {
          return { ok: res.ok, status: res.status, data: data };
        });
      })
      .then(function (r) {
        if (r.ok && r.data.redirect) {
          window.location.assign(r.data.redirect);
          return;
        }

        /* Device refusal (Level 1) is not a login failure and must not
         * be shown as one. Telling someone "email or password is
         * incorrect" when the real problem is an unenrolled laptop
         * sends them round a loop of password resets that cannot
         * possibly help. Hand them the page that explains it. */
        if (r.status === 403 && r.data.device_error) {
          var qs = new URLSearchParams({ reason: r.data.device_error });
          window.location.assign('no-device.html?' + qs.toString());
          return;
        }

        busy(false);

        /* One generic message for bad email, bad password, and unknown
         * account. Distinguishing them tells an attacker which addresses
         * are real, which on this product hands them a staff list for the
         * target company. The server must be equally vague. */
        if (r.status === 429) {
          showAlert(r.data.message ||
            'Too many attempts. Try again shortly — your administrator has been notified.');
        } else if (r.status === 423) {
          showAlert(r.data.message ||
            'This account is locked. Contact your administrator.');
        } else {
          showAlert(r.data.message || 'Email or password is incorrect.');
        }

        password.value = '';
        password.focus();
      })
      .catch(function (err) {
        busy(false);
        if (err.message === 'BACKEND_UNAVAILABLE') {
          showAlert('Sign-in is not available yet — the API endpoint is not connected.');
        } else {
          showAlert('Could not reach the server. Check your connection and try again.');
        }
        console.warn('[datafort] login failed:', err);
      });
  });


  // Land the cursor where work starts, but never steal focus from someone
  // whose password manager has already filled the form.
  if (!email.value) email.focus();
})();
