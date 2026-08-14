/*
 * The user identity surface: the author rail, THE hover card, and DMs.
 *
 * Hand-authored against the runtime module registry rather than built through
 * webpack: the app container has no node toolchain, and Flarum exposes every
 * core module on `flarum.core.compat` at runtime, so this is a supported (if
 * unusual) integration path.
 *
 * ── ONE hover card ──────────────────────────────────────────────────────────
 * There is exactly one card component in this file (`cardView`), one host
 * (`HOST`), one configuration (`CFG`), and one delegated listener. It is
 * mounted from `wireCard()` and from nowhere else. Every site a username
 * appears on this forum gets it without knowing anything about it, because the
 * listener is delegated from `document` and matches on what a username link
 * actually IS — an anchor to /u/<slug>, or an element carrying
 * `data-lmx-user` / `data-lmx-userid`.
 *
 * Sites covered and MEASURED (e2e/card-sweep.ts asserts each one opens it):
 *   post author rail · post header · post mentions (@user) · quote attributions
 *   discussion-list author and last-poster · the shoutbox (both the name and
 *   the @mentions inside a message) · the leaderboard · notifications ·
 *   search results · the profile page's own header.
 *
 * Other lanes that ship their own card are listed in HOVERCARD-API.md with the
 * one-line change each needs. Until they make it, `html.lmx-usercard-owner`
 * (set below, only once this file has actually bound) suppresses theirs, so a
 * reader never sees two cards for one name.
 *
 * ── The registry keys are MEASURED, not guessed ──────────────────────────────
 * `e2e/userinfo-probe.ts` dumps `Object.keys(flarum.core.compat)` from the live
 * page. On this install the keys carry NO `forum/` prefix: the module is
 * `components/CommentPost`, not `forum/components/CommentPost`. A wrong key is
 * `undefined`, which means bind() quietly does nothing, every post renders
 * without a panel, and NOTHING is logged. That is the worst failure mode in
 * this file, so both spellings are tried and the resolved one is recorded on
 * `window.__lmxUserInfo` for the harness to assert on.
 *
 * ── Zero override() calls ────────────────────────────────────────────────────
 * CommentPost.view and DiscussionPage.view are among the most-clobbered methods
 * in the Flarum ecosystem. Everything here appends to a named ItemList —
 * `contentItems`, `infoItems`, `badges`, `sidebarItems`, `items` — which is the
 * additive surface those lists exist for, so two extensions adding to the same
 * list both survive.
 *
 * ── Nothing here invents a number ────────────────────────────────────────────
 * Every value rendered comes from the `userInfo` attribute the server put on
 * the user payload. A field the server sent as null renders as nothing at all,
 * never as a zero, because a displayed zero is a claim.
 */
(function () {
  'use strict';

  var BOUND = false;
  var m = null;
  var extend = null;
  var app = null;
  var reg = {};

  var RESOLVED = {}; // what each key actually resolved to, for the harness

  function registry() {
    return (window.flarum && window.flarum.core && window.flarum.core.compat) || {};
  }

  /* Try both spellings; record which one answered. */
  function mod(name) {
    var c = registry();
    var found = c[name] !== undefined ? c[name] : c['forum/' + name];
    RESOLVED[name] = found === undefined ? null : (c[name] !== undefined ? name : 'forum/' + name);
    if (found && found.default && !found.prototype && !found.extend) return found.default;
    return found;
  }

  /* ============================================================ i18n */

  /*
   * ── The stray-comma bug this function exists to kill ────────────────────────
   *
   * `app.translator.trans(key, params)` does NOT return a string. When the
   * message has placeholders it returns an ARRAY of fragments, because a
   * translation may legally contain vnodes. Putting that array where a string
   * is expected coerces it with Array.prototype.toString, which joins with
   * COMMAS. That is the whole explanation for what the operator reported:
   *
   *   locale/en.yml  legacy.posts: "{n, plural, other {{count} posts}}"
   *   rendered        "38.2K, posts · 76.3K, reactions"
   *   title attribute "Last seen ,last year"     (measured on /d/25909)
   *
   * Both were verbatim in the DOM before this change. Nothing in this file
   * needs rich translations, so every lookup is flattened to a plain string
   * exactly once, here, and `app.translator.trans` is not called anywhere else.
   */
  function flat(v) {
    if (v == null || v === false) return '';
    if (typeof v === 'string') return v;
    if (typeof v === 'number') return String(v);
    if (Array.isArray(v)) {
      var out = '';
      for (var i = 0; i < v.length; i++) out += flat(v[i]);
      return out;
    }
    // a mithril vnode: its text is either .text or nested .children
    if (v.text != null) return String(v.text);
    if (v.children != null) return flat(v.children);
    return '';
  }

  function t(key, params) {
    try {
      return flat(app.translator.trans('local-looksmax-userinfo.forum.' + key, params || {}));
    } catch (e) {
      return '';
    }
  }

  /* ========================================================== configuration */

  /*
   * ONE configuration object for every call site.
   *
   * It arrives on the forum payload as `lmxUserInfo` (src/Config.php) and is
   * editable in the admin panel. The defaults below are only a floor: they keep
   * the card working on an install where the attribute is missing, which is the
   * case for a page served from cache during a deploy.
   */
  var CFG = {
    trigger: 'hover',
    openDelay: 320,
    closeDelay: 220,
    placement: 'auto',
    followScroll: true,
    mobile: 'sheet',
    mobileBreakpoint: 767,
    width: 340,
    avatarSize: 64,
    fields: 'avatar,name,rank,title,groups,banners,presence,joined,posts,threads,reactions,perDay,bestPost,mix,legacy',
    actions: 'message,mention,profile,posts,report,suspend,edit',
    railEnabled: true,
    railWidth: 200,
    dmEnabled: true,
    dmMaxLength: 8000,
    dmMaxRecipients: 10,
  };

  var FIELD = {}; // fields, as a set
  var ACTION = []; // actions, in configured order

  function loadConfig() {
    var got = null;
    try { got = app.forum.attribute('lmxUserInfo'); } catch (e) { got = null; }
    if (got && typeof got === 'object') {
      for (var k in got) if (got[k] !== null && got[k] !== undefined) CFG[k] = got[k];
    }

    FIELD = {};
    String(CFG.fields || '').split(',').forEach(function (f) {
      f = f.trim();
      if (f) FIELD[f] = true;
    });
    ACTION = String(CFG.actions || '').split(',').map(function (a) { return a.trim(); })
      .filter(function (a) { return a; });

    // The two style knobs that are layout, not decoration, are published as
    // custom properties so the stylesheet reads them by name and nothing here
    // writes a pixel value into an element's style attribute.
    var root = document.documentElement;
    root.style.setProperty('--lmx-card-w', CFG.width + 'px');
    root.style.setProperty('--lmx-card-avatar', CFG.avatarSize + 'px');
    root.style.setProperty('--lmx-author-w', CFG.railWidth + 'px');
  }

  /**
   * True when this viewport must get the sheet rather than a hover card.
   *
   * ── Why the width comes first and the media query is narrowed ──────────────
   * The first version was `matchMedia('(hover: none)')` alone. HEADLESS CHROME
   * MATCHES THAT AT ANY WIDTH — it has no pointing device — so every card in a
   * 1440px automated run opened as a bottom sheet and the sweep measured a
   * mobile layout while claiming to measure a desktop one. Measured:
   * `LmxCardHost is-open is-sheet` on a 1440x1100 viewport.
   *
   * The operator's requirement is about WIDTH ("at <=767px it must be a
   * tap-to-open bottom sheet"), so width is the primary test and it is exact.
   * The media query stays as a second condition for a touch tablet that is
   * wider than the breakpoint, and it now requires `pointer: coarse` as well —
   * a finger, not merely the absence of a mouse.
   */
  function isTouchLayout() {
    if (window.innerWidth <= Number(CFG.mobileBreakpoint || 767)) return true;
    if (window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches) return true;
    return false;
  }

  /* ============================================================ formatting */

  /*
   * 12,431 is unreadable in a 92px column and 12.4k is not, but the exact
   * figure still has to be available — a stat that cannot be checked is a stat
   * nobody trusts. So the compact form renders and the exact form goes in the
   * title attribute.
   */
  function compact(n) {
    n = Number(n) || 0;
    // Locale-aware. The hand-rolled 'k'/'M' suffixes below were English-only:
    // Spanish compacts 12431 to "12,4 mil", not "12.4k".
    if (window.lmxI18n) return window.lmxI18n.compact(n);
    if (n < 1000) return String(n);
    if (n < 10000) return (Math.floor(n / 100) / 10).toFixed(1).replace(/\.0$/, '') + 'k';
    if (n < 1000000) return Math.round(n / 1000) + 'k';
    return (Math.round(n / 100000) / 10).toFixed(1).replace(/\.0$/, '') + 'M';
  }

  function exact(n) {
    n = Number(n) || 0;
    // toLocaleString() with NO locale argument follows the BROWSER's locale,
    // not the forum's, so a Spanish page could print "12,431" beside Spanish
    // text on an en-US browser and "12.431" on an es-MX one.
    return window.lmxI18n ? window.lmxI18n.exact(n) : String(n);
  }

  function num(n) {
    return window.lmxI18n ? window.lmxI18n.num(n) : String(n);
  }

  function parseDate(v) {
    if (!v) return null;
    var ts = new Date(String(v).replace(' ', 'T')).getTime();
    return isNaN(ts) ? null : ts;
  }

  /* "Mar 2019" — a join date is a tenure signal, not an appointment. */
  function monthYear(v) {
    var ts = parseDate(v);
    if (ts === null) return null;
    if (window.lmxI18n) return window.lmxI18n.monthYear(ts);
    return new Date(ts).toLocaleString(undefined, { month: 'short', year: 'numeric' });
  }

  function fullDate(v) {
    var ts = parseDate(v);
    if (ts === null) return null;
    if (window.lmxI18n) return window.lmxI18n.date(ts);
    return new Date(ts).toLocaleString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
  }

  function dateTime(v) {
    var ts = parseDate(v);
    if (ts === null) return null;
    if (window.lmxI18n) return window.lmxI18n.dateTime(ts);
    return new Date(ts).toLocaleString();
  }

  function ago(v) {
    var ts = parseDate(v);
    if (ts === null) return null;
    // "hace 3 horas", not "3h ago". Intl.RelativeTimeFormat picks the unit and
    // the wording; the hand-rolled ladder below is the no-i18n fallback.
    if (window.lmxI18n) return window.lmxI18n.rel(ts);
    var s = Math.floor((Date.now() - ts) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    var d = Math.floor(s / 86400);
    if (d < 30) return d + 'd ago';
    if (d < 365) return Math.round(d / 30.44) + 'mo ago';
    return Math.round(d / 365.25) + 'y ago';
  }

  /* ================================================================= model */

  function infoOf(user) {
    if (!user) return null;
    try {
      var i = user.attribute('userInfo');
      return i && typeof i === 'object' ? i : null;
    } catch (e) {
      return null;
    }
  }

  /*
   * The identity payload from local/looksmax-ranks, when that extension is
   * installed and has data. Read defensively and never required: this panel
   * must render fully on an install where ranks does not exist.
   */
  function identityOf(user) {
    if (!user) return null;
    try {
      var i = user.attribute('identity');
      return i && typeof i === 'object' ? i : null;
    } catch (e) {
      return null;
    }
  }

  /* The colour a name is painted in. Rank first, then the ranks extension's
   * own cosmetic, then the theme's default ink. Never a colour this file
   * invented for a user. */
  function nameColor(user) {
    var info = infoOf(user);
    if (info && info.rank && info.rank.name && info.rank.color) return info.rank.color;
    var id = identityOf(user);
    if (id && id.rankColor && id.rankSlug && id.rankSlug !== 'greycel') return id.rankColor;
    return null;
  }

  function nameClass(user) {
    var id = identityOf(user);
    return id && id.nameClass ? id.nameClass : null;
  }

  function frameClass(user) {
    var id = identityOf(user);
    return id && id.frameClass ? id.frameClass : null;
  }

  function can(info, what) {
    return !!(info && info.can && info.can[what]);
  }

  function me() {
    try { return app.session && app.session.user ? app.session.user : null; } catch (e) { return null; }
  }

  /* ============================================================== fragments */

  function route(url) {
    return function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
      e.preventDefault();
      closeCard();
      try { m.route.set(url); } catch (x) { window.location.href = url; }
    };
  }

  function avatarNode(user, cls) {
    var av = reg.avatar;
    var frame = frameClass(user);
    var inner = av ? av(user, { className: 'LmxAvatar-img' }) : null;
    var info = infoOf(user);

    return m(
      'span.LmxAvatar' + (cls ? '.' + cls : '') + (frame ? '.' + frame : ''),
      { 'data-online': info && info.online ? '1' : '0' },
      [
        inner,
        // The dot is a sibling of the image, not an overlay on it, so a broken
        // avatar url cannot take the presence indicator with it.
        info
          ? m('span.LmxAvatar-presence', {
              title: info.online
                ? t('presence.online')
                : info.lastSeenAt
                ? t('presence.last_seen', { when: ago(info.lastSeenAt) })
                : t('presence.never_seen'),
            })
          : null,
      ]
    );
  }

  function nameNode(user, info, cls) {
    if (!user) return m('span.LmxAuthor-name', t('user.deleted'));
    var color = nameColor(user);
    var ns = nameClass(user);
    var href = app && app.route ? app.route.user(user) : '#';

    return m(
      'a.LmxAuthor-name' + (cls ? '.' + cls : '') + (ns ? '.' + ns : ''),
      {
        href: href,
        // Mithril routes internal links only when the router link component is
        // used; a bare href reloads the SPA. onclick keeps it a route change.
        onclick: route(href),
        style: color ? { color: color } : undefined,
        'data-legacy-style': info && info.legacyStyleClass ? info.legacyStyleClass : undefined,
      },
      user.displayName()
    );
  }

  function rankChip(info) {
    if (!info || !info.rank || !info.rank.name || !FIELD.rank) return null;
    var r = info.rank;

    return m(
      'span.LmxRank',
      {
        // A CUSTOM PROPERTY, not `color`/`borderColor` directly.
        //
        // An inline `color` is the highest-priority declaration there is, so
        // the stylesheet cannot adapt it — and the rank palette (Catalog.php)
        // is a set of pale hues chosen for a near-black page. On the light
        // scheme that shipped as unreadable text: measured 1.63:1 for the
        // Luminary chip against the 4.5 floor. Handing CSS the raw hue instead
        // lets less/forum.less mix it toward --ink per scheme, which keeps the
        // rank identifiable in both without the client needing to know which
        // scheme is active.
        style: { '--lmx-rank-color': r.color },
        'data-source': r.source,
        title:
          r.source === 'ranks'
            ? (r.nextName
                ? t('rank.title_next', { name: r.name, points: exact(r.toNext), next: r.nextName })
                : t('rank.title', { name: r.name }))
            : r.kind === 'style'
            ? t('rank.legacy_style', { name: r.name })
            : t('rank.legacy_rank', { name: r.name }),
      },
      [r.icon ? m('iconify-icon.LmxRank-icon', { icon: r.icon }) : null, m('span', r.name)]
    );
  }

  /*
   * The forum's OWN groups.
   *
   * A third axis beside rank (earned) and banner (carried over): a group is
   * what this install granted, and it is the only one of the three that carries
   * real permissions. Rendering it is most of what "roles and shit" meant —
   * before this, an administrator and a brand-new account were typographically
   * identical everywhere except the profile page.
   */
  function groupNodes(info) {
    if (!info || !info.groups || !info.groups.length || !FIELD.groups) return null;
    return m(
      'span.LmxGroups',
      info.groups.map(function (g) {
        return m(
          'span.LmxGroup',
          { style: g.color ? { color: g.color, borderColor: g.color } : undefined, title: t('group.title', { name: g.name }) },
          [g.icon ? m('i.LmxGroup-icon', { className: g.icon }) : null, m('span', g.name)]
        );
      })
    );
  }

  function bannerNodes(info) {
    if (!info || !info.banners || !info.banners.length || !FIELD.banners) return null;
    return m(
      'span.LmxBanners',
      info.banners.map(function (b) {
        return m(
          'span.LmxBanner',
          { style: { color: b.color, borderColor: b.color }, title: t('banner.title', { name: b.name }) },
          [m('iconify-icon', { icon: b.icon }), m('span', b.name)]
        );
      })
    );
  }

  function suspendedChip(info) {
    if (!info || !info.suspended) return null;
    return m('span.LmxSuspended', { title: t('suspended.until', { date: dateTime(info.suspended) || '' }) }, [
      m('iconify-icon', { icon: 'ph:prohibit-bold' }),
      m('span', t('suspended.label')),
    ]);
  }

  function presenceNode(info) {
    if (!info || !FIELD.presence) return null;
    if (info.online) {
      return m('div.LmxPresence.is-online', [m('i.LmxPresence-dot'), m('span', t('presence.online'))]);
    }
    if (!info.lastSeenAt) {
      return m('div.LmxPresence', [m('i.LmxPresence-dot'), m('span', t('presence.never_seen'))]);
    }
    return m('div.LmxPresence', { title: t('presence.last_seen_on', { date: fullDate(info.lastSeenAt) || '' }) }, [
      m('i.LmxPresence-dot'),
      m('span', t('presence.seen_short', { when: ago(info.lastSeenAt) })),
    ]);
  }

  /*
   * The stat block.
   *
   * Local numbers only, and every one of them comes off the SAME column the
   * profile page prints: `users.comment_count` and `users.discussion_count`.
   * `userinfo:backfill --verify` asserts both against a live COUNT(*) and now
   * also against the `userinfo_profiles` snapshot, which is what had drifted —
   * the card said 6 posts where the profile said 295 for the same account
   * (user 3015, measured 2026-08-13). One source, checked by a command.
   */
  function statsNode(info, opts) {
    if (!info) return null;
    opts = opts || {};
    var rows = [];

    var joined = FIELD.joined ? monthYear(info.joinedAt) : null;
    if (joined) {
      rows.push(stat(t('stat.joined'), joined, fullDate(info.joinedAt), 'ph:calendar-blank-fill'));
    }
    if (FIELD.posts) {
      rows.push(stat(
        t('stat.messages'),
        compact(info.posts),
        t('stat.messages_title', { n: info.posts, count: exact(info.posts) }),
        'ph:chat-teardrop-text-fill'
      ));
    }
    if (FIELD.threads) {
      rows.push(stat(
        t('stat.threads'),
        compact(info.discussions),
        t('stat.threads_title', { n: info.discussions, count: exact(info.discussions) }),
        'ph:tree-structure-fill'
      ));
    }

    // Only when it exists. A reaction score of 0 on an account with 400 posts
    // would read as "nobody liked any of this", which is not what we measured —
    // we measured that we have no reaction data for it.
    if (FIELD.reactions && info.reactions > 0) {
      rows.push(stat(
        t('stat.reactions'),
        compact(info.reactions),
        t('stat.reactions_title', { n: info.reactions, count: exact(info.reactions) }) +
          (info.reactionRate ? t('stat.reactions_rate', { rate: num(info.reactionRate) }) : ''),
        'ph:heart-fill'
      ));
    }

    if (opts.wide && FIELD.perDay && info.postsPerDay) {
      rows.push(stat(t('stat.per_day'), num(info.postsPerDay), t('stat.per_day_title'), 'ph:pulse-bold'));
    }
    if (opts.wide && FIELD.bestPost && info.bestPostScore > 0) {
      rows.push(stat(
        t('stat.best_post'),
        compact(info.bestPostScore),
        t('stat.best_post_title', { n: info.bestPostScore, count: exact(info.bestPostScore) }),
        'ph:trophy-fill'
      ));
    }

    if (!rows.length) return null;

    return m('dl.LmxStats' + (opts.wide ? '.LmxStats--wide' : '') + (opts.row ? '.LmxStats--row' : ''), rows);
  }

  function stat(label, value, title, icon) {
    return m('div.LmxStat', { title: title || label }, [
      m('dt', [icon ? m('iconify-icon', { icon: icon }) : null, m('span', label)]),
      m('dd', value),
    ]);
  }

  /*
   * Carried-over standing.
   *
   * The source board's lifetime totals are real and worth showing — a member
   * who wrote 180,000 posts elsewhere is not a new account — but they describe
   * a DIFFERENT FORUM. Merging them into the local counts would produce a
   * number that is true nowhere, so they are their own row, visually quieter,
   * and explicitly labelled.
   *
   * ── Why the label came back ────────────────────────────────────────────────
   * A previous pass reduced this to an archive glyph plus "38.2K posts · 76.3K
   * reactions" and put the sentence in a tooltip, because the prose was two
   * thirds of a 176px line. That solved the width and created a worse problem:
   * on a card that also says "Messages 8", an unlabelled "38.2K posts" reads as
   * a contradiction, and a tooltip is not an answer on a phone, which has no
   * hover. The rail is 200px now and the card is 340px, so the label fits — and
   * where it does not, `.LmxLegacy-label` truncates before the figures do.
   */
  function legacyNode(info) {
    if (!info || !info.legacy || !FIELD.legacy) return null;
    var l = info.legacy;
    if (!l.posts && !l.reactions && !l.threads) return null;

    var bits = [];
    if (l.posts) bits.push(t('legacy.posts', { n: l.posts, count: compact(l.posts) }));
    if (l.reactions) bits.push(t('legacy.reactions', { n: l.reactions, count: compact(l.reactions) }));
    if (!bits.length) return null;

    return m(
      'div.LmxLegacy',
      {
        title: t('legacy.title', {
          posts: exact(l.posts),
          reactions: exact(l.reactions),
          threads: exact(l.threads),
          hasJoined: l.joined ? 'yes' : 'no',
          joined: l.joined || '',
        }),
      },
      [
        m('iconify-icon', { icon: 'ph:archive-fill', 'aria-hidden': 'true' }),
        m('span.LmxLegacy-label', t('legacy.label')),
        m('span.LmxLegacy-figures', bits.join(' · ')),
      ]
    );
  }

  /*
   * The reaction mix.
   *
   * The crawler recorded WHICH of the nine reaction types each post drew, not
   * how many of each, so this is "how many of their posts drew a +1" and not
   * "how many +1s they got". The tooltip says exactly that, because a bar that
   * silently means something other than what a reader assumes is worse than no
   * bar.
   */
  var MIX_COLOR = {
    '+1': '#7fd18b', 'JFL': '#f0b352', 'Love it': '#f2748a', 'Hmm...': '#98a3b3',
    'So Sad': '#6ea8fe', 'Woah': '#b58cf0', 'Ugh..': '#8a94a6', 'WTF': '#e0705f', 'Nerd': '#5fd3c4',
  };

  function mixNode(info) {
    if (!info || !info.reactionMix || !FIELD.mix) return null;
    var keys = Object.keys(info.reactionMix);
    if (!keys.length) return null;

    var total = keys.reduce(function (s, k) { return s + info.reactionMix[k]; }, 0);
    if (!total) return null;

    return m(
      'div.LmxMix',
      {
        title:
          t('mix.title') + '\n' +
          keys.map(function (k) {
            return '  ' + t('mix.row', { name: k, n: info.reactionMix[k], count: exact(info.reactionMix[k]) });
          }).join('\n'),
      },
      keys.map(function (k) {
        return m('i.LmxMix-seg', {
          style: { flexGrow: String(info.reactionMix[k]), background: MIX_COLOR[k] || '#667186' },
        });
      })
    );
  }

  /* ========================================================== author panel */

  /**
   * The rail beside a post.
   *
   * Returned as a Mithril vnode appended to CommentPost's `contentItems`
   * ItemList, NOT injected into the DOM. An injected node inside a Mithril
   * subtree gets index-shifted out of existence on the next diff; a vnode is
   * diffed with everything else and updates when the user model does.
   */
  function authorPanel(post) {
    var user = null;
    try { user = post.user(); } catch (e) { user = null; }

    var info = infoOf(user);

    return m(
      'aside.LmxAuthor',
      {
        // The layout hook has to land on the <article>, and it is applied here
        // rather than by extending Post.classes so it lands on exactly the
        // posts that actually got a panel.
        oncreate: function (vnode) { markPost(vnode.dom); },
        onupdate: function (vnode) { markPost(vnode.dom); },
      },
      [
        m('div.LmxAuthor-id', [
          user
            ? m(
                'a.LmxAuthor-avatarLink',
                { href: app.route.user(user), onclick: route(app.route.user(user)) },
                avatarNode(user, 'LmxAvatar--lg')
              )
            : avatarNode(null, 'LmxAvatar--lg'),
          m('div.LmxAuthor-idText', [
            nameNode(user, info),
            rankChip(info),
            groupNodes(info),
            info && info.title ? m('div.LmxAuthor-title', { title: info.title }, info.title) : null,
            suspendedChip(info),
            bannerNodes(info),
          ]),
        ]),
        statsNode(info, { row: true }),
        mixNode(info),
        legacyNode(info),
        presenceNode(info),
      ]
    );
  }

  function markPost(el) {
    try {
      var art = el && el.closest ? el.closest('article.Post') : null;
      if (art && !art.classList.contains('has-lmx-author')) art.classList.add('has-lmx-author');
    } catch (e) {}
  }

  /* ======================================================= THE hover card */

  /*
   * One host, one open card, one set of timers.
   *
   * The card is a plain DOM node rendered with m.render into a detached host,
   * not a Mithril route-level component: it must survive a redraw of whatever
   * is underneath it and must not participate in the page's diff.
   *
   * ── Stacking ───────────────────────────────────────────────────────────────
   * The host is a direct child of <body>, never of the anchor's subtree. That
   * is not tidiness: `.Post`, the header and several theme surfaces create
   * stacking contexts with transform/filter/backdrop-filter, and a card
   * rendered inside one of them cannot rise above its ancestor no matter what
   * z-index it is given. `--z-tooltip` on a body-level host is the only
   * arrangement that actually wins.
   */
  var HOST = null;
  var SCRIM = null;
  var openTimer = null;
  var closeTimer = null;
  var openFor = null; // the key of the user the card is currently showing
  var openAnchor = null;
  var openMode = 'popover'; // 'popover' | 'sheet'
  var lastFocus = null;

  function host() {
    if (HOST && document.body.contains(HOST)) return HOST;
    HOST = document.createElement('div');
    HOST.className = 'LmxCardHost';
    HOST.setAttribute('role', 'dialog');
    HOST.setAttribute('aria-live', 'polite');
    HOST.addEventListener('mouseenter', function () { clearTimeout(closeTimer); });
    HOST.addEventListener('mouseleave', function () { if (openMode === 'popover' && CFG.trigger === 'hover') scheduleClose(); });
    document.body.appendChild(HOST);
    return HOST;
  }

  function scrim() {
    if (SCRIM && document.body.contains(SCRIM)) return SCRIM;
    SCRIM = document.createElement('div');
    SCRIM.className = 'LmxCardScrim';
    SCRIM.addEventListener('click', closeCard);
    document.body.appendChild(SCRIM);
    return SCRIM;
  }

  function scheduleClose() {
    clearTimeout(closeTimer);
    closeTimer = setTimeout(closeCard, Number(CFG.closeDelay) || 220);
  }

  function closeCard() {
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
    openFor = null;
    openAnchor = null;
    if (HOST) {
      HOST.classList.remove('is-open', 'is-sheet');
      try { m.render(HOST, null); } catch (e) {}
    }
    if (SCRIM) SCRIM.classList.remove('is-open');
    document.documentElement.classList.remove('lmx-card-open');
    if (lastFocus && lastFocus.focus) {
      try { lastFocus.focus({ preventScroll: true }); } catch (e) {}
    }
    lastFocus = null;
  }

  /** The slug in /u/<slug>, or null for anything that is not a user link. */
  function userSlugFrom(el) {
    if (!el || !el.getAttribute) return null;
    var explicit = el.getAttribute('data-lmx-user');
    if (explicit) return explicit;
    var href = el.getAttribute('href') || '';
    var mm = href.match(/\/u\/([^/?#]+)/);
    return mm ? decodeURIComponent(mm[1]) : null;
  }

  function userIdFrom(el) {
    if (!el || !el.getAttribute) return null;
    var v = el.getAttribute('data-lmx-userid');
    return v && /^\d+$/.test(v) ? v : null;
  }

  function findUserBySlug(slug) {
    var all = app.store.all('users');
    var lower = String(slug).toLowerCase();
    for (var i = 0; i < all.length; i++) {
      var u = all[i];
      try {
        if (String(u.slug()).toLowerCase() === lower || String(u.username()).toLowerCase() === lower) return u;
      } catch (e) {}
    }
    return null;
  }

  /* ------------------------------------------------------------- actions */

  /*
   * Actions are permission-gated by the SERVER, in `userInfo.can`, and this
   * function renders exactly the ones that came back true. Nothing here infers
   * a permission from a group name or from the presence of data: a button that
   * 403s is the same defect as a button that does nothing.
   *
   * `report` has a second condition that only the browser knows — flarum/flags
   * flags a POST, not a person, so the action is offered only when the card was
   * opened from inside one.
   */
  function actionNodes(user, info, anchor) {
    if (!user || !info) return null;
    var items = [];

    ACTION.forEach(function (name) {
      var a = buildAction(name, user, info, anchor);
      if (a) items.push(a);
    });

    if (!items.length) return null;
    return m('div.LmxCardActions', items);
  }

  function actionButton(icon, label, onclick, cls) {
    return m(
      'button.LmxCardAction' + (cls ? '.' + cls : ''),
      { type: 'button', onclick: onclick, title: label },
      [m('iconify-icon', { icon: icon, 'aria-hidden': 'true' }), m('span', label)]
    );
  }

  function buildAction(name, user, info, anchor) {
    var href;

    if (name === 'profile' && can(info, 'profile')) {
      href = app.route.user(user);
      return m('a.LmxCardAction', { href: href, onclick: route(href), title: t('card.view_profile') },
        [m('iconify-icon', { icon: 'ph:user-fill', 'aria-hidden': 'true' }), m('span', t('card.view_profile'))]);
    }

    if (name === 'posts' && can(info, 'posts') && info.posts > 0) {
      href = app.route.user(user) + '?tab=posts';
      return m('a.LmxCardAction', { href: href, onclick: route(href), title: t('card.view_posts') },
        [m('iconify-icon', { icon: 'ph:chat-teardrop-text-fill', 'aria-hidden': 'true' }), m('span', t('card.view_posts'))]);
    }

    if (name === 'message' && CFG.dmEnabled && can(info, 'message')) {
      return actionButton('ph:paper-plane-tilt-fill', t('card.message'), function () {
        closeCard();
        openCompose(user);
      }, 'LmxCardAction--primary');
    }

    if (name === 'mention' && can(info, 'mention') && composerReachable()) {
      return actionButton('ph:at-bold', t('card.mention'), function () {
        closeCard();
        insertMention(user);
      });
    }

    if (name === 'report' && can(info, 'report')) {
      var post = postFor(anchor);
      if (post) {
        return actionButton('ph:flag-fill', t('card.report'), function () {
          closeCard();
          reportPost(post);
        });
      }
      return null;
    }

    if (name === 'suspend' && can(info, 'suspend')) {
      return actionButton('ph:prohibit-bold', info.suspended ? t('card.unsuspend') : t('card.suspend'), function () {
        closeCard();
        openSuspend(user);
      }, 'LmxCardAction--danger');
    }

    if (name === 'edit' && can(info, 'edit')) {
      return actionButton('ph:pencil-simple-fill', t('card.edit'), function () {
        closeCard();
        openEdit(user);
      });
    }

    return null;
  }

  /** The post model the card was opened from, or null when it was not. */
  function postFor(anchor) {
    try {
      var art = anchor && anchor.closest ? anchor.closest('article.Post[id^="post-"], .PostStream-item[data-id]') : null;
      if (!art) return null;
      var id = art.getAttribute('data-id') || (art.id || '').replace(/^post-/, '');
      return id ? app.store.getById('posts', id) : null;
    } catch (e) {
      return null;
    }
  }

  function composerReachable() {
    try { return !!(app.composer && me()); } catch (e) { return false; }
  }

  /*
   * Insert a mention using flarum/mentions' OWN replacement format.
   *
   * Hardcoding `@"name"#id` here would be a second copy of a format that
   * extension owns and can change; `app.mentionables.get('user').replacement()`
   * is the same call its autocomplete makes. The literal is the fallback for an
   * install without the extension, where it renders as plain text rather than
   * as a broken tag.
   */
  function mentionText(user) {
    try {
      var mentionable = app.mentionables && app.mentionables.get ? app.mentionables.get('user') : null;
      if (mentionable && typeof mentionable.replacement === 'function') return mentionable.replacement(user);
    } catch (e) {}
    return '@"' + user.displayName() + '"#' + user.id() + ' ';
  }

  function insertMention(user) {
    var text = mentionText(user);
    try {
      if (app.composer && app.composer.isVisible && app.composer.isVisible() && app.composer.editor) {
        app.composer.editor.insertAtCursor(text);
        app.composer.focus();
        return;
      }
      // No composer open: put it where a reply will pick it up. `bodyPreset` is
      // read by the reply placeholder on this install; when it is not, the text
      // still reaches the clipboard so the action is never a no-op.
      if (app.composer && app.composer.editor) {
        app.composer.editor.insertAtCursor(text);
        return;
      }
    } catch (e) {}
    try {
      navigator.clipboard.writeText(text);
      alertOk(t('card.mention_copied'));
    } catch (e) {}
  }

  function reportPost(post) {
    // flarum/flags exposes its modal on the extension registry. Resolved at
    // click time, not at boot: the flags bundle may load after this file.
    try {
      var ext = window.flarum && window.flarum.extensions && window.flarum.extensions['flarum-flags'];
      var Modal = ext && (ext.components && ext.components.FlagPostModal);
      if (Modal) { app.modal.show(Modal, { post: post }); return; }
    } catch (e) {}
    // Fall back to the post's own control list, which flags always populates.
    try {
      var el = document.querySelector('#post-' + post.id() + ' .Post-controls .Dropdown-toggle');
      if (el) { el.click(); return; }
    } catch (e) {}
    alertErr(t('card.report_unavailable'));
  }

  function openSuspend(user) {
    try {
      var ext = window.flarum && window.flarum.extensions && window.flarum.extensions['flarum-suspend'];
      var Modal = ext && (ext.components && ext.components.SuspendUserModal);
      if (Modal) { app.modal.show(Modal, { user: user }); return; }
    } catch (e) {}
    // The user page always carries the control; send the moderator there
    // rather than pretending the action happened.
    m.route.set(app.route.user(user));
  }

  function openEdit(user) {
    try {
      var EditUserModal = mod('components/EditUserModal');
      if (EditUserModal) { app.modal.show(EditUserModal, { user: user }); return; }
    } catch (e) {}
    m.route.set(app.route.user(user));
  }

  function alertOk(text) {
    try { app.alerts.show({ type: 'success' }, text); } catch (e) {}
  }

  function alertErr(text) {
    try { app.alerts.show({ type: 'error' }, text); } catch (e) {}
  }

  /* --------------------------------------------------------------- view */

  function cardView(user, anchor) {
    var info = infoOf(user);
    var color = nameColor(user);

    return m('div.LmxHoverCard', { 'data-mode': openMode }, [
      // A wash in the account's own rank colour. It is the only decoration on
      // the card and it is data, not styling: two accounts with the same rank
      // look the same, and one without a rank has no wash at all.
      color ? m('div.LmxHoverCard-wash', { style: { background: color } }) : null,

      m('button.LmxHoverCard-close', {
        type: 'button',
        onclick: closeCard,
        'aria-label': t('card.close'),
      }, m('iconify-icon', { icon: 'ph:x-bold' })),

      m('div.LmxHoverCard-head', [
        FIELD.avatar
          ? m('a.LmxHoverCard-avatar', { href: app.route.user(user), onclick: route(app.route.user(user)) },
              avatarNode(user, 'LmxAvatar--card'))
          : null,
        m('div.LmxHoverCard-headText', [
          m('div.LmxHoverCard-nameRow', [
            FIELD.name ? nameNode(user, info, 'LmxAuthor-name--card') : null,
            suspendedChip(info),
          ]),
          m('div.LmxHoverCard-chips', [rankChip(info), groupNodes(info)]),
          info && info.title && FIELD.title ? m('div.LmxAuthor-title', { title: info.title }, info.title) : null,
          presenceNode(info),
        ]),
      ]),

      bannerNodes(info),
      statsNode(info, { wide: true }),
      mixNode(info),
      legacyNode(info),
      actionNodes(user, info, anchor),
    ]);
  }

  /* ----------------------------------------------------------- placement */

  /*
   * Place the popover, then correct it against what was actually measured.
   *
   * Two passes are unavoidable: the card's height depends on how many stats and
   * actions this particular account has, and that is not known until it has
   * rendered. The first pass puts it in the configured place, the second (in a
   * rAF, after layout) flips and clamps it. `position: fixed`, so the anchor's
   * viewport rect is the coordinate system and no scroll offset arithmetic is
   * involved.
   */
  function place(anchor) {
    var h = host();
    var r = anchor.getBoundingClientRect();
    var margin = 10;

    h.style.left = Math.round(r.left) + 'px';
    h.style.top = Math.round(r.bottom + 8) + 'px';
    h.classList.add('is-open');

    requestAnimationFrame(function () {
      if (!h.classList.contains('is-open')) return;
      var box = h.getBoundingClientRect();
      var vw = document.documentElement.clientWidth;
      var vh = window.innerHeight;

      var left = r.left;
      if (left + box.width > vw - margin) left = vw - margin - box.width;
      if (left < margin) left = margin;

      var below = r.bottom + 8;
      var above = r.top - box.height - 8;
      var top;
      if (CFG.placement === 'top') {
        top = above >= margin ? above : below;
      } else if (CFG.placement === 'bottom') {
        top = below + box.height <= vh - margin ? below : Math.max(margin, above);
      } else {
        // auto: below unless it would be clipped and there is more room above
        top = (below + box.height > vh - margin && above >= margin) ? above : below;
      }
      if (top + box.height > vh - margin) top = Math.max(margin, vh - margin - box.height);
      if (top < margin) top = margin;

      h.style.left = Math.round(left) + 'px';
      h.style.top = Math.round(top) + 'px';
      h.setAttribute('data-placement', top < r.top ? 'top' : 'bottom');
    });
  }

  function placeSheet() {
    var h = host();
    h.style.left = '';
    h.style.top = '';
    h.classList.add('is-open', 'is-sheet');
    scrim().classList.add('is-open');
    document.documentElement.classList.add('lmx-card-open');
  }

  /* --------------------------------------------------------------- open */

  /**
   * Open the card for whoever `anchor` identifies.
   *
   * `key` is the slug or the id — whichever the anchor carried — and is what
   * makes a second hover over the same name a no-op instead of a re-render.
   */
  function openCard(anchor, opts) {
    opts = opts || {};
    if (CFG.mobile === 'off' && isTouchLayout()) return;

    var slug = userSlugFrom(anchor);
    var id = userIdFrom(anchor);
    var key = id ? 'id:' + id : slug ? 'slug:' + slug.toLowerCase() : null;
    if (!key) return;

    if (openFor === key && HOST && HOST.classList.contains('is-open')) return;

    openFor = key;
    openAnchor = anchor;
    openMode = isTouchLayout() && CFG.mobile === 'sheet' ? 'sheet' : 'popover';
    lastFocus = opts.restoreFocus === false ? null : document.activeElement;

    var render = function (user) {
      if (openFor !== key || !user) return;
      var h = host();
      m.render(h, cardView(user, anchor));
      if (openMode === 'sheet') placeSheet();
      else place(anchor);
      if (opts.focus) {
        var first = h.querySelector('.LmxCardAction, .LmxHoverCard-close');
        if (first) try { first.focus({ preventScroll: true }); } catch (e) {}
      }
    };

    var existing = id ? app.store.getById('users', id) : slug ? findUserBySlug(slug) : null;
    if (existing) {
      render(existing);
      return;
    }

    // Not in the store yet — a mention in a post body references a user the
    // page never loaded. One request, and the store caches it for every later
    // hover anywhere on the site.
    var pending = id
      ? app.store.find('users', id)
      : app.store.find('users', slug, { bySlug: true });

    pending.then(render).catch(function () { if (openFor === key) closeCard(); });
  }

  /* ------------------------------------------------------------- wiring */

  /**
   * What counts as a username on this forum.
   *
   * `a[href*="/u/"]` is the honest definition — it is what the DOM actually
   * contains at every one of the call sites, including the ones this file has
   * never heard of. The two data attributes are the escape hatch for a lane
   * whose username is not a link (the shoutbox sets `data-lmx-user`).
   */
  var ANCHOR_SELECTOR = 'a[href*="/u/"], [data-lmx-user], [data-lmx-userid]';

  function anchorFrom(target) {
    if (!target || !target.closest) return null;
    var el = target.closest(ANCHOR_SELECTOR);
    if (!el) return null;
    // Opted out explicitly, or inside the card itself.
    if (el.closest('[data-lmx-nocard]') || el.closest('.LmxHoverCard')) return null;
    if (!userSlugFrom(el) && !userIdFrom(el)) return null;
    return el;
  }

  function wireCard() {
    if (document.documentElement.getAttribute('data-lmx-card') === '1') return;
    document.documentElement.setAttribute('data-lmx-card', '1');

    document.addEventListener('mouseover', function (e) {
      if (CFG.trigger !== 'hover' || isTouchLayout()) return;
      var a = anchorFrom(e.target);
      if (!a) return;
      clearTimeout(closeTimer);
      clearTimeout(openTimer);
      openTimer = setTimeout(function () { openCard(a); }, Number(CFG.openDelay) || 320);
    }, true);

    document.addEventListener('mouseout', function (e) {
      if (CFG.trigger !== 'hover' || isTouchLayout()) return;
      if (!anchorFrom(e.target)) return;
      clearTimeout(openTimer);
      scheduleClose();
    }, true);

    /*
     * Click. On touch this is the ONLY way in, and it must not also navigate:
     * a tap that opens a sheet and simultaneously routes to the profile shows
     * the sheet for one frame over a page the reader did not ask for. On a
     * pointer device with `trigger: click` the same applies; with
     * `trigger: hover` a click is left alone so a username stays a link.
     */
    document.addEventListener('click', function (e) {
      var a = anchorFrom(e.target);
      if (!a) return;
      var touch = isTouchLayout();
      if (!touch && CFG.trigger !== 'click') return;
      if (touch && CFG.mobile === 'off') return;
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;

      // A second tap on the same name closes it, which is the only way to
      // dismiss a sheet without reaching for the scrim.
      var key = userIdFrom(a) ? 'id:' + userIdFrom(a) : 'slug:' + String(userSlugFrom(a)).toLowerCase();
      if (openFor === key) { e.preventDefault(); closeCard(); return; }

      e.preventDefault();
      e.stopPropagation();
      openCard(a, { focus: touch });
    }, true);

    // A keyboard user gets the same information: focusing a username opens the
    // card, blurring closes it, Escape always closes it.
    document.addEventListener('focusin', function (e) {
      if (isTouchLayout()) return;
      var a = anchorFrom(e.target);
      if (a) openCard(a, { restoreFocus: false });
    });
    document.addEventListener('focusout', function (e) {
      if (anchorFrom(e.target)) scheduleClose();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && openFor) { e.stopPropagation(); closeCard(); }
    });

    // A popover is anchored to a viewport rect, so it has to go when that rect
    // moves. A sheet is not anchored to anything and stays.
    window.addEventListener('scroll', function () {
      if (openFor && openMode === 'popover' && CFG.followScroll) closeCard();
    }, { passive: true, capture: true });
    window.addEventListener('resize', function () { if (openFor) closeCard(); });

    // Declare ownership of the hover-card surface. less/forum.less uses this
    // class to suppress the older card in local/looksmax-ranks, which is the
    // only other implementation on this install. See HOVERCARD-API.md.
    document.documentElement.classList.add('lmx-usercard-owner');
    if (document.querySelector('.LmxHover') && window.console) {
      console.info('[userinfo] a second hover card (.LmxHover, looksmax-ranks) is present and is being suppressed; see HOVERCARD-API.md');
    }
  }

  /* ================================================== the public mount API */

  /*
   * Everything another lane needs, and nothing it does not.
   *
   * The card is delegated from `document`, so in the overwhelming majority of
   * cases a lane needs to do NOTHING AT ALL: render a username as an anchor to
   * /u/<slug> and the card appears. This object exists for the two cases where
   * that is not possible — a username that is not a link, and a card that has
   * to open from something other than a hover.
   */
  function publishApi() {
    window.LmxUserCard = {
      version: 2,

      /** The live configuration. Read-only by convention; see admin.js. */
      config: CFG,

      /**
       * Mark an element as identifying a user.
       *
       *   LmxUserCard.attach(el, { username: 'Notcel' })
       *   LmxUserCard.attach(el, { id: 3015 })
       *
       * Sets the data attributes the delegated listener already looks for. No
       * event listener is added, so calling it twice is harmless and removing
       * the element leaks nothing.
       */
      attach: function (el, opts) {
        if (!el || !opts) return el;
        if (opts.username) el.setAttribute('data-lmx-user', String(opts.username));
        if (opts.id) el.setAttribute('data-lmx-userid', String(opts.id));
        return el;
      },

      /** Open the card against an element, now, ignoring trigger/delay. */
      open: function (el, opts) { openCard(el, opts || {}); },

      close: closeCard,

      /** Opt a subtree out entirely. */
      ignore: function (el) { if (el) el.setAttribute('data-lmx-nocard', '1'); return el; },

      /** Open the DM composer to a user model or a numeric id. */
      message: function (userOrId) {
        var u = typeof userOrId === 'object' ? userOrId : app.store.getById('users', String(userOrId));
        if (u) openCompose(u);
      },

      /** Open the inbox. */
      inbox: function () { openInbox(); },
    };
  }

  /* ============================================================== messages */

  /*
   * Direct messages.
   *
   * The card's "Message" action was the thing the operator specifically
   * refused to accept as a dead button, and this install has NO private-message
   * extension: `SHOW TABLES` has no conversations table and `php flarum info`
   * lists none. fof/byobu was evaluated and rejected — see the reasoning in
   * migrations/2026_08_13_170000_create_lmx_dm.php — so the whole round trip
   * lives here: three tables, six API routes, and this UI.
   *
   * The UI is deliberately NOT a route. Flarum builds its router at boot from
   * `app.routes`, and this file runs after that, so a route added here would
   * never resolve. An overlay opens from anywhere, works on the profile page
   * and in the middle of a thread without losing the reader's place, and needs
   * no server-rendered page.
   */
  var DM = {
    host: null,
    open: false,
    view: 'list',    // 'list' | 'thread' | 'compose'
    threads: null,
    thread: null,
    messages: [],
    to: null,        // user model, when composing
    draft: '',
    busy: false,
    error: null,
    unread: 0,
  };

  function dmUrl(path) {
    return app.forum.attribute('apiUrl') + '/lmx-dm' + path;
  }

  function dmHost() {
    if (DM.host && document.body.contains(DM.host)) return DM.host;
    DM.host = document.createElement('div');
    DM.host.className = 'LmxDmHost';
    document.body.appendChild(DM.host);
    return DM.host;
  }

  function dmRedraw() {
    if (!DM.open) return;
    try { m.render(dmHost(), dmView()); } catch (e) { if (window.console) console.warn('[userinfo] dm render', e); }
  }

  function closeDm() {
    DM.open = false;
    DM.error = null;
    document.documentElement.classList.remove('lmx-dm-open');
    if (DM.host) { try { m.render(DM.host, null); } catch (e) {} }
  }

  function openInbox() {
    DM.open = true;
    DM.view = 'list';
    DM.error = null;
    document.documentElement.classList.add('lmx-dm-open');
    dmRedraw();
    loadThreads();
  }

  function openCompose(user) {
    DM.open = true;
    DM.view = 'compose';
    DM.to = user;
    DM.draft = '';
    DM.error = null;
    document.documentElement.classList.add('lmx-dm-open');
    dmRedraw();
    setTimeout(function () {
      var ta = DM.host && DM.host.querySelector('.LmxDm-input');
      if (ta) ta.focus();
    }, 60);
  }

  function loadThreads() {
    DM.busy = true;
    dmRedraw();
    app.request({ method: 'GET', url: dmUrl('/threads') })
      .then(function (res) {
        DM.threads = (res && res.threads) || [];
        DM.unread = (res && res.unread) || 0;
        DM.busy = false;
        paintUnread();
        dmRedraw();
      })
      .catch(function (err) { dmFail(err); });
  }

  function openThread(id) {
    DM.view = 'thread';
    DM.busy = true;
    DM.thread = null;
    DM.messages = [];
    DM.error = null;
    dmRedraw();

    app.request({ method: 'GET', url: dmUrl('/threads/' + id) })
      .then(function (res) {
        DM.thread = res.thread;
        DM.messages = res.messages || [];
        DM.busy = false;
        dmRedraw();
        scrollDmToEnd();
        // Reading it is what marks it read; the server owns the cursor.
        return app.request({ method: 'POST', url: dmUrl('/threads/' + id + '/read'), body: {} });
      })
      .then(function (res) {
        if (res && typeof res.unread === 'number') { DM.unread = res.unread; paintUnread(); }
      })
      .catch(function (err) { dmFail(err); });
  }

  function scrollDmToEnd() {
    setTimeout(function () {
      var log = DM.host && DM.host.querySelector('.LmxDm-log');
      if (log) log.scrollTop = log.scrollHeight;
    }, 30);
  }

  function dmFail(err) {
    DM.busy = false;
    // Say what actually happened. A silent failure here is exactly the "dead
    // button" the whole feature exists to avoid.
    var status = err && err.status ? err.status : '?';
    var code = '';
    try { code = err && err.response && err.response.error ? err.response.error : ''; } catch (e) {}
    DM.error = t('dm.error', { status: String(status), code: code });
    if (window.console) console.warn('[userinfo] dm request failed', status, code, err);
    dmRedraw();
  }

  function send() {
    var body = String(DM.draft || '').trim();
    if (!body || DM.busy) return;
    DM.busy = true;
    DM.error = null;
    dmRedraw();

    var req = DM.view === 'compose'
      ? app.request({ method: 'POST', url: dmUrl('/threads'), body: { recipients: [Number(DM.to.id())], body: body } })
      : app.request({ method: 'POST', url: dmUrl('/threads/' + DM.thread.id + '/messages'), body: { body: body } });

    req.then(function (res) {
      DM.draft = '';
      DM.busy = false;
      if (DM.view === 'compose') {
        openThread(res.threadId);
        alertOk(t('dm.sent'));
      } else {
        DM.messages.push(res.message);
        dmRedraw();
        scrollDmToEnd();
      }
    }).catch(function (err) { dmFail(err); });
  }

  function others(participants) {
    var mine = me();
    var myId = mine ? Number(mine.id()) : -1;
    return (participants || []).filter(function (p) { return p.id !== myId; });
  }

  function threadTitle(th) {
    if (th.subject) return th.subject;
    var o = others(th.participants);
    if (!o.length) return t('dm.just_you');
    return o.map(function (p) { return p.displayName; }).join(', ');
  }

  function personAvatar(p) {
    return p.avatarUrl
      ? m('img.LmxDm-avatar', { src: p.avatarUrl, alt: '' })
      : m('span.LmxDm-avatar.LmxDm-avatar--initial', String(p.displayName || '?').charAt(0).toUpperCase());
  }

  function dmView() {
    return m('div.LmxDm', { 'data-view': DM.view }, [
      m('div.LmxDm-scrim', { onclick: closeDm }),
      m('div.LmxDm-panel', { role: 'dialog', 'aria-label': t('dm.title') }, [
        m('header.LmxDm-head', [
          DM.view !== 'list'
            ? m('button.LmxDm-back', { type: 'button', onclick: function () { DM.view = 'list'; dmRedraw(); loadThreads(); }, 'aria-label': t('dm.back') },
                m('iconify-icon', { icon: 'ph:arrow-left-bold' }))
            : m('iconify-icon.LmxDm-headIcon', { icon: 'ph:envelope-simple-fill' }),
          m('h3', DM.view === 'compose'
            ? t('dm.compose_to', { name: DM.to ? DM.to.displayName() : '' })
            : DM.view === 'thread' && DM.thread
            ? threadTitle(DM.thread)
            : t('dm.title')),
          m('button.LmxDm-close', { type: 'button', onclick: closeDm, 'aria-label': t('dm.close') },
            m('iconify-icon', { icon: 'ph:x-bold' })),
        ]),

        DM.error ? m('div.LmxDm-error', DM.error) : null,

        DM.view === 'list' ? dmList() : DM.view === 'thread' ? dmThread() : dmCompose(),
      ]),
    ]);
  }

  function dmList() {
    if (DM.busy && !DM.threads) return m('div.LmxDm-empty', t('dm.loading'));
    if (!DM.threads || !DM.threads.length) return m('div.LmxDm-empty', t('dm.empty'));

    return m('ul.LmxDm-threads', DM.threads.map(function (th) {
      var o = others(th.participants);
      return m('li', m('button.LmxDm-thread' + (th.unread ? '.is-unread' : ''), {
        type: 'button',
        onclick: function () { openThread(th.id); },
      }, [
        o.length ? personAvatar(o[0]) : m('span.LmxDm-avatar.LmxDm-avatar--initial', '?'),
        m('span.LmxDm-threadText', [
          m('span.LmxDm-threadTop', [
            m('span.LmxDm-threadName', threadTitle(th)),
            m('time.LmxDm-threadTime', { datetime: th.lastMessageAt || '', title: dateTime(th.lastMessageAt) || '' }, ago(th.lastMessageAt) || ''),
          ]),
          m('span.LmxDm-threadPreview', th.last && th.last.excerpt ? th.last.excerpt : t('dm.no_messages')),
        ]),
        th.unread ? m('span.LmxDm-badge', String(th.unread)) : null,
      ]));
    }));
  }

  function dmThread() {
    if (DM.busy && !DM.thread) return m('div.LmxDm-empty', t('dm.loading'));
    var mine = me();
    var myId = mine ? Number(mine.id()) : -1;
    var by = {};
    ((DM.thread && DM.thread.participants) || []).forEach(function (p) { by[p.id] = p; });

    return [
      m('div.LmxDm-log', DM.messages.map(function (msg) {
        var p = by[msg.userId] || { displayName: '?' };
        return m('div.LmxDm-msg' + (msg.userId === myId ? '.is-own' : ''), { key: msg.id }, [
          m('div.LmxDm-msgMeta', [
            m('span.LmxDm-msgWho', p.displayName),
            m('time', { datetime: msg.createdAt, title: dateTime(msg.createdAt) || '' }, ago(msg.createdAt) || ''),
          ]),
          m('div.LmxDm-msgBody', msg.deleted ? m('em', t('dm.deleted')) : msg.content),
        ]);
      })),
      dmComposer(t('dm.reply_placeholder')),
    ];
  }

  function dmCompose() {
    return [
      m('div.LmxDm-to', DM.to ? [
        avatarNode(DM.to, 'LmxAvatar--sm'),
        m('span', t('dm.to', { name: DM.to.displayName() })),
      ] : null),
      dmComposer(t('dm.body_placeholder')),
    ];
  }

  function dmComposer(placeholder) {
    var left = Number(CFG.dmMaxLength) - String(DM.draft || '').length;
    return m('form.LmxDm-form', {
      onsubmit: function (e) { e.preventDefault(); send(); },
    }, [
      m('textarea.LmxDm-input', {
        placeholder: placeholder,
        maxlength: String(CFG.dmMaxLength),
        value: DM.draft,
        disabled: DM.busy,
        oninput: function (e) { DM.draft = e.target.value; },
        onkeydown: function (e) {
          // Enter sends, Shift+Enter is a newline. Same contract as the
          // shoutbox on this install, so one habit works in both.
          if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
        },
      }),
      m('div.LmxDm-formFoot', [
        m('span.LmxDm-count' + (left < 200 ? '.is-low' : ''), left < 500 ? String(left) : ''),
        m('button.LmxDm-send.Button.Button--primary', {
          type: 'submit',
          disabled: DM.busy || !String(DM.draft || '').trim(),
        }, [
          m('iconify-icon', { icon: 'ph:paper-plane-tilt-fill' }),
          m('span', DM.busy ? t('dm.sending') : t('dm.send')),
        ]),
      ]),
    ]);
  }

  /* ------------------------------------------------------ the header entry */

  function paintUnread() {
    var el = document.querySelector('.LmxInbox-count');
    if (!el) return;
    el.textContent = DM.unread > 99 ? '99+' : String(DM.unread);
    el.style.display = DM.unread > 0 ? '' : 'none';
  }

  function loadUnread() {
    if (!me() || !CFG.dmEnabled) return;
    app.request({ method: 'GET', url: dmUrl('/unread') })
      .then(function (res) { DM.unread = (res && res.unread) || 0; paintUnread(); m.redraw(); })
      .catch(function () { /* an unread badge is not worth an alert */ });
  }

  function inboxItem() {
    return m('button.LmxInbox.Button.Button--link', {
      type: 'button',
      onclick: openInbox,
      title: t('dm.title'),
      'aria-label': t('dm.title'),
    }, [
      m('iconify-icon', { icon: 'ph:envelope-simple-fill' }),
      m('span.LmxInbox-count', { style: { display: DM.unread > 0 ? '' : 'none' } }, DM.unread > 99 ? '99+' : String(DM.unread)),
    ]);
  }

  /* ============================================================== profile */

  /*
   * The profile summary, fetched once per account per page load.
   *
   * ── The request storm this replaces ─────────────────────────────────────────
   * The first version keyed "have we loaded this?" off a cache that was only
   * written ON SUCCESS, and the caller ran on every redraw. A failing endpoint
   * therefore produced: request -> failure -> redraw -> cache still empty ->
   * request. Measured on /u/Xangsane with the endpoint 403ing, one profile view
   * issued 40+ identical GETs before the screenshot was even taken.
   *
   * Three things stop it, and all three are needed:
   *   1. state is recorded BEFORE the request goes out;
   *   2. failure is a cached result, not the absence of one;
   *   3. a hard attempt cap.
   */
  var summaryCache = {};

  function summaryOf(userId) {
    var e = summaryCache[userId];
    return e && e.state === 'done' ? e.data : null;
  }

  function loadSummary(userId, onDone) {
    onDone = onDone || function () {};
    var e = summaryCache[userId];

    if (e && (e.state === 'loading' || e.state === 'done')) {
      if (e.state === 'done') onDone(e.data);
      return;
    }
    if (e && e.state === 'failed' && e.tries >= 2) {
      onDone(null);
      return;
    }

    var tries = (e && e.tries) || 0;
    summaryCache[userId] = { state: 'loading', data: null, tries: tries + 1 };

    app
      .request({ method: 'GET', url: app.forum.attribute('apiUrl') + '/userinfo/summary', params: { id: userId } })
      .then(function (res) {
        summaryCache[userId] = { state: 'done', data: (res && res.data) || null, tries: tries + 1 };
        onDone(summaryCache[userId].data);
        m.redraw();
      })
      .catch(function (err) {
        summaryCache[userId] = { state: 'failed', data: null, tries: tries + 1 };
        if (window.console) {
          console.warn('[userinfo] summary failed for user ' + userId, err && err.status ? 'HTTP ' + err.status : err);
        }
        onDone(null);
        m.redraw();
      });
  }

  function sparkline(months) {
    if (!months || months.length < 2) return null;
    var max = months.reduce(function (a, b) { return Math.max(a, b.posts); }, 0) || 1;
    return m(
      'div.LmxSpark',
      { title: t('profile.spark_title') },
      months.map(function (p) {
        return m('i.LmxSpark-bar', {
          style: { height: Math.max(2, Math.round((p.posts / max) * 34)) + 'px' },
          title: t('profile.spark_bar', { month: window.lmxI18n ? window.lmxI18n.monthYear(p.month) : p.month, n: p.posts, count: exact(p.posts) }),
        });
      })
    );
  }

  function profileBlocks(user) {
    var info = infoOf(user);
    if (!info) return null;

    var s = summaryOf(user.id());
    var blocks = [];

    blocks.push(
      m('section.LmxProfile-block', [
        m('h4', t('profile.standing')),
        m('div.LmxProfile-chips', [
          rankChip(info),
          groupNodes(info),
          bannerNodes(info),
          info.title ? m('span.LmxAuthor-title', info.title) : null,
        ]),
        statsNode(info, { wide: true }),
        mixNode(info),
        legacyNode(info),
      ])
    );

    if (s && s.topTags && s.topTags.length) {
      blocks.push(
        m('section.LmxProfile-block', [
          m('h4', t('profile.top_tags')),
          m(
            'div.LmxTagBars',
            s.topTags.map(function (tag) {
              var top = s.topTags[0].posts || 1;
              return m('a.LmxTagBar', { href: app.forum.attribute('baseUrl') + '/t/' + tag.slug, title: t('profile.tag_title', { n: tag.posts, count: exact(tag.posts), name: tag.name }) }, [
                m('span.LmxTagBar-name', { style: tag.color ? { color: tag.color } : undefined }, tag.name),
                m('span.LmxTagBar-track', m('i', { style: { width: Math.round((tag.posts / top) * 100) + '%', background: tag.color || 'var(--accent)' } })),
                m('span.LmxTagBar-n', compact(tag.posts)),
              ]);
            })
          ),
        ])
      );
    }

    if (s && s.activityByMonth && s.activityByMonth.length > 1) {
      blocks.push(
        m('section.LmxProfile-block', [
          m('h4', t('profile.activity')),
          sparkline(s.activityByMonth),
          s.replyShare
            ? m('div.LmxProfile-note', t('profile.reply_share', { ns: s.replyShare.starts, starts: exact(s.replyShare.starts), nr: s.replyShare.replies, replies: exact(s.replyShare.replies) }))
            : null,
          s.firstPostAt ? m('div.LmxProfile-note', t('profile.first_post', { date: fullDate(s.firstPostAt) || '' })) : null,
        ])
      );
    }

    if (s && s.recentDiscussions && s.recentDiscussions.length) {
      blocks.push(
        m('section.LmxProfile-block', [
          m('h4', t('profile.recent')),
          m(
            'ul.LmxRecent',
            s.recentDiscussions.map(function (d) {
              var url = '/d/' + d.id + '-' + d.slug;
              return m('li', [
                m('a', { href: app.forum.attribute('baseUrl') + url, onclick: route(url) }, d.title),
                m('span.LmxRecent-meta', t('profile.recent_meta', { nr: d.replies, replies: compact(d.replies), nv: d.views, views: compact(d.views) })),
              ]);
            })
          ),
        ])
      );
    }

    return m('div.LmxProfile', blocks);
  }

  /* ================================================================= wire */

  function wire() {
    loadConfig();

    var CommentPost = reg.CommentPost;
    var UserCard = reg.UserCard;
    var UserPage = reg.UserPage;
    var UserModel = reg.UserModel;
    var HeaderSecondary = reg.HeaderSecondary;

    /* --- the rail, on every comment -------------------------------------- */
    if (CFG.railEnabled && CommentPost && CommentPost.prototype) {
      extend(CommentPost.prototype, 'contentItems', function (items) {
        try {
          var post = this.attrs && this.attrs.post;
          if (!post) return;
          // Priority above 'header' (100), so the panel is the first child of
          // the post's inner wrapper and the float can run down the side.
          items.add('lmxAuthor', authorPanel(post), 110);
        } catch (e) {
          if (window.console) console.warn('[userinfo] panel failed', e);
        }
      });
    }

    /* --- role banners as real badges, everywhere badges are shown --------- */
    if (UserModel && UserModel.prototype) {
      extend(UserModel.prototype, 'badges', function (items) {
        try {
          var info = infoOf(this);
          if (!info || !info.banners) return;
          info.banners.forEach(function (b, i) {
            items.add(
              'lmxBanner' + i,
              m('li.LmxBadge', { title: t('banner.title', { name: b.name }) }, [
                m('iconify-icon', { icon: b.icon, style: { color: b.color } }),
              ]),
              80 - i
            );
          });
        } catch (e) {}
      });
    }

    /* --- the profile hero ------------------------------------------------ */
    if (UserCard && UserCard.prototype) {
      extend(UserCard.prototype, 'infoItems', function (items) {
        try {
          var user = this.attrs && this.attrs.user;
          var info = infoOf(user);
          if (!info) return;

          if (info.rank && info.rank.name) items.add('lmxRank', m('li.LmxCardInfo', rankChip(info)), 95);
          if (info.groups && info.groups.length) items.add('lmxGroups', m('li.LmxCardInfo', groupNodes(info)), 94);
          if (info.title) items.add('lmxTitle', m('li.LmxCardInfo', m('span.LmxAuthor-title', info.title)), 93);

          /*
           * The hero gets ONLY what the sidebar's Standing card cannot carry,
           * which on a profile page is nothing — both render from the same
           * statsNode, and side by side they said the same six numbers twice.
           *
           * Decided from the ROUTE, not the DOM: infoItems runs during view,
           * before this component has an element to inspect, so this.element is
           * undefined exactly when the question is asked.
           */
          var onProfile = false;
          try { onProfile = /^\/u\//.test((m.route.get() || '').split('?')[0]); } catch (e) { onProfile = false; }

          if (!onProfile) {
            items.add('lmxCounts', m('li.LmxCardInfo', statsNode(info, { wide: true })), 50);
            if (info.legacy) {
              var leg = legacyNode(info);
              if (leg) items.add('lmxLegacy', m('li.LmxCardInfo', leg), 40);
            }
          }
        } catch (e) {
          if (window.console) console.warn('[userinfo] card failed', e);
        }
      });
    }

    /* --- profile page: the rest of the identity surface ------------------- */
    if (UserPage && UserPage.prototype) {
      extend(UserPage.prototype, 'sidebarItems', function (items) {
        try {
          var user = this.user;
          if (!user) return;
          // Idempotent: loadSummary itself decides whether a request is
          // warranted. Guarding on the cache HERE is what produced the storm.
          loadSummary(user.id());
          var blocks = profileBlocks(user);
          if (blocks) items.add('lmxProfile', blocks, -10);
        } catch (e) {
          if (window.console) console.warn('[userinfo] profile failed', e);
        }
      });
    }

    /* --- the inbox entry in the header ----------------------------------- */
    if (CFG.dmEnabled && HeaderSecondary && HeaderSecondary.prototype) {
      extend(HeaderSecondary.prototype, 'items', function (items) {
        try {
          if (!me()) return;
          items.add('lmxInbox', inboxItem(), 18); // just under notifications
        } catch (e) {}
      });
    }

    wireCard();
    publishApi();
    watchNames();
    loadUnread();
  }

  /* ======================================================= name colouring */

  /*
   * Paint every username in the forum in its rank colour, including the ones
   * inside post bodies that no component renders.
   *
   * Done by decoration rather than by overriding `helpers/username`, because
   * that helper is a bare function with a `.default` hung off it — overriding
   * it writes a property nothing calls (measured by the icons lane).
   */
  function paintNames(root) {
    var links = (root || document).querySelectorAll('a[href*="/u/"]:not([data-lmx-painted])');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      a.setAttribute('data-lmx-painted', '1');
      var slug = userSlugFrom(a);
      if (!slug) continue;
      var u = findUserBySlug(slug);
      if (!u) continue;
      var color = nameColor(u);
      var cls = nameClass(u);
      var target = a.querySelector('.username') || a;
      // A custom property, not `color`. An inline colour is the one declaration
      // a stylesheet cannot adapt, and these hues come from the rank/name
      // catalogue, which is pale by design for the black scheme -- painted raw
      // they measured as low as 1.63:1 on the light scheme. less/forum.less
      // mixes --lmx-name-color toward --ink so the scheme picks the direction.
      if (color) target.style.setProperty('--lmx-name-color', color);
      if (cls) target.classList.add(cls);
      var info = infoOf(u);
      if (info && info.legacyStyleClass) a.setAttribute('data-legacy-style', info.legacyStyleClass);
    }
  }

  /* ====================================================== generated avatars */

  /*
   * The avatar an account gets when it has never uploaded one.
   *
   * Core draws a letter on a colour it generates itself from the username. That
   * colour is not from any palette — on this theme it produced a pale mint
   * circle with a black glyph on a near-black page.
   *
   * This repaints it with the SAME colour system the forum palette uses: one
   * OKLCH lightness and chroma for every account, hue from a hash of the
   * username. Deterministic, so an account looks the same on every surface; one
   * lightness, so ONE dark ink is legible on all of them.
   */
  function hashHue(s) {
    var h = 0x811c9dc5;
    for (var i = 0; i < s.length; i++) {
      h ^= s.charCodeAt(i);
      h = (h + (h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24)) >>> 0;
    }
    return h % 360;
  }

  function oklchHex(L, C, H) {
    var h = (H * Math.PI) / 180;
    var a = C * Math.cos(h);
    var b = C * Math.sin(h);
    var l = Math.pow(L + 0.3963377774 * a + 0.2158037573 * b, 3);
    var m2 = Math.pow(L - 0.1055613458 * a - 0.0638541728 * b, 3);
    var s2 = Math.pow(L - 0.0894841775 * a - 1.2914855480 * b, 3);
    var rgb = [
      4.0767416621 * l - 3.3077115913 * m2 + 0.2309699292 * s2,
      -1.2684380046 * l + 2.6097574011 * m2 - 0.3413193965 * s2,
      -0.0041960863 * l - 0.7034186147 * m2 + 1.7076147010 * s2,
    ];
    var out = '#';
    for (var i = 0; i < 3; i++) {
      var v = Math.max(0, Math.min(1, rgb[i]));
      v = v <= 0.0031308 ? 12.92 * v : 1.055 * Math.pow(v, 1 / 2.4) - 0.055;
      out += ('0' + Math.round(v * 255).toString(16)).slice(-2);
    }
    return out;
  }

  function identityFor(el) {
    var a = el.closest ? el.closest('a[href*="/u/"]') : null;
    if (a) {
      var mm = /\/u\/([^/?#]+)/.exec(a.getAttribute('href') || '');
      if (mm) return decodeURIComponent(mm[1]);
    }
    var text = el.getAttribute && (el.getAttribute('alt') || el.getAttribute('title'));
    if (text) return text;
    return (el.textContent || '?').trim();
  }

  function paintAvatars(root) {
    var els = (root || document).querySelectorAll('.Avatar:not([data-lmx-av])');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.tagName === 'IMG' || el.querySelector('img')) { el.setAttribute('data-lmx-av', 'img'); continue; }
      var text = (el.textContent || '').trim();
      if (!text) { el.setAttribute('data-lmx-av', 'empty'); continue; }

      var hue = hashHue(identityFor(el).toLowerCase());
      var base = oklchHex(0.8, 0.12, hue);
      var lift = oklchHex(0.9, 0.09, hue);

      el.setAttribute('data-lmx-av', '1');
      el.style.setProperty('--lmx-av', base);
      el.style.setProperty('--lmx-av-lift', lift);
      el.style.background = 'radial-gradient(115% 115% at 28% 18%, ' + lift + ' 0%, ' + base + ' 62%)';
      el.style.color = '#131820';
    }
  }

  function watchNames() {
    if (window.__lmxNameObserver) return;
    var pending = null;
    var obs = new MutationObserver(function () {
      clearTimeout(pending);
      pending = setTimeout(function () {
        try { paintNames(); } catch (e) {}
        try { paintAvatars(); } catch (e) {}
      }, 120);
    });
    obs.observe(document.body, { childList: true, subtree: true });
    window.__lmxNameObserver = obs;
    paintNames();
    paintAvatars();
  }

  /* ================================================================= boot */

  function bind() {
    if (BOUND) return true;

    var c = registry();
    var extendModule = c['extend'] || c['common/extend'];
    var appModule = c['app'] || c['forum/app'];

    if (!appModule || !extendModule || !window.m) return false;

    m = window.m;
    extend = extendModule.extend;
    app = appModule.default || appModule;

    reg.CommentPost = mod('components/CommentPost');
    reg.UserCard = mod('components/UserCard');
    reg.UserPage = mod('components/UserPage');
    reg.UserModel = mod('models/User');
    reg.HeaderSecondary = mod('components/HeaderSecondary');
    reg.avatar = mod('helpers/avatar');
    if (reg.avatar && reg.avatar.default) reg.avatar = reg.avatar.default;

    if (!reg.CommentPost) return false;

    BOUND = true;
    wire();

    // A diagnostic handle. The harness asserts on it so that "loaded but bound
    // nothing" is distinguishable from "never ran", which are completely
    // different causes and otherwise indistinguishable from outside the page.
    window.__lmxUserInfo = { bound: true, at: Date.now(), resolved: RESOLVED, config: CFG };

    forceRepaint();

    return true;
  }

  /*
   * ── The intermittent-blank-rail bug this exists to kill ─────────────────────
   *
   * `extend()` only affects renders that happen AFTER it runs, and Flarum's
   * Post component wraps its view in a SubtreeRetainer keyed on
   * `post.freshness` and `post.user().freshness`
   * (vendor/flarum/core/js/dist/forum.js — `SubtreeRetainer(() =>
   * this.attrs.post.freshness, () => { var t = n.attrs.post.user(); return t &&
   * t.freshness })`). So if this file binds even one frame after the stream has
   * painted, a plain m.redraw() is refused by every post that is already on
   * screen, and the rail never appears — for that page load only.
   *
   * It is a RACE, and it was reproduced: four consecutive loads of /d/25909
   * with the bind time measured from navigationStart —
   *
   *   bind at 723ms -> 0 rails,  a manual m.redraw() afterwards -> still 0
   *   bind at 511ms -> 20 rails
   *   bind at 455ms -> 20 rails
   *   bind at 711ms -> 20 rails
   *
   * Bumping `freshness` is the retainer's own invalidation signal, so this asks
   * for the re-render in the vocabulary the component already uses instead of
   * reaching into its internals.
   */
  function forceRepaint() {
    try {
      ['posts', 'users', 'discussions'].forEach(function (type) {
        app.store.all(type).forEach(function (model) { model.freshness = new Date(); });
      });
      m.redraw();
    } catch (e) {}
    // A second pass for anything the first render fetched.
    setTimeout(function () {
      try {
        app.store.all('posts').forEach(function (p) { p.freshness = new Date(); });
        m.redraw();
        paintNames();
      } catch (e) {}
    }, 400);
  }

  function attemptBind() {
    try {
      return bind();
    } catch (e) {
      window.__lmxUserInfo = { bound: false, error: String(e && e.message), resolved: RESOLVED };
      if (window.console) console.warn('[userinfo] bind failed', e);
      return true; // recorded; stop retrying
    }
  }

  /*
   * Bind at the earliest instant it is possible to bind.
   *
   * This script is in <head> and therefore runs BEFORE the core bundle, so the
   * registry does not exist yet. Polling on a 100ms interval — what this used
   * to do — means binding up to 100ms after the registry appears, which is what
   * put it on the wrong side of the first paint on slow loads.
   *
   * Instead: intercept the assignment. `window.flarum = {...}` is the first
   * thing the core bundle does, long before app.boot(), so a setter on that
   * property is a deterministic hook rather than a race. The interval and the
   * load events stay as a safety net for any path that does not assign it.
   */
  function armEarlyBind() {
    if (window.flarum) return;
    try {
      var held;
      Object.defineProperty(window, 'flarum', {
        configurable: true,
        enumerable: true,
        get: function () { return held; },
        set: function (v) {
          held = v;
          // Restore a plain data property so nothing downstream sees an
          // accessor where it expects an object.
          try {
            delete window.flarum;
            window.flarum = v;
          } catch (e) {}
          // The compat registry is populated by the time the bundle finishes;
          // a microtask is after that and still before app.boot() paints.
          Promise.resolve().then(attemptBind);
        },
      });
    } catch (e) {
      // A browser that refuses the redefinition falls back to the poller.
    }
  }

  armEarlyBind();

  if (!attemptBind()) {
    var tries = 0;
    var timer = setInterval(function () {
      if (attemptBind() || ++tries > 200) clearInterval(timer);
    }, 40);
    document.addEventListener('DOMContentLoaded', attemptBind);
    window.addEventListener('load', attemptBind);
  }
})();
