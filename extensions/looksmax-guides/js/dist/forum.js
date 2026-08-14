/*
 * Guides — frontend layer.
 *
 * Hand-authored against the runtime module registry (flarum.core.compat)
 * rather than built through webpack, because the app container has no node
 * toolchain. Flarum exposes every core module this way at runtime, so this is
 * a supported integration path, just an unusual one.
 *
 * ── Rule 1: zero override() calls. ──────────────────────────────────────────
 * The ecosystem sweep (Babel-exact, 12,051 files) counts 90 method-level
 * override conflicts where two or more extensions fully replace the same
 * Target.method and silently clobber each other. The ones this feature would
 * otherwise walk straight into are the worst on the list:
 *
 *   DiscussionPage.view   overridden by 9        Post.contentHtml  by 10
 *   DiscussionList.view   overridden by 6        IndexPage.hero    by 32
 *   DiscussionPage.render / .positionChanged / DiscussionPageResolver.onmatch
 *     — 3 each, and all three are the table-of-contents extension family,
 *       i.e. precisely the feature a long-form guide needs.
 *
 * So everything here is either an append to a named ItemList (the designed,
 * additive surface) or DOM decoration of markup the server already rendered.
 *
 * ── Rule 2: bind late, and never assume the bundle survived. ────────────────
 * This file ships as its own <script> element, not inside forum.js. Measured
 * on this install: flarum/markdown's s9e preview module calls
 * `new XSLTProcessor` at bundle top level, and every extension bundle
 * concatenated after it dies when that throws. A separate element has its own
 * error boundary. See src/Listeners/InjectScript.php for the full measurement.
 *
 * Because that element loads after app.boot(), extend() alone is too late for
 * the first paint — hooks only affect subsequent renders. So binding is
 * followed by a forced redraw and an independent DOM sweep.
 */
(function () {
  'use strict';

  /* ============================================================ resolved refs */

  var BOUND = false;
  var m = null;
  var extend = null;
  var DiscussionListItem = null;
  var CommentPost = null;
  var DiscussionPage = null;

  function registry() {
    return (window.flarum && window.flarum.core && window.flarum.core.compat) || {};
  }

  function forumApp() {
    return registry()['forum/app'];
  }

  /* ================================================================== model */

  var TIER_COLOR = {
    0: '#6b7583', 1: '#9aa4b2', 2: '#7aa2f7',
    3: '#e8c07d', 4: '#9ece6a', 5: '#b5e08a', 9: '#f7768e',
  };
  var TIER_LABEL = {
    0: 'Anecdote', 1: 'Community', 2: 'Mechanism',
    3: 'Observational', 4: 'Trial', 5: 'Guideline', 9: 'Marketing',
  };
  var TIER_SHORT = { 0: 'T0', 1: 'T1', 2: 'T2', 3: 'T3', 4: 'T4', 5: 'T5', 9: 'TX' };

  /* The serializer sends `guide` only for guides, so its presence is the test. */
  function guideOf(discussion) {
    if (!discussion) return null;
    try {
      var g = discussion.attribute('guide');
      return g && typeof g === 'object' ? g : null;
    } catch (e) {
      return null;
    }
  }

  function isGuide(discussion) {
    if (!discussion) return false;
    try {
      return !!discussion.attribute('isGuide');
    } catch (e) {
      return false;
    }
  }

  function profileEntries(profile) {
    if (!profile) return [];
    var out = [];
    Object.keys(profile).forEach(function (k) {
      var n = Number(profile[k]) || 0;
      if (n > 0) out.push({ tier: Number(k), count: n });
    });
    // Ascending tier, so the bar reads weakest-to-strongest left to right —
    // the same direction as the legend below it.
    out.sort(function (a, b) { return a.tier - b.tier; });
    return out;
  }

  function relTime(iso) {
    if (!iso) return null;
    var then = new Date(String(iso).replace(' ', 'T')).getTime();
    if (isNaN(then)) return null;
    // "hace 3 meses", not "3 months ago". Intl.RelativeTimeFormat picks both
    // the unit and the wording for the forum's locale; the ladder below is the
    // fallback for an install without looksmax-i18n.
    if (window.lmxI18n) return window.lmxI18n.rel(then);
    var days = Math.floor((Date.now() - then) / 86400000);
    if (days < 1) return 'today';
    if (days < 30) return days + ' day' + (days === 1 ? '' : 's') + ' ago';
    var months = Math.round(days / 30.44);
    if (months < 24) return months + ' month' + (months === 1 ? '' : 's') + ' ago';
    return Math.round(months / 12) + ' years ago';
  }

  /* ========================================================== the evidence bar */

  /*
   * Rendered as a proportional bar and never as a number.
   *
   * A single displayed integer is a target, and on a board with an existing
   * reciprocal-reaction culture the target would immediately become "put T4 on
   * everything". A bar is only convincing if the claims behind it actually
   * exist and survive being read, which is much more expensive to fake.
   */
  function evidenceBar(profile, opts) {
    var entries = profileEntries(profile);
    if (!entries.length) return null;

    var total = entries.reduce(function (s, e) { return s + e.count; }, 0);
    opts = opts || {};

    var children = [
      m('.GuideEvidence-bar', entries.map(function (e) {
        return m('span.GuideEvidence-seg', {
          'data-tier': String(e.tier),
          style: { flexGrow: String(e.count) },
          title: flarum.core.app.translator.trans('local-looksmax-guides.forum.evidence.seg_title', { label: (function (n) { return n ? flarum.core.app.translator.trans('local-looksmax-guides.forum.tier.' + n.toLowerCase()) : ''; })(TIER_LABEL[e.tier]), count: e.count, total: total }),
        });
      })),
    ];

    if (opts.legend) {
      children.push(
        m('.GuideEvidence-legend', entries.map(function (e) {
          return m('span.GuideEvidence-key', [
            m('i', { style: { background: TIER_COLOR[e.tier] || '#6b7583' } }),
            TIER_SHORT[e.tier] + ' ' + (function (n) { return n ? flarum.core.app.translator.trans('local-looksmax-guides.forum.tier.' + n.toLowerCase()) : ''; })(TIER_LABEL[e.tier]) + ' · ' + e.count,
          ]);
        }))
      );
    }

    return m('.GuideEvidence', children);
  }

  function freshnessTag(guide) {
    var A = flarum.core.app.translator;
    var K = 'local-looksmax-guides.forum.freshness.';
    var seen = relTime(guide.reviewedAt);
    var label = {
      fresh: A.trans(K + 'reviewed', { when: seen || A.trans(K + 'recently') }),
      due: A.trans(K + 'due'),
      stale: A.trans(K + 'stale', { when: seen || A.trans(K + 'long_time') }),
      unknown: A.trans(K + 'never'),
    }[guide.freshness] || A.trans(K + 'never');

    return m('span.GuideFresh.GuideFresh--' + (guide.freshness || 'unknown'), [
      m('span.GuideFresh-dot'),
      label,
    ]);
  }

  function buildHeader(guide) {
    var bits = [];

    bits.push(m('.GuideHeader-top', [
      m('span.GuideHeader-badge', flarum.core.app.translator.trans('local-looksmax-guides.forum.badge.guide')),
      m('span.GuideHeader-meta', [
        flarum.core.app.translator.trans('local-looksmax-guides.forum.header.read_time', { minutes: guide.readMinutes }),
        guide.sectionCount ? ' · ' + flarum.core.app.translator.trans('local-looksmax-guides.forum.header.sections', { n: guide.sectionCount, count: guide.sectionCount }) : '',
        guide.version > 1 ? ' · ' + flarum.core.app.translator.trans('local-looksmax-guides.forum.header.version', { version: guide.version }) : '',
        // wordCount.toLocaleString() carried NO locale argument, so it followed
        // the browser: "12,431" on an en-US browser reading a Spanish page.
        guide.wordCount ? ' · ' + flarum.core.app.translator.trans('local-looksmax-guides.forum.header.words', { n: guide.wordCount, count: window.lmxI18n ? window.lmxI18n.num(guide.wordCount) : String(guide.wordCount) }) : '',
      ].join('')),
      freshnessTag(guide),
    ]));

    if (guide.claimCount > 0) {
      bits.push(evidenceBar(guide.evidenceProfile, { legend: true }));
      // Sourcing shown as a ratio, not a volume: "12 of 14 claims sourced"
      // cannot be inflated by adding more unsourced claims, which a bare count
      // of sourced claims could be.
      bits.push(m('.GuideHeader-meta', { style: { marginTop: '6px' } },
        flarum.core.app.translator.trans('local-looksmax-guides.forum.header.sourced', { n: guide.claimCount, sourced: guide.sourcedCount, total: guide.claimCount })));
    }

    return m('.GuideHeader', bits);
  }

  function buildStaleBanner(guide) {
    if (guide.freshness !== 'stale' && guide.freshness !== 'due') return null;

    var due = guide.freshness === 'due';
    var when = relTime(guide.reviewedAt);

    return m('.GuideStaleBanner' + (due ? '.GuideStaleBanner--due' : ''), [
      m('strong', due
        ? flarum.core.app.translator.trans('local-looksmax-guides.forum.banner.due_title')
        : flarum.core.app.translator.trans('local-looksmax-guides.forum.banner.stale_title')),
      m('span', due
        ? flarum.core.app.translator.trans('local-looksmax-guides.forum.banner.due_body', { when: when || flarum.core.app.translator.trans('local-looksmax-guides.forum.banner.some_time_ago') })
        : flarum.core.app.translator.trans('local-looksmax-guides.forum.banner.stale_body', { when: when || flarum.core.app.translator.trans('local-looksmax-guides.forum.banner.since_written') })),
    ]);
  }

  /* ============================================================== decoration */

  /*
   * Operates on markup the server already produced. If any of it throws, the
   * guide still reads: the structure, the claims, the tiers, the spec sheet
   * and the regimen table are all server-rendered HTML that needs no JS.
   */
  function decorate(el, discussion) {
    var guide = guideOf(discussion);
    if (!guide || !el) return false;

    var body = el.querySelector('.Post-body') ||
      (el.classList && el.classList.contains('Post-body') ? el : null);
    if (!body || body.getAttribute('data-guide-done') === '1') return false;
    body.setAttribute('data-guide-done', '1');

    var post = el.closest ? (el.closest('.CommentPost') || el) : el;
    post.classList.add('GuidePost');

    // --- header + banner, prepended above the document ---------------------
    var header = document.createElement('div');
    m.render(header, [buildStaleBanner(guide), buildHeader(guide)]);
    body.insertBefore(header, body.firstChild);

    // --- heading anchors ---------------------------------------------------
    // The server already computed the anchor slugs and stored them in
    // guide_meta.toc, in document order, unicode-safe and de-duplicated. They
    // are applied here by position rather than recomputed in the browser, so
    // the table of contents, the URL fragment and the stored anchor can never
    // disagree — and a Cyrillic or Turkish heading gets the same anchor the
    // server indexed, which a naive browser-side slugify would not produce.
    var toc = Array.isArray(guide.toc) ? guide.toc : [];
    var headings = body.querySelectorAll('h1, h2, h3, h4');

    for (var i = 0; i < headings.length; i++) {
      var h = headings[i];
      var entry = toc[i];
      if (!entry || !entry.anchor) continue;

      h.id = entry.anchor;

      var a = document.createElement('a');
      a.className = 'GuideAnchor';
      a.href = '#' + entry.anchor;
      a.setAttribute('aria-label', flarum.core.app.translator.trans('local-looksmax-guides.forum.anchor_label'));
      a.textContent = '§';
      h.insertBefore(a, h.firstChild);
    }

    // --- step checkboxes ---------------------------------------------------
    // A protocol you are three weeks into is useless if it forgets where you
    // are on every reload. One key per guide, not one per checkbox, so a
    // 40-step guide is a single storage entry.
    var storeKey = 'lmx.guide.steps.' + discussion.id();
    var done = {};
    try { done = JSON.parse(localStorage.getItem(storeKey) || '{}') || {}; } catch (e) { done = {}; }

    var steps = body.querySelectorAll('.GuideStep');
    for (var s = 0; s < steps.length; s++) {
      (function (step, index) {
        var box = step.querySelector('.GuideStep-check');
        if (!box) return;

        if (done[index]) {
          step.classList.add('is-done');
          box.setAttribute('aria-checked', 'true');
        }

        var toggle = function () {
          var nowDone = step.classList.toggle('is-done');
          box.setAttribute('aria-checked', nowDone ? 'true' : 'false');
          done[index] = nowDone;
          try { localStorage.setItem(storeKey, JSON.stringify(done)); } catch (e) {}
        };

        box.addEventListener('click', toggle);
        box.addEventListener('keydown', function (ev) {
          if (ev.key === ' ' || ev.key === 'Enter') { ev.preventDefault(); toggle(); }
        });
      })(steps[s], s);
    }

    installProgress(body);

    return true;
  }

  /* ============================================================= progress bar */

  var progressEl = null;
  var progressHandler = null;

  function installProgress(body) {
    removeProgress();

    progressEl = document.createElement('div');
    progressEl.className = 'GuideProgress';
    progressEl.style.width = '0%';
    document.body.appendChild(progressEl);

    var raf = 0;
    progressHandler = function () {
      if (raf) return;
      raf = window.requestAnimationFrame(function () {
        raf = 0;
        if (!progressEl || !body.isConnected) return removeProgress();

        var rect = body.getBoundingClientRect();
        var total = rect.height - window.innerHeight;
        var pct = total <= 0 ? 100 : Math.min(100, Math.max(0, (-rect.top / total) * 100));
        progressEl.style.width = pct.toFixed(1) + '%';

        highlightToc(body);
      });
    };

    window.addEventListener('scroll', progressHandler, { passive: true });
    progressHandler();
  }

  function removeProgress() {
    if (progressHandler) {
      window.removeEventListener('scroll', progressHandler);
      progressHandler = null;
    }
    if (progressEl && progressEl.parentNode) progressEl.parentNode.removeChild(progressEl);
    progressEl = null;
  }

  function highlightToc(body) {
    var links = document.querySelectorAll('.GuideToc-link');
    if (!links.length) return;

    var headings = body.querySelectorAll('h1[id], h2[id], h3[id], h4[id]');
    var currentId = null;

    for (var i = 0; i < headings.length; i++) {
      // 90px clears the sticky header; the last heading scrolled past it is
      // the section the reader is actually in.
      if (headings[i].getBoundingClientRect().top <= 90) currentId = headings[i].id;
      else break;
    }

    for (var j = 0; j < links.length; j++) {
      links[j].classList.toggle('is-current', links[j].getAttribute('href') === '#' + currentId);
    }
  }

  /* =================================================================== hooks */

  function wire() {
    if (DiscussionListItem && DiscussionListItem.prototype) {
      extend(DiscussionListItem.prototype, 'infoItems', function (items) {
        var discussion = this.attrs && this.attrs.discussion;
        if (!isGuide(discussion)) return;

        var guide = guideOf(discussion);
        var stale = guide && guide.freshness === 'stale';

        // High priority so the guide marker leads the info row — it is the
        // strongest signal on the row and the reason to click it.
        items.add(
          'guide',
          m('span.DiscussionListItem-guideBadge' + (stale ? '.DiscussionListItem-guideBadge--stale' : ''), {
            title: guide
              ? flarum.core.app.translator.trans('local-looksmax-guides.forum.list.badge_title', { minutes: guide.readMinutes, n: guide.claimCount, claims: guide.claimCount, sourced: guide.sourcedCount })
              : flarum.core.app.translator.trans('local-looksmax-guides.forum.badge.guide'),
          }, stale
            ? flarum.core.app.translator.trans('local-looksmax-guides.forum.badge.guide_stale')
            : flarum.core.app.translator.trans('local-looksmax-guides.forum.badge.guide')),
          100
        );

        // A four-pixel evidence bar on the row. Cheap, and it is the whole
        // difference between "some guide" and "a guide with trial-level
        // sourcing" before the reader spends a click on it.
        if (guide && guide.claimCount > 0) {
          var entries = profileEntries(guide.evidenceProfile);
          if (entries.length) {
            items.add(
              'guideEvidence',
              m('span.DiscussionListItem-guideMini', {
                title: entries.map(function (e) { return TIER_SHORT[e.tier] + ' x' + e.count; }).join('  '),
              }, entries.map(function (e) {
                return m('i', {
                  style: { flexGrow: String(e.count), background: TIER_COLOR[e.tier] || '#6b7583' },
                });
              })),
              99
            );
          }
        }
      });
    }

    if (CommentPost && CommentPost.prototype) {
      var run = function (vnode) {
        try {
          var post = this.attrs && this.attrs.post;
          if (!post || post.number() !== 1) return;

          var discussion = post.discussion();
          if (!isGuide(discussion)) return;

          decorate(vnode.dom, discussion);
        } catch (e) {
          // Decoration is additive. A failure here must never take the post
          // with it, because the post IS the guide.
          if (window.console) console.warn('[guides] decorate failed', e);
        }
      };

      extend(CommentPost.prototype, 'oncreate', run);
      extend(CommentPost.prototype, 'onupdate', run);
    }

    if (DiscussionPage && DiscussionPage.prototype) {
      extend(DiscussionPage.prototype, 'sidebarItems', function (items) {
        var discussion = this.discussion;
        if (!isGuide(discussion)) return;

        var guide = guideOf(discussion);
        var toc = guide && Array.isArray(guide.toc) ? guide.toc : [];
        if (toc.length < 2) return;

        items.add(
          'guideToc',
          m('nav.GuideToc', { 'aria-label': flarum.core.app.translator.trans('local-looksmax-guides.forum.toc.aria') }, [
            m('.GuideToc-title', flarum.core.app.translator.trans('local-looksmax-guides.forum.toc.title')),
            toc.map(function (entry) {
              return m('a.GuideToc-link', {
                href: '#' + entry.anchor,
                'data-level': String(entry.level || 2),
                onclick: function (e) {
                  // Native anchor navigation inside a Mithril SPA rewrites the
                  // route and unmounts the post stream. Scrolling manually
                  // keeps the stream's position state intact.
                  var target = document.getElementById(entry.anchor);
                  if (!target) return;
                  e.preventDefault();
                  target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                  if (window.history && history.replaceState) {
                    history.replaceState(null, '', '#' + entry.anchor);
                  }
                },
              }, entry.text);
            }),
          ]),
          50
        );
      });

      extend(DiscussionPage.prototype, 'onremove', function () {
        removeProgress();
      });
    }
  }

  /* ==================================================================== boot */

  /**
   * Decorate an already-rendered guide without waiting for a redraw.
   *
   * Reads the discussion off the router's current page rather than off a
   * component, because at bind time there is no component instance to ask.
   */
  function sweep() {
    try {
      var app = forumApp();
      if (!app || !app.current || !app.current.get) return false;

      var discussion = app.current.get('discussion');
      if (!isGuide(discussion)) return false;

      var el = document.querySelector('.PostStream .CommentPost') ||
        document.querySelector('.CommentPost') ||
        document.querySelector('.Post-body');

      return el ? decorate(el, discussion) : false;
    } catch (e) {
      if (window.console) console.warn('[guides] sweep failed', e);
      return true; // recorded; stop retrying
    }
  }

  function bind() {
    if (BOUND) return true;

    var compat = registry();
    var extendModule = compat['common/extend'];

    if (!compat['forum/app'] || !extendModule || !window.m) return false;

    m = window.m;
    extend = extendModule.extend;
    DiscussionListItem = compat['forum/components/DiscussionListItem'];
    CommentPost = compat['forum/components/CommentPost'];
    DiscussionPage = compat['forum/components/DiscussionPage'];

    BOUND = true;
    wire();

    // A diagnostic handle. Verification asserts on this so that "loaded but
    // bound nothing" is distinguishable from "never ran at all" — two
    // completely different causes, and this is the cheapest way to tell them
    // apart from outside the page.
    window.__lmxGuides = { bound: true, at: Date.now() };

    // extend() only affects renders that happen after it. This script loads
    // after app.boot() has already mounted, so on a cold load of a guide
    // CommentPost.oncreate has already fired and the document would sit
    // undecorated until a redraw that may never come.
    try { m.redraw(); } catch (e) {}

    // app.current is populated by the router, which resolves after boot()
    // returns. A single sweep here fires too early on a cold load and finds
    // no discussion, so sweep on a short decaying schedule and stop as soon
    // as one succeeds. Bounded at ~3s: if the page has not produced a guide
    // post by then it is not going to.
    var attempts = 0;
    var sweeper = setInterval(function () {
      if (sweep() || ++attempts > 15) clearInterval(sweeper);
    }, 200);
    sweep();

    return true;
  }

  function attemptBind() {
    try {
      return bind();
    } catch (e) {
      window.__lmxGuides = { bound: false, error: String(e && e.message) };
      if (window.console) console.warn('[guides] bind failed', e);
      return true; // stop retrying; the error is recorded for the harness
    }
  }

  if (!attemptBind()) {
    var tries = 0;
    var timer = setInterval(function () {
      if (attemptBind() || ++tries > 60) clearInterval(timer);
    }, 100);
    document.addEventListener('DOMContentLoaded', attemptBind);
    window.addEventListener('load', attemptBind);
  }
})();
