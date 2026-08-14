/*
 * Reactions, hand-authored against the runtime module registry.
 *
 * WHY THERE IS NO BUILD STEP: the app container has no node toolchain, and
 * Flarum exposes every core module on flarum.core.compat at runtime, so this
 * is a supported (if unusual) integration path and the one the other twelve
 * extensions on this install already use. Edit this file, reload, done.
 *
 * WHY IT IS INJECTED AS ITS OWN <script> (see src/InjectScript.php): Flarum
 * concatenates every extension's forum.js into ONE bundle and runs it through
 * bootExtensions. A single top-level throw there stops every extension after
 * it and a wrong export shape blanks the SPA. Both have happened on this
 * install. This file is parsed and fails alone.
 *
 * TWO FACTS ABOUT THE REGISTRY THAT ARE MEASURED, NOT ASSUMED:
 *
 *   1. Keys sometimes carry a 'forum/' prefix and sometimes do not, depending
 *      on how the bundle was built. mod() tries both. A wrong key is
 *      `undefined`, extend() then silently no-ops, and NOTHING is logged --
 *      you get a page with no reactions and a clean console. Hence
 *      window.__lmxReactions.resolved, which the e2e harness asserts on.
 *
 *   2. compat['helpers/x'] is the FUNCTION with .default hung off it, not the
 *      module namespace object consumers hold, so override(mod,'default',fn)
 *      writes a property nothing calls. Only extend() onto class prototypes
 *      and named ItemLists is used below.
 *
 * This script loads BEFORE app.boot() has painted, so binding ends with an
 * m.redraw().
 */
(function () {
  'use strict';

  var RESOLVED = {};
  var BOUND = false;

  /**
   * Same-origin API base.
   *
   * app.forum.attribute('apiUrl') is absolute and pinned to whatever
   * config.php's `url` says. When the browser is on a different origin -- a
   * tunnel, 127.0.0.1, a staging host -- every request from it is cross-origin
   * and Flarum surfaces the failure as "Something went wrong during a
   * cross-origin request", which looks like a reaction bug and is not one.
   * Flarum always mounts its API at /api on the same host, so build from there.
   */
  function api(path) {
    return '/api' + path;
  }

  function registry() {
    return (window.flarum && window.flarum.core && window.flarum.core.compat) || {};
  }

  function mod(name) {
    var c = registry();
    var found = c[name] !== undefined ? c[name] : c['forum/' + name];
    RESOLVED[name] = found === undefined ? null : (c[name] !== undefined ? name : 'forum/' + name);
    if (found && found.default && !found.prototype && !found.extend) return found.default;
    return found;
  }

  // ---------------------------------------------------------------- catalogue

  var CAT = [];
  var BY_ID = {};
  var BY_SLUG = {};
  var STRIP_PX = 24;
  var MAX_PER_POST = 6;

  function loadCatalogue(app) {
    var list = app.forum.attribute('lmxReactions') || [];
    CAT = list;
    BY_ID = {};
    BY_SLUG = {};
    for (var i = 0; i < list.length; i++) {
      BY_ID[list[i].id] = list[i];
      BY_SLUG[list[i].slug] = list[i];
    }
    STRIP_PX = app.forum.attribute('lmxReactionsStripSize') || 24;
    MAX_PER_POST = app.forum.attribute('lmxReactionsMaxPerPost') || 6;
    return list.length;
  }

  /** Ladder sizes shipped by bin/process-assets.py. Keep in sync with Catalog. */
  var LADDER = [24, 48, 96, 256];

  function nearest(px) {
    for (var i = 0; i < LADDER.length; i++) if (LADDER[i] >= px) return LADDER[i];
    return LADDER[LADDER.length - 1];
  }

  /**
   * <picture> with avif -> webp -> png, and a density srcset inside each.
   *
   * The formats are measured, not assumed: across the 13 faces at four sizes
   * the same pixels cost 1,274,409 B as PNG, 342,728 B as WebP and 198,620 B
   * as AVIF, so the order is worth the extra two elements. PNG stays as the
   * final fallback because it costs nothing to list and the whole ladder is
   * under a megabyte anyway.
   */
  function icon(m, r, px) {
    if (!r) return null;
    var attrs = {
      width: px, height: px, alt: r.display, draggable: false,
      loading: 'lazy', decoding: 'async',
      style: { width: px + 'px', height: px + 'px', objectFit: 'contain' }
    };

    if (r.type === 'svg' || (r.urls && r.urls.svg)) {
      attrs.src = r.urls.svg;
      return m('img.LmxRx-icon', attrs);
    }

    var one = nearest(px), two = nearest(px * 2), four = nearest(px * 4);
    function set(fmt) {
      var u = r.urls[fmt];
      if (!u) return null;
      var parts = [u[one] + ' 1x'];
      if (two !== one) parts.push(u[two] + ' 2x');
      if (four !== two) parts.push(u[four] + ' 4x');
      return parts.join(', ');
    }

    attrs.src = (r.urls.png && r.urls.png[one]) || '';
    var srcAvif = set('avif'), srcWebp = set('webp'), srcPng = set('png');
    if (srcPng) attrs.srcset = srcPng;

    return m('picture.LmxRx-pic', [
      srcAvif ? m('source', { type: 'image/avif', srcset: srcAvif }) : null,
      srcWebp ? m('source', { type: 'image/webp', srcset: srcWebp }) : null,
      m('img.LmxRx-icon', attrs)
    ]);
  }

  // ------------------------------------------------------------------ helpers

  function rx(post) {
    return post.attribute('reactions') || { counts: {}, mine: [], legacy: {}, legacyScore: 0 };
  }

  function mineHas(state, id) {
    var mine = state.mine || [];
    for (var i = 0; i < mine.length; i++) if (mine[i] === id) return true;
    return false;
  }

  function entries(obj) {
    var out = [];
    if (!obj) return out;
    for (var k in obj) if (Object.prototype.hasOwnProperty.call(obj, k)) out.push([k, obj[k]]);
    return out;
  }

  /**
   * A copy of the icon that flies up and fades, spawned at the click point.
   * Feedback before the request resolves; entirely decorative, pointer-events
   * none, and it removes itself so no node ever leaks.
   */
  function fly(el, r) {
    if (!el || !r || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var box = el.getBoundingClientRect();
    var node = document.createElement('img');
    node.className = 'LmxRx-fly';
    node.src = (r.urls && (r.urls.svg || (r.urls.png && r.urls.png[48]))) || '';
    node.style.left = (box.left + box.width / 2 - 14) + 'px';
    node.style.top = (box.top - 4) + 'px';
    node.style.width = '28px';
    node.style.height = '28px';
    document.body.appendChild(node);
    node.addEventListener('animationend', function () {
      if (node.parentNode) node.parentNode.removeChild(node);
    });
  }

  // ------------------------------------------------------------ body-mounted overlay
  //
  // Flarum's modal manager is reached through a Modal SUBCLASS, which this file
  // cannot construct without importing the base class and matching its
  // lifecycle. A plain node mounted on document.body is fewer moving parts, is
  // immune to the transformed ancestors in the post stream creating a new
  // containing block for position:fixed, and cannot interfere with a Flarum
  // modal that is already open.

  var overlayHost = null;
  var overlayState = { open: false, view: null };

  function ensureHost(m) {
    if (overlayHost) return;
    overlayHost = document.createElement('div');
    overlayHost.className = 'LmxRxOverlayHost';
    document.body.appendChild(overlayHost);
    m.mount(overlayHost, {
      view: function () {
        if (!overlayState.open || !overlayState.view) return null;
        return m('.LmxRxOverlay', {
          style: {
            position: 'fixed', inset: 0, zIndex: 1050, display: 'flex',
            alignItems: 'center', justifyContent: 'center',
            background: 'var(--scrim, rgba(5,7,11,.72))', padding: '20px'
          },
          onclick: function (e) { if (e.target === e.currentTarget) closeOverlay(); }
        }, m('.LmxRxOverlay-panel', {
          style: {
            maxWidth: '620px', width: '100%', maxHeight: '80vh', overflow: 'auto',
            background: 'var(--surface-0, #0b0f16)', border: '1px solid var(--line, #2f3b4c)',
            borderRadius: 'var(--r-lg, 12px)', padding: '18px',
            boxShadow: 'var(--e-4, 0 24px 64px rgba(0,0,0,.6))'
          }
        }, overlayState.view()));
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && overlayState.open) closeOverlay();
    });
  }

  var Mref = null;
  function openOverlay(view) {
    overlayState.open = true;
    overlayState.view = view;
    if (Mref) Mref.redraw();
  }
  function closeOverlay() {
    overlayState.open = false;
    overlayState.view = null;
    if (Mref) Mref.redraw();
  }

  // ------------------------------------------------------------------ picker

  function Picker(m, app) {
    return {
      oninit: function (v) {
        v.state.q = '';
        v.state.cursor = null;
      },
      oncreate: function (v) {
        var input = v.dom.querySelector('.LmxRx-search');
        if (input && window.innerWidth > 767) input.focus();
      },
      view: function (v) {
        var post = v.attrs.post;
        var state = rx(post);
        var q = (v.state.q || '').toLowerCase();

        var groups = [
          ['chrigger', app.translator.trans('local-looksmax-reactions.forum.picker.group_chrigger')],
          ['classic', app.translator.trans('local-looksmax-reactions.forum.picker.group_classic')],
          ['extra', app.translator.trans('local-looksmax-reactions.forum.picker.group_extra')]
        ];

        var sections = [];
        for (var g = 0; g < groups.length; g++) {
          var key = groups[g][0];
          var items = CAT.filter(function (r) {
            if (r.group !== key) return false;
            if (!q) return true;
            return r.slug.indexOf(q) !== -1 || r.display.toLowerCase().indexOf(q) !== -1;
          });
          if (!items.length) continue;

          sections.push(m('.LmxRx-groupLabel', groups[g][1]));
          sections.push(m('.LmxRx-grid', items.map(function (r) {
            return m('button.LmxRx-option', {
              key: r.slug,
              type: 'button',
              title: r.display,
              'aria-label': r.display,
              'data-slug': r.slug,
              className: (mineHas(state, r.id) ? 'is-mine' : '')
                + (v.state.cursor === r.slug ? ' is-cursor' : ''),
              style: { '--rx': r.tint || 'var(--ink-faint)' },
              onmouseenter: function () { v.state.cursor = r.slug; },
              onclick: function (e) {
                e.preventDefault();
                e.stopPropagation();
                v.attrs.onpick(r, e.currentTarget);
              }
            }, icon(m, r, 30));
          })));
        }

        if (!sections.length) sections.push(m('.LmxRx-groupLabel', app.translator.trans('local-looksmax-reactions.forum.picker.no_match', { query: v.state.q })));

        var hovered = v.state.cursor ? BY_SLUG[v.state.cursor] : null;

        return m('.LmxRx-picker' + (v.attrs.flip ? '.LmxRx-picker--below' : ''), {
          onclick: function (e) { e.stopPropagation(); }
        }, [
          m('input.LmxRx-search', {
            type: 'text',
            placeholder: app.translator.trans('local-looksmax-reactions.forum.picker.search_placeholder', { count: CAT.length }),
            value: v.state.q,
            oninput: function (e) { v.state.q = e.target.value; }
          }),
          sections,
          m('.LmxRx-preview', hovered ? [
            icon(m, hovered, 26),
            m('span', hovered.display),
            m('span.LmxRx-previewHint',
              mineHas(state, hovered.id)
                ? app.translator.trans('local-looksmax-reactions.forum.picker.hint_remove')
                : app.translator.trans('local-looksmax-reactions.forum.picker.hint_react'))
          ] : m('span.LmxRx-previewHint', app.translator.trans('local-looksmax-reactions.forum.picker.limit_hint', { max: window.lmxI18n.num(MAX_PER_POST) })))
        ]);
      }
    };
  }

  // -------------------------------------------------------------- who reacted

  function reactorsView(m, app, post, payload) {
    if (!payload) return m('div', app.translator.trans('local-looksmax-reactions.forum.loading'));

    var blocks = (payload.groups || []).map(function (g) {
      var r = BY_ID[g.reactionId];
      return m('.LmxRxModal-group', { style: { '--rx': (r && r.tint) || 'var(--ink-faint)' } }, [
        m('.LmxRxModal-groupHead', [
          icon(m, r, 22),
          m('span', (r && r.display) || g.slug),
          m('span.LmxRxModal-groupCount', window.lmxI18n.num(g.count))
        ]),
        m('.LmxRxModal-users', g.users.map(function (u) {
          // app.route.user() calls .slug() on a User MODEL. These are plain
          // objects from our own endpoint, so it throws
          // "e.slug is not a function" from inside core and takes the whole
          // modal's render with it. /u/<username> is the same URL core builds.
          return m('a.LmxRxModal-user', { href: '/u/' + encodeURIComponent(u.username) }, [
            u.avatarUrl ? m('img', { src: u.avatarUrl, alt: '' }) : null,
            m('span.username', u.username)
          ]);
        }))
      ]);
    });

    var legacy = payload.legacy || {};
    var legacyTypes = (legacy.types || []).filter(function (t) { return !!t.slug; });
    if (legacyTypes.length || legacy.score) {
      blocks.push(m('.LmxRxModal-legacy', [
        m('div', [
          m('strong', [app.translator.trans('local-looksmax-reactions.forum.legacy_note'), ' — ']),
          legacy.score
            ? m('span', app.translator.trans('local-looksmax-reactions.forum.legacy.total_count', { count: legacy.score }))
            : null
        ]),
        legacyTypes.length ? m('div', { style: { marginTop: '6px' } }, legacyTypes.map(function (t) {
          var r = BY_SLUG[t.slug];
          return m('span', { style: { marginRight: '12px', display: 'inline-flex', alignItems: 'center', gap: '5px' } }, [
            icon(m, r, 18),
            m('span', (r && r.display) || t.slug),
            m('span', { style: { opacity: 0.65 } },
              t.count === null
                ? ['(', app.translator.trans('local-looksmax-reactions.forum.legacy_unknown'), ')']
                : window.lmxI18n.num(t.count))
          ]);
        })) : null,
        legacy.summary
          ? m('div', { style: { marginTop: '8px', fontStyle: 'italic' } }, legacy.summary)
          : null,
        m('div', { style: { marginTop: '8px', opacity: 0.7 } },
          app.translator.trans('local-looksmax-reactions.forum.legacy.explainer'))
      ]));
    }

    if (!blocks.length) return m('div', app.translator.trans('local-looksmax-reactions.forum.reactors.empty'));

    return m('div', [
      m('h3', { style: { margin: '0 0 14px', font: '700 16px/1 var(--font-ui)', color: 'var(--ink)' } },
        app.translator.trans('local-looksmax-reactions.forum.who_reacted')),
      m('.LmxRxModal-groups', blocks)
    ]);
  }

  function showReactors(m, app, post) {
    var payload = null;
    ensureHost(m);
    openOverlay(function () { return reactorsView(m, app, post, payload); });
    app.request({
      method: 'GET',
      url: api('/lmx/posts/' + post.id() + '/reactors')
    }).then(function (res) {
      payload = res;
      m.redraw();
    }).catch(function (e) {
      payload = { groups: [], legacy: {} };
      console.warn('reactions: reactors failed', e);
      m.redraw();
    });
  }

  // ------------------------------------------------------------------- strip

  // Per-post UI state, keyed explicitly rather than left to vnode.state.
  //
  // The strip is one component object reused for every post on the page, and
  // Mithril's POJO-component state semantics made "is this picker open" a
  // question with a different answer depending on which of twenty instances
  // asked. Clicking the add button reliably ran the handler and reliably did
  // not open the picker. An explicit map keyed on the post id has no such
  // ambiguity and survives the component being re-created by a redraw.
  /**
   * Invalidate one post's SubtreeRetainer.
   *
   * THE most important three lines in this file. Flarum's Post component holds
   * a SubtreeRetainer and its onbeforeupdate is `return
   * this.subtree.needsRebuild()`. Nothing this extension does changes anything
   * the retainer watches, so m.redraw() runs, Mithril walks the tree, and
   * SKIPS the entire post — the strip renders exactly once and then never
   * again. Measured: clicking the picker button ran the handler (a counter in
   * it reached 1) and flipped the state, and the DOM still had
   * aria-expanded="false" and no picker, with no error anywhere.
   *
   * `freshness` is the field the retainer watches and the handle core itself
   * uses for this. Every local state change has to be followed by it.
   */
  function touch(post) {
    try { post.freshness = new Date(); } catch (e) {}
  }

  var UI = {};
  function ui(post) {
    var k = String(post.id());
    return UI[k] || (UI[k] = { open: false, busy: {}, error: null, flip: false, justAdded: null });
  }

  function Strip(m, app) {
    return {
      oncreate: function (v) {
        // One delegated outside-click listener per strip instance, removed in
        // onremove. fof/reactions binds a fresh global $(document).click per
        // post and calls $('.Reactions').unbind() in every oncreate, so an
        // N-post page rebinds N times and leaks N handlers. Not copied.
        var st = ui(v.attrs.post);
        v.state.away = function (e) {
          if (st.open && v.dom && !v.dom.contains(e.target)) {
            st.open = false;
            touch(v.attrs.post);
            m.redraw();
          }
        };
        // Bubble phase, not capture. In capture the listener runs BEFORE the
        // button's own onclick, so on the very click that opens the picker it
        // sees open===false and does nothing — but on the next redraw cycle the
        // ordering made the open/close race observable. Bubble phase plus the
        // stopPropagation on the button means "outside" really means outside.
        document.addEventListener('click', v.state.away, false);
      },
      onremove: function (v) {
        document.removeEventListener('click', v.state.away, false);
      },

      view: function (v) {
        var post = v.attrs.post;
        var st = ui(post);
        var state = rx(post);
        var canReact = post.attribute('canReact');
        var counts = entries(state.counts);
        var legacy = entries(state.legacy);

        var self = this;

        function toggle(r, el) {
          if (st.busy[r.slug]) return;
          st.busy[r.slug] = true;
          st.error = null;

          var adding = !mineHas(rx(post), r.id);
          if (adding) fly(el, r);

          app.request({
            method: 'POST',
            url: api('/lmx/posts/' + post.id() + '/react'),
            body: { slug: r.slug }
          }).then(function (res) {
            // The server returns the post's whole reaction state, freshly read,
            // so the client never computes a count. A lost or duplicated
            // request cannot drift the UI away from the table.
            post.pushAttributes({ reactions: res.reactions });
            st.busy[r.slug] = false;
            st.justAdded = res.added ? r.slug : null;
            st.open = false;
            touch(post);
            m.redraw();
            setTimeout(function () { st.justAdded = null; touch(post); m.redraw(); }, 400);
          }).catch(function (e) {
            st.busy[r.slug] = false;
            touch(post);
            var body = (e && e.response) || {};
            st.error = body.error === 'rate-limited'
              ? app.translator.trans('local-looksmax-reactions.forum.rate_limited')
              : body.error === 'too-many-on-post'
                ? app.translator.trans('local-looksmax-reactions.forum.too_many', { max: body.max })
                : app.translator.trans('local-looksmax-reactions.forum.error.save_failed');
            m.redraw();
            console.warn('reactions: react failed', e);
          });
        }

        var chips = counts
          .filter(function (kv) { return kv[1] > 0 && BY_ID[kv[0]]; })
          .sort(function (a, b) { return BY_ID[a[0]].position - BY_ID[b[0]].position; })
          .map(function (kv) {
            var r = BY_ID[kv[0]];
            var mine = mineHas(state, r.id);
            return m('button.LmxRx-chip', {
              key: 'n' + r.slug,
              type: 'button',
              className: (mine ? 'is-mine' : '') + (st.justAdded === r.slug ? ' just-added' : ''),
              style: { '--rx': r.tint || 'var(--ink-faint)' },
              title: mine
                ? app.translator.trans('local-looksmax-reactions.forum.chip.title_mine', { name: r.display })
                : r.display,
              'aria-pressed': mine ? 'true' : 'false',
              'data-slug': r.slug,
              disabled: !canReact,
              onclick: function (e) { e.preventDefault(); if (canReact) toggle(r, e.currentTarget); },
              oncontextmenu: function (e) { e.preventDefault(); showReactors(m, app, post); }
            }, [icon(m, r, STRIP_PX), m('span.LmxRx-chip-count', window.lmxI18n.num(kv[1]))]);
          });

        // Imported reactions, rendered as history: dashed, not clickable, and
        // showing a number only where one was actually recorded.
        var legacyChips = legacy
          .filter(function (kv) { return BY_ID[kv[0]]; })
          .sort(function (a, b) { return BY_ID[a[0]].position - BY_ID[b[0]].position; })
          .map(function (kv) {
            var r = BY_ID[kv[0]];
            var n = kv[1];
            return m('span.LmxRx-chip.LmxRx-chip--legacy', {
              key: 'l' + r.slug,
              style: { '--rx': r.tint || 'var(--ink-faint)' },
              title: n === null
                ? app.translator.trans('local-looksmax-reactions.forum.chip.title_legacy_unknown', { name: r.display })
                : app.translator.trans('local-looksmax-reactions.forum.chip.title_legacy', { name: r.display }),
              onclick: function () { showReactors(m, app, post); }
            }, [
              icon(m, r, STRIP_PX),
              n === null
                ? m('span.LmxRx-chip-count.LmxRx-chip-count--unknown', '·')
                : m('span.LmxRx-chip-count', window.lmxI18n.num(n))
            ]);
          });

        var total = state.legacyScore || 0;
        var totalChip = total
          ? m('span.LmxRx-legacyTotal', {
              title: state.legacySummary
                ? app.translator.trans('local-looksmax-reactions.forum.legacy.total_title_with', { summary: state.legacySummary })
                : app.translator.trans('local-looksmax-reactions.forum.legacy.total_title'),
              onclick: function () { showReactors(m, app, post); }
            }, [m('span', '★'), m('span', window.lmxI18n.num(total))])
          : null;

        var addButton = canReact
          ? m('.LmxRx-pickerWrap', [
              m('button.LmxRx-add', {
                type: 'button',
                'aria-label': app.translator.trans('local-looksmax-reactions.forum.add'),
                'aria-expanded': st.open ? 'true' : 'false',
                className: st.open ? 'is-open' : '',
                onclick: function (e) {
                  window.__lmxRxClicks = (window.__lmxRxClicks || 0) + 1;
                  window.__lmxRxLast = 'add:' + post.id();
                  e.preventDefault();
                  e.stopPropagation();
                  touch(post);
                  // Flip below when there is not room above, so the picker is
                  // never opened off the top of the viewport.
                  var box = e.currentTarget.getBoundingClientRect();
                  st.flip = box.top < 360;
                  st.open = !st.open;
                }
              }, m.trust(
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" '
                + 'stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">'
                + '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5s1.2 1.6 3.5 1.6 3.5-1.6 3.5-1.6"/>'
                + '<path d="M9 9.5h.01M15 9.5h.01"/></svg>'
              )),
              st.open
                ? m(Picker(m, app), {
                    post: post,
                    flip: st.flip,
                    onpick: function (r, el) { toggle(r, el); }
                  })
                : null
            ])
          : null;

        var anything = chips.length || legacyChips.length || totalChip || addButton;
        if (!anything) return null;

        // data-post-id is here for the e2e harness. Flarum's <article> id is
        // not reliably parseable to a post id on this install (it came back as
        // 0), and a test that guesses which post it is testing proves nothing.
        return m('.LmxRx', { 'data-post-id': post.id(), 'data-mine': (state.mine || []).length }, [
          chips,
          legacyChips,
          totalChip,
          addButton,
          (chips.length || legacyChips.length)
            ? m('button.LmxRx-add', {
                type: 'button',
                title: app.translator.trans('local-looksmax-reactions.forum.who_reacted'),
                'aria-label': app.translator.trans('local-looksmax-reactions.forum.who_reacted'),
                style: { borderStyle: 'solid' },
                onclick: function (e) { e.preventDefault(); showReactors(m, app, post); }
              }, m.trust(
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
                + 'stroke-width="2" stroke-linecap="round" aria-hidden="true">'
                + '<path d="M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19"/>'
                + '<circle cx="10" cy="8" r="3.2"/><path d="M20 19v-1.4a3.5 3.5 0 0 0-2.6-3.4"/>'
                + '<path d="M15.5 5.2a3.2 3.2 0 0 1 0 5.6"/></svg>'
              ))
            : null,
          st.error
            ? m('span', { style: { color: 'var(--danger)', font: '600 11px/1 var(--font-ui)' } },
                st.error)
            : null
        ]);
      }
    };
  }

  // ------------------------------------------------------- thread reactions

  function threadPanel(m, app, discussion, payload) {
    if (!payload) return m('div', app.translator.trans('local-looksmax-reactions.forum.loading'));

    var totals = payload.totals || {};
    var rows = payload.posts || [];

    return m('div', [
      m('h3', { style: { margin: '0 0 12px', font: '700 16px/1 var(--font-ui)', color: 'var(--ink)' } },
        app.translator.trans('local-looksmax-reactions.forum.thread.title')),
      m('.LmxRx-filterBar', [
        m('span.LmxRx-filterLabel', app.translator.trans('local-looksmax-reactions.forum.thread.filter_label')),
        m('button.LmxRx-chip', {
          className: payload._slug ? '' : 'is-mine',
          onclick: function () { loadThread(m, app, discussion, null); }
        }, app.translator.trans('local-looksmax-reactions.forum.thread.filter_all')),
        CAT.filter(function (r) {
          return totals[r.slug] || (payload.legacyTotals && payload.legacyTotals[r.slug]);
        }).map(function (r) {
          var native = totals[r.slug] || 0;
          var leg = (payload.legacyTotals && payload.legacyTotals[r.slug]) || null;
          return m('button.LmxRx-chip', {
            key: r.slug,
            className: payload._slug === r.slug ? 'is-mine' : '',
            style: { '--rx': r.tint },
            title: leg
              ? app.translator.trans('local-looksmax-reactions.forum.thread.chip_title_legacy', { name: r.display, count: window.lmxI18n.num(native), legacy: leg.posts })
              : app.translator.trans('local-looksmax-reactions.forum.thread.chip_title', { name: r.display, count: window.lmxI18n.num(native) }),
            onclick: function () { loadThread(m, app, discussion, r.slug); }
          }, [icon(m, r, 20), m('span.LmxRx-chip-count', window.lmxI18n.num(native + (leg ? leg.posts : 0)))]);
        })
      ]),
      rows.length
        ? m('ol', { style: { margin: 0, paddingLeft: '20px', color: 'var(--ink-dim)' } },
            rows.slice(0, 40).map(function (p) {
              return m('li', { style: { margin: '5px 0' } }, [
                m('a', {
                  href: app.route.discussion(discussion, p.number),
                  style: { color: 'var(--accent-2)' },
                  onclick: function () { closeOverlay(); }
                }, app.translator.trans('local-looksmax-reactions.forum.post_ref', { number: window.lmxI18n.num(p.number) })),
                m('span', { style: { marginLeft: '8px', opacity: 0.75 } },
                  p.legacy
                    ? app.translator.trans('local-looksmax-reactions.forum.thread.row_both', { count: window.lmxI18n.num(p.native), legacy: window.lmxI18n.num(p.legacy) })
                    : app.translator.trans('local-looksmax-reactions.forum.thread.row_native', { count: window.lmxI18n.num(p.native) }))
              ]);
            }))
        : m('div', { style: { opacity: 0.7 } }, app.translator.trans('local-looksmax-reactions.forum.thread.empty'))
    ]);
  }

  function loadThread(m, app, discussion, slug) {
    var payload = null;
    ensureHost(m);
    openOverlay(function () { return threadPanel(m, app, discussion, payload); });
    app.request({
      method: 'GET',
      url: api('/lmx/discussions/' + discussion.id() + '/reactions'
        + (slug ? '?slug=' + encodeURIComponent(slug) : ''))
    }).then(function (res) {
      res._slug = slug;
      payload = res;
      m.redraw();
    }).catch(function (e) {
      console.warn('reactions: thread panel failed', e);
      payload = { totals: {}, posts: [] };
      m.redraw();
    });
  }

  // --------------------------------------------------------- leaderboard

  function leaderboardView(m, app, st) {
    var tabs = [
      ['received', app.translator.trans('local-looksmax-reactions.forum.board.tab_received')],
      ['given', app.translator.trans('local-looksmax-reactions.forum.board.tab_given')],
      ['posts', app.translator.trans('local-looksmax-reactions.forum.board.tab_posts')]
    ];
    var periods = [
      ['day', app.translator.trans('local-looksmax-reactions.forum.board.period_day')],
      ['week', app.translator.trans('local-looksmax-reactions.forum.board.period_week')],
      ['month', app.translator.trans('local-looksmax-reactions.forum.board.period_month')],
      ['all', app.translator.trans('local-looksmax-reactions.forum.board.period_all')]
    ];

    return m('div', [
      m('h3', { style: { margin: '0 0 12px', font: '700 16px/1 var(--font-ui)', color: 'var(--ink)' } },
        app.translator.trans('local-looksmax-reactions.forum.leaderboard')),
      m('.LmxRx-filterBar', [
        tabs.map(function (t) {
          return m('button.LmxRx-chip', {
            key: t[0],
            className: st.kind === t[0] ? 'is-mine' : '',
            onclick: function () { st.kind = t[0]; st.load(); }
          }, t[1]);
        })
      ]),
      m('.LmxRx-filterBar', [
        periods.map(function (p) {
          return m('button.LmxRx-chip', {
            key: p[0],
            className: st.period === p[0] ? 'is-mine' : '',
            onclick: function () { st.period = p[0]; st.load(); }
          }, p[1]);
        })
      ]),
      !st.data ? m('div', app.translator.trans('local-looksmax-reactions.forum.loading')) : m('ol', {
        style: { margin: 0, paddingLeft: '22px', color: 'var(--ink-dim)' }
      }, (st.data.rows || []).map(function (r) {
        return m('li', { style: { margin: '6px 0' } }, st.kind === 'posts'
          ? [
              m('a', { href: r.url, style: { color: 'var(--accent-2)' },
                onclick: function () { closeOverlay(); } },
                r.title || app.translator.trans('local-looksmax-reactions.forum.post_ref', { number: window.lmxI18n.num(r.postId) })),
              m('span', { style: { marginLeft: '8px', opacity: 0.75 } },
                r.legacy
                  ? app.translator.trans('local-looksmax-reactions.forum.board.post_total_split', { total: window.lmxI18n.num(r.total), native: window.lmxI18n.num(r.native), legacy: window.lmxI18n.num(r.legacy) })
                  : window.lmxI18n.num(r.total))
            ]
          : [
              m('a', { href: '/u/' + encodeURIComponent(r.username),
                style: { color: 'var(--ink)', fontWeight: 600 } }, r.username),
              m('span', { style: { marginLeft: '8px', opacity: 0.75 } },
                app.translator.trans('local-looksmax-reactions.forum.board.user_stats', { count: r.total, total: window.lmxI18n.num(r.total), points: window.lmxI18n.num(r.points) }))
            ]);
      })),
      st.data && !st.data.includesLegacy && st.period === 'all'
        ? m('div', { style: { marginTop: '12px', opacity: 0.65, fontSize: '12px' } },
            app.translator.trans('local-looksmax-reactions.forum.board.all_time_note'))
        : null
    ]);
  }

  function showLeaderboard(m, app) {
    var st = { kind: 'received', period: 'week', data: null };
    st.load = function () {
      st.data = null;
      m.redraw();
      app.request({
        method: 'GET',
        url: api('/lmx/reactions/leaderboard?kind='
          + st.kind + '&period=' + st.period)
      }).then(function (res) { st.data = res; m.redraw(); })
        .catch(function (e) {
          console.warn('reactions: leaderboard failed', e);
          st.data = { rows: [] };
          m.redraw();
        });
    };
    ensureHost(m);
    openOverlay(function () { return leaderboardView(m, app, st); });
    st.load();
  }

  // -------------------------------------------------------------------- bind

  function bind() {
    if (BOUND) return true;

    var c = registry();
    var extend = c['extend'] || c['common/extend'];
    var app = c['app'] || c['forum/app'];
    var m = window.m;
    if (extend && extend.extend) extend = extend.extend;
    if (app && app.default) app = app.default;

    // CommentPost, NOT Post -- and this is measured, not preference.
    // Flarum 1.8's CommentPost declares its OWN footerItems (hasOwnProperty is
    // true on both prototypes) and does not call super, so an extender on
    // Post.prototype is shadowed for exactly the posts that have a footer.
    // Verified in the live page:
    //     Post.prototype.footerItems.call(...).toArray().length        -> 1
    //     CommentPost.prototype.footerItems.call(...).toArray().length -> 0
    // The symptom is an empty <footer class="Post-footer"></footer> with no
    // error anywhere, which is why it is asserted on in e2e/reactions.ts.
    var CommentPost = mod('components/CommentPost');
    var Post = mod('components/Post');
    var Target = (CommentPost && CommentPost.prototype) ? CommentPost : Post;
    var DiscussionPage = mod('components/DiscussionPage');
    var IndexPage = mod('components/IndexPage');

    if (!extend || !app || !m || !Target || !Target.prototype) return false;
    if (!app.forum) return false;

    Mref = m;
    var n = loadCatalogue(app);
    if (!n) {
      // The catalogue rides on the forum payload. Zero entries means the
      // serializer never ran, which is a server-side failure, not a timing
      // one — retrying forever would hide it.
      window.__lmxReactions = { bound: false, reason: 'empty catalogue', resolved: RESOLVED };
      console.warn('reactions: forum payload carried no catalogue');
      return true;
    }

    var StripComponent = Strip(m, app);

    extend(Target.prototype, 'footerItems', function (items) {
      try {
        var post = this.attrs.post;
        if (!post || post.isHidden() || !post.attribute) return;
        if (post.contentType && post.contentType() !== 'comment') return;
        items.add('lmxReactions', m(StripComponent, { post: post }), 100);
      } catch (e) {
        console.warn('reactions: footerItems', e);
      }
    });

    if (DiscussionPage && DiscussionPage.prototype) {
      extend(DiscussionPage.prototype, 'sidebarItems', function (items) {
        try {
          var d = this.discussion;
          if (!d) return;
          items.add('lmxReactions', m('button.Button.Button--link', {
            style: { display: 'block', width: '100%', textAlign: 'left' },
            onclick: function () { loadThread(m, app, d, null); }
          }, app.translator.trans('local-looksmax-reactions.forum.thread.open')), -5);
        } catch (e) {
          console.warn('reactions: sidebarItems', e);
        }
      });
    }

    if (IndexPage && IndexPage.prototype) {
      extend(IndexPage.prototype, 'sidebarItems', function (items) {
        try {
          items.add('lmxReactionBoard', m('button.Button.Button--link', {
            style: { display: 'block', width: '100%', textAlign: 'left' },
            onclick: function () { showLeaderboard(m, app); }
          }, app.translator.trans('local-looksmax-reactions.forum.leaderboard')), -10);
        } catch (e) {
          console.warn('reactions: index sidebarItems', e);
        }
      });
    }

    // Notification rendering. Without this the alert list shows a blank row
    // for our type rather than nothing, which reads as a bug.
    try {
      if (app.notificationComponents) {
        app.notificationComponents.lmxPostReacted = {
          view: function (v) {
            var n = v.attrs.notification;
            var slug = (n.content() || {}).reactionSlug;
            var r = BY_SLUG[slug];
            return m('a.Notification', {
              href: app.route.post(n.subject()),
              style: { display: 'flex', alignItems: 'center', gap: '8px', padding: '8px 12px' }
            }, [
              icon(m, r, 22),
              m('span', app.translator.trans(
                r && r.display
                  ? 'local-looksmax-reactions.forum.notification_text_with_reaction'
                  : 'local-looksmax-reactions.forum.notification_text',
                {
                  username: m('strong', n.fromUser() ? n.fromUser().username() : app.translator.trans('local-looksmax-reactions.forum.notification.someone')),
                  reaction: (r && r.display) || ''
                }
              ))
            ]);
          }
        };
      }
    } catch (e) {
      console.warn('reactions: notification component', e);
    }

    BOUND = true;
    window.__lmxReactions = {
      bound: true,
      at: Date.now(),
      catalogue: n,
      stripPx: STRIP_PX,
      target: (CommentPost && CommentPost.prototype) ? 'CommentPost' : 'Post',
      resolved: RESOLVED,
      slugs: CAT.map(function (r) { return r.slug; })
    };

    // A redraw alone is NOT enough here, and this cost an hour.
    //
    // Flarum's Post component holds a SubtreeRetainer and its onbeforeupdate is
    // `return this.subtree.needsRebuild()`. Nothing this extender does changes
    // anything the retainer watches, so after a late bind Mithril SKIPS every
    // mounted post and the footer stays exactly as it first rendered:
    //
    //     <footer class="Post-footer"></footer>
    //
    // with no <ul> inside it, no console error, and footerItems() returning our
    // item perfectly well when called by hand. The extender was fine; the posts
    // were simply never asked to render again.
    //
    // `post.freshness` is the field the retainer watches and the same handle
    // core itself uses to force a post to redraw. Bumping it makes needsRebuild
    // true exactly once, for posts that were painted before we bound.
    try {
      var posts = app.store.all('posts');
      for (var pi = 0; pi < posts.length; pi++) posts[pi].freshness = new Date();
    } catch (e) {
      console.warn('reactions: could not invalidate post subtrees', e);
    }
    m.redraw();
    return true;
  }

  // Poll tightly at first, then back off. The window between "the core bundle
  // has evaluated, so flarum.core.compat and window.m exist" and "the inline
  // boot script has painted the first frame" is a few milliseconds; binding
  // inside it means the very first render already carries the strip and the
  // freshness bump above is a no-op. A flat 100ms poll misses that window most
  // of the time, which is what made this look like a broken extender.
  var tries = 0;
  (function attempt() {
    var ok = false;
    try {
      ok = bind();
    } catch (e) {
      console.warn('reactions: bind threw', e);
      window.__lmxReactions = { bound: false, error: String(e), resolved: RESOLVED };
      return;
    }
    if (ok) return;
    if (++tries > 400) {
      window.__lmxReactions = { bound: false, reason: 'gave up after ' + tries, resolved: RESOLVED };
      console.warn('reactions: never resolved the module registry', RESOLVED);
      return;
    }
    setTimeout(attempt, tries < 200 ? 0 : 100);
  })();
})();
