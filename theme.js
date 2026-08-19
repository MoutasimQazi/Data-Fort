/* theme.js — sets data-theme before the first paint.
 *
 * This must stay a plain <script> in <head>, NOT deferred and NOT a module.
 * Anything async runs after the first paint, which means dark-mode users
 * get a white flash on every page load. The cost of blocking here is a
 * few microseconds; the cost of not blocking is visible.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'datafort.theme';

  function stored() {
    // localStorage throws in private mode on some browsers rather than
    // returning null, so this is wrapped rather than checked.
    try { return localStorage.getItem(STORAGE_KEY); } catch (e) { return null; }
  }

  function systemPrefersDark() {
    return window.matchMedia &&
           window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  /* Three states, not two: an explicit choice stamps data-theme, while
   * "system" deliberately leaves the attribute off so the CSS media query
   * stays in charge and follows the OS if the user changes it mid-session. */
  function apply(theme) {
    if (theme === 'dark' || theme === 'light') {
      document.documentElement.setAttribute('data-theme', theme);
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
  }

  apply(stored());

  // Exposed so the toggle button can drive it once the DOM is up.
  window.DatafortTheme = {
    get: function () {
      return stored() || 'system';
    },

    set: function (theme) {
      try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) {}
      apply(theme);
    },

    /* Cycles light -> dark -> light. "system" is reachable by clearing
     * storage, but it is not in the click cycle: users who touch the
     * toggle are expressing a preference, so we honour it explicitly. */
    toggle: function () {
      var current = stored();
      if (!current) current = systemPrefersDark() ? 'dark' : 'light';
      this.set(current === 'dark' ? 'light' : 'dark');
      return stored();
    }
  };
})();
