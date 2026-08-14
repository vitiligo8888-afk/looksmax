/*
 * The storefront.
 *
 * Hand-authored against Flarum's runtime module registry rather than built
 * through webpack, because the container has no node toolchain. Flarum exposes
 * every core module on `flarum.core.compat` at runtime, so this is a supported
 * integration path — just an unusual one, and the same one looksmax-guides,
 * looksmax-icons and looksmax-ranks already use on this install.
 *
 * ── Why this file runs from the document HEAD ───────────────────────────────
 * A route has to exist before `app.boot()` calls m.route, and Flarum's boot
 * script runs after the JS bundle. `$document->foot` is therefore too late and
 * the shared bundle is not trustworthy here anyway: flarum/markdown's s9e
 * preview calls `new XSLTProcessor` at top level of assets/forum.js (line 336
 * of 458, measured 2026-08-13), Chrome has removed XSLTProcessor, and every
 * extension registration in that file lives after line 377.
 *
 * So this file installs an accessor on `window.flarum` from the head. Flarum's
 * inline bootstrap does `var flarum = {extensions: {}}`, which — because the
 * property already exists and is configurable — assigns through the setter
 * instead of redefining it. Core then assigns `flarum.core`, the nested setter
 * fires during bundle evaluation, and a microtask queued there runs after the
 * bundle script finishes and before the separate boot script. That is the one
 * window where `app` exists, `compat` is populated and no initializer has run.
 *
 * If any of that fails, `takeover()` renders the store into #content after
 * load. And if THAT fails, /store still returns a real forum page, because the
 * route is registered server-side by the Frontend extender. Three levels,
 * because a store that is a blank page is worse than no store.
 */
(function () {
  'use strict';

  var API = '/api/store';
  var BOOTED = false;

  // Breadcrumbs for the one part of this file that cannot be debugged from the
  // server: which of the pre-boot hooks fired, and what stopped the route being
  // registered if it was not. `window.__lmxStore` is read by e2e/probe.ts.
  var TRACE = window.__lmxStore = {
    hooked: false, flarumSet: false, coreSet: false, fired: false,
    registered: false, why: null, at: null,
  };

  /* ------------------------------------------------------------------ state */

  var S = {
    loading: false,
    error: null,
    data: null,          // catalogue payload
    orders: null,        // orders payload
    admin: null,         // admin payload
    category: null,
    busy: {},            // sku -> true while a purchase is in flight
    keys: {},            // sku -> idempotency key for the current intent
    dialog: null,        // { item, gift, card }
    redeeming: null,     // { kind, entitlement }
    adminEdit: null,
    adminTab: 'catalogue',
    allOrders: false,
    allLedger: false,
    adminFilter: '',
    lastResult: null,
  };

  /* ------------------------------------------------------------------ utils */

  function registry() {
    return (window.flarum && window.flarum.core && window.flarum.core.compat) || {};
  }

  function app() {
    var r = registry();
    return (r['forum/app'] && r['forum/app'].default) || window.app;
  }

  // Translation and locale-aware formatting, both routed through the i18n
  // extension's runtime helper (`window.lmxI18n`, injected on every page).
  //
  // `toLocaleString('en-US')` was hardcoded to English separators and the k/M
  // suffixes below it were English words spelled as letters; Spanish says
  // "12.431" and "12,4 mil". Every number in this file goes through num(),
  // shortNum(), pct(), dec() or money() so that is decided in one place.
  //
  // t() falls back to the English source string if the i18n script has not
  // loaded — this file runs from the document HEAD and must never be able to
  // render a blank storefront because a sibling extension is disabled.
  function i18n() { return window.lmxI18n || null; }

  function t(key, params, fallback) {
    var full = 'local-looksmax-store.' + key;
    var h = i18n();
    if (h && h.t) return h.t(full, params || {}, fallback);
    var a = app();
    if (a && a.translator) {
      var out = a.translator.trans(full, params || {});
      if (typeof out !== 'string' || out !== full) return out;
    }
    return fallback;
  }

  function num(n, opts) {
    var x = Number(n) || 0;
    var h = i18n();
    return h ? h.num(x, opts) : String(x);
  }

  function shortNum(n) {
    var h = i18n();
    return h ? h.compact(Number(n) || 0) : num(n);
  }

  /** 0.2 -> "20%" / "20 %". */
  function pct(fraction) {
    var h = i18n();
    return h ? h.pct(Number(fraction) * 100) : Math.round(Number(fraction) * 100) + '%';
  }

  /** A multiplier: "1.50" / "1,50". */
  function dec(n, digits) {
    return num(n, { minimumFractionDigits: digits, maximumFractionDigits: digits });
  }

  /** Cents of USD. "$5.00" / "US$ 5.00" — the currency is the store's, the
   *  placement of the symbol is the reader's. */
  function money(cents) {
    var x = (Number(cents) || 0) / 100;
    var h = i18n();
    return h ? h.num(x, { style: 'currency', currency: 'USD' }) : '$' + x.toFixed(2);
  }

  function rarityLabel(r) { return t('forum.rarity.' + r, {}, r); }

  function kindLabel(k) { return t('forum.kind.' + k, {}, k); }

  function icon(name, cls) {
    return m('iconify-icon', { icon: name, inline: '', class: cls || '' });
  }

  function when(iso) {
    if (!iso) return '';
    // `toLocaleDateString(undefined, …)` follows the BROWSER's locale, not the
    // forum's, so an English Chrome rendered "4 Mar" beside Spanish copy.
    // lmxI18n.dateTime() reads the forum locale and handles the MySQL
    // "Y-m-d H:i:s" shape this API returns.
    var h = i18n();
    if (h) return h.dateTime(iso) || String(iso);
    var d = new Date(String(iso).replace(' ', 'T') + (String(iso).indexOf('Z') < 0 && String(iso).indexOf('+') < 0 ? 'Z' : ''));
    return isNaN(d.getTime()) ? String(iso) : d.toISOString().slice(0, 16).replace('T', ' ');
  }

  function countdown(iso) {
    if (!iso) return '';
    var d = new Date(String(iso).replace(' ', 'T') + 'Z').getTime() - Date.now();
    if (isNaN(d)) return '';
    if (d <= 0) return t('forum.time.ended', {}, 'ended');
    var h = Math.floor(d / 3600000);
    if (h >= 48) return t('forum.time.days_left', { count: Math.floor(h / 24) }, Math.floor(h / 24) + ' days left');
    if (h >= 1) return t('forum.time.hours_left', { count: h }, h + ' hours left');
    var mins = Math.max(1, Math.round(d / 60000));
    return t('forum.time.minutes_left', { count: mins }, mins + ' minutes left');
  }

  function newKey() {
    return 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  }

  /* -------------------------------------------------------------------- api */

  function apiBase() {
    var a = app();
    return (a && a.forum && a.forum.attribute('apiUrl')) || '/api';
  }

  function get(what, into, done) {
    var a = app();
    S.loading = true;
    return a.request({ method: 'GET', url: apiBase() + '/store/' + what })
      .then(function (res) {
        S[into] = res;
        S.loading = false;
        S.error = null;
        if (into === 'data' && !S.category && res.categories && res.categories.length) {
          S.category = res.categories[0].key;
        }
        if (done) done(res);
        m.redraw();
      })
      .catch(function (e) {
        S.loading = false;
        S.error = (e && e.response && (e.response.error || e.response.detail)) || t('forum.error.no_answer', {}, 'The store did not answer. Reload the page.');
        m.redraw();
      });
  }

  function post(action, body) {
    var a = app();
    return a.request({
      method: 'POST',
      url: apiBase() + '/store/' + action,
      body: body,
      errorHandler: function (e) { throw e; },
    });
  }

  function alertError(text) {
    var a = app();
    if (a && a.alerts) a.alerts.show({ type: 'error' }, text);
  }

  function alertOk(text) {
    var a = app();
    if (a && a.alerts) a.alerts.show({ type: 'success' }, text);
  }

  /* ------------------------------------------------------- purchase actions */

  function buy(item, opts) {
    opts = opts || {};
    if (S.busy[item.sku]) return;

    // One key per intent. A double click reuses it, so the second request is
    // answered from the first order instead of charging again.
    S.keys[item.sku] = S.keys[item.sku] || newKey();
    S.busy[item.sku] = true;
    m.redraw();

    var body = { sku: item.sku, key: S.keys[item.sku] };
    if (opts.gift) body.gift = opts.gift;
    if (opts.provider) body.provider = opts.provider;
    if (opts.card) body.card = opts.card;
    if (opts.fault) body.fault = opts.fault;

    return post('purchase', body).then(function (res) {
      S.busy[item.sku] = false;
      S.keys[item.sku] = null;
      S.dialog = null;
      S.lastResult = res;

      var won = res.granted && res.granted.won;
      alertOk(won
        ? t('forum.alert.box_won', { name: won.name, rarity: rarityLabel(won.rarity), worth: num(won.worth) },
          'The box gave you ' + won.name + ' (' + won.rarity + ', normally ' + num(won.worth) + ').')
        : (opts.gift
          ? t('forum.alert.gift_sent', { item: item.name, username: opts.gift }, item.name + ' sent to ' + opts.gift + '.')
          : t('forum.alert.bought', { item: item.name }, item.name + ' is yours.')));

      refreshAll();
      return res;
    }).catch(function (e) {
      S.busy[item.sku] = false;
      var res = (e && e.response) || {};
      alertError(res.error || t('forum.error.purchase_failed', {}, 'That did not go through. Nothing was charged.'));
      S.keys[item.sku] = null;
      m.redraw();
    });
  }

  function refund(order) {
    return post('refund', { order: order.id }).then(function () {
      alertOk(t('forum.alert.refunded', { count: Number(order.total) || 0 }, 'Refunded ' + num(order.total) + ' credits.'));
      refreshAll();
    }).catch(function (e) {
      alertError(((e && e.response) || {}).error || t('forum.error.refund_failed', {}, 'That order could not be refunded.'));
    });
  }

  function redeem(kind, arg) {
    var body = { kind: kind };
    if (kind === 'rename') body.username = arg;
    else body.discussion = arg;

    return post('redeem', body).then(function (res) {
      alertOk(kind === 'rename'
        ? t('forum.alert.renamed', { username: res.username }, 'You are now ' + res.username + '.')
        : (kind === 'bump'
          ? t('forum.alert.bumped', {}, 'Thread bumped.')
          : t('forum.alert.redeemed', { when: when(res.expiresAt) }, 'Done. It ends ' + when(res.expiresAt) + '.')));
      S.redeeming = null;
      refreshAll();
    }).catch(function (e) {
      alertError(((e && e.response) || {}).error || t('forum.error.redeem_failed', {}, 'That could not be redeemed.'));
    });
  }

  function refreshAll() {
    var a = app();
    get('catalogue', 'data', function (res) {
      // Keep the header chip and the session in step with the new balance
      // without a page reload; the chip reads app.session.user.
      if (a && a.session && a.session.user && res.me && !res.me.guest) {
        try {
          a.session.user.pushAttributes({ points: res.me.balance });
        } catch (e) { /* older payload shape; the store still shows the truth */ }
      }
    });
    if (S.orders) get('orders', 'orders');
    if (S.admin) get('admin', 'admin');
  }

  /* ----------------------------------------------------------------- pieces */

  function balanceBar() {
    var me = (S.data && S.data.me) || {};
    if (me.guest) {
      return m('div.StoreBar.StoreBar--guest', [
        m('div.StoreBar-line', t('forum.bar.guest', {}, 'Sign in to see your balance and buy anything here.')),
      ]);
    }

    var boosts = me.boosts || { earn: 1 };
    var multiplier = (me.earn || 1) * (boosts.earn || 1);

    return m('div.StoreBar', [
      m('div.StoreBar-balance', [
        icon('ph:coins-fill', 'StoreBar-coin'),
        m('span.StoreBar-n', num(me.balance)),
        m('span.StoreBar-label', t('forum.bar.credits_unit', { count: Number(me.balance) || 0 }, 'credits')),
      ]),
      m('div.StoreBar-facts', [
        m('div.StoreFact', [
          m('span.StoreFact-k', t('forum.bar.membership', {}, 'Membership')),
          m('span.StoreFact-v', { style: me.tierColor ? { color: me.tierColor } : {} }, me.tierName || t('forum.tier.standard', {}, 'Standard')),
          me.tierExpiresAt ? m('span.StoreFact-sub', countdown(me.tierExpiresAt)) : null,
        ]),
        me.discount > 0 ? m('div.StoreFact', [
          m('span.StoreFact-k', t('forum.bar.discount', {}, 'Your discount')),
          m('span.StoreFact-v', pct(me.discount)),
          m('span.StoreFact-sub', t('forum.bar.discount_sub', {}, 'applied to the prices below')),
        ]) : null,
        m('div.StoreFact', [
          m('span.StoreFact-k', t('forum.bar.earn_rate', {}, 'Earning rate')),
          m('span.StoreFact-v', dec(multiplier, 2) + '×'),
          m('span.StoreFact-sub', boosts.earn > 1 ? t('forum.bar.boost_active', {}, 'boost active') : t('forum.bar.base_rate', {}, 'base rate for your tier')),
        ]),
        m('div.StoreFact', [
          m('span.StoreFact-k', t('forum.bar.lifetime', {}, 'Lifetime earned')),
          m('span.StoreFact-v', shortNum(me.lifetime)),
          m('span.StoreFact-sub', t('forum.bar.lifetime_sub', {}, 'spending never reduces this')),
        ]),
      ]),
    ]);
  }

  function tabs() {
    var me = (S.data && S.data.me) || {};
    var here = window.location.pathname;

    var items = [
      { path: '/store', label: t('forum.nav.catalogue', {}, 'Catalogue'), icon: 'ph:storefront-fill' },
      { path: '/store/inventory', label: t('forum.nav.inventory', {}, 'What you own'), icon: 'ph:backpack-fill' },
      { path: '/store/orders', label: t('forum.nav.orders', {}, 'Orders'), icon: 'ph:receipt-fill' },
    ];
    if (me.canAdmin) items.push({ path: '/store/admin', label: t('forum.nav.manage', {}, 'Manage'), icon: 'ph:sliders-fill' });

    return m('nav.StoreTabs', items.map(function (t) {
      return m('a.StoreTab', {
        href: t.path,
        class: here === t.path ? 'active' : '',
        onclick: function (e) {
          if (e.metaKey || e.ctrlKey || e.button) return;
          e.preventDefault();
          m.route.set(t.path);
        },
      }, [icon(t.icon), m('span', t.label)]);
    }));
  }

  function priceTag(item) {
    if (item.currency === 'money') {
      return m('div.StoreCard-price', [
        m('span.StoreCard-money', money(item.money)),
        m('span.StoreCard-note', t('forum.price.card_not_live', {}, 'card not live')),
      ]);
    }
    return m('div.StoreCard-price', [
      item.saving > 0 ? m('span.StoreCard-was', num(item.listPrice)) : null,
      icon('ph:coins-fill', 'StoreCard-coin'),
      m('span.StoreCard-n', num(item.price)),
      item.saving > 0 ? m('span.StoreCard-save', '-' + num(item.saving)) : null,
    ]);
  }

  function preview(item) {
    var p = item.preview || {};
    if (p.type === 'style') {
      return m('div.StorePreview', m('span.lmx-name.StorePreview-name', { class: p.class }, t('forum.preview.username_sample', {}, 'Username')));
    }
    if (p.type === 'frame') {
      return m('div.StorePreview', m('span.lmx-frame.StorePreview-frame', { class: p.class },
        m('span.StorePreview-avatar', icon('ph:user-fill'))));
    }
    if (p.type === 'tier') {
      return m('div.StorePreview.StorePreview--tier', (p.headline || []).map(function (h) {
        return m('div.StorePreview-perk', [icon('ph:check-bold'), m('span', h)]);
      }));
    }
    return null;
  }

  function card(item) {
    var me = (S.data && S.data.me) || {};
    var locked = item.locked;
    var cannotAfford = !locked && !item.affordable && item.currency !== 'money';
    var busy = !!S.busy[item.sku];

    return m('div.StoreCard', {
      key: item.sku,
      class: [
        'rarity-' + item.rarity,
        item.owned ? 'is-owned' : '',
        locked ? 'is-locked' : '',
        cannotAfford ? 'is-poor' : '',
      ].join(' '),
    }, [
      m('div.StoreCard-head', [
        m('span.StoreCard-icon', { style: item.color ? { color: item.color } : {} }, icon(item.icon)),
        m('div.StoreCard-title', [
          m('h3', item.name),
          m('span.StoreCard-rarity', rarityLabel(item.rarity)),
        ]),
      ]),
      preview(item),
      // A membership card already lists its three headline perks as the
      // preview, and the blurb is those same three sentences joined up. Printing
      // both is how a card ends up saying everything twice.
      item.kind === 'tier' ? null : m('p.StoreCard-blurb', item.blurb),
      m('div.StoreCard-meta', [
        item.durationDays ? m('span.StoreChip', [icon('ph:clock-fill'), t('forum.chip.days', { count: item.durationDays }, item.durationDays + ' days')]) : null,
        item.uses ? m('span.StoreChip', [icon('ph:ticket-fill'), t('forum.chip.uses', { count: item.uses }, item.uses + (item.uses > 1 ? ' uses' : ' use'))]) : null,
        item.stockLeft !== null && item.stockLeft !== undefined
          ? m('span.StoreChip.StoreChip--stock', [icon('ph:package-fill'), t('forum.chip.stock', { left: num(item.stockLeft), total: num(item.stockTotal) }, item.stockLeft + ' of ' + item.stockTotal + ' left')])
          : null,
        item.availableUntil ? m('span.StoreChip.StoreChip--timed', [icon('ph:hourglass-fill'), countdown(item.availableUntil)]) : null,
        item.minTier && item.minTier !== 'standard'
          ? m('span.StoreChip', [icon('ph:lock-simple-fill'), item.minTierName || item.minTier]) : null,
        item.holders > 0 ? m('span.StoreChip.StoreChip--holders', [icon('ph:users-fill'), t('forum.chip.holders', { count: item.holders }, item.holders + (item.holders === 1 ? ' member has this' : ' members have this'))]) : null,
      ]),
      m('div.StoreCard-foot', [
        priceTag(item),
        item.owned && item.ownedCount > 0 && item.kind !== 'boost' && item.kind !== 'credits'
          ? m('span.StoreCard-owned', [icon('ph:check-circle-fill'), t('forum.card.owned', {}, 'owned')])
          : m('button.Button.StoreCard-buy', {
            disabled: !!locked || busy || me.guest,
            class: cannotAfford ? 'is-poor' : '',
            onclick: function () { S.dialog = { item: item, gift: '', card: '' }; },
          }, busy ? t('forum.card.working', {}, 'working…') : buyLabel(item, me)),
      ]),
      locked ? m('div.StoreCard-locked', [icon('ph:info-fill'), m('span', locked)]) : null,
      !locked && cannotAfford ? m('div.StoreCard-locked', [
        icon('ph:coin-vertical-fill'),
        m('span', t('forum.card.short', { count: item.price - (me.balance || 0) }, num(item.price - (me.balance || 0)) + ' credits short')),
      ]) : null,
    ]);
  }

  /**
   * What the button should say. "Buy" is wrong for the membership somebody is
   * already on — that is a renewal, and calling it a purchase is how people end
   * up thinking they bought a second one.
   */
  function buyLabel(item, me) {
    if (item.currency === 'money') return t('forum.buy.credits', {}, 'Buy credits');
    if (item.kind === 'tier' && item.payload && item.payload.tier === me.tier) return t('forum.buy.renew', {}, 'Renew');
    if (item.kind === 'tier') return t('forum.buy.upgrade', {}, 'Upgrade');
    return t('forum.buy.buy', {}, 'Buy');
  }

  function dialog() {
    if (!S.dialog) return null;
    var item = S.dialog.item;
    var me = (S.data && S.data.me) || {};
    var isMoney = item.currency === 'money';
    var after = (me.balance || 0) - (isMoney ? 0 : item.price);
    var busy = !!S.busy[item.sku];

    return m('div.StoreModal', { onclick: function (e) { if (e.target === e.currentTarget) S.dialog = null; } }, [
      m('div.StoreModal-panel', [
        m('h3.StoreModal-title', item.name),
        m('p.StoreModal-blurb', item.blurb),

        // Order matters: what it costs, what came off, what you pay, what is
        // left. The first cut listed the ALREADY DISCOUNTED price as "Price"
        // and then subtracted the discount again underneath it, which reads as
        // a double discount and makes the one number that has to be trusted the
        // one number that looks wrong.
        m('dl.StoreModal-lines', [
          isMoney ? m('dt', t('forum.dialog.price', {}, 'Price')) : null,
          isMoney ? m('dd', money(item.money)) : null,

          !isMoney && item.saving > 0 ? m('dt', t('forum.dialog.list_price', {}, 'List price')) : null,
          !isMoney && item.saving > 0 ? m('dd', t('forum.dialog.credits_amount', { count: item.listPrice }, num(item.listPrice) + ' credits')) : null,
          !isMoney && item.saving > 0 ? m('dt', t('forum.dialog.discount', {}, 'Your membership discount')) : null,
          !isMoney && item.saving > 0 ? m('dd.is-saving', '-' + num(item.saving)) : null,

          !isMoney ? m('dt', t('forum.dialog.you_pay', {}, 'You pay')) : null,
          !isMoney ? m('dd', t('forum.dialog.credits_amount', { count: item.price }, num(item.price) + ' credits')) : null,
          !isMoney ? m('dt', t('forum.dialog.balance_after', {}, 'Balance after')) : null,
          !isMoney ? m('dd', { class: after < 0 ? 'is-negative' : '' }, t('forum.dialog.credits_amount', { count: after }, num(after) + ' credits')) : null,
          item.durationDays ? m('dt', t('forum.dialog.lasts', {}, 'Lasts')) : null,
          item.durationDays ? m('dd', t('forum.chip.days', { count: item.durationDays }, item.durationDays + ' days')) : null,
        ]),

        item.giftable && !isMoney ? m('div.StoreModal-gift', [
          m('label', t('forum.dialog.gift_label', {}, 'Buy it for someone else')),
          m('input.FormControl', {
            placeholder: t('forum.dialog.gift_placeholder', {}, 'their username, or leave empty'),
            value: S.dialog.gift,
            oninput: function (e) { S.dialog.gift = e.target.value; },
          }),
        ]) : null,

        isMoney ? m('div.StoreModal-card', [
          m('label', t('forum.dialog.card_label', {}, 'Card number')),
          m('input.FormControl', {
            placeholder: '4242 4242 4242 4242',
            value: S.dialog.card,
            oninput: function (e) { S.dialog.card = e.target.value; },
          }),
          m('p.StoreModal-mock', t('forum.dialog.card_mock', {}, 'Card payment is not connected yet. This path is wired end to end against a mock provider so the real one can drop in; nothing is charged and no card details are stored or sent anywhere. 4000 0000 0000 0002 is treated as a declined card.')),
        ]) : null,

        m('div.StoreModal-actions', [
          m('button.Button', { onclick: function () { S.dialog = null; } }, t('forum.action.cancel', {}, 'Cancel')),
          m('button.Button.Button--primary', {
            disabled: busy || (!isMoney && after < 0),
            onclick: function () {
              buy(item, {
                gift: S.dialog.gift ? S.dialog.gift.trim() : null,
                card: S.dialog.card || null,
                provider: isMoney ? 'card' : null,
              });
            },
          }, busy ? t('forum.card.working', {}, 'working…') : (S.dialog.gift ? t('forum.dialog.send', {}, 'Send it') : t('forum.dialog.confirm', {}, 'Confirm'))),
        ]),
      ]),
    ]);
  }

  /* ----------------------------------------------------------- catalogue view */

  function catalogueView() {
    if (!S.data) return m('div.StoreLoading', t('forum.loading.catalogue', {}, 'Loading the catalogue…'));

    var cats = S.data.categories || [];
    var items = (S.data.items || []).filter(function (i) {
      return !S.category || i.category === S.category;
    });

    return [
      balanceBar(),
      m('div.StoreCats', cats.map(function (c) {
        return m('button.StoreCat', {
          class: S.category === c.key ? 'active' : '',
          onclick: function () { S.category = c.key; },
        }, [icon(c.icon), m('span', c.label), m('span.StoreCat-n', c.count)]);
      })),
      m('div.StoreGrid', items.map(card)),
      items.length === 0 ? m('p.StoreEmpty', t('forum.empty.category', {}, 'Nothing in this section yet.')) : null,
    ];
  }

  /* ---------------------------------------------------------- inventory view */

  function inventoryView() {
    if (!S.data) return m('div.StoreLoading', t('forum.loading.generic', {}, 'Loading…'));
    var held = S.data.held || [];
    var threads = S.data.threads || [];

    var consumables = held.filter(function (h) { return h.usesLeft !== null && h.usesLeft > 0; });
    var timed = held.filter(function (h) { return h.expiresAt; });
    var permanent = held.filter(function (h) { return !h.expiresAt && h.usesLeft === null; });

    return [
      balanceBar(),
      section(t('forum.inventory.ready', {}, 'Ready to use'), consumables.length ? consumables.map(function (h) {
        return m('div.StoreHeld', [
          heldMain(h, t('forum.inventory.uses_left', { count: h.usesLeft }, h.usesLeft + (h.usesLeft === 1 ? ' use left' : ' uses left'))),
          redeemControl(h, threads),
        ]);
      }) : m('p.StoreEmpty', t('forum.inventory.ready_empty', {}, 'Nothing waiting to be used. Pins, highlights, bumps and name changes appear here after you buy them.'))),

      section(t('forum.inventory.running', {}, 'Running now'), timed.length ? timed.map(function (h) {
        return m('div.StoreHeld', [heldMain(h, t('forum.inventory.ends', { countdown: countdown(h.expiresAt), when: when(h.expiresAt) }, countdown(h.expiresAt) + ', ends ' + when(h.expiresAt)))]);
      }) : m('p.StoreEmpty', t('forum.inventory.running_empty', {}, 'No boosts or memberships running.'))),

      section(t('forum.inventory.permanent', {}, 'Kept forever'), permanent.length ? permanent.map(function (h) {
        return m('div.StoreHeld', [
          heldMain(h, h.from && h.from !== h.name ? t('forum.inventory.from', { name: h.from }, 'from ' + h.from) : kindLabel(h.kind)),
          h.preview && h.preview.type === 'style'
            ? m('span.lmx-name.StoreHeld-preview', { class: h.preview.class }, h.name)
            : null,
        ]);
      }) : m('p.StoreEmpty', t('forum.inventory.permanent_empty', {}, 'Colours and frames you buy stay here permanently, even if a membership lapses.'))),
    ];
  }

  // Was a module-level map of English words; the labels now live in
  // locale/{en,es}.yml under forum.kind.* and are read by kindLabel(), which
  // runs per render rather than at script-evaluation time.

  /** The left half of an inventory row: icon, name, one line of context. */
  function heldMain(h, sub) {
    return m('div.StoreHeld-main', [
      m('span.StoreHeld-icon', { style: h.color ? { color: h.color } : {} }, icon(h.icon || 'ph:tag-fill')),
      m('span.StoreHeld-text', [
        m('strong', h.name || h.sku),
        m('span.StoreHeld-sub', sub),
      ]),
    ]);
  }

  function redeemControl(held, threads) {
    var kind = held.kind;

    if (kind === 'rename') {
      return m('div.StoreHeld-action', [
        m('input.FormControl', {
          placeholder: t('forum.inventory.new_username_placeholder', {}, 'new username'),
          value: S.redeeming && S.redeeming.kind === 'rename' ? S.redeeming.value : '',
          oninput: function (e) { S.redeeming = { kind: 'rename', value: e.target.value }; },
        }),
        m('button.Button.Button--primary', {
          onclick: function () { redeem('rename', (S.redeeming || {}).value || ''); },
        }, t('forum.inventory.rename_button', {}, 'Change my name')),
      ]);
    }

    if (kind === 'highlight' || kind === 'sticky' || kind === 'bump') {
      if (!threads.length) {
        return m('span.StoreHeld-sub', t('forum.inventory.no_threads', {}, 'Start a thread first, then come back.'));
      }
      var sel = S.redeeming && S.redeeming.kind === kind ? S.redeeming.value : threads[0].id;
      return m('div.StoreHeld-action', [
        m('select.FormControl', {
          onchange: function (e) { S.redeeming = { kind: kind, value: Number(e.target.value) }; },
        }, threads.map(function (t) {
          return m('option', { value: t.id, selected: t.id === sel }, t.title);
        })),
        m('button.Button.Button--primary', {
          onclick: function () { redeem(kind, sel); },
        }, kind === 'bump' ? t('forum.inventory.bump', {}, 'Bump it') : (kind === 'sticky' ? t('forum.inventory.pin', {}, 'Pin it') : t('forum.inventory.highlight', {}, 'Highlight it'))),
      ]);
    }

    return null;
  }

  function section(title, body) {
    return m('section.StoreSection', [m('h3.StoreSection-title', title), m('div.StoreSection-body', body)]);
  }

  /* ------------------------------------------------------------- orders view */

  function stateChip(state) {
    var label = t('forum.state.' + state, {}, {
      granted: 'delivered', refused: 'refused', failed: 'failed',
      refunded: 'refunded', expired: 'ended', pending: 'in flight',
    }[state] || state);

    return m('span.StoreState', { class: 'is-' + state }, label);
  }

  function ordersView() {
    if (!S.orders) return m('div.StoreLoading', t('forum.loading.orders', {}, 'Loading your orders…'));
    var orders = S.orders.orders || [];
    var gifts = S.orders.gifts || [];
    var ledger = S.orders.ledger || [];

    return [
      balanceBar(),
      section(t('forum.orders.title', {}, 'Your orders'), orders.length ? [m('table.StoreTable', [
        m('thead', m('tr', [
          m('th', t('forum.table.when', {}, 'When')), m('th', t('forum.table.item', {}, 'Item')), m('th', t('forum.table.paid', {}, 'Paid')), m('th', t('forum.table.state', {}, 'State')), m('th', ''),
        ])),
        // Capped, with a way to see the rest. A history that renders every
        // order somebody has ever made is a 5,000 pixel page by the second week.
        m('tbody', orders.slice(0, S.allOrders ? orders.length : 25).map(function (o) {
          return m('tr', { key: o.id }, [
            m('td.StoreTable-when', when(o.createdAt)),
            m('td', [
              m('span', o.name),
              o.gift ? m('span.StoreTable-sub', t('forum.orders.gift_to', { name: o.recipientName || '#' + o.recipientId }, 'gift to ' + (o.recipientName || '#' + o.recipientId))) : null,
              o.error ? m('span.StoreTable-sub.is-error', o.error) : null,
            ]),
            m('td.StoreTable-num', o.total ? num(o.total) : '-'),
            m('td', stateChip(o.state)),
            m('td', o.state === 'granted' && o.refundableUntil && new Date(o.refundableUntil) > new Date()
              ? m('button.Button.Button--text', { onclick: function () { refund(o); } }, t('forum.action.refund', {}, 'Refund'))
              : null),
          ]);
        })),
      ]),
      orders.length > 25 ? m('button.Button.Button--text.StoreMore', {
        onclick: function () { S.allOrders = !S.allOrders; },
      }, S.allOrders ? t('forum.action.show_fewer', {}, 'Show fewer') : t('forum.orders.show_all', { count: orders.length }, 'Show all ' + orders.length + ' orders')) : null,
      ] : m('p.StoreEmpty', t('forum.orders.empty', {}, 'You have not bought anything yet.'))),

      gifts.length ? section(t('forum.orders.gifts_title', {}, 'Gifts you were given'), m('table.StoreTable', [
        m('thead', m('tr', [m('th', t('forum.table.when', {}, 'When')), m('th', t('forum.table.item', {}, 'Item')), m('th', t('forum.table.from', {}, 'From'))])),
        m('tbody', gifts.map(function (g) {
          return m('tr', { key: g.id }, [
            m('td.StoreTable-when', when(g.completedAt || g.createdAt)),
            m('td', g.name),
            m('td', g.fromName || '#' + g.userId),
          ]);
        })),
      ])) : null,

      section(t('forum.ledger.title', {}, 'Credit movements'), ledger.length ? [m('table.StoreTable', [
        m('thead', m('tr', [m('th', t('forum.table.when', {}, 'When')), m('th', t('forum.table.reason', {}, 'Reason')), m('th', t('forum.table.reference', {}, 'Reference')), m('th', t('forum.table.amount', {}, 'Amount'))])),
        m('tbody', ledger.slice(0, S.allLedger ? ledger.length : 20).map(function (l, i) {
          return m('tr', { key: i }, [
            m('td.StoreTable-when', when(l.created_at)),
            m('td', t('forum.ledger.reason.' + l.reason, {}, l.reason)),
            m('td.StoreTable-sub', l.ref || ''),
            m('td.StoreTable-num', { class: l.delta < 0 ? 'is-debit' : 'is-credit' },
              (l.delta > 0 ? '+' : '') + num(l.delta)),
          ]);
        })),
      ]),
      ledger.length > 20 ? m('button.Button.Button--text.StoreMore', {
        onclick: function () { S.allLedger = !S.allLedger; },
      }, S.allLedger ? t('forum.action.show_fewer', {}, 'Show fewer') : t('forum.ledger.show_all', { count: ledger.length }, 'Show all ' + ledger.length + ' movements')) : null,
      ] : m('p.StoreEmpty', t('forum.ledger.empty', {}, 'No store movements on your ledger yet.'))),
    ];
  }

  /* -------------------------------------------------------------- admin view */

  function adminView() {
    if (!S.admin) return m('div.StoreLoading', t('forum.loading.generic', {}, 'Loading…'));
    var a = S.admin;
    var st = a.stats || {};

    return [
      m('div.StoreStats', [
        stat(t('forum.admin.stat.items', {}, 'Items'), st.items), stat(t('forum.admin.stat.orders', {}, 'Orders'), st.orders), stat(t('forum.admin.stat.delivered', {}, 'Delivered'), st.granted),
        stat(t('forum.admin.stat.refused', {}, 'Refused'), st.refused), stat(t('forum.admin.stat.failed', {}, 'Failed'), st.failed), stat(t('forum.admin.stat.refunded', {}, 'Refunded'), st.refunded),
        stat(t('forum.admin.stat.spent', {}, 'Credits spent'), shortNum(st.spent)), stat(t('forum.admin.stat.circulating', {}, 'Credits circulating'), shortNum(st.circulating)),
        stat(t('forum.admin.stat.entitlements', {}, 'Live entitlements'), st.entitlements),
      ]),

      section(t('forum.admin.adjust_title', {}, 'Adjust a balance'), m('div.StoreAdminForm', [
        m('input.FormControl', { placeholder: t('forum.admin.username_placeholder', {}, 'username'), id: 'adj-user' }),
        m('input.FormControl', { placeholder: t('forum.admin.amount_placeholder', {}, 'amount, e.g. 500 or -500'), id: 'adj-delta', type: 'number' }),
        m('input.FormControl', { placeholder: t('forum.admin.reason_placeholder', {}, 'reason (recorded)'), id: 'adj-note' }),
        m('button.Button.Button--primary', {
          onclick: function () {
            var u = document.getElementById('adj-user').value;
            var d = Number(document.getElementById('adj-delta').value);
            var n = document.getElementById('adj-note').value;
            post('admin', { op: 'balance.adjust', username: u, delta: d, note: n })
              .then(function (r) {
                alertOk(t('forum.admin.adjusted', { username: r.username, before: num(r.before), after: num(r.after) }, r.username + ': ' + num(r.before) + ' to ' + num(r.after)));
                get('admin', 'admin');
              })
              .catch(function (e) { alertError(((e && e.response) || {}).error || t('forum.admin.failed', {}, 'That did not work.')); });
          },
        }, t('forum.action.apply', {}, 'Apply')),
      ])),

      m('div.StoreCats.StoreCats--admin', [
        ['catalogue', t('forum.admin.tab.catalogue', {}, 'Catalogue'), 'ph:tag-fill'],
        ['orders', t('forum.admin.tab.orders', {}, 'Recent orders'), 'ph:receipt-fill'],
        ['audit', t('forum.admin.tab.audit', {}, 'Audit trail'), 'ph:scroll-fill'],
      ].map(function (t) {
        return m('button.StoreCat', {
          class: S.adminTab === t[0] ? 'active' : '',
          onclick: function () { S.adminTab = t[0]; },
        }, [icon(t[2]), m('span', t[1])]);
      })),

      S.adminTab !== 'catalogue' ? null : section(t('forum.admin.catalogue_section', {}, 'Catalogue'), [
        m('div.StoreAdminBar', [
          m('button.Button', {
            onclick: function () {
              post('admin', { op: 'sync' }).then(function (r) {
                alertOk(t('forum.admin.synced', { added: r.added, updated: r.updated }, 'Catalogue synced: ' + r.added + ' added, ' + r.updated + ' refreshed.'));
                get('admin', 'admin');
              });
            },
          }, t('forum.admin.sync_button', {}, 'Sync shipped catalogue')),
          m('button.Button', {
            onclick: function () { S.adminEdit = { sku: '', name: '', kind: 'boost', category: 'boosts', price: 1000, active: true, giftable: true, discountable: true, payload: '{}' }; },
          }, t('forum.admin.new_item', {}, 'New item')),
        ]),
        S.adminEdit ? adminEditor() : null,
        m('input.FormControl.StoreAdminSearch', {
          placeholder: t('forum.admin.filter_placeholder', {}, 'filter by SKU, name or kind'),
          value: S.adminFilter,
          oninput: function (e) { S.adminFilter = e.target.value.toLowerCase(); },
        }),
        m('table.StoreTable.StoreTable--admin', [
          m('thead', m('tr', [
            m('th', t('forum.table.sku', {}, 'SKU')), m('th', t('forum.table.name', {}, 'Name')), m('th', t('forum.table.kind', {}, 'Kind')), m('th', t('forum.table.price', {}, 'Price')),
            m('th', t('forum.table.stock', {}, 'Stock')), m('th', t('forum.table.state', {}, 'State')), m('th', ''),
          ])),
          // Filtered and capped. The unfiltered screen rendered 90 items, 100
          // orders and 60 audit rows in one 17,000 pixel column, which is a
          // data dump rather than a management screen.
          m('tbody', (a.items || []).filter(function (i) {
            if (!S.adminFilter) return true;
            return (i.sku + ' ' + i.name + ' ' + i.kind + ' ' + i.category).toLowerCase().indexOf(S.adminFilter) >= 0;
          }).slice(0, 40).map(function (i) {
            return m('tr', { key: i.sku, class: i.active ? '' : 'is-off' }, [
              m('td.StoreTable-sub', i.sku),
              m('td', i.name),
              m('td.StoreTable-sub', i.kind),
              m('td.StoreTable-num', num(i.price)),
              m('td.StoreTable-num', i.stock_total === null ? '∞' : (i.stock_total - i.stock_sold) + '/' + i.stock_total),
              m('td', i.active ? m('span.StoreState.is-granted', t('forum.admin.on_sale', {}, 'on sale')) : m('span.StoreState.is-refused', t('forum.admin.off', {}, 'off'))),
              m('td', [
                m('button.Button.Button--text', {
                  onclick: function () {
                    S.adminEdit = Object.assign({}, i, { payload: JSON.stringify(i.payload || {}) });
                  },
                }, t('forum.action.edit', {}, 'Edit')),
                m('button.Button.Button--text', {
                  onclick: function () {
                    post('admin', { op: 'item.toggle', sku: i.sku }).then(function () { get('admin', 'admin'); });
                  },
                }, i.active ? t('forum.action.retire', {}, 'Retire') : t('forum.action.restore', {}, 'Restore')),
              ]),
            ]);
          })),
        ]),
      ]),

      S.adminTab !== 'orders' ? null : section(t('forum.admin.orders_section', {}, 'Recent orders'), m('table.StoreTable', [
        m('thead', m('tr', [m('th', t('forum.table.when', {}, 'When')), m('th', t('forum.table.buyer', {}, 'Buyer')), m('th', t('forum.table.item', {}, 'Item')), m('th', t('forum.table.paid', {}, 'Paid')), m('th', t('forum.table.state', {}, 'State')), m('th', '')])),
        m('tbody', (a.orders || []).slice(0, 30).map(function (o) {
          return m('tr', { key: o.id }, [
            m('td.StoreTable-when', when(o.createdAt)),
            m('td', o.userName || '#' + o.userId),
            m('td', [o.name, o.gift ? m('span.StoreTable-sub', t('forum.orders.gift_to', { name: o.recipientName || o.recipientId }, 'gift to ' + (o.recipientName || o.recipientId))) : null]),
            m('td.StoreTable-num', num(o.total)),
            m('td', [stateChip(o.state), o.error ? m('span.StoreTable-sub.is-error', o.error) : null]),
            m('td', o.state === 'granted'
              ? m('button.Button.Button--text', { onclick: function () { refund(o); } }, t('forum.action.refund', {}, 'Refund'))
              : null),
          ]);
        })),
      ])),

      S.adminTab !== 'audit' ? null : section(t('forum.admin.audit_section', {}, 'Audit trail'), m('table.StoreTable', [
        m('thead', m('tr', [m('th', t('forum.table.when', {}, 'When')), m('th', t('forum.table.action', {}, 'Action')), m('th', t('forum.table.subject', {}, 'Subject')), m('th', t('forum.table.before', {}, 'Before')), m('th', t('forum.table.after', {}, 'After')), m('th', t('forum.table.note', {}, 'Note'))])),
        m('tbody', (a.audit || []).slice(0, 25).map(function (r) {
          return m('tr', { key: r.id }, [
            m('td.StoreTable-when', when(r.created_at)),
            m('td', r.action),
            m('td.StoreTable-sub', r.subject || ''),
            m('td.StoreTable-sub', JSON.stringify(r.before || {}).slice(0, 60)),
            m('td.StoreTable-sub', JSON.stringify(r.after || {}).slice(0, 60)),
            m('td.StoreTable-sub', r.note || ''),
          ]);
        })),
      ])),
    ];
  }

  function stat(label, value) {
    return m('div.StoreStat', [m('span.StoreStat-v', value === undefined || value === null ? '-' : (typeof value === 'number' ? num(value) : String(value))), m('span.StoreStat-k', label)]);
  }

  function adminEditor() {
    var e = S.adminEdit;
    var field = function (key, label, type) {
      return m('label.StoreField', [
        m('span', label),
        m('input.FormControl', {
          type: type || 'text',
          value: e[key] === null || e[key] === undefined ? '' : e[key],
          oninput: function (ev) { e[key] = type === 'number' ? Number(ev.target.value) : ev.target.value; },
        }),
      ]);
    };
    var toggle = function (key, label) {
      return m('label.StoreField.StoreField--check', [
        m('input', { type: 'checkbox', checked: !!e[key], onchange: function (ev) { e[key] = ev.target.checked; } }),
        m('span', label),
      ]);
    };

    return m('div.StoreEditor', [
      m('div.StoreEditor-grid', [
        field('sku', t('forum.admin.field.sku', {}, 'SKU')), field('name', t('forum.admin.field.name', {}, 'Name')), field('kind', t('forum.admin.field.kind', {}, 'Kind')), field('category', t('forum.admin.field.category', {}, 'Category')),
        field('price', t('forum.admin.field.price', {}, 'Price'), 'number'), field('rarity', t('forum.admin.field.rarity', {}, 'Rarity')), field('icon', t('forum.admin.field.icon', {}, 'Icon')), field('color', t('forum.admin.field.color', {}, 'Colour')),
        field('max_per_user', t('forum.admin.field.max_per_user', {}, 'Max per user'), 'number'), field('stock_total', t('forum.admin.field.stock_total', {}, 'Stock total'), 'number'),
        field('duration_days', t('forum.admin.field.duration_days', {}, 'Duration (days)'), 'number'), field('uses', t('forum.admin.field.uses', {}, 'Charges'), 'number'),
        field('refund_minutes', t('forum.admin.field.refund_minutes', {}, 'Refund window (minutes)'), 'number'), field('min_tier', t('forum.admin.field.min_tier', {}, 'Minimum tier')),
        field('min_rank', t('forum.admin.field.min_rank', {}, 'Minimum rank')), field('available_until', t('forum.admin.field.available_until', {}, 'Closes at (Y-m-d H:i:s)')),
        field('sort', t('forum.admin.field.sort', {}, 'Sort'), 'number'),
      ]),
      m('label.StoreField.StoreField--wide', [m('span', t('forum.admin.field.blurb', {}, 'Blurb')), m('input.FormControl', {
        value: e.blurb || '', oninput: function (ev) { e.blurb = ev.target.value; },
      })]),
      m('label.StoreField.StoreField--wide', [m('span', t('forum.admin.field.payload', {}, 'Payload (JSON)')), m('textarea.FormControl', {
        rows: 3, value: e.payload || '{}', oninput: function (ev) { e.payload = ev.target.value; },
      })]),
      m('div.StoreEditor-checks', [toggle('active', t('forum.admin.field.active', {}, 'On sale')), toggle('giftable', t('forum.admin.field.giftable', {}, 'Giftable')), toggle('discountable', t('forum.admin.field.discountable', {}, 'Membership discount applies'))]),
      m('div.StoreEditor-actions', [
        m('button.Button', { onclick: function () { S.adminEdit = null; } }, t('forum.action.cancel', {}, 'Cancel')),
        m('button.Button.Button--primary', {
          onclick: function () {
            post('admin', Object.assign({ op: 'item.save' }, e)).then(function () {
              alertOk(t('forum.admin.saved', { sku: e.sku }, 'Saved ' + e.sku + '.'));
              S.adminEdit = null;
              get('admin', 'admin');
            }).catch(function (err) {
              alertError(((err && err.response) || {}).error || t('forum.admin.save_failed', {}, 'That did not save.'));
            });
          },
        }, t('forum.action.save', {}, 'Save')),
      ]),
    ]);
  }

  /* -------------------------------------------------------------------- page */

  /**
   * The page component.
   *
   * A plain Mithril component object, NOT a subclass of Flarum's Page.
   *
   * Measured, not assumed: at the moment the route has to be registered — the
   * microtask between the bundle finishing and `app.boot()` running —
   * `flarum.core.compat['common/components/Page'].default` is still undefined,
   * even though the module itself is present and resolves a few milliseconds
   * later. Subclassing it therefore failed exactly once per page load, silently,
   * and the router fell back to the index: /store answered 200 and showed the
   * discussion list. `window.__lmxStore.why` said "no Page component".
   *
   * A component literal has no such dependency, so registration cannot fail for
   * a reason that only exists for two milliseconds. The two things Page would
   * have given us — the body class and the document title — are four lines.
   */
  /**
   * Which of the four screens the current URL means.
   *
   * Read from the path rather than from route attrs: Flarum's route map passes
   * only URL parameters through to the component, so the `attrs` key on a route
   * definition is silently dropped. Every screen therefore rendered the
   * catalogue — /store/orders answered 200, drew the tabs, and showed the shop.
   * The path is the one source that cannot disagree with the address bar.
   */
  function viewFor(path) {
    path = (path || window.location.pathname || '').replace(/\/+$/, '');
    if (path.indexOf('/store/orders') === 0) return 'orders';
    if (path.indexOf('/store/inventory') === 0) return 'inventory';
    if (path.indexOf('/store/admin') === 0) return 'admin';
    return 'catalogue';
  }

  function makePage() {
    return {
      oninit: function (vnode) {
        this.view_ = viewFor();
        document.body.classList.add('App--store');

        var a = app();
        if (a && a.setTitle) {
          a.setTitle(t('forum.title.' + this.view_, {}, {
            catalogue: 'Store',
            inventory: 'What you own',
            orders: 'Your orders',
            admin: 'Store admin',
          }[this.view_] || 'Store'));
        }

        if (!S.data) get('catalogue', 'data');
        if (this.view_ === 'orders' && !S.orders) get('orders', 'orders');
        if (this.view_ === 'admin' && !S.admin) get('admin', 'admin');
      },

      onremove: function () {
        document.body.classList.remove('App--store');
      },

      view: function () {
        // Recomputed per render, not cached from oninit: Mithril reuses the
        // component instance when two routes resolve to the same component, so
        // clicking Orders from the catalogue would otherwise keep drawing the
        // catalogue.
        var which = viewFor();

        if (which !== this.view_) {
          this.view_ = which;
          if (which === 'orders' && !S.orders) get('orders', 'orders');
          if (which === 'admin' && !S.admin) get('admin', 'admin');
        }
        var body;

        if (S.error) {
          body = m('div.StoreError', [icon('ph:warning-fill'), m('span', S.error)]);
        } else if (which === 'orders') {
          body = ordersView();
        } else if (which === 'inventory') {
          body = inventoryView();
        } else if (which === 'admin') {
          body = adminView();
        } else {
          body = catalogueView();
        }

        return m('div.StorePage', m('div.container', [
          m('header.StoreHead', [
            m('h1.StoreHead-title', t('forum.head.title', {}, 'Store')),
            m('p.StoreHead-sub', t('forum.head.sub', {}, 'Credits are earned by posting things people read. Spend them here.')),
          ]),
          tabs(),
          body,
          dialog(),
        ]));
      },
    };
  }

  /* -------------------------------------------------------- route registration */

  function register(compat) {
    if (BOOTED) return true;

    var a = app();
    if (!a) { TRACE.why = 'no app'; return false; }
    if (!a.initializers) { TRACE.why = 'no initializers'; return false; }
    if (!window.m) { TRACE.why = 'no mithril'; return false; }

    var StorePage = makePage();

    TRACE.registered = true;
    TRACE.at = (window.app && window.app.booted) ? 'after boot' : 'before boot';

    BOOTED = true;

    a.initializers.add('local-store', function (app_) {
      app_.routes.store = { path: '/store', component: StorePage };
      app_.routes['store.inventory'] = { path: '/store/inventory', component: StorePage, attrs: { view: 'inventory' } };
      app_.routes['store.orders'] = { path: '/store/orders', component: StorePage, attrs: { view: 'orders' } };
      app_.routes['store.admin'] = { path: '/store/admin', component: StorePage, attrs: { view: 'admin' } };
    });

    return true;
  }

  /**
   * Fallback: the app booted without our route (the accessor never fired, or
   * an initializer threw). Render the store into the content element by hand
   * so /store is never a blank page or a silent redirect to the index.
   */
  function takeover() {
    if (window.location.pathname.indexOf('/store') !== 0) return;
    var a = app();
    if (a && a.routes && a.routes.store) return;

    var host = document.getElementById('content') || document.querySelector('.App-content');
    if (!host) return;

    var StorePage = makePage();
    if (!window.m) return;

    var view = { '/store/orders': 'orders', '/store/inventory': 'inventory', '/store/admin': 'admin' }[window.location.pathname] || 'catalogue';
    host.innerHTML = '';
    m.mount(host, { view: function () { return m(StorePage, { view: view }); } });
    console.warn('store: rendered without the router; in-app navigation to /store will full-load');
  }

  /* --------------------------------------------------------------- bootstrap */

  function hook() {
    var existing = Object.getOwnPropertyDescriptor(window, 'flarum');
    if (existing && !existing.configurable) return;

    var value = window.flarum;

    Object.defineProperty(window, 'flarum', {
      configurable: true,
      enumerable: true,
      get: function () { return value; },
      set: function (v) {
        value = v;
        TRACE.flarumSet = true;
        try { watchCore(v); } catch (e) { TRACE.why = 'watchCore: ' + e; console.warn('store:', e); }
      },
    });

    TRACE.hooked = true;

    if (value) watchCore(value);
  }

  function watchCore(f) {
    if (!f || typeof f !== 'object') return;

    var core = f.core;
    var seen = false;

    var fire = function () {
      if (seen) return;
      seen = true;
      TRACE.coreSet = true;
      // Runs at the end of the bundle script's evaluation and before the
      // separate bootstrap script that calls app.boot(). That gap is the only
      // moment a route can still be added.
      Promise.resolve().then(function () {
        TRACE.fired = true;
        try { register(registry()); } catch (e) { TRACE.why = 'register: ' + e; console.warn('store:', e); }
      });
    };

    Object.defineProperty(f, 'core', {
      configurable: true,
      enumerable: true,
      get: function () { return core; },
      set: function (c) { core = c; fire(); },
    });

    if (core) fire();
  }

  hook();

  window.addEventListener('load', function () {
    try {
      if (!BOOTED) register(registry());
      takeover();
    } catch (e) {
      console.warn('store:', e);
    }
  });
})();
