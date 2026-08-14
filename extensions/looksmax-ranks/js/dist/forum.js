/*
 * Identity decorator.
 *
 * Why a DOM decorator rather than component overrides: standing has to appear
 * in places owned by five different extensions — post headers (core), the
 * discussion listing (core + theme), the shoutbox (looksmax-chat), the index
 * sidebar (looksmax-index), user cards (core). Overriding five components from
 * one extension means five compat imports and five load-order dependencies, and
 * the container has no node toolchain to build them properly. Watching the DOM
 * works one level down, where the result is unambiguous, and it cannot take the
 * SPA down because it never touches the boot path.
 *
 * The same conclusion looksmax-icons reached, for the same reasons, and it is
 * the reason this ships as its own <script> element: Flarum concatenates every
 * extension's JS into one bundle, so a top-level throw here would kill every
 * extension registered after it.
 *
 * Performance, on a page that can carry 50 names:
 *   - one API call for the whole username -> standing map, cached 60s
 *   - decorated elements are marked with data-lmx so a Mithril redraw costs a
 *     class check, not a re-render
 *   - animated names and frames start paused and are switched on by an
 *     IntersectionObserver, so off-screen cosmetics cost zero compositor time
 */
(function () {
  'use strict';

  var API = '/api/identity';
  var MAP = null;          // username -> standing
  var RANKS = {};          // slug -> catalogue row
  var TIERS = {};
  var ME = null;
  var fetching = false;
  var lastFetch = 0;

  // ------------------------------------------------------------------ utils

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  function icon(name, cls) {
    // looksmax-icons registers the iconify-icon element; if it is not present
    // the tag renders as an unknown inline element with no box, which degrades
    // to "no glyph" rather than to a broken layout.
    var e = document.createElement('iconify-icon');
    e.setAttribute('icon', name);
    e.setAttribute('inline', '');
    if (cls) e.className = cls;
    return e;
  }

  /*
   * Compact counts, in the reader's locale.
   *
   * The hand-rolled k/M suffixes below are English: Spanish compacts 12,431 as
   * "12,4 mil", not "12.4k", and the decimal separator flips with it. So this
   * defers to window.lmxI18n.compact(), which is Intl with the forum's locale,
   * and keeps the old arithmetic only as the fallback for the tick before the
   * i18n script has been parsed — this file is injected as its own <script>
   * and cannot assume it lands second.
   */
  function num(n) {
    if (window.lmxI18n && window.lmxI18n.compact) return window.lmxI18n.compact(n);
    if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
    return String(n);
  }

  /**
   * A translation key, with the English text as the fallback.
   *
   * There is no `app` in this IIFE to reach a translator through, and there
   * deliberately never will be — see the header. window.lmxI18n.t() boots one
   * lazily, records a miss for the i18n gate, and returns the fallback rather
   * than the raw key. None of the keys used here contain tags, so every result
   * is a plain string and safe in .title and in textContent.
   */
  function t(key, params, fallback) {
    if (window.lmxI18n && window.lmxI18n.t) {
      return window.lmxI18n.t('local-looksmax-ranks.' + key, params || {}, fallback);
    }
    return fallback;
  }

  /** Flarum's boot payload, which carries the session and the CSRF token. */
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

  window.lmxIdentityPost = function (action, body) {
    var headers = { 'Content-Type': 'application/json' };
    var t = csrf();
    if (t) headers['X-CSRF-Token'] = t;
    return fetch(API + '/' + action, {
      method: 'POST',
      headers: headers,
      credentials: 'same-origin',
      body: JSON.stringify(body || {}),
    }).then(function (r) {
      return r.json().then(function (j) {
        j._status = r.status;
        return j;
      });
    });
  };

  // ------------------------------------------------------------------ data

  function load() {
    if (fetching || Date.now() - lastFetch < 60000) return Promise.resolve(MAP);
    fetching = true;
    return fetch(API + '/names', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        MAP = d.names || {};
        RANKS = d.ranks || {};
        TIERS = d.tiers || {};
        lastFetch = Date.now();
        fetching = false;
        decorate();
        return MAP;
      })
      .catch(function () { fetching = false; });
  }

  function loadMe() {
    return fetch(API + '/me', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { ME = d; window.lmxIdentityMe = d; return d; })
      .catch(function () {});
  }

  // ------------------------------------------------------- visibility gate
  // A CSS animation on an off-screen element still burns compositor time every
  // frame. With 50 names in a listing that is the difference between a smooth
  // scroll and a stuttering one, so nothing animates until it is on screen.

  var observer = null;
  function watch(node) {
    if (!('IntersectionObserver' in window)) {
      node.classList.add('is-live');
      return;
    }
    if (!observer) {
      observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          e.target.classList.toggle('is-live', e.isIntersecting);
        });
      }, { rootMargin: '120px' });
    }
    observer.observe(node);
  }

  // -------------------------------------------------------------- decorate

  /**
   * Pull a username out of an element.
   *
   * Order matters: an explicit data attribute beats the text content, because
   * a nickname-enabled forum renders the display name and the map is keyed on
   * the account name. Falls back to text, which is what the shoutbox gives us.
   */
  function nameOf(node) {
    var n = node.getAttribute('data-lmx-user');
    if (n) return n;

    var link = node.closest('a[href*="/u/"]') || node.querySelector('a[href*="/u/"]');
    if (link) {
      var m = link.getAttribute('href').match(/\/u\/([^\/?#]+)/);
      if (m) return decodeURIComponent(m[1]);
    }

    return (node.textContent || '').trim();
  }

  function standingFor(node) {
    if (!MAP) return null;
    var name = nameOf(node);
    if (!name) return null;
    return MAP[name] || null;
  }

  function rankColor(slug) {
    var r = RANKS[slug];
    return (r && r.color) || '#9aa4b2';
  }

  /** Build the chip cluster that follows a name. */
  function chips(s, opts) {
    var wrap = el('span', 'lmx-standing');
    var rank = RANKS[s.r];
    var tier = TIERS[s.t];

    if (rank && s.ri > 0) {
      var rc = el('span', 'lmx-chip lmx-chip--rank');
      rc.style.setProperty('--lmx-rank-color', rank.color);
      rc.appendChild(icon(rank.icon));
      rc.appendChild(el('span', null, rank.name));
      rc.title = t('forum.hover.rank_title', { rank: rank.name, blurb: rank.blurb || '' }, rank.name + ' — ' + (rank.blurb || ''));
      wrap.appendChild(rc);
    }

    if (tier && s.t !== 'standard') {
      var tc = el('span', 'lmx-chip lmx-chip--tier tier-' + s.t);
      tc.style.setProperty('--lmx-tier-color', tier.color);
      tc.appendChild(icon(tier.icon));
      tc.appendChild(el('span', null, tier.name));
      tc.title = t('forum.hover.tier_title', { tier: tier.name }, tier.name + ' member');
      wrap.appendChild(tc);
    }

    if (opts && opts.badges && s.b > 0) {
      var bc = el('span', 'lmx-chip lmx-chip--badges');
      bc.appendChild(icon('ph:seal-check-fill'));
      bc.appendChild(el('span', null, String(s.b)));
      bc.title = t('forum.hover.badges_title', { count: s.b }, s.b + ' badge' + (s.b === 1 ? '' : 's'));
      wrap.appendChild(bc);
    }

    return wrap.childNodes.length ? wrap : null;
  }

  function paintName(node, s) {
    node.classList.add('lmx-name');
    node.classList.add('rk-' + s.r);
    node.style.setProperty('--lmx-rank-color', rankColor(s.r));

    if (s.s) {
      node.classList.add(s.s);
      node.classList.remove('rk-' + s.r); // a cosmetic overrides the rank colour
      watch(node);
    }
  }

  /** Wrap the avatar nearest this name in a frame ring. */
  function paintFrame(container, s) {
    if (!s.f || !container) return;
    var av = container.querySelector('.Avatar');
    if (!av || av.parentNode.classList.contains('lmx-frame')) return;

    var wrap = el('span', 'lmx-frame ' + s.f);
    av.parentNode.insertBefore(wrap, av);
    wrap.appendChild(av);
    watch(wrap);
  }

  var SELECTORS = [
    '.PostUser-name .username',
    '.PostUser .username',
    '.username',
    '.UserCard-identity .username',
    '.LmxChat-who',
    '.LmxIndex .LmxForum-last span',
  ];

  function decorate() {
    if (!MAP) return;

    var nodes = document.querySelectorAll(SELECTORS.join(','));
    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i];
      if (node.hasAttribute('data-lmx')) continue;

      var s = standingFor(node);
      node.setAttribute('data-lmx', s ? '1' : '0');
      if (!s) continue;

      paintName(node, s);

      // the chip cluster goes after the name, in the header row that already
      // exists, so nothing gains a line
      var host = node.closest('.PostUser-name') || node.closest('h3') || node.parentNode;
      var isPost = !!node.closest('.Post-header, .PostUser');

      if (isPost && host && !host.querySelector('.lmx-standing')) {
        var c = chips(s, { badges: true });
        if (c) host.appendChild(c);

        if (s.ti) {
          var t = el('span', 'lmx-title', s.ti);
          if (s.tc) t.style.setProperty('--lmx-title-color', s.tc);
          var titleHost = node.closest('.PostUser') || host;
          if (!titleHost.querySelector('.lmx-title')) titleHost.appendChild(t);
        }
      } else if (host && !host.querySelector('.lmx-standing') && !node.closest('.LmxChat')) {
        var c2 = chips(s, { badges: false });
        if (c2) host.appendChild(c2);
      }

      paintFrame(node.closest('.PostUser, .UserCard, .DiscussionListItem, .LmxLeader, li') || null, s);

      attachHover(node, s);
    }
  }

  // ------------------------------------------------------------- hovercard
  // A standing card on hover. Built as a positioned div rather than through
  // Flarum's tooltip because it carries a gradient name and a badge row, and
  // Flarum's tooltip is a title attribute.

  var card = null;
  var cardTimer = null;

  function ensureCard() {
    if (card) return card;
    card = el('div', 'LmxHover');
    document.body.appendChild(card);
    return card;
  }

  function attachHover(node, s) {
    node.addEventListener('mouseenter', function () {
      clearTimeout(cardTimer);
      cardTimer = setTimeout(function () { showCard(node, s); }, 260);
    });
    node.addEventListener('mouseleave', function () {
      clearTimeout(cardTimer);
      if (card) card.classList.remove('is-open');
    });
  }

  function showCard(node, s) {
    var c = ensureCard();
    var rank = RANKS[s.r] || {};
    var tier = TIERS[s.t];
    c.innerHTML = '';

    var nm = el('div', 'LmxHover-name', nameOf(node));
    nm.classList.add('lmx-name');
    if (s.s) { nm.classList.add(s.s); nm.classList.add('is-live'); }
    else { nm.classList.add('rk-' + s.r); }
    c.appendChild(nm);

    if (s.ti) {
      var t = el('div', 'lmx-title', s.ti);
      if (s.tc) t.style.setProperty('--lmx-title-color', s.tc);
      c.appendChild(t);
    }

    var row = el('div', 'LmxHover-row');
    row.style.setProperty('--lmx-rank-color', rank.color || '#9aa4b2');
    row.appendChild(icon(rank.icon || 'ph:circle-dashed-bold'));
    row.appendChild(el('span', null, rank.name || t('forum.hover.rank_unknown', null, 'Greycel')));
    if (tier && s.t !== 'standard') {
      var sep = el('span', null, '·');
      sep.style.opacity = '0.4';
      row.appendChild(sep);
      var tn = el('span', null, tier.name);
      tn.style.color = tier.color;
      tn.style.fontWeight = '700';
      row.appendChild(tn);
    }
    c.appendChild(row);

    if (rank.blurb) {
      var b = el('div', 'LmxHover-row', rank.blurb);
      b.style.fontStyle = 'italic';
      b.style.opacity = '0.75';
      c.appendChild(b);
    }

    var stats = el('div', 'LmxHover-stats');
    var mk = function (v, label) {
      var d = el('div');
      d.appendChild(el('b', null, v));
      d.appendChild(el('span', null, label));
      return d;
    };
    stats.appendChild(mk(num(s.b || 0), t('forum.hover.stat_badges', null, 'badges')));
    stats.appendChild(mk(rank.name || t('forum.hover.stat_empty', null, '—'), t('forum.hover.stat_rank', null, 'rank')));
    c.appendChild(stats);

    var r = node.getBoundingClientRect();
    var top = r.bottom + 8;
    var left = Math.min(r.left, window.innerWidth - 280);
    if (top + 190 > window.innerHeight) top = Math.max(8, r.top - 190);
    c.style.top = top + 'px';
    c.style.left = Math.max(8, left) + 'px';
    c.classList.add('is-open');
  }

  // ---------------------------------------------------------- points chip
  // The balance belongs in the header: a currency you cannot see is a currency
  // nobody spends.

  var lastPoints = null;

  function mountPoints() {
    if (!ME || ME.guest) return;
    var host = document.querySelector('.Header-secondary .item-session');
    if (!host) return;

    var chip = document.querySelector('.lmx-points');
    var points = ME.standing ? ME.standing.points : 0;

    if (!chip) {
      chip = el('a', 'lmx-points');
      chip.href = '/store';
      chip.setAttribute('title', t('forum.points.title', null, 'Your balance — spend it in the store'));
      chip.appendChild(icon('ph:coins-fill'));
      chip.appendChild(el('span', 'lmx-points-n', num(points)));
      host.parentNode.insertBefore(chip, host);
      lastPoints = points;
      return;
    }

    if (!chip.isConnected) {
      host.parentNode.insertBefore(chip, host);
    }

    if (points !== lastPoints) {
      chip.querySelector('.lmx-points-n').textContent = num(points);
      chip.classList.remove('is-bumped');
      void chip.offsetWidth; // force reflow so the animation restarts
      chip.classList.add('is-bumped');
      lastPoints = points;
    }
  }

  // ------------------------------------------------------ front page card
  // looksmax-index owns the sidebar and is not ours to edit, so the leaderboard
  // is appended as our own card rather than by rewriting theirs. If the index
  // is not present, nothing happens.

  var leaderWindow = 'week';
  var leaderCache = {};

  function mountLeaders() {
    var side = document.querySelector('.LmxIndex-side');
    if (!side) return;
    if (side.querySelector('.LmxCard--leaders')) return;

    var cardEl = el('section', 'LmxCard LmxCard--leaders');
    var h = el('h3');
    h.appendChild(icon('game-icons:podium-winner'));
    h.appendChild(el('span', null, t('forum.leaders.heading', null, 'Standing')));

    var tabs = el('span', 'LmxLeaders-tabs');
    ['week', 'all'].forEach(function (w) {
      var b = el('button', w === leaderWindow ? 'is-active' : null, w === 'week' ? t('forum.leaders.window_week', null, '7d') : t('forum.leaders.window_all', null, 'all'));
      b.type = 'button';
      b.addEventListener('click', function () {
        leaderWindow = w;
        tabs.querySelectorAll('button').forEach(function (x) { x.classList.remove('is-active'); });
        b.classList.add('is-active');
        renderLeaders(cardEl);
      });
      tabs.appendChild(b);
    });
    h.appendChild(tabs);
    cardEl.appendChild(h);
    cardEl.appendChild(el('ol', 'LmxLeaders'));

    // slot it after the board stats card, which is where a reader is already
    // looking for numbers about the forum
    var stats = side.querySelector('.LmxCard--stats');
    if (stats && stats.nextSibling) side.insertBefore(cardEl, stats.nextSibling);
    else side.appendChild(cardEl);

    renderLeaders(cardEl);
  }

  function renderLeaders(cardEl) {
    var list = cardEl.querySelector('.LmxLeaders');
    var w = leaderWindow;

    var draw = function (data) {
      list.innerHTML = '';
      (data.entries || []).slice(0, 8).forEach(function (e, i) {
        var li = el('li', 'LmxLeader');
        li.appendChild(el('span', 'LmxLeader-pos', String(i + 1)));

        // An account with no uploaded avatar gets its initial, the same way
        // core draws one, rather than an empty grey square. And an <img> that
        // fails to load is swapped for that initial instead of leaving the
        // browser's broken-image glyph in a leaderboard row.
        var initial = el('span', 'LmxLeader-avatar LmxLeader-avatar--initial',
          (e.username || '?').trim().charAt(0).toUpperCase());
        if (e.avatarUrl) {
          var img = el('img', 'LmxLeader-avatar');
          img.src = e.avatarUrl;
          img.alt = '';
          img.loading = 'lazy';
          img.addEventListener('error', function () {
            if (img.parentNode) img.parentNode.replaceChild(initial, img);
          });
          li.appendChild(img);
        } else {
          li.appendChild(initial);
        }

        var a = el('a', 'LmxLeader-name lmx-name', e.username);
        a.href = '/u/' + encodeURIComponent(e.username);
        if (e.nameClass) { a.classList.add(e.nameClass); watch(a); }
        else a.style.color = e.rankColor;
        li.appendChild(a);

        li.appendChild(el('span', 'LmxLeader-score', num(e.score)));
        li.title = t('forum.leaders.entry_title', { username: e.username, rank: e.rank }, e.username + ' — ' + e.rank);
        list.appendChild(li);
      });

      if (!(data.entries || []).length) {
        list.appendChild(el('li', 'LmxLeader', t('forum.leaders.empty', null, 'nothing earned yet')));
      }
    };

    if (leaderCache[w]) { draw(leaderCache[w]); return; }

    fetch(API + '/leaderboard?window=' + w, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { leaderCache[w] = d; draw(d); })
      .catch(function () {});
  }

  // --------------------------------------------------------------- runtime

  function tick() {
    try {
      decorate();
      mountPoints();
      mountLeaders();
    } catch (e) {
      // never let a decoration failure take a page down; report once
      if (!tick._warned) { tick._warned = true; console.warn('identity:', e); }
    }
  }

  function boot() {
    load();
    loadMe();
    tick();

    // Mithril replaces subtrees wholesale on redraw, which drops our injected
    // nodes and the data-lmx marks with them. Observing mutations catches that
    // immediately; the interval is the backstop for redraws that reuse nodes.
    if ('MutationObserver' in window) {
      var mo = new MutationObserver(function () {
        clearTimeout(boot._t);
        boot._t = setTimeout(tick, 60);
      });
      mo.observe(document.body, { childList: true, subtree: true });
    }
    setInterval(tick, 1500);
    setInterval(function () { lastFetch = 0; load(); }, 120000);
    window.addEventListener('popstate', function () { setTimeout(tick, 120); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
