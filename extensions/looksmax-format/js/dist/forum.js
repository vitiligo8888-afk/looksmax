/**
 * Post content behaviours.
 *
 * Delivered as its own <script> (see src/InjectScript.php) rather than through
 * the extension bundle: a throw here must not be able to abort bootExtensions.
 * Everything is delegated from document, so it survives Flarum's SPA
 * re-renders without needing to hook the component lifecycle.
 */
(function () {
  if (window.__lmxFormat) return;
  window.__lmxFormat = true;

  /*
   * i18n.
   *
   * This file is injected into <head> as its own <script> (src/InjectScript.php)
   * and runs before Flarum boots, so the translator cannot be captured at eval
   * time — every call resolves it fresh. window.lmxI18n.t records a miss so the
   * i18n gate can fail the deploy on a raw key reaching the screen, and the
   * English fallback keeps post chrome readable if looksmax-i18n is absent.
   */
  var NS = 'local-looksmax-format.';
  function t(key, params, fallback) {
    var k = NS + key;
    try {
      if (window.lmxI18n) return window.lmxI18n.t(k, params || {}, fallback);
      var s = window.flarum.core.app.translator.trans(k, params || {});
      if (typeof s === 'string' && s !== k) return s;
      if (s && typeof s.join === 'function') return s.join('');
    } catch (e) {}
    return fallback == null ? k : fallback;
  }

  var EMBED = {
    youtube: function (u) {
      var id = (u.match(/(?:v=|youtu\.be\/|embed\/|shorts\/)([\w-]{6,})/) || [])[1];
      return id ? 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1' : null;
    },
    vimeo: function (u) {
      var id = (u.match(/(\d{6,})/) || [])[1];
      return id ? 'https://player.vimeo.com/video/' + id + '?autoplay=1' : null;
    },
  };

  function embedUrl(site, url) {
    if (EMBED[site]) {
      var r = EMBED[site](url);
      if (r) return r;
    }
    return url;
  }

  /** Click-to-play: swap the facade for the real iframe, once, on demand. */
  document.addEventListener('click', function (e) {
    var media = e.target.closest && e.target.closest('.lmxMedia');
    if (!media || media.classList.contains('is-live')) return;
    e.preventDefault();

    var url = media.getAttribute('data-lmx-media');
    var site = media.getAttribute('data-lmx-site') || '';
    if (!url) return;

    var frame = document.createElement('iframe');
    frame.src = embedUrl(site, url);
    frame.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture');
    frame.setAttribute('allowfullscreen', '');
    frame.setAttribute('referrerpolicy', 'origin');
    frame.setAttribute('loading', 'lazy');
    frame.className = 'lmxMedia-iframe';
    if (media.getAttribute('data-lmx-height')) frame.style.height = media.getAttribute('data-lmx-height') + 'px';

    var slot = media.querySelector('.lmxMedia-frame');
    if (!slot) return;
    slot.innerHTML = '';
    slot.appendChild(frame);
    media.classList.add('is-live');
  });

  /**
   * Lightbox. Post images are capped by CSS so a 4000px attachment does not
   * blow out the column; without a way back to full size that is a loss, so
   * clicking one opens it.
   */
  function closeLightbox() {
    var el = document.querySelector('.lmxLightbox');
    if (el) el.remove();
    document.body.classList.remove('lmxLightbox-open');
  }

  document.addEventListener('click', function (e) {
    var img = e.target;
    if (!img.classList || !img.classList.contains('lmxImage')) return;
    if (img.closest('a')) return; // an image inside a link is a link
    e.preventDefault();

    closeLightbox();
    var box = document.createElement('div');
    box.className = 'lmxLightbox';
    box.innerHTML =
      '<button class="lmxLightbox-close">&times;</button>' +
      '<img class="lmxLightbox-img" alt="">';
    // Set as a property rather than interpolated into the innerHTML above: a
    // translation is text, and text concatenated into markup is a bug waiting.
    box.querySelector('.lmxLightbox-close').setAttribute('aria-label', t('forum.post.lightbox_close', {}, 'Close'));
    box.querySelector('.lmxLightbox-img').src = img.currentSrc || img.src;
    box.querySelector('.lmxLightbox-img').alt = img.alt || '';
    box.addEventListener('click', function (ev) {
      if (ev.target === box || ev.target.classList.contains('lmxLightbox-close')) closeLightbox();
    });
    document.body.appendChild(box);
    document.body.classList.add('lmxLightbox-open');
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeLightbox();
  });

  /**
   * Images that 404 (the source board expires attachments, and 9,378 of the
   * urls in the corpus have no local copy) otherwise leave a broken-image
   * glyph mid-paragraph. Mark them so CSS can render a labelled placeholder.
   */
  document.addEventListener(
    'error',
    function (e) {
      var t = e.target;
      if (t && t.tagName === 'IMG' && t.classList && t.classList.contains('lmxImage')) {
        t.classList.add('is-missing');
      }
    },
    true
  );

  /**
   * Spoiler state is per-element and Flarum re-renders the post stream on
   * scroll, which resets <details open>. Remember it for the session so a
   * revealed spoiler stays revealed.
   */
  var opened = Object.create(null);
  document.addEventListener('toggle', function (e) {
    var d = e.target;
    if (!d.classList || !d.classList.contains('lmxSpoiler')) return;
    var key = (d.textContent || '').slice(0, 120);
    if (d.open) opened[key] = 1;
    else delete opened[key];
  }, true);

  var restore = function () {
    document.querySelectorAll('details.lmxSpoiler:not([open])').forEach(function (d) {
      if (opened[(d.textContent || '').slice(0, 120)]) d.open = true;
    });
  };
  if (document.readyState !== 'loading') setTimeout(restore, 0);
  document.addEventListener('DOMContentLoaded', restore);

  /**
   * Inline spoilers ([ispoiler], 380 occurrences across 87 posts).
   *
   * Blurred in place and revealed on click. Keyboard-operable because the
   * element is rendered with role="button" and tabindex=0 — a click-only
   * reveal hides the content from keyboard users permanently, which for a
   * spoiler is indistinguishable from deleting it.
   */
  function revealISpoiler(el) {
    el.classList.add('is-revealed');
    el.setAttribute('aria-label', t('forum.post.spoiler_revealed', {}, 'Hidden text, revealed'));
  }

  document.addEventListener('click', function (e) {
    var s = e.target.closest && e.target.closest('.lmxISpoiler');
    if (s && !s.classList.contains('is-revealed')) {
      e.preventDefault();
      revealISpoiler(s);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var s = e.target;
    if (s && s.classList && s.classList.contains('lmxISpoiler') && !s.classList.contains('is-revealed')) {
      e.preventDefault();
      revealISpoiler(s);
    }
  });

  /**
   * Long quotes arrive pre-collapsed.
   *
   * The operator's case: "sometimes they reply and it's like that for an
   * entire guide per reply" — someone quotes a 5,000-word guide to add one
   * line, and the reply is off the bottom of the screen.
   *
   * The threshold is measured, and it is measured in PIXELS rather than
   * characters on purpose. The corpus quote-length distribution
   * (reports/CORPUS-SURVEY.md §5, all 300,179 quotes) is p50 75, p75 163,
   * p90 382, p95 796, p99 7,000, max 87,600 characters — so the long tail is
   * real but narrow, and collapsing at p50 would put a control on half the
   * quotes on the site. Targeting roughly the top decile means ~9.5% of
   * quotes.
   *
   * Characters are the wrong unit for the actual complaint though: a
   * 200-character quote holding three images is far taller than a
   * 900-character one of plain prose, and nested quotes compound it. What
   * pushes the reply off screen is height. 320px is about 12 lines at this
   * theme's post line-height — comfortably more than the p90 quote of ~380
   * characters renders to, so short and medium quotes are untouched and the
   * guide-length ones fold.
   */
  var COLLAPSE_PX = 320;
  /** How much stays visible when collapsed: enough to know what is quoted. */
  var PEEK_PX = 150;

  function collapsible(q) {
    if (q.getAttribute('data-lmx-fold')) return;
    var body = q.querySelector('.lmxQuote-body');
    if (!body) return;

    // A nested quote is folded by its ancestor; folding both is noise.
    if (q.parentElement && q.parentElement.closest('.lmxQuote')) return;

    if (body.scrollHeight <= COLLAPSE_PX) {
      q.setAttribute('data-lmx-fold', 'no');
      return;
    }

    q.setAttribute('data-lmx-fold', 'collapsed');
    body.style.maxHeight = PEEK_PX + 'px';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lmxQuote-more';
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = t('forum.quote.show_rest', {}, 'Show the rest of this quote');
    btn.addEventListener('click', function () {
      var open = q.getAttribute('data-lmx-fold') === 'open';
      if (open) {
        q.setAttribute('data-lmx-fold', 'collapsed');
        body.style.maxHeight = PEEK_PX + 'px';
        btn.textContent = t('forum.quote.show_rest', {}, 'Show the rest of this quote');
        btn.setAttribute('aria-expanded', 'false');
        // Keep the control in view when folding a very tall quote back up.
        var r = q.getBoundingClientRect();
        if (r.top < 0) q.scrollIntoView({ block: 'start' });
      } else {
        q.setAttribute('data-lmx-fold', 'open');
        body.style.maxHeight = body.scrollHeight + 'px';
        btn.textContent = t('forum.quote.collapse', {}, 'Collapse quote');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
    q.appendChild(btn);
  }

  /**
   * Run after images have had a chance to load: scrollHeight before an image
   * resolves is the height WITHOUT it, so a picture-heavy quote would measure
   * short and never fold. Re-measured on each image load, and folded quotes
   * are never re-measured (data-lmx-fold is the guard).
   */
  function foldQuotes(root) {
    (root || document).querySelectorAll('.lmxQuote').forEach(collapsible);
  }

  document.addEventListener(
    'load',
    function (e) {
      var t = e.target;
      if (t && t.tagName === 'IMG') {
        var q = t.closest && t.closest('.lmxQuote');
        if (q && q.getAttribute('data-lmx-fold') === 'no') {
          q.removeAttribute('data-lmx-fold');
          collapsible(q);
        }
      }
    },
    true
  );

  /**
   * Code blocks get a copy button.
   *
   * Guides in this corpus paste protocols, dosages and shell commands, and
   * selecting a <pre> across a scroll container on a phone is miserable.
   */
  function addCopy(pre) {
    if (pre.getAttribute('data-lmx-copy')) return;
    pre.setAttribute('data-lmx-copy', '1');

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'lmxCode-copy';
    btn.textContent = t('forum.post.copy', {}, 'Copy');
    btn.addEventListener('click', function () {
      var text = pre.innerText;
      var done = function () {
        btn.textContent = t('forum.post.copied', {}, 'Copied');
        setTimeout(function () { btn.textContent = t('forum.post.copy', {}, 'Copy'); }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { btn.textContent = t('forum.post.copy_manual', {}, 'Press Ctrl+C'); });
      } else {
        // No clipboard API (insecure origin): select it so Ctrl+C works.
        var r = document.createRange();
        r.selectNodeContents(pre);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(r);
        btn.textContent = t('forum.post.copy_manual', {}, 'Press Ctrl+C');
      }
    });

    var wrap = document.createElement('div');
    wrap.className = 'lmxCode';
    pre.parentNode.insertBefore(wrap, pre);
    wrap.appendChild(pre);
    wrap.appendChild(btn);
  }

  /**
   * Put the real emoji character back where flarum/emoji swapped in a
   * hotlinked twemoji image.
   *
   * The Content-Security-Policy already BLOCKS the cdn.jsdelivr.net request, so
   * nothing leaks either way — this is purely so the reader sees an emoji
   * instead of a blocked-image glyph. The <img alt> is the original character,
   * which is also what should end up on the clipboard and in a screen reader,
   * so replacing the element with a text node is strictly better than the image
   * ever was.
   *
   * Done here rather than server-side because flarum/emoji performs the swap in
   * the browser, from the compiled bundle: no template override can reach it.
   */
  function swapEmojiImg(img) {
    var ch = img.getAttribute('alt');
    if (!ch) return;
    var span = document.createElement('span');
    span.className = 'lmxEmoji';
    span.textContent = ch;
    if (img.parentNode) img.parentNode.replaceChild(span, img);
  }

  function restoreEmoji(root) {
    (root || document)
      .querySelectorAll('img.emoji, img[class*="emoji"]')
      .forEach(swapEmojiImg);
  }

  /**
   * Catch the twemoji <img> AT INSERTION, before the fetch is dispatched.
   *
   * restoreEmoji() above runs on enhance(), which is after flarum/emoji has
   * already put the element in the document — by then the browser has queued
   * the request, CSP refuses it, and every emoji logs a console error and a
   * failed request. The reader never saw a broken image (we swap it in time),
   * but "renders fine while throwing" is exactly the state a console-error gate
   * exists to catch, and it buried real errors in the noise.
   *
   * A MutationObserver callback runs as a microtask immediately after the
   * mutation, which beats the network dispatch in practice. Clearing `src`
   * first is the part that actually prevents the request; the swap then happens
   * as before. This is best-effort by nature — if a fetch has already started
   * we simply lose that race and land back on the old behaviour, which was
   * already visually correct.
   */
  function watchEmoji() {
    if (typeof MutationObserver !== 'function') return;

    new MutationObserver(function (records) {
      for (var i = 0; i < records.length; i++) {
        var added = records[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          var n = added[j];
          if (!n || n.nodeType !== 1) continue;
          if (n.tagName === 'IMG' && /emoji/.test(n.className || '')) {
            n.removeAttribute('src');
            swapEmojiImg(n);
          } else if (n.querySelectorAll) {
            var imgs = n.querySelectorAll('img.emoji, img[class*="emoji"]');
            for (var k = 0; k < imgs.length; k++) {
              imgs[k].removeAttribute('src');
              swapEmojiImg(imgs[k]);
            }
          }
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }

  function enhance() {
    restoreEmoji();
    foldQuotes();
    document.querySelectorAll('.Post-body pre').forEach(addCopy);
  }

  /*
   * Flarum is an SPA and re-renders the post stream as it scrolls, so a
   * one-shot pass on load enhances only the posts that happened to be mounted
   * at that moment. A MutationObserver is the only thing that keeps up with
   * it, and it is cheap because every enhancer is idempotent and guarded by
   * its own data attribute.
   */
  var scheduled = false;
  var observer = new MutationObserver(function () {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(function () {
      scheduled = false;
      enhance();
    });
  });

  function start() {
    enhance();
    observer.observe(document.body, { childList: true, subtree: true });
  }

  /*
   * Started IMMEDIATELY, not from start(). This script is injected in <head>,
   * so documentElement exists but <body> does not yet — and the whole value of
   * this observer is being live before flarum/emoji inserts its first <img>.
   * The main observer above cannot do this job: it is rAF-debounced, which by
   * definition runs after the frame in which the request was already sent.
   */
  watchEmoji();

  if (document.readyState !== 'loading') start();
  else document.addEventListener('DOMContentLoaded', start);
})();
