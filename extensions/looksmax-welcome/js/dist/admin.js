/*
 * Admin: the one control this extension needs.
 *
 * Same hand-authored, separately-injected shape as the forum bundle and for
 * the same reason (see src/InjectAdminScript.php): a top-level throw inside
 * the concatenated admin bundle stops every extension registered after it,
 * and the admin panel is where you go to turn a broken extension off.
 *
 * `registerSetting` is the supported admin API and exists by the time this
 * poll succeeds; the poll (not a hard dependency on load order) is what makes
 * a separate <script> safe here the same way it is in every other lane's
 * admin.js on this install.
 */
(function () {
  'use strict';

  var EXT = 'local-looksmax-welcome';
  var tries = 0;

  function t(key) {
    try {
      var v = window.app.translator.trans('local-looksmax-welcome.admin.' + key);
      // trans() returns an ARRAY when the message has placeholders, and an
      // array in a label renders with commas between the fragments.
      return Array.isArray(v) ? v.join('') : v;
    } catch (e) {
      return key;
    }
  }

  (function attempt() {
    var app = window.app;
    if (!app || !app.extensionData || !app.translator) {
      if (++tries > 400) return;
      setTimeout(attempt, tries < 100 ? 0 : 100);
      return;
    }

    try {
      var d = app.extensionData.for(EXT);

      d.registerSetting({
        setting: 'welcome.enabled',
        type: 'boolean',
        label: t('settings.enabled'),
      });

      window.__lmxWelcomeAdmin = { bound: true, at: Date.now() };
    } catch (e) {
      if (window.console) console.warn('welcome admin: registration failed', e);
      window.__lmxWelcomeAdmin = { bound: false, error: String(e) };
    }
  })();
})();
