/* api.js — the only place the front end talks to the server.
 *
 * Loaded before app.js on every signed-in page. Replaces mock-data.js,
 * which has been deleted.
 *
 * Three failure modes get handled here rather than in nine page
 * scripts, because getting any of them wrong is confusing in a way the
 * user cannot diagnose:
 *
 *   401  session gone      -> back to login
 *   403 + device_error     -> the DEVICE was refused, not the password.
 *                             Sending someone to the login page here
 *                             starts a loop of password resets that
 *                             cannot possibly help.
 *   anything else          -> surface the server's message as-is
 */
window.DatafortAPI = (function () {
  'use strict';

  var BASE = 'api/';

  function toLogin() {
    // Preserve where they were trying to go.
    var here = location.pathname.split('/').pop() || 'index.html';
    location.replace('login.html?next=' + encodeURIComponent(here));
  }

  function toNoDevice(reason) {
    location.replace('no-device.html?reason=' + encodeURIComponent(reason || 'no_certificate'));
  }

  /**
   * Core request. Resolves with the parsed body, rejects with an Error
   * carrying .status and .payload so callers can branch if they need to.
   */
  function request(path, options) {
    options = options || {};

    var init = {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: {}
    };

    if (options.body !== undefined) {
      if (options.body instanceof FormData) {
        // Let the browser set the multipart boundary.
        init.body = options.body;
      } else {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(options.body);
      }
    }

    return fetch(BASE + path, init).then(function (res) {
      var type = res.headers.get('Content-Type') || '';

      // lead-reveal.php answers with image/png on success.
      if (type.indexOf('image/') === 0) {
        return res.blob().then(function (blob) {
          if (!res.ok) throw buildError(res.status, {});
          var quota = null;
          try {
            quota = JSON.parse(res.headers.get('X-Datafort-Quota') || 'null');
          } catch (e) { /* Older servers may not send the quota header. */ }
          return { image: URL.createObjectURL(blob), quota: quota };
        });
      }

      return res.text().then(function (text) {
        var data;
        try {
          data = text ? JSON.parse(text) : {};
        } catch (e) {
          /* A non-JSON body from a PHP endpoint is almost always a fatal
           * error or a warning printed before the headers. Show that
           * rather than a parser error — it is the actual diagnostic. */
          throw buildError(res.status, {
            error: 'Server returned an unexpected response. ' +
                   (text ? text.slice(0, 300) : 'Empty body.')
          });
        }

        if (res.ok) return data;

        if (res.status === 401) { toLogin(); throw buildError(401, data); }
        if (res.status === 403 && data.device_error) {
          toNoDevice(data.device_error);
          throw buildError(403, data);
        }

        throw buildError(res.status, data);
      });
    });
  }

  function buildError(status, data) {
    var err = new Error(data.error || data.message || ('Request failed (' + status + ')'));
    err.status = status;
    err.payload = data;
    return err;
  }


  /* ══ Endpoints ═════════════════════════════════════════════════ */

  function qs(params) {
    var parts = [];
    Object.keys(params || {}).forEach(function (k) {
      if (params[k] !== '' && params[k] !== null && params[k] !== undefined) {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
      }
    });
    return parts.length ? '?' + parts.join('&') : '';
  }

  return {
    request: request,

    session:  function ()  { return request('auth-session.php'); },
    login:    function (b) { return request('auth-login.php',  { method: 'POST', body: b }); },
    logout:   function ()  { return request('auth-logout.php', { method: 'POST', body: {} }); },
    dashboard: function () { return request('dashboard.php'); },

    leads:       function (p) { return request('leads-list.php' + qs(p)); },
    reveal:      function (lead, field) {
      return request('lead-reveal.php', { method: 'POST', body: { lead: lead, field: field } });
    },
    updateLead:  function (b) { return request('leads-update.php', { method: 'POST', body: b }); },
    assignLeads: function (b) { return request('leads-assign.php', { method: 'POST', body: b }); },
    sendEmail:   function (b) { return request('lead-email.php',   { method: 'POST', body: b }); },

    users:      function ()   { return request('users-list.php'); },
    userDetail: function (id) { return request('user-detail.php?id=' + encodeURIComponent(id)); },
    saveUser: function (b) { return request('users-save.php', { method: 'POST', body: b }); },

    devices:    function ()  { return request('devices-list.php'); },
    saveDevice: function (b) { return request('devices-save.php', { method: 'POST', body: b }); },

    audit: function (p) { return request('audit-list.php' + qs(p)); },

    settings:     function ()  { return request('settings-get.php'); },
    saveSettings: function (b) { return request('settings-save.php', { method: 'POST', body: b }); },

    importCommit: function (formData) {
      return request('import-commit.php', { method: 'POST', body: formData });
    },
    importDestroy: function (sourceId) {
      return request('import-destroy.php', { method: 'POST', body: { sourceId: sourceId } });
    },

    securityEvent: function (type, detail) {
      // Fire and forget. A failed telemetry post must never break a page.
      var body = JSON.stringify({ events: [{
        type: type, detail: detail || null,
        at: new Date().toISOString(), page: location.pathname
      }]});

      if (navigator.sendBeacon) {
        navigator.sendBeacon(BASE + 'security-event.php',
          new Blob([body], { type: 'application/json' }));
      }
    }
  };
})();
