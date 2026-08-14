/*
 * Admin: the ONE place the user card is configured.
 *
 * Same hand-authored, separately-injected shape as the forum bundle and for the
 * same reason (see src/InjectScript.php): a top-level throw inside the
 * concatenated admin bundle stops every extension registered after it, and the
 * admin panel is where you go to turn a broken extension off.
 *
 * Every control here writes a `userinfo.*` setting that src/Config.php reads
 * and publishes on the forum payload as `lmxUserInfo`. There is no per-call-site
 * option anywhere in the forum bundle, so what is set here is what every card on
 * the forum does — post author, mention, shoutbox, listing, notification,
 * search result and leaderboard alike.
 *
 * `registerSetting` is the supported admin API and exists by the time this
 * polls successfully; the poll is what makes the separate <script> safe.
 */
(function () {
  'use strict';

  var EXT = 'local-looksmax-userinfo';
  var tries = 0;

  function t(key) {
    try {
      var v = window.app.translator.trans('local-looksmax-userinfo.admin.' + key);
      // trans() returns an ARRAY when the message has placeholders, and an
      // array in a label renders with commas between the fragments. Flattened
      // for the same reason as in the forum bundle.
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

      // --- behaviour ----------------------------------------------------
      d.registerSetting({ setting: 'userinfo.trigger', type: 'text', label: t('settings.trigger'), placeholder: 'hover' });
      d.registerSetting({ setting: 'userinfo.openDelay', type: 'number', label: t('settings.open_delay'), placeholder: '320' });
      d.registerSetting({ setting: 'userinfo.closeDelay', type: 'number', label: t('settings.close_delay'), placeholder: '220' });
      d.registerSetting({ setting: 'userinfo.placement', type: 'text', label: t('settings.placement'), placeholder: 'auto' });
      d.registerSetting({ setting: 'userinfo.followScroll', type: 'boolean', label: t('settings.follow_scroll') });

      // --- responsive ---------------------------------------------------
      d.registerSetting({ setting: 'userinfo.mobile', type: 'text', label: t('settings.mobile'), placeholder: 'sheet' });
      d.registerSetting({ setting: 'userinfo.mobileBreakpoint', type: 'number', label: t('settings.mobile_breakpoint'), placeholder: '767' });

      // --- style --------------------------------------------------------
      d.registerSetting({ setting: 'userinfo.width', type: 'number', label: t('settings.width'), placeholder: '340' });
      d.registerSetting({ setting: 'userinfo.avatarSize', type: 'number', label: t('settings.avatar_size'), placeholder: '64' });

      // --- content ------------------------------------------------------
      // Free text rather than a checkbox per field: the list is ordered, and
      // fifteen checkboxes that cannot express order would be a worse control
      // than one line that can. An unknown token is ignored by the forum
      // bundle, so a typo degrades to "that field is off", never to a blank
      // card.
      d.registerSetting({ setting: 'userinfo.fields', type: 'text', label: t('settings.fields') });
      d.registerSetting({ setting: 'userinfo.actions', type: 'text', label: t('settings.actions') });

      // --- the author rail ----------------------------------------------
      d.registerSetting({ setting: 'userinfo.railEnabled', type: 'boolean', label: t('settings.rail_enabled') });
      d.registerSetting({ setting: 'userinfo.railWidth', type: 'number', label: t('settings.rail_width'), placeholder: '200' });

      // --- direct messages ----------------------------------------------
      d.registerSetting({ setting: 'userinfo.dmEnabled', type: 'boolean', label: t('settings.dm_enabled') });
      d.registerSetting({ setting: 'userinfo.dmMaxLength', type: 'number', label: t('settings.dm_max_length'), placeholder: '8000' });
      d.registerSetting({ setting: 'userinfo.dmMaxRecipients', type: 'number', label: t('settings.dm_max_recipients'), placeholder: '10' });

      d.registerPermission(
        { icon: 'fas fa-envelope', label: t('permissions.send'), permission: 'lmxdm.send' },
        'reply'
      );

      window.__lmxUserInfoAdmin = { bound: true, at: Date.now() };
    } catch (e) {
      if (window.console) console.warn('userinfo admin: registration failed', e);
      window.__lmxUserInfoAdmin = { bound: false, error: String(e) };
    }
  })();
})();
