/*
 * Iconify for Flarum, by DOM substitution.
 *
 * Why not the helper override: flarum.core.compat['helpers/icon'] is the icon
 * FUNCTION with a .default property hung off it, not the module namespace
 * object consumers hold. Verified in-page. So override(mod,'default',fn) writes
 * a property nothing calls and every icon still renders as FontAwesome. A real
 * webpack build could import the same module object; the container has no node.
 *
 * So this works one level down, where the result is unambiguous: watch the DOM
 * and replace <i class="fas fa-x"> with <iconify-icon icon="set:name">. It does
 * not touch the boot path, so it cannot take the SPA down.
 */
(function () {
  'use strict';

  // Several sets on purpose. Phosphor for UI furniture (legible at 14px),
  // Lucide where its geometry reads cleaner, Material Symbols and Solar for
  // concepts the others draw poorly, Simple Icons for brands.
  var MAP = {
    'fa-thumbtack': 'ph:push-pin-fill', 'fa-lock': 'ph:lock-key-fill',
    'fa-unlock': 'ph:lock-key-open-bold', 'fa-check': 'ph:check-circle-fill',
    'fa-check-circle': 'ph:seal-check-fill', 'fa-star': 'ph:star-fill',
    'fa-eye': 'ph:eye-fill', 'fa-eye-slash': 'ph:eye-closed-bold',
    'fa-reply': 'ph:arrow-bend-up-left-bold', 'fa-trash': 'ph:trash-fill',
    'fa-trash-alt': 'ph:trash-fill', 'fa-pencil-alt': 'ph:pencil-simple-fill',
    'fa-edit': 'ph:pencil-simple-line-bold', 'fa-flag': 'ph:flag-pennant-fill',
    'fa-bell': 'ph:bell-ringing-fill', 'fa-bell-slash': 'ph:bell-slash-bold',
    'fa-search': 'ph:magnifying-glass-bold', 'fa-plus': 'ph:plus-bold',
    'fa-minus': 'ph:minus-bold', 'fa-times': 'ph:x-bold',
    'fa-ellipsis-h': 'ph:dots-three-bold', 'fa-ellipsis-v': 'ph:dots-three-vertical-bold',
    // Found by measurement, not by reading core: e2e/icon-probe.ts reports
    // every <i class="fa-*"> still on the page after the swap. These 13 were
    // rendering as blank boxes on the index, /all, /tags, a profile and a
    // discussion. Re-run that probe after touching this map.
    'fa-angle-double-down': 'lucide:chevrons-down', 'fa-angle-double-up': 'lucide:chevrons-up',
    'fa-arrow-down': 'lucide:arrow-down', 'fa-at': 'ph:at-bold',
    'fa-bars': 'ph:list-bold', 'fa-caret-down': 'ph:caret-down-fill',
    'fa-comment-dots': 'ph:chat-teardrop-dots-fill', 'fa-expand': 'ph:arrows-out-bold',
    'fa-paper-plane': 'ph:paper-plane-tilt-fill', 'fa-sort': 'ph:arrows-down-up-bold',
    'fa-sync': 'ph:arrows-clockwise-bold', 'fa-th-large': 'ph:squares-four-fill',
    'fa-xmark': 'ph:x-bold',
    'fa-chevron-down': 'lucide:chevron-down', 'fa-chevron-up': 'lucide:chevron-up',
    'fa-chevron-left': 'lucide:chevron-left', 'fa-chevron-right': 'lucide:chevron-right',
    'fa-angle-double-right': 'lucide:chevrons-right', 'fa-arrow-left': 'lucide:arrow-left',
    'fa-arrow-right': 'lucide:arrow-right', 'fa-external-link-alt': 'lucide:external-link',
    'fa-user': 'ph:user-fill', 'fa-users': 'ph:users-three-fill',
    'fa-user-circle': 'ph:user-circle-fill', 'fa-user-plus': 'ph:user-plus-fill',
    'fa-user-doctor': 'material-symbols:stethoscope-rounded', 'fa-user-lock': 'material-symbols:lock-person-rounded',
    'fa-sign-out-alt': 'ph:sign-out-bold', 'fa-sign-in-alt': 'ph:sign-in-bold',
    'fa-address-card': 'ph:identification-card-fill',
    'fa-image': 'ph:image-fill', 'fa-images': 'ph:images-fill',
    'fa-paperclip': 'ph:paperclip-horizontal-bold', 'fa-quote-left': 'ph:quotes-fill',
    'fa-link': 'ph:link-simple-bold', 'fa-bookmark': 'ph:bookmark-simple-fill',
    'fa-tag': 'ph:tag-fill', 'fa-tags': 'ph:tag-chevron-fill',
    'fa-list': 'ph:list-bullets-bold', 'fa-code': 'ph:code-bold',
    'fa-table': 'ph:table-fill', 'fa-bold': 'ph:text-b-bold', 'fa-italic': 'ph:text-italic-bold',
    'fa-fire': 'ph:fire-fill', 'fa-clock': 'ph:clock-countdown-fill',
    'fa-chart-line': 'ph:chart-line-up-bold', 'fa-chart-bar': 'ph:chart-bar-fill',
    'fa-coins': 'ph:coins-fill', 'fa-crown': 'solar:crown-bold',
    'fa-shield-alt': 'ph:shield-check-fill', 'fa-medal': 'ph:medal-fill',
    'fa-award': 'solar:medal-ribbons-star-bold', 'fa-bullhorn': 'ph:megaphone-fill',
    'fa-circle-question': 'material-symbols:help-rounded', 'fa-question-circle': 'ph:question-fill',
    'fa-circle-info': 'ph:info-fill', 'fa-info-circle': 'ph:info-fill',
    'fa-exclamation-circle': 'ph:warning-circle-fill', 'fa-exclamation-triangle': 'ph:warning-fill',
    'fa-home': 'ph:house-fill', 'fa-comments': 'ph:chats-circle-fill',
    'fa-comment': 'ph:chat-circle-fill', 'fa-dumbbell': 'material-symbols:exercise',
    'fa-heart-pulse': 'material-symbols:ecg-heart', 'fa-sack-dollar': 'material-symbols:payments-rounded',
    'fa-star-half-alt': 'material-symbols:star-half-rounded', 'fa-globe': 'ph:globe-hemisphere-west-fill',
    'fa-earth-americas': 'material-symbols:language', 'fa-language': 'material-symbols:translate-rounded',
    'fa-layer-group': 'ph:stack-fill', 'fa-cog': 'ph:gear-six-fill',
    'fa-wrench': 'ph:wrench-fill', 'fa-flask': 'ph:flask-fill',
    'fa-book-open': 'ph:book-open-text-fill', 'fa-graduation-cap': 'ph:graduation-cap-fill',
    'fa-bitcoin': 'simple-icons:bitcoin', 'fa-discord': 'simple-icons:discord',
    'fa-twitter': 'simple-icons:x', 'fa-github': 'simple-icons:github',
    'fa-bolt': 'ph:lightning-fill', 'fa-table-columns': 'ph:columns-fill',
    'fa-file-import': 'ph:download-simple-bold', 'fa-icons': 'ph:shapes-fill',
    'fa-palette': 'ph:palette-fill',

    /*
     * ── Every remaining fa-* name in core and in the installed extensions ────
     *
     * Not "the ones that looked wrong". Enumerated by grepping every fa-* token
     * out of /flarum/app/vendor/flarum and /flarum/app/extensions in the running
     * container — 96 distinct names — and diffing against this map. 33 had no
     * entry. An unmapped name is INVISIBLE: the <i> is empty, less/forum.less
     * hides empty ones so nothing shows a blank square, and the control simply
     * has no icon. e2e/audit.ts had already caught 9 of these from the page
     * (fa-circle 169 times, on the composer toolbar and the notification list);
     * the grep found the other 24 that live on surfaces the audit does not walk.
     *
     * Every id below was checked against api.iconify.design before it was
     * written here, by tools/verify-icons.py. None of them is a guess.
     */
    'fa-key': 'ph:key-fill', 'fa-thumbs-up': 'ph:thumbs-up-fill',
    'fa-ban': 'ph:prohibit-bold', 'fa-circle': 'ph:circle-fill',
    'fa-envelope': 'ph:envelope-simple-fill', 'fa-sync-alt': 'ph:arrows-clockwise-bold',
    'fa-paint-brush': 'ph:paint-brush-fill', 'fa-i-cursor': 'ph:cursor-text-bold',
    'fa-comment-alt': 'ph:chat-teardrop-fill', 'fa-times-circle': 'ph:x-circle-fill',
    'fa-plus-circle': 'ph:plus-circle-fill', 'fa-upload': 'ph:upload-simple-bold',
    'fa-file-export': 'ph:export-bold', 'fa-compress': 'ph:arrows-in-bold',
    'fa-arrow-up': 'lucide:arrow-up',

    // composer / text formatting toolbar
    'fa-heading': 'ph:text-h-bold', 'fa-strikethrough': 'ph:text-strikethrough-bold',
    'fa-list-ul': 'ph:list-bullets-bold', 'fa-list-ol': 'ph:list-numbers-bold',
    'fa-smile': 'ph:smiley-fill',

    // moderation and account administration
    'fa-gavel': 'ph:gavel-fill', 'fa-user-cog': 'ph:user-gear-fill',
    'fa-users-cog': 'material-symbols:manage-accounts-rounded',
    'fa-user-tag': 'ph:user-rectangle-fill', 'fa-life-ring': 'ph:lifebuoy-fill',

    // media controls, and the long tail
    'fa-step-forward': 'ph:skip-forward-fill', 'fa-step-backward': 'ph:skip-back-fill',
    'fa-reply-all': 'ph:arrow-bend-double-up-left-bold', 'fa-book': 'ph:book-fill',
    'fa-laptop': 'ph:laptop-fill', 'fa-bullseye': 'ph:target-fill',
    'fa-donate': 'ph:hand-heart-fill', 'fa-swimmer': 'ph:person-simple-swim-bold',
    'fa-readme': 'simple-icons:readme'
  };

  /*
   * ── Nothing is fetched from a CDN ───────────────────────────────────────────
   *
   * This used to load the component from code.iconify.design, after which every
   * <iconify-icon> fetched its own geometry from api.iconify.design, one
   * request per distinct icon. Measured on the live forum with e2e/icon-probe.ts:
   *
   *     /      62 icons, 62 resolved,  6 zero-size
   *     /all  122 icons, 68 resolved, 25 zero-size   (after a 5s wait)
   *
   * An <iconify-icon> that has not resolved is a replaced element with NO
   * intrinsic dimensions, so a flex or grid parent stretches it to whatever
   * space is going. That is what the operator saw as "a weird stretched like
   * rotating big icon" on notifications and a "stretched and weird" search
   * glyph: not the wrong icon, an unsized box.
   *
   * It was also a privacy leak — every visitor's browser told a third party
   * exactly which icons this forum renders, on every page.
   *
   * Both the component and the icon data are now served from our own origin.
   * InjectScript inlines them ahead of this file, so by the time anything is
   * swapped the element is defined AND every icon it will ever ask for is
   * already registered. There is no network round trip and therefore no race.
   */
  function loadComponent() {
    // Already inlined by InjectScript. Kept as a function so the call sites and
    // the swap-on-ready behaviour below do not change shape.
    if (window.customElements && window.customElements.get('iconify-icon')) {
      swapAll(document.body);
      return;
    }
    // The component element is defined asynchronously by the inlined script in
    // some browsers; wait for it rather than assuming.
    if (window.customElements && window.customElements.whenDefined) {
      window.customElements.whenDefined('iconify-icon').then(function () { swapAll(document.body); });
    }
  }

  /*
   * FontAwesome class names are two different vocabularies sharing one prefix.
   * `fa-bell` names an icon; `fa-fw`, `fa-lg`, `fa-2x`, `fa-spin`, `fa-rotate-90`
   * and friends are MODIFIERS that describe how to draw whatever icon is also
   * on the element. Matching the first `fa-*` token therefore resolves
   * `class="fa-fw fa-bell"` to the non-existent icon `fa-fw` and renders
   * nothing, and Flarum core writes `fa-fw` first in several places.
   */
  var MODIFIER = /^fa-(fw|lg|sm|xs|\d+x|border|li|ul|pull-left|pull-right|spin|spin-pulse|spin-reverse|pulse|beat|fade|beat-fade|bounce|flip|shake|inverse|stack|stack-1x|stack-2x|rotate-(90|180|270|by)|flip-(horizontal|vertical|both)|sr-only|sr-only-focusable|layers|swap-opacity|width-auto)$/;

  /* The animation modifiers have to survive the swap: `fa-spin` on a spinner is
   * the whole point of the element, and dropping it leaves a motionless glyph
   * where a reader expects progress. Font Awesome's own keyframes are not
   * guaranteed to be loaded, so less/forum.less re-declares them for these. */
  var ANIM = { 'fa-spin': 'iconify-spin', 'fa-spin-reverse': 'iconify-spin-reverse',
               'fa-pulse': 'iconify-pulse', 'fa-spin-pulse': 'iconify-pulse' };

  function classes(el) {
    return String(el.getAttribute('class') || '').split(/\s+/).filter(Boolean);
  }

  function resolve(el) {
    var cs = classes(el);
    for (var i = 0; i < cs.length; i++) {
      if (cs[i].indexOf('fa-') !== 0 || MODIFIER.test(cs[i])) continue;
      if (MAP[cs[i]]) return MAP[cs[i]];
    }
    return null;
  }

  /* Every fa-* token this page asked for that we have no icon for. Collected
   * rather than discarded so e2e/audit.ts can report it by name instead of the
   * reader finding it as a blank box. */
  var UNMAPPED = (window.__lmxUnmappedIcons = window.__lmxUnmappedIcons || {});
  function noteUnmapped(el) {
    var cs = classes(el);
    for (var i = 0; i < cs.length; i++) {
      if (cs[i].indexOf('fa-') !== 0 || MODIFIER.test(cs[i]) || MAP[cs[i]]) continue;
      UNMAPPED[cs[i]] = (UNMAPPED[cs[i]] || 0) + 1;
    }
  }

  /*
   * ── The systemic fix: turn the component's IntersectionObserver OFF ────────
   *
   * iconify-icon lazily renders. connectedCallback() calls startObserver(),
   * which builds an IntersectionObserver, and when the element scrolls out of
   * view _forceRender() runs `const t = Ut(this._shadowRoot); t &&
   * this._shadowRoot.removeChild(t)` — it DELETES the rendered <svg>. The
   * element is then a replaced element with no intrinsic size again, which is
   * exactly the case a flex or grid parent stretches.
   *
   * Measured on https://looksmax.lat/ before this change: of 128
   * <iconify-icon> on the index, 13 had an <svg> in their shadow root. The
   * other 115 were emptied by the observer. Across the whole audit — 10
   * surfaces, two widths — 1729 of 2255 visible icon boxes were in that state.
   * Every one of them is a "weird stretched icon" waiting for a parent that
   * does not have our CSS floor on it.
   *
   * That laziness exists to avoid API round trips for off-screen icons. This
   * forum has NO round trips: src/InjectScript.php inlines the entire geometry
   * bundle into <head> and registers it synchronously. So the observer buys
   * nothing at all here and costs correctness plus visible pop-in on scroll.
   *
   * Two mechanisms, because one is not enough:
   *   • `noobserver` set BEFORE the element is connected, so startObserver()
   *     never builds an observer in the first place. Setting the attribute
   *     afterwards does not help — startObserver() early-returns when
   *     `this._observer` already exists, so the existing observer survives.
   *   • stopObserver() for elements we did not create (server-rendered markup
   *     and other extensions' own <iconify-icon>). It is a public method on the
   *     prototype: it disconnects, sets _visible = true and re-renders.
   */
  function unobserve(el) {
    try {
      if (!el.hasAttribute('noobserver')) el.setAttribute('noobserver', '');
      if (typeof el.stopObserver === 'function') el.stopObserver();
    } catch (e) { /* not upgraded yet; the sweep below catches it next pass */ }
  }

  function unobserveAll(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.tagName === 'ICONIFY-ICON') unobserve(root);
    var l = root.querySelectorAll('iconify-icon');
    for (var i = 0; i < l.length; i++) unobserve(l[i]);
  }

  function swap(el) {
    if (el.dataset && el.dataset.iconified) return;
    var name = resolve(el);
    if (!name) { noteUnmapped(el); return; }
    var out = document.createElement('iconify-icon');
    out.setAttribute('icon', name);
    out.setAttribute('aria-hidden', 'true');
    out.setAttribute('inline', '');
    /* Before insertion, so connectedCallback never starts an observer. */
    out.setAttribute('noobserver', '');

    /*
     * Strip the icon tokens BEFORE the style tokens.
     *
     * The old order ran /\b(fas|far|fab|fal|fa)\b/ first, and `\bfa\b` matches
     * the "fa" inside "fa-bars" because "-" is not a word character. So
     * `class="icon fas fa-bars Button-icon"` became `icon  -bars Button-icon`
     * and every swapped element on the live forum carried a junk `-bars` /
     * `-sort` / `-check` class. Verified in the page before the fix.
     */
    var keep = [];
    var cs = classes(el);
    for (var i = 0; i < cs.length; i++) {
      var c = cs[i];
      if (ANIM[c]) { keep.push(ANIM[c]); continue; }
      if (c.indexOf('fa-') === 0) continue;
      if (c === 'fas' || c === 'far' || c === 'fab' || c === 'fal' || c === 'fa') continue;
      if (c === 'icon' || c === 'iconify-glyph') continue; // added below; no duplicates
      keep.push(c);
    }
    keep.push('icon', 'iconify-glyph');
    out.className = keep.join(' ');
    out.dataset.iconified = '1';
    if (el.parentNode) el.parentNode.replaceChild(out, el);
  }

  function swapAll(root) {
    if (!root || !root.querySelectorAll) return;
    var l = root.querySelectorAll('i.fa, i.fas, i.far, i.fab, i.fal, i[class*="fa-"]');
    for (var i = 0; i < l.length; i++) swap(l[i]);
    unobserveAll(root);
  }

  /*
   * Mithril replaces subtrees wholesale on redraw, so a one-shot pass reverts
   * to FontAwesome on the next navigation. Observing is what survives.
   *
   * The previous version read `recs` inside the rAF and dropped every mutation
   * that arrived while a frame was already pending — `if (pending) return`
   * discards those records, it does not queue them. A redraw landing in that
   * window left its <i class="fa-*"> in place permanently, because nothing ever
   * looked at that subtree again. Re-sweeping the document instead of tracking
   * records cannot drop anything, and the sweep is a single querySelectorAll
   * over a page that has a few thousand nodes: cheaper than the bookkeeping.
   */
  function observe() {
    var pending = false;
    new MutationObserver(function () {
      if (pending) return;
      pending = true;
      requestAnimationFrame(function () {
        pending = false;
        swapAll(document.body);
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  function start() { loadComponent(); swapAll(document.body); observe(); }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
