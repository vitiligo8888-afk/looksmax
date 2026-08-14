/*
 * Post signatures — the client half.
 *
 * TWO jobs, one file:
 *   1. RENDER the author's signature under each post in the stream.
 *   2. The EDITOR on /settings.
 *
 * ── why a DOM decorator, again ─────────────────────────────────────────────
 * Same answer as looksmax-cosmetics: this script is injected as its own
 * <script> outside the extension bundle, so it cannot import CommentPost and
 * override view(). One observer over the rendered stream covers every way a
 * post reaches the page — initial load, infinite scroll, a reply posted live,
 * navigating back to a thread — with one implementation.
 *
 * ── where the text comes from ──────────────────────────────────────────────
 * `userInfo.signature` on the user payload, which extend.php ALREADY eager
 * loads onto every post author for the hover card. So rendering a signature
 * costs no request and no query: if the post is on screen, its author's
 * signature is already in the store.
 *
 * The server decides whether a signature is allowed to render at all (feature
 * flag, account-age gate — Presenter::signature). This file renders what it is
 * given and gates nothing, deliberately: a client-side gate on abuse-relevant
 * content is decoration.
 *
 * ── textContent, never innerHTML ───────────────────────────────────────────
 * A signature renders under every post its author ever made, so a stored-XSS
 * payload here is one write for thousands of impressions. The text is inserted
 * as a text node and line breaks are built as real <br> elements. There is no
 * code path in this file that assigns markup from user input.
 */
(function () {
  'use strict';

  function compat() {
    return (window.flarum && window.flarum.core && window.flarum.core.compat) || {};
  }

  function getApp() {
    var r = compat();
    return (r['forum/app'] && r['forum/app'].default) || window.app || null;
  }

  function cfg() {
    var app = getApp();
    try {
      return (app && app.forum && app.forum.attribute('lmxUserInfo')) || {};
    } catch (e) {
      return {};
    }
  }

  // ---- rendering -----------------------------------------------------------

  function signatureFor(slug) {
    var app = getApp();
    if (!app || !app.store) return null;
    try {
      var users = app.store.all('users');
      for (var i = 0; i < users.length; i++) {
        if (users[i].slug && users[i].slug() === slug) {
          var info = users[i].attribute('userInfo');
          return (info && info.signature) || null;
        }
      }
    } catch (e) { /* store shape changed — render nothing rather than throw */ }
    return null;
  }

  function build(text) {
    var box = document.createElement('div');
    box.className = 'LmxSig';
    box.setAttribute('data-lmx-sig', '1');

    // Split on newline and join with real <br> nodes. Never innerHTML.
    var lines = String(text).split('\n');
    for (var i = 0; i < lines.length; i++) {
      if (i > 0) box.appendChild(document.createElement('br'));
      box.appendChild(document.createTextNode(lines[i]));
    }
    return box;
  }

  function decorate(post) {
    // IDEMPOTENCE GUARD, on the post element itself.
    //
    // This used to test `post.querySelector(':scope > .LmxSig')` — a DIRECT
    // child of the post. The signature is inserted with
    // `body.insertAdjacentElement('afterend', ...)`, which lands it as a
    // SIBLING OF .Post-body, one level deeper than that selector can see. So
    // the guard never matched, and since this runs from a MutationObserver,
    // every mutation appended another signature: the node count grew without
    // bound until the tab died.
    //
    // It only reproduced on pages showing an author who actually HAS a
    // signature (everyone else returns early below), which is why it looked
    // like "new discussions hang" — the only account with one had authored
    // them, so every other thread looked healthy.
    var body = post.querySelector('.Post-body');
    if (!body) return;

    // Test the node we ACTUALLY insert, in the place we actually insert it.
    // Marking the post up-front would be simpler but wrong: on the first pass
    // the user payload may not be in the store yet, signatureFor() returns
    // null, and a post flagged "done" would never get its signature when the
    // data arrives a tick later.
    var next = body.nextElementSibling;
    if (next && next.classList && next.classList.contains('LmxSig')) return;

    // The author link in the post header carries the slug.
    var link = post.querySelector('.PostUser a[href*="/u/"], .Post-header a[href*="/u/"]');
    if (!link) return;

    var m = link.getAttribute('href').match(/\/u\/([^/?#]+)/);
    if (!m) return;

    var text = signatureFor(decodeURIComponent(m[1]));
    if (!text) return;

    body.insertAdjacentElement('afterend', build(text));
  }

  function sweep() {
    if (cfg().sigEnabled === false) return;
    var posts = document.querySelectorAll('.CommentPost, .Post--comment');
    for (var i = 0; i < posts.length; i++) {
      try { decorate(posts[i]); } catch (e) { /* one bad post must not stop the rest */ }
    }
  }

  // ---- the /settings editor ------------------------------------------------

  function injectEditor() {
    var page = document.querySelector('.SettingsPage .container');
    if (!page || document.getElementById('lmx-sig-editor')) return;

    var conf = cfg();
    if (!conf.sigEnabled) return;

    var maxLen = Number(conf.sigMaxLength || 280);
    var maxLines = Number(conf.sigMaxLines || 4);

    var box = document.createElement('fieldset');
    box.id = 'lmx-sig-editor';
    box.className = 'Settings-signature';

    var legend = document.createElement('legend');
    legend.textContent = 'Firma';
    box.appendChild(legend);

    var help = document.createElement('p');
    help.className = 'helpText';
    help.textContent = 'Se muestra debajo de cada uno de tus mensajes. Máximo '
      + maxLen + ' caracteres, ' + maxLines + ' líneas. Solo texto.';
    box.appendChild(help);

    var ta = document.createElement('textarea');
    ta.className = 'FormControl';
    ta.rows = maxLines;
    ta.maxLength = maxLen;

    var app = getApp();
    try {
      var me = app && app.session && app.session.user;
      var info = me && me.attribute('userInfo');
      ta.value = (info && info.signature) || '';
    } catch (e) { ta.value = ''; }
    box.appendChild(ta);

    var status = document.createElement('span');
    status.className = 'LmxSig-status';

    var save = document.createElement('button');
    save.type = 'button';
    save.className = 'Button Button--primary';
    save.textContent = 'Guardar firma';
    save.addEventListener('click', function () {
      save.disabled = true;
      status.textContent = '';

      var headers = { 'Content-Type': 'application/json' };
      try {
        if (app && app.session && app.session.csrfToken) {
          headers['X-CSRF-Token'] = app.session.csrfToken;
        }
      } catch (e) { /* no token available — server will reject, which is correct */ }

      fetch('/api/lmx-signature', {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify({ signature: ta.value })
      })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
          if (!res.ok) {
            status.textContent = res.body && res.body.error === 'too_long'
              ? 'Demasiado largo (máx ' + res.body.max + ').'
              : (res.body && res.body.error === 'too_many_lines'
                ? 'Demasiadas líneas (máx ' + res.body.max + ').'
                : 'No se pudo guardar.');
            return;
          }
          status.textContent = 'Guardada.';
          // Keep the in-memory user in sync so the stream re-renders the new
          // text without a reload.
          try {
            var me = app.session.user;
            var info = Object.assign({}, me.attribute('userInfo') || {});
            info.signature = res.body.signature;
            me.pushAttributes({ userInfo: info });
            document.querySelectorAll('[data-lmx-sig]').forEach(function (n) { n.remove(); });
            sweep();
          } catch (e) { /* cosmetic only */ }
        })
        .catch(function () { status.textContent = 'No se pudo guardar.'; })
        .then(function () { save.disabled = false; });
    });

    var row = document.createElement('div');
    row.className = 'LmxSig-actions';
    row.appendChild(save);
    row.appendChild(status);
    box.appendChild(row);

    page.appendChild(box);
  }

  function tick() {
    try { sweep(); } catch (e) { /* never throw into the observer */ }
    try { injectEditor(); } catch (e) { /* ditto */ }
  }

  function start() {
    tick();

    // rAF-debounced. tick() sweeps every post on the page, and the enhancers
    // in this forum mutate the DOM themselves, so an undebounced observer runs
    // a full sweep for each of their mutations. One pass per frame is enough
    // and keeps a long thread responsive.
    var scheduled = false;
    new MutationObserver(function () {
      if (scheduled) return;
      scheduled = true;
      requestAnimationFrame(function () {
        scheduled = false;
        tick();
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
