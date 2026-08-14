/*
 * Admin: the boost stacking ceiling.
 *
 * Same hand-authored, separately-injected shape as looksmax-userinfo's admin
 * bundle (see src/InjectAdminScript.php). Everything else about the store is
 * priced from the catalogue at /store/admin (see InjectAdminLink.php for that
 * doorway); these two numbers are the exception because they are not a
 * property of any one item — see Config.php and Entitlements::boosts().
 */
(function () {
  'use strict';

  var EXT = 'local-looksmax-store';
  var tries = 0;

  function t(key) {
    try {
      var v = window.app.translator.trans('local-looksmax-store.admin.settings.' + key);
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

      d.registerSetting({ setting: 'store.boost.maxEarnMultiplier', type: 'text', label: t('boost_max_earn'), placeholder: '4' });
      d.registerSetting({ setting: 'store.boost.maxCapBoost', type: 'text', label: t('boost_max_cap'), placeholder: '4' });

      window.__lmxStoreAdminSettings = { bound: true, at: Date.now() };
    } catch (e) {
      if (window.console) console.warn('store admin settings: registration failed', e);
      window.__lmxStoreAdminSettings = { bound: false, error: String(e) };
    }
  })();
})();
