<?php

namespace Local\Index;

use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the front page server-side and hands it to the document as a template
 * plus a mounting script.
 *
 * ── Server-side, still ──────────────────────────────────────────────────────
 *
 * Everything on this page is data the server already has in one query each: the
 * six section tags and their latest threads, the news tag, trending, recent, the
 * tag tree, the board counts. Doing it in the SPA is several round trips and a
 * visible empty state on every load — on the one surface where a visitor decides
 * whether to stay.
 *
 * ── Injected, not an IndexPage override ─────────────────────────────────────
 *
 * Overriding IndexPage is what 88 extensions do and it silently clobbers
 * whichever of them loads second. This stays out of the boot path entirely: a
 * `<template>` in the head and a small script that clones it. A failure here
 * cannot take the SPA down.
 *
 * ── Why a <template> and not <script type="text/template"> ──────────────────
 *
 * The previous version used a script element, which is a live trap on this
 * project. Script data is raw text that ends at `</script`, and `<!--` inside it
 * switches the tokenizer into a state where any `</tag` closes the element. The
 * icon bundle hit exactly this and truncated the document mid-`<head>`,
 * discarding everything after it — including this template — while still
 * returning HTTP 200. `<template>` is parsed as ordinary markup, has no escaping
 * rule to get wrong, and hands the mount script real DOM nodes rather than a
 * string to re-parse. The one sequence that could still close it early
 * (`</template`) cannot appear in escaped output, and e2e/index/render.ts
 * asserts that as a gate rather than trusting it.
 *
 * ── Composition, not layout ────────────────────────────────────────────────
 *
 * This class knows four region names and nothing else. What goes in them, in
 * what order and on which side is the registry's business (see Rails), which is
 * what lets another lane add a leaderboard without editing this file.
 */
class RenderIndex
{
    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        try {
            $actor = $request->getAttribute('actor');
            $actor = $actor instanceof User ? $actor : new Guest();

            $ctx = new Context($this->db, $this->settings, $this->translator, $actor, $this->view($actor));
            $html = $this->compose($ctx);
        } catch (\Throwable $e) {
            // The front door renders even when a block is broken. Not defensive
            // programming for its own sake: this extension's predecessor took
            // the whole forum down twice by throwing during document rendering,
            // and a missing index is survivable where a 500 is not.
            return;
        }

        if ($html === '') {
            return;
        }

        $document->head[] = '<template id="lmx-index-template">' . $html . '</template>';
        $document->head[] = '<script data-lmx-index>' . $this->mountScript() . '</script>';
    }

    /**
     * The reader's persisted layout, resolved on the SERVER.
     *
     * Resolving it in JavaScript would make the first paint always `cards`, and
     * a reader who chose `list` would watch it change under them on every
     * navigation. Guests have no preference to read here, so they get the
     * configured default and the mount script upgrades them from localStorage
     * before anything is measured.
     */
    private function view(User $actor): string
    {
        $v = $actor->isGuest() ? '' : (string) ($actor->getPreference('lmxIndexView') ?: '');

        if ($v === '') {
            $v = (string) ($this->settings->get('looksmax-index.default_view') ?: 'cards');
        }

        return $v === 'list' ? 'list' : 'cards';
    }

    private function compose(Context $ctx): string
    {
        $regions = [
            Rails::SIDE_TOP => '',
            Rails::SIDE_LEFT => '',
            Rails::SIDE_MAIN => '',
            Rails::SIDE_RIGHT => '',
        ];

        foreach (Rails::all($ctx) as $block) {
            try {
                $html = $block->render($ctx);
            } catch (\Throwable $e) {
                continue; // one bad block, not a bad page
            }

            // Null means "I have nothing to say" and the block disappears. A
            // rail of five cards, two of which say nothing, reads as broken.
            if ($html === null || $html === '') {
                continue;
            }

            $regions[Rails::side($block, $ctx->settings)] .= $html;
        }

        if ($regions[Rails::SIDE_MAIN] === '' && $regions[Rails::SIDE_TOP] === '') {
            return '';
        }

        // Full-bleed wrapper -> constrained grid. The theme pins its container
        // to 1100px, which measured out at 1920 as 820px — 43% of the viewport —
        // of empty margin with no left rail at all. The breakout itself is in
        // the stylesheet; this is the element it applies to.
        // The left rail is OMITTED, not emptied, when nothing is placed in it.
        // An empty <aside> still occupies its 232px grid track, so disabling
        // every left block (which the operator did, to give "Por dónde empezar"
        // the room) would otherwise leave a dead column and a narrower main.
        // The marker class lets the stylesheet drop the track entirely.
        $hasLeft = $regions[Rails::SIDE_LEFT] !== '';

        return '<div class="LmxIndex" data-view="' . $ctx->view . '">'
            . '<div class="LmxIndex-inner' . ($hasLeft ? '' : ' has-no-left') . '">'
            . ($regions[Rails::SIDE_TOP] !== '' ? '<div class="LmxIndex-top">' . $regions[Rails::SIDE_TOP] . '</div>' : '')
            . ($hasLeft ? '<aside class="LmxIndex-left">' . $regions[Rails::SIDE_LEFT] . '</aside>' : '')
            . '<main class="LmxIndex-main" id="lmx-sections">' . $regions[Rails::SIDE_MAIN] . '</main>'
            . '<aside class="LmxIndex-side">' . $regions[Rails::SIDE_RIGHT] . '</aside>'
            . '</div></div>';
    }

    /**
     * Mount on the index route only, re-mount after Mithril redraws (which
     * replace subtrees wholesale), and own the page's five interactions: the
     * layout switch, the news dismissal and re-open, the onboarding dismissal,
     * and the browse-all disclosure.
     *
     * Polling is deliberate and unchanged from the previous version: hooking the
     * router would mean patching core, and this stays out of the boot path so a
     * failure here cannot take the SPA down.
     */
    private function mountScript(): string
    {
        return <<<'JS'
(function () {
  var LS_VIEW = 'lmxIndexView', LS_NEWS = 'lmxNewsSeen', LS_ONB = 'lmxOnboardingDone',
      LS_FEED = 'lmxFeedTab', LS_FEED_EXPANDED = 'lmxFeedExpanded';

  function app() {
    try { return window.flarum && window.flarum.core && window.flarum.core.app; } catch (e) { return null; }
  }
  function user() { var a = app(); return a && a.session && a.session.user; }
  function ls(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function setLs(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }

  /* Persist to the account when there is one, and to localStorage always.
     Both, for a member: the preference is the durable copy that follows them
     across devices, and localStorage is what makes the NEXT first paint correct
     before the session payload has been parsed. */
  function persist(key, value) {
    setLs(key, String(value));
    var u = user();
    if (u && u.savePreferences) {
      var p = {}; p[key] = value;
      try { u.savePreferences(p); } catch (e) {}
    }
  }

  function isIndex() {
    /* front page only. /all is the discussion list and previously got the
       index stacked on top of it. */
    var p = location.pathname;
    return p === '/' || p === '' || p === '/tags';
  }

  function applyView(root, v) {
    root.setAttribute('data-view', v);
    var s = root.querySelector('.LmxSections');
    if (s) s.setAttribute('data-view', v);
    var btns = root.querySelectorAll('[data-lmx-view]');
    for (var i = 0; i < btns.length; i++) {
      var on = btns[i].getAttribute('data-lmx-view') === v;
      btns[i].classList.toggle('is-on', on);
      btns[i].setAttribute('aria-pressed', on ? 'true' : 'false');
    }
  }

  function applyFeed(root, id) {
    var tabs = root.querySelectorAll('[data-lmx-feed]');
    var any = false;
    for (var i = 0; i < tabs.length; i++) {
      if (tabs[i].getAttribute('data-lmx-feed') === id) { any = true; break; }
    }
    if (!any) return;               /* a tab that was not rendered this time */
    for (var j = 0; j < tabs.length; j++) {
      var on = tabs[j].getAttribute('data-lmx-feed') === id;
      tabs[j].classList.toggle('is-on', on);
      tabs[j].setAttribute('aria-selected', on ? 'true' : 'false');
    }
    var panels = root.querySelectorAll('.LmxFeed-list');
    for (var k = 0; k < panels.length; k++) {
      panels[k].hidden = panels[k].getAttribute('data-feed') !== id;
    }
  }

  function applyFeedExpanded(root, expanded) {
    var feed = root.querySelector('.LmxFeed');
    if (!feed) return;
    feed.classList.toggle('is-expanded', !!expanded);
    var btn = feed.querySelector('[data-lmx-feed-collapse]');
    if (btn) btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  }

  function wire(root) {
    if (root.__lmxWired) return;
    root.__lmxWired = true;

    var storedFeed = ls(LS_FEED);
    if (storedFeed) applyFeed(root, storedFeed);

    /* The feed is compact by default; a reader who chose to expand it keeps
       that across navigations. Only 'true' expands — an absent/again-compacted
       value leaves the tighter default in place. */
    if (ls(LS_FEED_EXPANDED) === 'true') applyFeedExpanded(root, true);

    var stored = ls(LS_VIEW);
    if (stored === 'cards' || stored === 'list') applyView(root, stored);

    if (ls(LS_ONB) === 'true') {
      var onb = root.querySelector('[data-block="onboarding"]');
      if (onb) onb.parentNode.removeChild(onb);
    }
    var seen = parseInt(ls(LS_NEWS) || '0', 10);
    var news0 = root.querySelector('.LmxNews');
    if (news0 && seen && seen >= (parseInt(news0.getAttribute('data-newest'), 10) || 0)) {
      news0.className += ' is-collapsed';
    }

    /* Arrow keys move between tabs and activate, which is the tablist pattern a
       screen reader user expects. Without it six buttons are six tab stops. */
    root.addEventListener('keydown', function (e) {
      var t = e.target.closest && e.target.closest('[data-lmx-feed]');
      if (!t) return;
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft' && e.key !== 'Home' && e.key !== 'End') return;
      var tabs = [].slice.call(root.querySelectorAll('[data-lmx-feed]'));
      var i = tabs.indexOf(t);
      var n = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : tabs.length - 1;
      if (n < 0) n = tabs.length - 1;
      if (n >= tabs.length) n = 0;
      e.preventDefault();
      tabs[n].focus();
      applyFeed(root, tabs[n].getAttribute('data-lmx-feed'));
      persist(LS_FEED, tabs[n].getAttribute('data-lmx-feed'));
    });

    root.addEventListener('click', function (e) {
      var c = e.target.closest && e.target.closest('[data-lmx-feed-collapse]');
      if (c) {
        var feed = root.querySelector('.LmxFeed');
        var nowExpanded = !(feed && feed.classList.contains('is-expanded'));
        applyFeedExpanded(root, nowExpanded);
        persist(LS_FEED_EXPANDED, nowExpanded ? 'true' : 'false');
        return;
      }

      var t = e.target.closest && e.target.closest('[data-lmx-view]');
      if (t) {
        var v = t.getAttribute('data-lmx-view');
        applyView(root, v);
        persist(LS_VIEW, v);
        return;
      }

      var d = e.target.closest('[data-lmx-news-dismiss]');
      if (d) {
        var news = root.querySelector('.LmxNews');
        if (news) news.classList.add('is-collapsed');
        persist(LS_NEWS, parseInt(d.getAttribute('data-lmx-news-dismiss'), 10) || 0);
        return;
      }

      if (e.target.closest('[data-lmx-news-open]')) {
        var n2 = root.querySelector('.LmxNews');
        if (n2) n2.classList.remove('is-collapsed');
        persist(LS_NEWS, 0);
        return;
      }

      var o = e.target.closest('[data-lmx-dismiss]');
      if (o) {
        var card = o.closest('.LmxCard');
        if (card && card.parentNode) card.parentNode.removeChild(card);
        persist(LS_ONB, true);
        return;
      }

      /* The feed's tab strip. All six panels are already in the DOM (see
         FeedBlock), so switching is a hidden attribute and an aria flag — no
         request, no spinner, no layout shift, and it works with the keyboard
         because the tabs are real buttons in a real tablist. The choice is
         persisted the same way the section layout is, so a reader who lives on
         "Sin responder" lands there next time. */
      var ft = e.target.closest('[data-lmx-feed]');
      if (ft) {
        applyFeed(root, ft.getAttribute('data-lmx-feed'));
        persist(LS_FEED, ft.getAttribute('data-lmx-feed'));
        return;
      }

      var br = e.target.closest('[data-lmx-browse]');
      if (br) {
        var body = br.parentNode.querySelector('.LmxBrowse-body');
        var open = br.getAttribute('aria-expanded') === 'true';
        br.setAttribute('aria-expanded', open ? 'false' : 'true');
        if (body) body.hidden = open;
        return;
      }

      /* The two hero buttons open Flarum's own surfaces. Going through the app
         is the only way they carry its state; clicking the header's own control
         is the fallback if the compat path ever moves. */
      if (e.target.closest('[data-lmx-signup]')) {
        var a = app();
        try {
          var SignUp = window.flarum.core.compat['forum/components/SignUpModal'];
          if (a && a.modal && SignUp) { a.modal.show(SignUp); return; }
        } catch (err) {}
        var btn = document.querySelector('.item-signUp button, .item-logIn button');
        if (btn) btn.click();
        return;
      }
      if (e.target.closest('[data-lmx-compose]')) {
        var a2 = app();
        try {
          var Composer = window.flarum.core.compat['forum/components/DiscussionComposer'];
          if (a2 && a2.composer && Composer) {
            a2.composer.load(Composer, { user: a2.session.user });
            a2.composer.show();
            return;
          }
        } catch (err) {}
        location.href = '/all';
      }
    });
  }

  var TPL = null;
  function node() {
    if (TPL === null) {
      var el = document.getElementById('lmx-index-template');
      TPL = (el && el.content) ? el.content : false;
    }
    if (!TPL) return null;
    var frag = TPL.cloneNode(true);
    return frag.firstElementChild;
  }

  function mount() {
    var host = document.querySelector('.IndexPage-results, .TagsPage-content, .IndexPage');
    if (!host) return;
    var existing = document.querySelector('.LmxIndex');
    if (!isIndex()) {
      if (existing) existing.parentNode.removeChild(existing);
      document.body.classList.remove('lmx-index-on');
      return;
    }
    if (existing && existing.isConnected) return;
    var n = node();
    if (!n) return;
    host.parentNode.insertBefore(n, host);
    document.body.classList.add('lmx-index-on');
    wire(n);
  }

  function tick() { try { mount(); } catch (e) {} }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tick);
  else tick();
  setInterval(tick, 700);
  window.addEventListener('popstate', tick);
})();
JS;
    }
}
