/*
 * Admin: the reaction catalogue.
 *
 * Same hand-authored, separately-injected shape as the forum bundle and for the
 * same reason (see src/InjectScript.php). Registers through
 * app.extensionData, which is the supported admin API and exists by the time
 * this polls successfully.
 *
 * The ordering control matters more than it looks. fof/reactions has no
 * ordering column on either branch, so the only way to reorder its picker is to
 * delete a reaction and recreate it — and its FK is ON DELETE CASCADE, so
 * reordering destroys every historical reaction of that type. Here `position`
 * is a column, DELETE is refused outright on a reaction that has any rows, and
 * disable is offered instead. You cannot lose data from this screen.
 */
(function () {
  'use strict';

  var EXT = 'local-looksmax-reactions';
  var tries = 0;

  function icon(m, r, px) {
    var base = (window.app && app.forum && app.data && app.data.lmxReactionsAssetBase) || '';
    if (!base) base = '/assets/extensions/local-looksmax-reactions';
    var src = r.type === 'svg'
      ? base + '/' + r.asset + '.svg'
      : base + '/' + r.asset.replace(/\/([^/]+)$/, '/48/$1') + '.webp';
    return m('img', { src: src, width: px, height: px, alt: r.display,
      style: { width: px + 'px', height: px + 'px', objectFit: 'contain' } });
  }

  function page(m, app) {
    return {
      oninit: function (v) {
        v.state.rows = null;
        v.state.busy = false;
        v.state.load = function () {
          app.request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/lmx/reactions' })
            .then(function (res) { v.state.rows = res.reactions; m.redraw(); })
            .catch(function (e) { console.warn('reactions admin: list failed', e); });
        };
        v.state.load();
      },
      view: function (v) {
        var rows = v.state.rows;
        if (!rows) return m('.ExtensionPage', m('.container', app.translator.trans('local-looksmax-reactions.admin.loading')));

        function patch(r, body) {
          v.state.busy = true;
          app.request({
            method: 'PATCH',
            url: app.forum.attribute('apiUrl') + '/lmx/reactions/' + r.id,
            body: body
          }).then(function () { v.state.busy = false; v.state.load(); })
            .catch(function (e) {
              v.state.busy = false;
              console.warn('reactions admin: patch failed', e);
              m.redraw();
            });
        }

        function move(i, delta) {
          var next = i + delta;
          if (next < 0 || next >= rows.length) return;
          var order = rows.map(function (r) { return r.slug; });
          var tmp = order[i]; order[i] = order[next]; order[next] = tmp;
          app.request({
            method: 'POST',
            url: app.forum.attribute('apiUrl') + '/lmx/reactions/order',
            body: { order: order }
          }).then(function () { v.state.load(); });
        }

        return m('.ExtensionPage', m('.container', [
          m('h2', app.translator.trans('local-looksmax-reactions.admin.catalogue.title')),
          m('p', app.translator.trans('local-looksmax-reactions.admin.catalogue.summary', {
            count: rows.length,
            enabled: rows.filter(function (r) { return r.enabled; }).length
          })),
          rows.map(function (r, i) {
            return m('.LmxRxAdmin-row', { key: r.slug }, [
              icon(m, r, 24),
              m('strong', { style: { minWidth: '130px' } }, r.display),
              m('code', { style: { minWidth: '110px', opacity: 0.7 } }, r.slug),
              m('span.LmxRxAdmin-grp', r.grp),
              m('span.LmxRxAdmin-swatch', { style: { background: r.tint } }),
              m('span', { style: { opacity: 0.7 } }, app.translator.trans('local-looksmax-reactions.admin.catalogue.points', { points: r.points })),
              r.xf_id ? m('span', { style: { opacity: 0.6 } }, 'xf#' + r.xf_id) : null,
              m('span', { style: { marginLeft: 'auto', display: 'flex', gap: '6px' } }, [
                m('button.Button.Button--icon', {
                  title: app.translator.trans('local-looksmax-reactions.admin.catalogue.move_up'),
                  'aria-label': app.translator.trans('local-looksmax-reactions.admin.catalogue.move_up'),
                  onclick: function () { move(i, -1); }
                }, '↑'),
                m('button.Button.Button--icon', {
                  title: app.translator.trans('local-looksmax-reactions.admin.catalogue.move_down'),
                  'aria-label': app.translator.trans('local-looksmax-reactions.admin.catalogue.move_down'),
                  onclick: function () { move(i, 1); }
                }, '↓'),
                m('button.Button', {
                  className: r.enabled ? 'Button--primary' : '',
                  disabled: v.state.busy,
                  onclick: function () { patch(r, { enabled: !r.enabled }); }
                }, r.enabled
                  ? app.translator.trans('local-looksmax-reactions.admin.catalogue.enabled')
                  : app.translator.trans('local-looksmax-reactions.admin.catalogue.disabled'))
              ])
            ]);
          })
        ]));
      }
    };
  }

  (function attempt() {
    var app = window.app;
    var m = window.m;
    if (!app || !app.extensionData || !m) {
      if (++tries > 300) { console.warn('reactions admin: app.extensionData never appeared'); return; }
      setTimeout(attempt, tries < 100 ? 0 : 100);
      return;
    }

    try {
      var d = app.extensionData.for(EXT);
      d.registerSetting({ setting: 'lmxreactions.selfReact', type: 'boolean',
        label: app.translator.trans('local-looksmax-reactions.admin.settings.self_react') });
      d.registerSetting({ setting: 'lmxreactions.maxPerPost', type: 'number',
        label: app.translator.trans('local-looksmax-reactions.admin.settings.max_per_post') });
      d.registerSetting({ setting: 'lmxreactions.stripSize', type: 'number',
        label: app.translator.trans('local-looksmax-reactions.admin.settings.strip_size') });
      d.registerSetting({ setting: 'lmxreactions.rateBurst', type: 'number',
        label: app.translator.trans('local-looksmax-reactions.admin.settings.rate_burst') });
      d.registerSetting({ setting: 'lmxreactions.rateHourly', type: 'number',
        label: app.translator.trans('local-looksmax-reactions.admin.settings.rate_hourly') });
      d.registerPermission({ icon: 'fas fa-face-smile', label: app.translator.trans('local-looksmax-reactions.admin.permissions.react'),
        permission: 'lmxreactions.react' }, 'reply');
      d.registerPermission({ icon: 'fas fa-users', label: app.translator.trans('local-looksmax-reactions.admin.permissions.see_reactors'),
        permission: 'lmxreactions.seeReactors' }, 'view');
      d.registerPage(page(m, app));
      window.__lmxReactionsAdmin = { bound: true, at: Date.now() };
    } catch (e) {
      console.warn('reactions admin: registration failed', e);
      window.__lmxReactionsAdmin = { bound: false, error: String(e) };
    }
  })();
})();
