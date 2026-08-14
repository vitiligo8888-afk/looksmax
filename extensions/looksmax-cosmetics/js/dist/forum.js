/*
 * Cosmetic personalisation — the client half.
 *
 * TWO jobs, one file, because they share the render spec:
 *
 *   1. DECORATE every avatar on the page with its owner's frame, wherever that
 *      avatar appears and whichever extension drew it.
 *   2. The WARDROBE panel on /settings: what you own, live preview on your own
 *      avatar, and the button that puts it on.
 *
 * ── why a DOM decorator ────────────────────────────────────────────────────
 * An avatar shows up in the post author rail (core), the hovercard (core), the
 * profile hero (core + looksmax-userinfo), the discussion list (core +
 * looksmax-theme), the shoutbox (looksmax-chat), the index last-poster
 * (looksmax-index), notifications, search results and the member list. Five
 * extensions own those files and this lane owns none of them. One MutationObserver
 * over `.Avatar` covers all nine call sites with one implementation and zero
 * cross-lane edits. looksmax-icons and looksmax-ranks reached the same answer.
 *
 * ── the interlock with looksmax-ranks ──────────────────────────────────────
 * looksmax-ranks has a partial frame renderer of its own: it wraps the avatar
 * nearest a `.username` node in `<span class="lmx-frame fr-...">`, and it
 * refuses to wrap an avatar whose parent already carries `.lmx-frame`
 * (looksmax-ranks/js/dist/forum.js:254). So the wrapper inserted here carries
 * that class deliberately — it is the handshake that guarantees ONE ring per
 * avatar. When ranks wins the race and wraps first, applyFrame() ADOPTS its
 * wrapper and removes the `fr-*` visual class, so the outcome is the same
 * either way and does not depend on script order.
 *
 * ── where the data comes from ──────────────────────────────────────────────
 * Render specs: `window.__lmxCosDefs`, inlined in the head by
 * src/InjectCosmetics.php. ~4KB, same for every visitor, no request.
 * Who wears what: the `cosmetics` attribute on the user payload the SPA already
 * holds — so the common case makes NO network request at all. The
 * /api/cosmetics/map endpoint is a fallback for surfaces that are raw DOM with
 * no serialized user behind them (the shoutbox, the index last-poster).
 *
 * Nothing here can take the SPA down: the whole file is injected as its own
 * <script> with its own catch, so the worst case is "no frames".
 */
(function () {
  'use strict';

  var DEFS = window.__lmxCosDefs || {};
  var API = '/api/cosmetics';

  /** username|slug -> { frame: slug, banner: slug } */
  var INDEX = Object.create(null);
  var mapFetched = 0;
  var mapPending = false;

  /** Instrumentation. Read by e2e/probe.ts — a decorator that silently does
   *  nothing is indistinguishable from a decorator that has nothing to do. */
  var TRACE = (window.__lmxCos = {
    defs: (DEFS.frame ? Object.keys(DEFS.frame).length : 0) + (DEFS.banner ? Object.keys(DEFS.banner).length : 0),
    avatarsSeen: 0,
    framed: 0,
    adopted: 0,
    unresolved: 0,
    fromStore: 0,
    fromMap: 0,
    mapFetches: 0,
    panel: false,
    lastError: null,
    // e2e/probe.ts asks this "did you know about this account?" per name it
    // finds on the page, which is what separates "nobody here owns a frame"
    // from "the decorator failed on this surface".
    lookup: function (n) { return INDEX[n] || null; },
  });

  // ------------------------------------------------------------------ utils

  function reg() {
    return (window.flarum && window.flarum.core && window.flarum.core.compat) || {};
  }

  function app() {
    var r = reg();
    return (r['forum/app'] && r['forum/app'].default) || window.app || null;
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  function icon(name, cls) {
    // looksmax-icons registers <iconify-icon>. Without it the tag is an unknown
    // inline element with no box, which degrades to "no glyph" rather than to a
    // broken layout.
    var e = document.createElement('iconify-icon');
    e.setAttribute('icon', name);
    e.setAttribute('inline', '');
    if (cls) e.className = cls;
    return e;
  }

  /**
   * A translation, with the SPANISH source string as the fallback.
   *
   * Spanish is this forum's default locale and therefore the source language:
   * locale/es.yml holds the strings an author wrote and locale/en.yml holds
   * their translation. The fallbacks below are Spanish for that reason, not by
   * accident — a fallback in English would make English the source the moment
   * the i18n helper is a tick late.
   */
  function t(key, params, fallback) {
    var full = 'local-looksmax-cosmetics.' + key;
    if (window.lmxI18n && window.lmxI18n.t) return window.lmxI18n.t(full, params || {}, fallback);
    var a = app();
    if (a && a.translator) {
      var out = a.translator.trans(full, params || {});
      if (typeof out === 'string' && out !== full) return out;
    }
    return fallback;
  }

  function num(n) {
    if (window.lmxI18n && window.lmxI18n.number) return window.lmxI18n.number(n);
    return String(n);
  }

  function payload() {
    if (payload._v !== undefined) return payload._v;
    payload._v = null;
    try {
      var node = document.getElementById('flarum-json-payload');
      if (node) payload._v = JSON.parse(node.textContent);
    } catch (e) {}
    return payload._v;
  }

  function csrf() {
    var p = payload();
    return (p && p.session && p.session.csrfToken) || null;
  }

  // ------------------------------------------------------------------- data

  /**
   * Harvest cosmetics off every user the SPA has already loaded.
   *
   * This is the zero-request path and it covers every surface that renders from
   * a serialized user: posts, the discussion list, profiles, notifications,
   * search results, the member list, the hovercard.
   */
  function harvest() {
    var a = app();
    if (!a || !a.store || !a.store.all) return;

    var users;
    try {
      users = a.store.all('users') || [];
    } catch (e) {
      return;
    }

    for (var i = 0; i < users.length; i++) {
      var u = users[i];
      var attrs = (u && u.data && u.data.attributes) || {};
      var cos = attrs.cosmetics;
      if (!cos) continue;

      var keys = [attrs.username, attrs.displayName, attrs.slug];
      for (var k = 0; k < keys.length; k++) {
        if (keys[k]) {
          if (!INDEX[keys[k]]) TRACE.fromStore++;
          INDEX[keys[k]] = cos;
        }
      }
    }
  }

  /**
   * The fallback map, for names that appear as text with no user record behind
   * them. Fetched at most once a minute and only when something on the page is
   * actually unresolved, so a page of known users never touches the network.
   */
  function fetchMap() {
    if (mapPending || Date.now() - mapFetched < 60000) return;
    mapPending = true;
    TRACE.mapFetches++;

    fetch(API + '/map', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var names = (d && d.names) || {};
        for (var n in names) {
          if (!INDEX[n]) TRACE.fromMap++;
          INDEX[n] = names[n];
        }
        mapFetched = Date.now();
        mapPending = false;
        decorate();
      })
      .catch(function (e) {
        mapPending = false;
        TRACE.lastError = 'map: ' + e;
      });
  }

  /**
   * Which account does this avatar belong to?
   *
   * Order matters and each step exists because a real call site needs it:
   *   1. an ancestor or sibling /u/<slug> link — posts, listings, search
   *   2. the session user, for the header avatar, which has no link
   *   3. title/alt — core's avatar helper writes displayName into title
   *   4. the nearest .username text — the shoutbox, which is raw DOM
   */
  function nameOf(av) {
    var link = av.closest('a[href*="/u/"]');
    if (!link) {
      var host = av.closest('.PostUser, .DiscussionListItem, .UserCard, .Notification, .LmxChat-line, li, .Search-results li');
      if (host) link = host.querySelector('a[href*="/u/"]');
    }
    if (link) {
      var m = (link.getAttribute('href') || '').match(/\/u\/([^\/?#]+)/);
      if (m) return decodeURIComponent(m[1]);
    }

    if (av.closest('.SessionDropdown, .App-header .Dropdown')) {
      var a = app();
      var me = a && a.session && a.session.user;
      if (me) {
        try { return me.slug ? me.slug() : me.username(); } catch (e) {}
      }
    }

    var title = av.getAttribute('title') || av.getAttribute('alt');
    if (title) return title.trim();

    var un = av.parentNode && av.parentNode.querySelector ? av.parentNode.querySelector('.username') : null;
    if (un) return (un.textContent || '').trim();

    return null;
  }

  function lookup(name) {
    if (!name) return null;
    return INDEX[name] || null;
  }

  // ------------------------------------------------------- visibility gate
  // A CSS animation on an off-screen element still costs the compositor a frame
  // of work every frame. With ~50 avatars on the index that is the difference
  // between a smooth scroll and a stuttering one, so a frame starts paused and
  // only runs while it is actually on screen.

  var io = null;
  function watch(node) {
    if (!('IntersectionObserver' in window)) {
      node.classList.add('is-live');
      return;
    }
    if (!io) {
      io = new IntersectionObserver(function (entries) {
        for (var i = 0; i < entries.length; i++) {
          entries[i].target.classList.toggle('is-live', entries[i].isIntersecting);
        }
      }, { rootMargin: '150px' });
    }
    io.observe(node);
  }

  // --------------------------------------------------------------- frames

  // Renderers with a continuous animation gated behind the IntersectionObserver
  // below (see watch()) so an off-screen avatar never costs a compositor frame.
  // 'nature' and 'laurel'-shaped frames are deliberately absent: they render a
  // static leaf mask with no keyframe, so watch() would just be wasted work.
  var ANIMATED = {
    conic: 1, dashed: 1, dual: 1,
    blaze: 1, frost: 1, storm: 1, venom: 1, royal: 1, void: 1, blood: 1, cosmic: 1, glitch: 1,
  };

  function applyFrame(av, slug) {
    var d = (DEFS.frame || {})[slug];
    if (!d) return false;

    var parent = av.parentNode;
    if (!parent || !parent.classList) return false;

    var wrap;
    if (parent.classList.contains('lmx-frame') || parent.classList.contains('LmxCosFrame')) {
      // Adopt whatever is already there — ours from an earlier pass, or ranks'.
      wrap = parent;
      if (!wrap.classList.contains('LmxCosFrame')) TRACE.adopted++;
    } else {
      wrap = el('span', 'lmx-frame LmxCosFrame');
      parent.insertBefore(wrap, av);
      wrap.appendChild(av);
    }

    // One renderer, not two. `fr-*` is looksmax-ranks' visual class for the
    // same equipped frame; leaving it on would draw a second ring from a
    // stylesheet this lane does not own.
    var keep = [];
    var have = (wrap.className || '').split(/\s+/);
    for (var i = 0; i < have.length; i++) {
      if (have[i] && have[i].indexOf('fr-') !== 0) keep.push(have[i]);
    }
    if (keep.indexOf('LmxCosFrame') < 0) keep.push('LmxCosFrame');
    if (keep.indexOf('lmx-frame') < 0) keep.push('lmx-frame');
    wrap.className = keep.join(' ');

    wrap.setAttribute('data-cf', slug);
    wrap.setAttribute('data-cf-render', d.r || 'ring');
    wrap.setAttribute('data-rarity', d.q || 'common');
    if (d.s && d.s !== 'circle') wrap.setAttribute('data-cf-shape', d.s);
    else wrap.removeAttribute('data-cf-shape');

    var css = d.c || {};
    for (var k in css) wrap.style.setProperty(k, css[k]);

    if (css['--cf-pulse']) wrap.classList.add('is-pulse');
    else wrap.classList.remove('is-pulse');

    if (ANIMATED[d.r] || css['--cf-pulse']) watch(wrap);

    return true;
  }

  /** Undo a frame on an avatar that no longer has one (unequipped, preview off). */
  function clearFrame(wrap) {
    if (!wrap || !wrap.classList || !wrap.hasAttribute('data-cf')) return;
    wrap.removeAttribute('data-cf');
    wrap.removeAttribute('data-cf-render');
    wrap.removeAttribute('data-cf-shape');
    wrap.removeAttribute('data-rarity');
    wrap.classList.remove('is-pulse', 'is-live');
    wrap.removeAttribute('style');
  }

  // --------------------------------------------------------------- banners

  /**
   * The profile cover.
   *
   * Only on a profile hero, and only for the account whose profile it is —
   * a banner is a statement about a person and it belongs on their page, not
   * behind every mention of them.
   */
  function decorateBanner() {
    var cards = document.querySelectorAll('.UserCard--profile, .UserPage .Hero, .UserCard');
    for (var i = 0; i < cards.length; i++) {
      var card = cards[i];
      if (card.closest('.PostUser, .DiscussionListItem')) continue;
      if (card.getAttribute('data-lmxcb') === '1') continue;

      var link = card.querySelector('a[href*="/u/"]');
      var name = null;
      if (link) {
        var m = (link.getAttribute('href') || '').match(/\/u\/([^\/?#]+)/);
        if (m) name = decodeURIComponent(m[1]);
      }
      if (!name) {
        var path = window.location.pathname.match(/\/u\/([^\/?#]+)/);
        if (path) name = decodeURIComponent(path[1]);
      }

      var cos = lookup(name);
      card.setAttribute('data-lmxcb', '1');
      if (!cos || !cos.banner) continue;

      var d = (DEFS.banner || {})[cos.banner];
      if (!d) continue;

      var plate = card.querySelector('.LmxCosBanner') || el('div', 'LmxCosBanner');
      plate.setAttribute('data-cb', cos.banner);
      plate.setAttribute('data-cb-pattern', d.p || 'none');
      var css = d.c || {};
      for (var k in css) plate.style.setProperty(k, css[k]);
      if (!plate.parentNode) card.insertBefore(plate, card.firstChild);
    }
  }

  // -------------------------------------------------------------- decorate

  var pass = 0;

  function decorate() {
    try {
      harvest();

      var avatars = document.querySelectorAll('.Avatar');
      var unresolved = 0;

      for (var i = 0; i < avatars.length; i++) {
        var av = avatars[i];
        if (av.getAttribute('data-lmxcos') === '1') continue;

        TRACE.avatarsSeen++;
        var name = nameOf(av);
        var cos = lookup(name);

        if (!cos && name && !INDEX[name]) unresolved++;

        av.setAttribute('data-lmxcos', '1');

        if (cos && cos.frame) {
          if (applyFrame(av, cos.frame)) TRACE.framed++;
        } else if (av.parentNode && av.parentNode.classList && av.parentNode.classList.contains('LmxCosFrame')) {
          clearFrame(av.parentNode);
        }
      }

      decorateBanner();
      TRACE.unresolved = unresolved;

      // Only reach for the network when the page actually holds a name the
      // payload did not explain. On a normal discussion this never fires.
      if (unresolved > 0) fetchMap();

      injectPanel();
      pass++;
    } catch (e) {
      TRACE.lastError = String(e && e.message ? e.message : e);
      console.warn('cosmetics:', e);
    }
  }

  // ============================================================== wardrobe
  //
  // Injected into the core /settings page rather than given a route of its own.
  // Registering a client route from a head script means redefining
  // `window.flarum` with an accessor to catch the single tick between the
  // bundle and app.boot(); looksmax-store already does that and two extensions
  // fighting over that property is a coin toss the loser does not survive.

  var W = {
    loaded: false,
    loading: false,
    data: null,
    error: null,
    preview: { frame: undefined, banner: undefined }, // undefined = show equipped
    busy: false,
    tab: 'frame',
  };

  function previewOf(kind) {
    if (W.preview[kind] !== undefined) return W.preview[kind];
    return (W.data && W.data.live && W.data.live[kind]) || null;
  }

  function dirty() {
    return ['frame', 'banner'].some(function (k) {
      return W.preview[k] !== undefined && W.preview[k] !== ((W.data && W.data.live && W.data.live[k]) || null);
    });
  }

  function load() {
    if (W.loading || W.loaded) return;
    W.loading = true;
    fetch(API + '/me', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        W.data = d;
        W.loaded = true;
        W.loading = false;
        render();
      })
      .catch(function (e) {
        W.loading = false;
        W.error = String(e);
        render();
      });
  }

  function save() {
    if (W.busy) return;
    W.busy = true;
    W.error = null;
    render();

    var jobs = [];
    ['frame', 'banner'].forEach(function (kind) {
      if (W.preview[kind] === undefined) return;
      var want = W.preview[kind];
      var now = (W.data && W.data.live && W.data.live[kind]) || null;
      if (want === now) return;
      jobs.push({ kind: kind, item: want });
    });

    var headers = { 'Content-Type': 'application/json' };
    var tok = csrf();
    if (tok) headers['X-CSRF-Token'] = tok;

    var chain = Promise.resolve();
    var failed = null;

    jobs.forEach(function (j) {
      chain = chain.then(function () {
        if (failed) return null;
        return fetch(API + '/equip', {
          method: 'POST',
          headers: headers,
          credentials: 'same-origin',
          body: JSON.stringify({ kind: j.kind, item: j.item }),
        }).then(function (r) {
          return r.json().then(function (body) {
            if (!r.ok) { failed = body && body.error ? body.error : 'HTTP ' + r.status; return; }
            // The server answers with the state it now holds, so the screen
            // redraws from the database rather than from what we hoped.
            if (body.live) W.data.live = body.live;
            if (body.equipped) W.data.equipped = body.equipped;
          });
        });
      });
    });

    chain.then(function () {
      W.busy = false;
      W.error = failed;
      if (!failed) W.preview = { frame: undefined, banner: undefined };
      // Repaint every avatar on the page with what is now true.
      INDEX = Object.create(null);
      var stale = document.querySelectorAll('[data-lmxcos]');
      for (var i = 0; i < stale.length; i++) stale[i].removeAttribute('data-lmxcos');
      mapFetched = 0;
      decorate();
      render();
    });
  }

  /** The user's own avatar markup, so the preview is on THEIR picture. */
  function myAvatarNode() {
    var a = app();
    var me = a && a.session && a.session.user;
    var url = null, initial = '?', color = null, name = '';

    if (me) {
      try {
        url = me.avatarUrl();
        name = me.displayName ? me.displayName() : me.username();
        initial = (name || '?').charAt(0).toUpperCase();
        color = me.color ? me.color() : null;
      } catch (e) {}
    }

    var node;
    if (url) {
      node = el('img', 'Avatar');
      node.src = url;
      node.alt = '';
    } else {
      node = el('span', 'Avatar', initial);
      if (color) node.style.setProperty('--avatar-bg', color);
    }
    node.setAttribute('data-lmxcos', '1');

    return { node: node, name: name };
  }

  function tile(item, kind) {
    var isNone = item === null;
    var slug = isNone ? null : item.slug;
    var current = previewOf(kind);
    var equipped = (W.data && W.data.live && W.data.live[kind]) || null;

    var b = el('button', 'LmxCosTile');
    b.type = 'button';

    if (isNone) {
      b.classList.add('LmxCosTile-none');
      b.setAttribute('data-rarity', 'common');
    } else {
      b.setAttribute('data-rarity', item.rarity || 'common');
      if (!item.owned) b.classList.add('is-locked');
    }
    if (current === slug) b.classList.add('is-selected');
    if (equipped === slug) b.classList.add('is-equipped');

    b.appendChild(el('span', 'LmxCosTile-rarity'));

    if (kind === 'frame') {
      var swatchWrap = el('span', 'LmxCosFrame');
      swatchWrap.classList.add('lmx-frame');
      var swatch = el('span', 'LmxCosTile-swatch');
      swatchWrap.appendChild(swatch);
      if (!isNone) {
        var d = (DEFS.frame || {})[slug];
        if (d) {
          swatchWrap.setAttribute('data-cf', slug);
          swatchWrap.setAttribute('data-cf-render', d.r || 'ring');
          if (d.s && d.s !== 'circle') swatchWrap.setAttribute('data-cf-shape', d.s);
          for (var k in (d.c || {})) swatchWrap.style.setProperty(k, d.c[k]);
          if (d.c && d.c['--cf-pulse']) swatchWrap.classList.add('is-pulse');
          swatchWrap.classList.add('is-live');
        }
      }
      b.appendChild(swatchWrap);
    } else {
      var plate = el('span', 'LmxCosTile-plate');
      if (!isNone) {
        var db = (DEFS.banner || {})[slug];
        if (db) {
          for (var k2 in (db.c || {})) plate.style.setProperty(k2, db.c[k2]);
          plate.setAttribute('data-cb-pattern', db.p || 'none');
        }
      }
      b.appendChild(plate);
    }

    b.appendChild(el('span', 'LmxCosTile-name', isNone ? t('forum.none', null, 'Ninguno') : item.name));

    if (isNone) {
      b.appendChild(el('span', 'LmxCosTile-meta', t('forum.none_hint', null, 'Sin decoración')));
    } else if (item.owned) {
      b.appendChild(el('span', 'LmxCosTile-meta', ownedLine(item)));
    } else {
      var lock = el('span', 'LmxCosTile-lock');
      lock.appendChild(icon('ph:lock-simple-fill'));
      lock.appendChild(el('span', null, lockLine(item)));
      b.appendChild(lock);
    }

    if (isNone || item.owned) {
      b.addEventListener('click', function () {
        W.preview[kind] = slug;
        render();
        repaintPreviewOnly();
      });
    }

    return b;
  }

  function ownedLine(item) {
    if (item.reason === 'sku' || item.reason === 'inventory') return t('forum.owned.bought', null, 'Comprado');
    if (item.reason === 'tier') return t('forum.owned.tier', { tier: item.unlock.tier || '' }, 'Incluido en tu membresía');
    if (item.reason === 'badge') return t('forum.owned.badge', null, 'Ganado');
    return t('forum.owned.generic', null, 'En tu inventario');
  }

  function lockLine(item) {
    var u = item.unlock || {};
    if (u.type === 'buy') {
      return t('forum.lock.buy', { price: num(item.price || 0) }, 'En la tienda por ' + num(item.price || 0));
    }
    if (u.type === 'tier') {
      return t('forum.lock.tier', { tier: u.tier || '' }, 'Requiere membresía ' + (u.tier || ''));
    }
    if (u.type === 'badge') {
      var b = u.badgeName || u.badge || '';
      return t('forum.lock.badge', { badge: b }, 'Requiere la insignia ' + b);
    }
    return t('forum.lock.award', null, 'Solo por concesión');
  }

  function rarityRank(r) {
    return { common: 0, uncommon: 1, rare: 2, epic: 3, legendary: 4 }[r] || 0;
  }

  function section(kind) {
    var items = ((W.data && W.data.items) || []).filter(function (i) { return i.kind === kind; });
    items.sort(function (a, b) {
      if (a.owned !== b.owned) return a.owned ? -1 : 1;
      var d = rarityRank(a.rarity) - rarityRank(b.rarity);
      return d !== 0 ? d : String(a.name).localeCompare(String(b.name));
    });

    var owned = items.filter(function (i) { return i.owned; }).length;

    var wrap = el('div', 'LmxCosSection');
    var head = el('h3', 'LmxCosSection-title');
    head.appendChild(el('span', null, kind === 'frame'
      ? t('forum.section.frames', null, 'Marcos de avatar')
      : t('forum.section.banners', null, 'Portadas de perfil')));
    head.appendChild(el('span', 'LmxCosSection-count',
      t('forum.section.count', { owned: num(owned), total: num(items.length) }, owned + ' de ' + items.length)));
    wrap.appendChild(head);

    var grid = el('div', 'LmxCosGrid');
    grid.appendChild(tile(null, kind));
    items.forEach(function (i) { grid.appendChild(tile(i, kind)); });
    wrap.appendChild(grid);

    return wrap;
  }

  function mirror() {
    var box = el('div', 'LmxCosMirror');

    var bslug = previewOf('banner');
    var plate = el('div', 'LmxCosMirror-plate');
    if (bslug) {
      var db = (DEFS.banner || {})[bslug];
      if (db) {
        for (var k in (db.c || {})) plate.style.setProperty(k, db.c[k]);
        plate.setAttribute('data-cb-pattern', db.p || 'none');
      }
    }
    box.appendChild(plate);

    var av = myAvatarNode();
    var holder = el('div', 'LmxCosMirror-avatar');
    var fslug = previewOf('frame');
    if (fslug) {
      var wrapper = el('span', 'lmx-frame LmxCosFrame');
      wrapper.appendChild(av.node);
      holder.appendChild(wrapper);
      var d = (DEFS.frame || {})[fslug];
      if (d) {
        wrapper.setAttribute('data-cf', fslug);
        wrapper.setAttribute('data-cf-render', d.r || 'ring');
        if (d.s && d.s !== 'circle') wrapper.setAttribute('data-cf-shape', d.s);
        for (var k2 in (d.c || {})) wrapper.style.setProperty(k2, d.c[k2]);
        if (d.c && d.c['--cf-pulse']) wrapper.classList.add('is-pulse');
        wrapper.classList.add('is-live');
      }
    } else {
      holder.appendChild(av.node);
    }
    box.appendChild(holder);

    var body = el('div', 'LmxCosMirror-body');
    body.appendChild(el('div', 'LmxCosMirror-name', av.name));
    body.appendChild(el('div', 'LmxCosMirror-note', dirty()
      ? t('forum.mirror.unsaved', null, 'Vista previa sin guardar')
      : t('forum.mirror.saved', null, 'Así te ve el foro')));
    box.appendChild(body);

    var actions = el('div', 'LmxCosMirror-actions');
    var apply = el('button', 'Button Button--primary');
    apply.type = 'button';
    apply.textContent = W.busy
      ? t('forum.applying', null, 'Aplicando…')
      : t('forum.apply', null, 'Aplicar');
    apply.disabled = W.busy || !dirty();
    apply.addEventListener('click', save);
    actions.appendChild(apply);

    var reset = el('button', 'Button');
    reset.type = 'button';
    reset.textContent = t('forum.revert', null, 'Descartar');
    reset.disabled = W.busy || !dirty();
    reset.addEventListener('click', function () {
      W.preview = { frame: undefined, banner: undefined };
      render();
      repaintPreviewOnly();
    });
    actions.appendChild(reset);

    box.appendChild(actions);

    return box;
  }

  /**
   * Repaint the avatars already on the page with the PREVIEW, so "try it on"
   * means the whole page and not one thumbnail. Reverted by save() or by
   * Descartar; never persisted by itself.
   */
  function repaintPreviewOnly() {
    var a = app();
    var me = a && a.session && a.session.user;
    if (!me) return;
    var names = [];
    try {
      names = [me.username(), me.displayName ? me.displayName() : null, me.slug ? me.slug() : null];
    } catch (e) {}

    var slug = previewOf('frame');
    for (var i = 0; i < names.length; i++) {
      if (!names[i]) continue;
      INDEX[names[i]] = INDEX[names[i]] || {};
      INDEX[names[i]] = { frame: slug, banner: previewOf('banner') };
    }

    var avatars = document.querySelectorAll('.Avatar[data-lmxcos]');
    for (var j = 0; j < avatars.length; j++) {
      if (avatars[j].closest('.LmxCosPanel')) continue;
      avatars[j].removeAttribute('data-lmxcos');
    }
    decorate();
  }

  function render() {
    var host = document.querySelector('.LmxCosPanel');
    if (!host) return;

    host.innerHTML = '';

    var head = el('div', 'LmxCosPage-head');
    head.appendChild(icon('ph:paint-brush-broad-fill'));
    head.appendChild(el('h2', 'LmxCosPage-title', t('forum.title', null, 'Personalización')));
    host.appendChild(head);
    host.appendChild(el('p', 'LmxCosPage-sub',
      t('forum.sub', null, 'Marcos y portadas que ya tienes. Pruébalos aquí antes de ponértelos.')));

    if (W.error) {
      host.appendChild(el('div', 'LmxCosError', W.error));
    }

    if (!W.loaded) {
      host.appendChild(el('div', 'LmxCosEmpty', t('forum.loading', null, 'Cargando…')));
      return;
    }

    if (W.data && W.data.guest) {
      host.appendChild(el('div', 'LmxCosEmpty', t('forum.guest', null, 'Inicia sesión para personalizar tu perfil.')));
      return;
    }

    host.appendChild(mirror());
    host.appendChild(section('frame'));
    host.appendChild(section('banner'));
    host.appendChild(el('p', 'LmxCosNote',
      t('forum.note', null, 'Los marcos animados se detienen solos cuando no están en pantalla y se apagan si tu sistema pide menos movimiento.')));
  }

  /**
   * Put the panel on the settings page.
   *
   * Mithril redraws that page and will discard anything it did not create, so
   * the MutationObserver puts it back and render() rebuilds it from W — the
   * user's in-flight selection survives a redraw because the state does not
   * live in the DOM.
   */
  function injectPanel() {
    var page = document.querySelector('.SettingsPage');
    if (!page) return;

    var container = page.querySelector('.container') || page;
    if (container.querySelector('.LmxCosPanel')) return;

    var panel = el('div', 'LmxCosPanel LmxCosPage');
    panel.id = 'cosmetics';
    container.appendChild(panel);
    TRACE.panel = true;

    load();
    render();
  }

  // ------------------------------------------------------------- lifecycle

  function boot() {
    decorate();

    if ('MutationObserver' in window) {
      var scheduled = false;
      var mo = new MutationObserver(function () {
        if (scheduled) return;
        scheduled = true;
        // Coalesce a Mithril redraw's worth of mutations into one pass.
        requestAnimationFrame(function () {
          scheduled = false;
          decorate();
        });
      });
      mo.observe(document.body, { childList: true, subtree: true });
    }

    // The SPA can swap routes without touching <body>'s children in a way the
    // observer notices, and the user store fills in after the first paint.
    var n = 0;
    var iv = setInterval(function () {
      decorate();
      if (++n > 20) clearInterval(iv);
    }, 400);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
