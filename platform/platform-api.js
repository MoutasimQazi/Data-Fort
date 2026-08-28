/* platform-api.js — the platform panel's only line to the server.
 * Trimmed from ../api.js: same request()/error shape, endpoints
 * re-pointed at api/platform/*.php. See that file's header for the
 * three failure modes this handles once so no page has to. */
window.DatafortAPI = (function () {
  'use strict';

  var BASE = '../api/platform/';

  function toLogin() {
    var here = location.pathname.split('/').pop() || 'index.html';
    location.replace('login.html?next=' + encodeURIComponent(here));
  }

  function request(path, options) {
    options = options || {};
    var init = { method: options.method || 'GET', credentials: 'same-origin', headers: {} };

    if (options.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }

    return fetch(BASE + path, init).then(function (res) {
      return res.text().then(function (text) {
        var data;
        try {
          data = text ? JSON.parse(text) : {};
        } catch (e) {
          throw buildError(res.status, {
            error: 'Server returned an unexpected response. ' + (text ? text.slice(0, 300) : 'Empty body.')
          });
        }

        if (res.ok) return data;
        if (res.status === 401) { toLogin(); throw buildError(401, data); }
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

    session: function ()  { return request('session.php'); },
    login:   function (b) { return request('login.php',  { method: 'POST', body: b }); },
    logout:  function ()  { return request('logout.php', { method: 'POST', body: {} }); },

    tenants:      function (p) { return request('tenants-list.php' + qs(p)); },
    tenantDetail: function (id) { return request('tenant-detail.php?id=' + encodeURIComponent(id)); },
    saveTenant:   function (b) { return request('tenants-save.php', { method: 'POST', body: b }); },

    plans:     function ()  { return request('plans-list.php'); },
    savePlan:  function (b) { return request('plans-save.php', { method: 'POST', body: b }); },

    leads:     function (p) { return request('leads-list.php' + qs(p)); },
    saveLead:  function (b) { return request('leads-save.php', { method: 'POST', body: b }); },

    admins:     function ()  { return request('admins-list.php'); },
    saveAdmin:  function (b) { return request('admins-save.php', { method: 'POST', body: b }); },

    devices:    function ()  { return request('devices-list.php'); },
    saveDevice: function (b) { return request('devices-save.php', { method: 'POST', body: b }); },

    audit: function (p) { return request('audit-list.php' + qs(p)); }
  };
})();
