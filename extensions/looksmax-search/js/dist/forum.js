/*
 * Looksmax search — forum UI.
 *
 * Plain DOM, no Mithril, no build step. Three reasons, all of them things that
 * have already gone wrong in this stack:
 *
 *  1. This file is injected as its own <script> with its own try/catch (see
 *     Listeners/InjectSearch). Flarum concatenates every extension's JS into one
 *     bundle, where a single top-level throw aborts bootExtensions and blanks
 *     the SPA. Staying outside that bundle means a bug in search breaks search.
 *  2. It touches no component prototype. `IndexPage` is extended by 189 other
 *     extensions and overridden by 36; `Search` by 7. Nothing here is in that
 *     contest — it attaches to the DOM the header already renders and adds its
 *     own elements under `.lmx-*`.
 *  3. There is no node in the app container, so there is no bundler. Written to
 *     be read, not compiled.
 *
 * Everything degrades: if the API is unreachable, the native Flarum search is
 * still there underneath, untouched.
 *
 * ---------------------------------------------------------------------------
 * WHAT CHANGED IN THIS REVISION, and the measurement that forced each change.
 * Baseline numbers are from extensions/looksmax-search/shots-before/report-out.json,
 * taken against the live site before any of this was written.
 *
 *  - `avatars: 0`. No result carried an author avatar. Now every discussion and
 *    post row does — from `hit.authorAvatarUrl` when the backend grows it, and
 *    from a deterministic initial disc until then (see avatarFor).
 *  - `ariaLive: 0`, `combobox: 0`, `activedesc: 0`. There was no combobox and no
 *    announced result count anywhere on the surface. Both exist now.
 *  - `/search` with no query rendered the string `Nothing matched “”` on an
 *    otherwise blank page. There is now a landing state: recent queries, saved
 *    searches, and the busiest forums read from `/api/tags`.
 *  - `recovery.suggestions: []` for `zzqqxwv`. The server's recover() only
 *    strips filters, phrases and words, so a single unknown word — the single
 *    most common zero-result shape — got nothing. The zero state now probes
 *    prefixes of the word itself and offers whatever actually returns hits.
 *  - At 390px the header search input measured `x:-212 w:220` — it lives inside
 *    `.App-drawer`, which is `transform`ed off-screen, so mobile had no visible
 *    search at all, and `.Search-results` (z-index 1030) hit-tested to
 *    `.Navigation-drawer`: TRAPPED in the drawer's stacking context, not a
 *    z-index shortfall. Fixed by not being a descendant — the dropdown is
 *    appended to <body> and positioned from the anchor's rect — plus a header
 *    button this file injects so mobile has a one-tap way in.
 *  - The palette's backdrop carried a bare `z-index: 1000`, the same layer as
 *    `--z-header`, and only won on DOM order. Everything is on the token ladder
 *    now.
 *  - Focusing the header field opened a modal and blurred the field. That is
 *    not a dropdown. The header field now stays focused and owns a real
 *    combobox popup; the full-screen surface is the MOBILE presentation of the
 *    same component, which is what a 390px screen actually wants.
 */
(function () {
  'use strict';

  var CFG = (function () {
    try {
      return JSON.parse(document.getElementById('lmx-search-config').textContent);
    } catch (e) {
      return { enabled: true, palette: true, minChars: 2, debounceMs: 120 };
    }
  })();
  if (!CFG.enabled) return;

  var API = '/api/looksmax/search';
  var MOBILE = 768;

  /*
   * The URL this document was actually served as, captured NOW.
   *
   * This script is injected into <head>, so it runs before Flarum boots. That
   * matters: Flarum 1.8's client-side router has no `/search` route, and when
   * it cannot match the current path it falls back to the default route AND
   * REWRITES THE ADDRESS BAR. Measured in a real browser: loading
   * `/search?q=jaw` leaves `location.pathname === "/"` by the time the app has
   * booted, while the server-rendered results for that exact query are sitting
   * in the document (17 of them).
   *
   * So `location.pathname` is not a reliable answer to "what page is this".
   * The value below is, because nothing has had a chance to change it yet.
   */
  var INITIAL_URL = location.pathname + location.search;
  var IS_SEARCH_ROUTE = /^\/search\b/;
  var searchMode = IS_SEARCH_ROUTE.test(location.pathname);
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var isMobile = function () { return window.innerWidth < MOBILE; };

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    for (var k in attrs || {}) {
      if (k === 'class') n.className = attrs[k];
      else if (k === 'html') n.innerHTML = attrs[k];
      // `css` sets CUSTOM PROPERTIES through the CSSOM rather than by building
      // a style attribute string. A category colour arrives from the database
      // and would otherwise be concatenated into markup; setProperty takes a
      // value, not markup, and silently drops anything the parser rejects.
      else if (k === 'css') { for (var p in attrs[k]) if (attrs[k][p] != null) n.style.setProperty(p, attrs[k][p]); }
      else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] != null) n.setAttribute(k, attrs[k]);
    }
    (kids || []).forEach(function (c) {
      if (c == null) return;
      // Anything that is not already a Node becomes text. A number, an array
      // from a rich translation, or an accidental object used to throw
      // "Argument 1 does not implement interface Node" and take the whole
      // search UI down with it.
      if (c && c.nodeType) n.appendChild(c);
      else if (Array.isArray(c)) c.forEach(function (x) {
        if (x == null) return;
        n.appendChild(x && x.nodeType ? x : document.createTextNode(String(x)));
      });
      else n.appendChild(document.createTextNode(String(c)));
    });
    return n;
  }

  // ===================================================================== highlight
  //
  // Two paths, and only two, both of which produce <mark> elements and NEITHER
  // of which ever puts user text through innerHTML:
  //
  //   marked(html)  — adopts the server's marks. Engine::hl escapes first and
  //                   substitutes the sentinels afterwards, so the only markup
  //                   that can legally be in there is <mark>. Everything else is
  //                   flattened to its text, so even a bug upstream cannot ship
  //                   an <img onerror> onto this page.
  //   markText(el)  — highlights CLIENT-SIDE by offsets over text nodes. Used
  //                   where the server marked nothing: the database fallback
  //                   path, `matchedPost` titles, and the related-threads rail.
  //
  // The second one is the reason this section exists at all. Highlighting by
  // building a string and assigning innerHTML is the classic way a forum ships
  // stored XSS; here the query never becomes markup, it becomes a pair of
  // integer offsets into a text node that is then SPLIT. There is no parse step
  // for an attacker to reach.

  function marked(html) {
    var span = document.createElement('span');
    span.innerHTML = String(html == null ? '' : html);
    (function walk(node) {
      Array.prototype.slice.call(node.childNodes).forEach(function (c) {
        if (c.nodeType === 1) {
          if (c.tagName !== 'MARK') {
            var t = document.createTextNode(c.textContent);
            node.replaceChild(t, c);
            return;
          }
          walk(c);
        }
      });
    })(span);
    return span;
  }

  /*
   * Case- and diacritic-folding that KEEPS AN OFFSET MAP.
   *
   * "niño" and "nino", "Ángulo" and "angulo", "ЛИЦО" and "лицо" have to be the
   * same string for matching and DIFFERENT strings for display — the reader
   * must see their own text with their own accents on it, marked in place.
   *
   * NFD-normalising the whole string and stripping marks changes its LENGTH
   * (ñ becomes two code points), so offsets computed on the folded form do not
   * address the original. Folding one code point at a time and recording where
   * each folded unit came from is what makes the mapping exact. `map[i]` is the
   * index in the ORIGINAL string that folded unit `i` came from, and `map` has
   * one extra entry so an exclusive end offset is always addressable.
   *
   * The stripped range is U+0300–U+036F (Latin combining marks: á é í ó ú ü ñ)
   * plus U+0483–U+0489 (Cyrillic combining marks), which is what turns й into и
   * and ё into е — the same folding Meilisearch's tokenizer does server-side,
   * so client and engine agree on what counts as a match.
   */
  var COMBINING = /[\u0300-\u036f\u0483-\u0489]/g;
  function fold(s) {
    var f = '', map = [], i = 0;
    while (i < s.length) {
      var cp = s.codePointAt(i);
      var chr = String.fromCodePoint(cp);
      var d;
      try { d = chr.toLowerCase().normalize('NFD').replace(COMBINING, ''); }
      catch (e) { d = chr.toLowerCase(); }
      if (d === '') d = chr.toLowerCase();
      for (var k = 0; k < d.length; k++) { f += d[k]; map.push(i); }
      i += chr.length;
    }
    map.push(s.length);
    return { f: f, map: map };
  }

  // A "word character" for boundary purposes, in every script this board has.
  var WORDCH = (function () {
    try { return new RegExp('[\\p{L}\\p{N}_]', 'u'); }
    catch (e) { return /[0-9A-Za-z_À-ɏЀ-ӿ؀-ۿ]/; }
  })();

  /*
   * The terms to mark, derived from the PARSED query the server returned rather
   * than from the raw string. That matters: `tag:looksmaxing sort:new -cope
   * "mouth breathing"` must mark `mouth breathing` as one phrase and must NOT
   * mark `looksmaxing`, `new` or `cope` — an operator's value is a filter, not
   * something the reader asked to see highlighted, and a negated word is the
   * opposite of a match. When there is no parsed object (the dropdown, and any
   * degraded response) the same shape is recovered locally.
   */
  var OPERATORS = /(^|\s)-?(tag|in|forum|category|prefix|by|author|from|user|before|until|after|since|reactions|likes|score|replies|comments|posts|views|len|length|words|lang|language|is|has|sort|order|thread|discussion|d|type):("[^"]*"|\S*)/gi;

  function termsFrom(parsed, raw) {
    var out = [], seen = {};
    function add(t, phrase) {
      t = String(t == null ? '' : t).trim();
      if (!t) return;
      var key = (phrase ? 'p:' : 'w:') + t.toLowerCase();
      if (seen[key]) return;
      seen[key] = 1;
      // A one-character term marks half the page and tells the reader nothing.
      if (!phrase && t.length < 2) return;
      out.push({ text: t, phrase: !!phrase });
    }
    if (parsed && (parsed.text != null || parsed.phrases)) {
      (parsed.phrases || []).forEach(function (p) { add(p, true); });
      String(parsed.text || '').split(/\s+/).forEach(function (w) { add(w, false); });
      var neg = {};
      (parsed.negatives || []).forEach(function (n) { neg[String(n).toLowerCase()] = 1; });
      out = out.filter(function (t) { return !neg[t.text.toLowerCase()]; });
      return out;
    }
    var s = String(raw || '');
    s.replace(/"([^"]+)"/g, function (_, p) { add(p, true); return ' '; });
    s = s.replace(/"[^"]*"/g, ' ').replace(OPERATORS, ' ');
    s.split(/\s+/).forEach(function (w) { if (w.charAt(0) !== '-') add(w, false); });
    return out;
  }

  /* Byte-exact ranges, in ORIGINAL-string coordinates, for one text node. */
  function rangesIn(text, terms) {
    var h = fold(text);
    var hits = [];
    terms.forEach(function (t) {
      var needle = fold(t.text).f;
      if (t.phrase) needle = needle.replace(/\s+/g, ' ');
      if (!needle) return;
      var hay = t.phrase ? h.f.replace(/\s/g, ' ') : h.f;
      var from = 0, at;
      while ((at = hay.indexOf(needle, from)) !== -1) {
        from = at + 1;
        // Anchor at a word boundary. Without this, "os" marks the middle of
        // "looksmaxing" and the page turns into confetti; with it, a prefix
        // ("mew" in "mewing") still marks, which is what the engine matched on.
        var prev = at > 0 ? hay.charAt(at - 1) : '';
        if (prev && WORDCH.test(prev)) continue;
        hits.push([h.map[at], h.map[at + needle.length]]);
      }
    });
    if (!hits.length) return hits;
    hits.sort(function (a, b) { return a[0] - b[0] || b[1] - a[1]; });
    var merged = [hits[0]];
    for (var i = 1; i < hits.length; i++) {
      var last = merged[merged.length - 1];
      if (hits[i][0] <= last[1]) last[1] = Math.max(last[1], hits[i][1]);
      else merged.push(hits[i]);
    }
    return merged;
  }

  /* Mark every text node under `root`. Returns how many marks were created. */
  function markText(root, terms) {
    if (!root || !terms || !terms.length) return 0;
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var nodes = [], n;
    while ((n = walker.nextNode())) {
      if (n.parentNode && n.parentNode.nodeName !== 'MARK' && n.nodeValue) nodes.push(n);
    }
    var made = 0;
    nodes.forEach(function (node) {
      var text = node.nodeValue;
      var rs = rangesIn(text, terms);
      if (!rs.length) return;
      var frag = document.createDocumentFragment(), at = 0;
      rs.forEach(function (r) {
        if (r[0] > at) frag.appendChild(document.createTextNode(text.slice(at, r[0])));
        frag.appendChild(el('mark', {}, [text.slice(r[0], r[1])]));
        made++;
        at = r[1];
      });
      if (at < text.length) frag.appendChild(document.createTextNode(text.slice(at)));
      node.parentNode.replaceChild(frag, node);
    });
    return made;
  }

  /*
   * Adopt the server's marks, and mark client-side only if it produced none.
   * Never both: double-marking splits a <mark> into three and looks like a bug.
   */
  function hlNode(html, terms) {
    var span = marked(html);
    tidyText(span);
    if (!span.querySelector('mark')) markText(span, terms);
    return span;
  }

  /*
   * Excerpts are indexed from raw post source, so a match can sit next to a
   * 120-character attachment URL or a `[embed …]` tag that pushes the actual
   * sentence out of the two visible lines. Rewritten on TEXT NODES ONLY — the
   * marks are already in the tree at this point and must survive.
   */
  function tidyText(root) {
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var nodes = [], n;
    while ((n = walker.nextNode())) nodes.push(n);
    nodes.forEach(function (node) {
      var v = node.nodeValue;
      if (!v) return;
      var out = v
        .replace(/\[\/?(embed|img|attach|url|media|quote)[^\]]*\]/gi, ' ')
        .replace(/https?:\/\/\S{24,}/g, function (u) {
          try { return new URL(u).hostname.replace(/^www\./, '') + '/…'; }
          catch (e) { return u.slice(0, 24) + '…'; }
        })
        .replace(/[ \t]{2,}/g, ' ');
      if (out !== v) node.nodeValue = out;
    });
  }

  var debounceTimer;
  function debounce(fn, ms) {
    return function () {
      var a = arguments, t = this;
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () { fn.apply(t, a); }, ms);
    };
  }

  function get(path, params) {
    var qs = Object.keys(params || {})
      .filter(function (k) { return params[k] !== '' && params[k] != null; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
      .join('&');
    return fetch(path + (qs ? '?' + qs : ''), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    }).then(function (r) {
      if (!r.ok) {
        // A bare "HTTP 500" names nothing. Carry the status, the path and as
        // much of the body as is useful onto the error, so the error state can
        // show the reader something true and the console shows an operator the
        // actual response instead of a number.
        return r.text().then(function (body) {
          var err = new Error('HTTP ' + r.status + ' ' + path);
          err.status = r.status;
          err.body = String(body || '').slice(0, 400);
          err.path = path;
          throw err;
        }, function () { var e2 = new Error('HTTP ' + r.status + ' ' + path); e2.status = r.status; throw e2; });
      }
      return r.json();
    });
  }

  function post(path, body) {
    var token = '';
    try { token = window.flarum.core.app.session.csrfToken || ''; } catch (e) {}
    return fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
      body: JSON.stringify(body),
    });
  }

  function loggedIn() {
    try { return !!window.flarum.core.app.session.user; } catch (e) { return false; }
  }

  // ------------------------------------------------------------ recent queries
  //
  // Local only. A search history is a record of what somebody was curious
  // about; it belongs in their browser, not in a table we own. The server-side
  // log records queries for relevance work and is separate and aggregate.
  var RECENT_KEY = 'lmx.search.recent';
  function recent() {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY)) || []; } catch (e) { return []; }
  }
  function remember(q) {
    if (!q || q.length < 2) return;
    var list = recent().filter(function (x) { return x !== q; });
    list.unshift(q);
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 8))); } catch (e) {}
  }
  function forget(q) {
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(recent().filter(function (x) { return x !== q; }))); } catch (e) {}
  }

  /*
   * The forum's own tag list, fetched same-origin.
   *
   * `app.store.all('tags')` is the obvious source and is NOT usable here:
   * FLARUM_BASE_URL points at a different host to the one being served, so the
   * SPA's own `/api/tags` refresh is cross-origin and answers 405 (measured —
   * it is the red banner at the bottom of every screenshot). A relative fetch
   * from this file is same-origin and answers 200 with 53 tags. Cached for the
   * session; a failure just means the landing state has one fewer section.
   */
  var tagsPromise = null;
  function allTags() {
    if (!tagsPromise) {
      tagsPromise = get('/api/tags', {}).then(function (d) {
        return (d.data || []).map(function (t) {
          var a = t.attributes || {};
          return { name: a.name, slug: a.slug, color: a.color, count: a.discussionCount || 0, isPrefix: /^p-/.test(a.slug || '') };
        }).sort(function (a, b) { return b.count - a.count; });
      }).catch(function () { return []; });
    }
    return tagsPromise;
  }

  // ------------------------------------------------------------------ helpers

  /*
   * i18n.
   *
   * This file is injected into <head> and runs before Flarum boots, so the
   * translator cannot be captured at eval time — every call resolves it fresh.
   * `window.lmxI18n.t` records a miss so the i18n gate can fail the deploy on a
   * raw key reaching the screen, while the English fallback keeps this surface
   * readable if looksmax-i18n is not loaded at all.
   */
  var NS = 'local-looksmax-search.';
  // ALWAYS returns a string. Flarum's translator returns an ARRAY for any
  // translation carrying rich/ICU parts, and so does lmxI18n — an array handed
  // to el() reaches appendChild() and throws "Argument 1 does not implement
  // interface Node". The tell is the error text itself: the operator saw
  // "la busqueda fallo: ,Node.appendChild..." and that leading comma is an
  // array being coerced to a string. The fallback path below already guarded
  // for this; the lmxI18n path did not, so the bug only appeared once Spanish
  // became the default locale and rich strings started coming back.
  function flat(v) {
    if (v == null) return '';
    if (typeof v === 'string') return v;
    if (Array.isArray(v)) return v.map(flat).join('');
    if (typeof v.join === 'function') return v.join('');
    return String(v);
  }

  function tr(key, params, fallback) {
    var k = NS + key;
    try {
      if (window.lmxI18n) return flat(window.lmxI18n.t(k, params || {}, fallback));
      var s = window.flarum.core.app.translator.trans(k, params || {});
      if (typeof s === 'string' && s !== k) return s;
      if (s && (Array.isArray(s) || typeof s.join === 'function')) return flat(s);
    } catch (e) {}
    return fallback == null ? k : fallback;
  }

  // "hace 3 horas", not "3h". Unix SECONDS in, so scale to ms for Intl.
  function timeAgo(unix) {
    if (!unix) return '';
    try { if (window.lmxI18n) return window.lmxI18n.rel(unix * 1000); } catch (e) {}
    var s = Math.max(0, Date.now() / 1000 - unix);
    var u = [[31536000, 'y'], [2592000, 'mo'], [604800, 'w'], [86400, 'd'], [3600, 'h'], [60, 'm']];
    for (var i = 0; i < u.length; i++) {
      if (s >= u[i][0]) return Math.floor(s / u[i][0]) + u[i][1];
    }
    return 'now';
  }

  // An absolute, unambiguous date for the `title` of a relative one.
  function absDate(unix) {
    if (!unix) return '';
    try {
      var loc = (document.documentElement.lang || 'es');
      return new Date(unix * 1000).toLocaleString(loc, { dateStyle: 'long', timeStyle: 'short' });
    } catch (e) { return new Date(unix * 1000).toISOString().slice(0, 16).replace('T', ' '); }
  }

  function ymd(unix) {
    if (!unix) return '';
    var d = new Date(unix * 1000);
    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
  }

  // "12.4K" / "12,4 mil". The k/M suffixes below are English; Intl's are not.
  function num(n) {
    var x = Number(n);
    if (!isFinite(x)) x = 0;
    try { if (window.lmxI18n) return window.lmxI18n.compact(x); } catch (e) {}
    if (x >= 1000000) return (x / 1000000).toFixed(1).replace('.0', '') + 'M';
    if (x >= 1000) return (x / 1000).toFixed(1).replace('.0', '') + 'k';
    return String(x || 0);
  }

  // Exact and grouped: "12,431" / "12.431". For a figure that must not round.
  function nnum(n) {
    var x = Number(n);
    if (!isFinite(x)) x = 0;
    try { if (window.lmxI18n) return window.lmxI18n.num(x); } catch (e) {}
    return String(x);
  }

  /*
   * Icons.
   *
   * Drawn as stroked geometry on a 24-unit grid, in the Lucide/Phosphor
   * convention the rest of the forum uses (2px stroke, round caps and joins).
   * Three rules, each of which fixes something that was wrong here before:
   *
   *  - Real geometry, not invented blobs. The first version of this function
   *    had a filled speech bubble standing in for a magnifying glass, which is
   *    not "a search icon drawn simply", it is the wrong icon.
   *  - `preserveAspectRatio` plus explicit width/height plus a locked CSS
   *    aspect-ratio, so the glyph CANNOT be stretched by whatever flex or grid
   *    context it lands in. A squashed magnifying glass is exactly what this
   *    prevents.
   *  - No network. Iconify's web component fetches its data from a CDN, which
   *    is unreachable from this box; anything drawn that way renders as a
   *    distorted placeholder or not at all. These are inline and always work.
   */
  var ICONS = {
    // circle + handle, the handle continuing the circle's 45° tangent
    search: 'M11 4a7 7 0 100 14 7 7 0 000-14M16.2 16.2 21 21',
    discussion: 'M4 6a2 2 0 012-2h12a2 2 0 012 2v8a2 2 0 01-2 2H9l-5 4z',
    post: 'M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2M9 9h6M9 13h6M9 17h3',
    user: 'M12 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7M5 20a7 7 0 0114 0',
    tag: 'M4 4h6.6a2 2 0 011.4.6l7.4 7.4a2 2 0 010 2.8l-5.6 5.6a2 2 0 01-2.8 0L3.6 13a2 2 0 01-.6-1.4V5a1 1 0 011-1M7.5 7.5h.01',
    clock: 'M12 4a8 8 0 100 16 8 8 0 000-16M12 8v4l3 2',
    filter: 'M3 5h18l-7 8v6l-4 2v-8z',
    close: 'M6 6l12 12M18 6 6 18',
    calendar: 'M5 6h14a1 1 0 011 1v12a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1M8 3v4M16 3v4M4 11h16',
    reply: 'M9 7 4 12l5 5M4 12h9a7 7 0 017 7',
    eye: 'M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6S2 12 2 12M12 9.5a2.5 2.5 0 100 5 2.5 2.5 0 000-5',
    heart: 'M12 20S3.5 14.6 3.5 9.2A4.7 4.7 0 0112 6.6a4.7 4.7 0 018.5 2.6C20.5 14.6 12 20 12 20',
    bookmark: 'M6 4h12a1 1 0 011 1v15l-7-4-7 4V5a1 1 0 011-1',
    trend: 'M3 17 9.5 10.5l3.5 3.5L21 6M21 6h-5M21 6v5',
    arrow: 'M5 12h13M13 6l6 6-6 6',
    back: 'M19 12H6M11 6l-6 6 6 6',
    sparkle: 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z',
    // three tracks with handles — the conventional "advanced / more controls"
    sliders: 'M4 7h9M17 7h3M4 12h3M11 12h9M4 17h13M20 17h0M15 5v4M9 10v4M18 15v4',
    check: 'M5 12.5 9.5 17 19 7',
  };

  function icon(name, cls) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('width', '16');
    svg.setAttribute('height', '16');
    // Without this an ancestor with a non-square box stretches the drawing.
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.9');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('class', 'lmx-ico lmx-ico-' + name + (cls ? ' ' + cls : ''));
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    p.setAttribute('d', ICONS[name] || ICONS.discussion);
    svg.appendChild(p);
    return svg;
  }

  // ------------------------------------------------------------------ avatars
  //
  // `hit.authorAvatarUrl` does not exist on the search API yet — only USER hits
  // carry `avatarUrl` (Engine::shapeUser). Requested from that lane; until it
  // lands, every discussion and post row draws the same generated disc Flarum
  // itself draws for an avatarless account, so the layout is the one the real
  // photo will drop into rather than one that has to be redesigned around it.
  //
  // The hue is a stable hash of the name, so the same person is the same colour
  // on every row of every search. Lightness is pinned high and the letter is
  // --ink-on-light, which is the pairing looksmax-theme already measured at
  // 10.6:1 — core's own white-on-pastel is 1.76:1 and unreadable.
  function hueOf(name) {
    var h = 0, s = String(name || '?');
    for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) % 360;
    return h;
  }

  function avatarFor(name, url, size) {
    var initial = String(name || '?').trim().charAt(0).toUpperCase() || '?';
    var disc = el('span', {
      class: 'lmx-av lmx-av--gen', 'aria-hidden': 'true',
      css: { '--av-h': hueOf(name), '--av-size': (size || 28) + 'px' },
    }, [initial]);
    if (!url) return disc;
    var img = el('img', {
      class: 'lmx-av lmx-av--img', src: url, alt: '', loading: 'lazy', decoding: 'async',
      css: { '--av-size': (size || 28) + 'px' },
    });
    // A 404 avatar is a defect that looks like a CSS problem. Swap to the
    // generated disc rather than leaving a broken-image glyph in the row.
    img.addEventListener('error', function () {
      if (img.parentNode) img.parentNode.replaceChild(disc, img);
    });
    return img;
  }

  // ---------------------------------------------------------------- tag chips

  // A colour arrives from `tags.color` in the database. setProperty rejects an
  // unparseable value on its own, but validating first means a junk value falls
  // back to the neutral token instead of half-applying.
  var COLOUR_OK = /^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$|^[a-z]{3,20}$/i;

  function tagChip(t, opts) {
    var o = opts || {};
    var kids = [el('i', { class: 'lmx-tag-dot', 'aria-hidden': 'true' }), el('span', { class: 'lmx-tag-n' }, [t.name])];
    if (t.count != null) kids.push(el('span', { class: 'lmx-tag-c' }, [num(t.count)]));
    var attrs = {
      class: 'lmx-tag' + (t.isPrefix ? ' lmx-tag--prefix' : ''),
      css: { '--cat': COLOUR_OK.test(String(t.color || '')) ? t.color : null },
    };
    if (o.onclick) { attrs.type = 'button'; attrs.onclick = o.onclick; attrs.title = o.title || null; return el('button', attrs, kids); }
    if (o.href) { attrs.href = o.href; return el('a', attrs, kids); }
    return el('span', attrs, kids);
  }

  // --------------------------------------------------------------- result row

  var TYPE_ICON = { discussion: 'discussion', post: 'post', user: 'user', tag: 'tag' };

  function metaBit(iconName, text, title) {
    return el('span', { class: 'lmx-m', title: title || null }, [icon(iconName), el('span', {}, [text])]);
  }

  function resultRow(hit, ctx) {
    var terms = (ctx && ctx.terms) || [];
    var kind = hit.type;
    var isPost = kind === 'post';

    // ---- title
    var titleEl = el('span', { class: 'lmx-r-title' });
    titleEl.appendChild(hlNode(hit.titleHtml || hit.title || hit.displayName || hit.name || '', terms));

    var badges = [];
    if (hit.isSticky) badges.push(el('span', { class: 'lmx-pin' }, [tr('forum.hit.pinned', {}, 'pinned')]));
    if (hit.isLocked) badges.push(el('span', { class: 'lmx-pin lmx-pin--lock' }, [tr('forum.hit.locked', {}, 'locked')]));

    // A discussion and a post are DIFFERENT THINGS and used to be visually
    // identical. The kind label says which, in words, in the reader's language;
    // the class drives a different rail colour and icon; and a post shows the
    // thread it is a reply inside, because a reply with no thread is unplaceable.
    var kindLabel = el('span', { class: 'lmx-r-kind' }, [
      icon(TYPE_ICON[kind] || 'discussion'),
      el('span', {}, [tr('forum.kind.' + kind, { n: hit.number || 0 },
        { discussion: 'Thread', post: 'Reply', user: 'Person', tag: 'Forum' }[kind] || kind)]),
    ]);

    var head = el('div', { class: 'lmx-r-head' }, [titleEl].concat(badges));

    var body = [el('div', { class: 'lmx-r-top' }, [kindLabel]), head];

    // ---- snippet
    if (hit.excerptHtml) {
      var ex = el('div', { class: 'lmx-r-snip' });
      ex.appendChild(hlNode(hit.excerptHtml, terms));
      body.push(ex);
    }
    // A thread that matched by TITLE and also has a matching post shows the post
    // as evidence. Without it the reader cannot tell why a thread with an
    // unrelated-looking title is in their results.
    if (hit.matchedPost && hit.matchedPost.excerptHtml) {
      var mp = el('div', { class: 'lmx-r-inpost' }, [
        el('span', { class: 'lmx-r-inpost-label' }, [
          icon('reply'),
          el('span', {}, [tr('forum.hit.matched_in_reply', { author: hit.matchedPost.author || '?' }, 'matched in reply by ' + (hit.matchedPost.author || '?') + ':')]),
        ]),
      ]);
      mp.appendChild(hlNode(hit.matchedPost.excerptHtml, terms));
      body.push(mp);
    }

    // ---- meta
    var meta = [];
    (hit.tags || []).slice(0, 3).forEach(function (t) { meta.push(tagChip(t)); });
    if (kind === 'discussion' || isPost) {
      if (hit.author) {
        meta.push(el('span', { class: 'lmx-by' }, [
          avatarFor(hit.author, hit.authorAvatarUrl, 18),
          el('span', {}, [hit.author]),
        ]));
      }
      if (hit.createdAt) meta.push(el('span', { class: 'lmx-m lmx-m--when', title: absDate(hit.createdAt) }, [icon('clock'), el('span', {}, [timeAgo(hit.createdAt)])]));
      if (hit.commentCount) meta.push(metaBit('reply', tr('forum.hit.replies', { count: hit.commentCount, n: num(hit.commentCount) }, num(hit.commentCount) + ' replies')));
      if (hit.reactions) meta.push(el('span', { class: 'lmx-m lmx-react' }, [icon('heart'), el('span', {}, [num(hit.reactions)])]));
      if (hit.views) meta.push(metaBit('eye', tr('forum.hit.views', { count: hit.views, n: num(hit.views) }, num(hit.views) + ' views')));
    } else if (kind === 'user') {
      if (hit.postsCount) meta.push(metaBit('post', tr('forum.hit.posts', { count: hit.postsCount, n: num(hit.postsCount) }, num(hit.postsCount) + ' posts')));
      (hit.groups || []).forEach(function (g) { meta.push(el('span', { class: 'lmx-grp' }, [g])); });
    } else if (kind === 'tag') {
      meta.push(metaBit('discussion', tr('forum.hit.threads', { count: hit.discussionCount || 0, n: num(hit.discussionCount) }, num(hit.discussionCount) + ' threads')));
    }
    if (meta.length) body.push(el('div', { class: 'lmx-r-meta' }, meta));

    // ---- avatar column
    var avName = kind === 'user' ? (hit.displayName || hit.username) : hit.author;
    var avUrl = kind === 'user' ? hit.avatarUrl : hit.authorAvatarUrl;
    var lead = kind === 'tag'
      ? el('span', { class: 'lmx-av lmx-av--tag', 'aria-hidden': 'true', css: { '--cat': COLOUR_OK.test(String(hit.color || '')) ? hit.color : null } }, [icon('tag')])
      : avatarFor(avName, avUrl, 34);

    var a = el('a', {
      class: 'lmx-r lmx-r--' + kind, href: hit.url, 'data-id': hit.id, 'data-kind': kind,
    }, [el('span', { class: 'lmx-r-lead' }, [lead]), el('div', { class: 'lmx-r-b' }, body)]);

    a.addEventListener('click', function () {
      if (ctx && ctx.logId) {
        // Fire-and-forget. A click that fails to record must never delay or
        // block the navigation the reader actually asked for.
        try {
          post(API + '/click', {
            logId: ctx.logId, resultId: hit.id, resultType: hit.type,
            position: ctx.index(a),
          });
        } catch (e) {}
      }
      remember(ctx && ctx.query);
    });
    return a;
  }

  /* Skeletons that are the SHAPE of a result row, so nothing reflows when the
   * real rows land. A centred spinner is what used to be here in spirit — the
   * page simply had no loading state at all, and the list jumped. */
  function skeletonRows(n) {
    var wrap = el('div', { class: 'lmx-skels', 'aria-hidden': 'true' });
    for (var i = 0; i < (n || 6); i++) {
      wrap.appendChild(el('div', { class: 'lmx-skel-row' }, [
        el('div', { class: 'lmx-sk lmx-sk--av' }),
        el('div', { class: 'lmx-sk-b' }, [
          el('div', { class: 'lmx-sk lmx-sk--kind' }),
          el('div', { class: 'lmx-sk lmx-sk--title' }),
          el('div', { class: 'lmx-sk lmx-sk--line' }),
          el('div', { class: 'lmx-sk lmx-sk--line lmx-sk--short' }),
          el('div', { class: 'lmx-sk lmx-sk--meta' }),
        ]),
      ]));
    }
    return wrap;
  }

  function errorPanel(e, retry) {
    var status = e && e.status ? e.status : null;
    return el('div', { class: 'lmx-error', role: 'alert' }, [
      el('div', { class: 'lmx-error-h' }, [icon('close'), el('strong', {}, [tr('forum.error.heading', {}, 'Search failed')])]),
      el('p', { class: 'lmx-error-m' }, [
        tr('forum.results.failed', { error: (e && e.message) || '?' }, 'Search failed: ' + ((e && e.message) || '?')),
      ]),
      status ? el('p', { class: 'lmx-error-d' }, [tr('forum.error.status', { status: status }, 'HTTP ' + status)]) : null,
      e && e.body ? el('pre', { class: 'lmx-error-body' }, [e.body]) : null,
      retry ? el('button', { class: 'lmx-btn lmx-btn--primary', type: 'button', onclick: retry }, [tr('forum.error.retry', {}, 'Try again')]) : null,
    ]);
  }

  // ====================================================================== surface
  //
  // ONE component with two presentations:
  //
  //   dropdown — anchored under the header field, on a pointer-sized screen.
  //              The header field keeps focus and becomes the combobox; the
  //              popup is its listbox. This is what "the dropdown of results
  //              under the header field" means, and it is what the previous
  //              revision did NOT do: focusing the field opened a modal and
  //              blurred the field, which is a different interaction wearing a
  //              dropdown's clothes.
  //   sheet    — a full-screen surface with its OWN input, on a phone. Measured
  //              at 390px: the header input is inside `.App-drawer`, which is
  //              transformed off-screen (rect x = -212), so there is nothing to
  //              anchor to and nothing to type in until the drawer is open.
  //
  // Both are appended to <body>. That is the fix for the stacking bug, and it
  // is a structural fix rather than a bigger number: `.Search-results` had
  // z-index 1030 and still hit-tested to `.Navigation-drawer`, because its
  // ancestor `.App-drawer` has a `transform`, and a transform creates a
  // stacking context that clamps every descendant into the ancestor's own
  // painting order no matter how large their z-index is. A popup that is a
  // child of <body> has no such ancestor and cannot be clamped.

  var SURF = null;

  function buildSurface() {
    var list = el('div', { class: 'lmx-dd-list', role: 'listbox', id: 'lmx-dd-list' });
    var status = el('div', { class: 'lmx-dd-status', role: 'status', 'aria-live': 'polite' });
    var sheetInput = el('input', {
      class: 'lmx-dd-input', type: 'search', autocomplete: 'off', spellcheck: 'false',
      enterkeyhint: 'search', autocapitalize: 'none',
      placeholder: tr('forum.palette.placeholder', {}, 'Search threads, posts, people…'),
      'aria-label': tr('forum.palette.aria_label', {}, 'Search'),
      'aria-controls': 'lmx-dd-list', 'aria-expanded': 'true', 'aria-autocomplete': 'list', role: 'combobox',
    });
    var head = el('div', { class: 'lmx-dd-head' }, [
      el('button', {
        class: 'lmx-dd-back', type: 'button', 'aria-label': tr('forum.surface.close', {}, 'Close search'),
        onclick: function () { closeSurface(); },
      }, [icon('back')]),
      el('span', { class: 'lmx-dd-inputwrap' }, [icon('search'), sheetInput]),
    ]);
    var foot = el('div', { class: 'lmx-dd-foot' }, [
      el('span', { class: 'lmx-kbd-hint' }, [
        el('kbd', {}, ['↑']), el('kbd', {}, ['↓']), el('span', {}, [tr('forum.surface.hint_move', {}, 'to move')]),
        el('kbd', {}, ['↵']), el('span', {}, [tr('forum.surface.hint_open', {}, 'to open')]),
        el('kbd', {}, ['esc']), el('span', {}, [tr('forum.surface.hint_close', {}, 'to close')]),
      ]),
      status,
    ]);
    var box = el('div', { class: 'lmx-dd-box' }, [head, list, foot]);
    var root = el('div', { class: 'lmx-dd', id: 'lmx-dd' }, [box]);

    // Keep focus where it is. Without this, mousedown inside the popup blurs
    // the header input, Flarum's own Search component tears its state down and
    // the click never lands on the row the reader aimed at.
    root.addEventListener('mousedown', function (e) {
      if (e.target === root) { closeSurface(); return; }
      if (SURF.mode === 'dropdown') e.preventDefault();
    });

    sheetInput.addEventListener('input', function () { SURF.query = sheetInput.value; run(); });
    sheetInput.addEventListener('keydown', onSurfaceKey);

    document.body.appendChild(root);
    SURF = {
      root: root, box: box, list: list, status: status, head: head, input: sheetInput,
      mode: 'dropdown', items: [], sel: -1, query: '', logId: null, anchor: null, restore: null, seq: 0,
    };
    return SURF;
  }

  function surfaceInput() {
    return SURF.mode === 'sheet' ? SURF.input : (SURF.anchor || SURF.input);
  }

  function setSel(i) {
    SURF.sel = i;
    SURF.items.forEach(function (n, k) {
      var on = k === i;
      n.classList.toggle('lmx-sel', on);
      n.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) {
        var r = n.getBoundingClientRect(), p = SURF.list.getBoundingClientRect();
        if (r.bottom > p.bottom) SURF.list.scrollTop += r.bottom - p.bottom;
        if (r.top < p.top) SURF.list.scrollTop -= p.top - r.top;
      }
    });
    var inp = surfaceInput();
    if (inp) {
      if (i >= 0 && SURF.items[i]) inp.setAttribute('aria-activedescendant', SURF.items[i].id);
      else inp.removeAttribute('aria-activedescendant');
    }
  }

  function pushItem(node, i) {
    node.id = 'lmx-dd-o-' + i;
    node.setAttribute('role', 'option');
    node.setAttribute('aria-selected', 'false');
    node.addEventListener('mouseenter', function () { setSel(SURF.items.indexOf(node)); });
    SURF.items.push(node);
    return node;
  }

  function renderSurface(data) {
    SURF.list.innerHTML = '';
    SURF.items = [];
    SURF.sel = -1;
    var q = (SURF.query || '').trim();
    var i = 0;

    function group(labelKey, fallback, iconName) {
      SURF.list.appendChild(el('div', { class: 'lmx-dd-group' }, [
        iconName ? icon(iconName) : null, el('span', {}, [tr(labelKey, {}, fallback)]),
      ]));
    }

    // ---- landing (nothing typed yet)
    if (q.length < CFG.minChars) {
      var rec = recent();
      if (rec.length) {
        group('forum.palette.recent', 'Recent', 'clock');
        rec.forEach(function (r) {
          var row = el('div', { class: 'lmx-r lmx-r--q' }, [
            el('span', { class: 'lmx-r-lead' }, [icon('clock', 'lmx-ico-lead')]),
            el('div', { class: 'lmx-r-b' }, [el('span', { class: 'lmx-r-title' }, [r])]),
          ]);
          row.addEventListener('click', function () { setQuery(r); run(); });
          var x = el('button', {
            class: 'lmx-r-x', type: 'button', 'aria-label': tr('forum.surface.forget', {}, 'Remove from history'),
            onclick: function (e) { e.stopPropagation(); forget(r); renderSurface(); },
          }, [icon('close')]);
          row.appendChild(x);
          SURF.list.appendChild(pushItem(row, i++));
        });
      }
      allTags().then(function (tags) {
        if ((SURF.query || '').trim().length >= CFG.minChars || !SURF.open) return;
        var top = tags.filter(function (t) { return !t.isPrefix && t.count > 0; }).slice(0, 8);
        if (!top.length || $('.lmx-dd-tags', SURF.list)) return;
        SURF.list.appendChild(el('div', { class: 'lmx-dd-group' }, [icon('trend'), el('span', {}, [tr('forum.landing.popular_tags', {}, 'Busiest forums')])]));
        SURF.list.appendChild(el('div', { class: 'lmx-dd-tags' }, top.map(function (t) {
          return tagChip(t, { onclick: function () { setQuery('tag:"' + t.name + '"'); run(); } });
        })));
      });
      if (!rec.length) {
        SURF.list.appendChild(el('div', { class: 'lmx-dd-hello' }, [
          icon('sparkle'),
          el('p', {}, [tr('forum.landing.hello', {}, 'Search threads, posts and people.')]),
          el('p', { class: 'lmx-dd-hello-ops' }, [tr('forum.landing.operators', {}, 'Try tag:looksmaxing · by:name · after:2023')]),
        ]));
      }
      // The advanced surface has to be reachable in ONE click from the header
      // field with nothing typed, which means it belongs in the landing state
      // and not only under a set of results.
      SURF.list.appendChild(pushItem(advancedCta(), i++));
      SURF.status.textContent = '';
      position();
      return;
    }

    var groups = (data && data.groups) || [];
    var terms = termsFrom(data && data.parsed, q);
    var total = 0;
    groups.forEach(function (g) { total += (g.hits || []).length; });

    if (!total) {
      SURF.list.appendChild(el('div', { class: 'lmx-dd-empty' }, [
        icon('search'),
        el('p', {}, [tr('forum.palette.no_matches', { query: q }, 'No matches for “' + q + '”')]),
      ]));
    } else {
      var ctx = {
        logId: data && data.logId, query: q, terms: terms,
        index: function (node) { return SURF.items.indexOf(node); },
      };
      groups.forEach(function (g) {
        if (!(g.hits || []).length) return;
        group('forum.groups.' + g.kind, { discussions: 'Threads', posts: 'Posts', users: 'People', tags: 'Forums' }[g.kind] || g.kind, TYPE_ICON[g.kind === 'discussions' ? 'discussion' : g.kind === 'posts' ? 'post' : g.kind === 'users' ? 'user' : 'tag']);
        g.hits.forEach(function (h) { SURF.list.appendChild(pushItem(resultRow(h, ctx), i++)); });
      });
    }

    var cta = el('button', { class: 'lmx-dd-cta', type: 'button', onclick: function () { go(SURF.query); } }, [
      icon('arrow'),
      el('span', {}, [tr('forum.palette.see_all', { query: q }, 'See all results for “' + q + '”')]),
    ]);
    SURF.list.appendChild(pushItem(cta, i++));
    SURF.list.appendChild(pushItem(advancedCta(), i++));
    if (total) setSel(0);

    SURF.status.textContent = (data && data.degraded)
      ? tr('forum.palette.unavailable', {}, 'search engine unavailable')
      : tr('forum.surface.count', { count: total, n: nnum(total) }, total + ' suggestions');
    position();
  }

  /* The obvious way in. `adv=1` is read by mountSearchPage and opens the filter
   * rail on the widths where it is a sheet rather than a permanent column, so
   * the click lands on the filters and not merely on a page that has some. */
  function advancedCta() {
    return el('button', {
      class: 'lmx-dd-cta lmx-dd-cta--adv', type: 'button',
      onclick: function () {
        var q = (SURF.query || '').trim();
        remember(q);
        closeSurface(true);
        searchMode = true;
        location.href = '/search?adv=1' + (q ? '&q=' + encodeURIComponent(q) : '');
      },
    }, [
      icon('sliders'),
      el('span', { class: 'lmx-dd-cta-t' }, [
        el('strong', {}, [tr('forum.advanced.title', {}, 'Advanced search')]),
        el('span', {}, [tr('forum.advanced.sub', {}, 'filter by forum, author, date, replies…')]),
      ]),
    ]);
  }

  var run = debounce(function () {
    var q = (SURF.query || '').trim();
    if (q.length < CFG.minChars) { SURF.box.classList.remove('lmx-loading'); renderSurface(null); return; }
    var seq = ++SURF.seq;
    SURF.box.classList.add('lmx-loading');
    get(API + '/suggest', { q: q, limit: 5 })
      .then(function (d) {
        if (seq !== SURF.seq) return; // a newer keystroke won
        SURF.box.classList.remove('lmx-loading');
        renderSurface(d);
      })
      .catch(function (e) {
        if (seq !== SURF.seq) return;
        SURF.box.classList.remove('lmx-loading');
        SURF.list.innerHTML = '';
        SURF.items = [];
        SURF.list.appendChild(errorPanel(e, function () { run(); }));
        SURF.status.textContent = tr('forum.palette.failed', { error: e.message }, 'search failed: ' + e.message);
        position();
      });
  }, CFG.debounceMs);

  function setQuery(v) {
    SURF.query = v;
    var inp = surfaceInput();
    if (inp) { inp.value = v; try { inp.setSelectionRange(v.length, v.length); } catch (e) {} }
    if (SURF.mode === 'dropdown' && SURF.anchor) SURF.anchor.value = v;
  }

  /* Anchor the dropdown to the header field's CURRENT rect. Re-run on scroll,
   * resize and after each render, because the field moves (the header is
   * sticky and the field grows on focus). */
  function position() {
    if (!SURF || !SURF.open) return;
    if (SURF.mode === 'sheet') { SURF.root.style.cssText = ''; return; }
    var a = SURF.anchor;
    if (!a) return;
    var r = a.getBoundingClientRect();
    var vw = document.documentElement.clientWidth;
    var w = Math.min(Math.max(r.width, 460), vw - 16);
    var left = Math.min(Math.max(8, r.right - w), vw - w - 8);
    SURF.root.style.left = Math.round(left) + 'px';
    SURF.root.style.top = Math.round(r.bottom + 8) + 'px';
    SURF.root.style.width = Math.round(w) + 'px';
    // Two caps, both needed. The first keeps the popup inside the viewport;
    // the second keeps it a DROPDOWN. Fifteen suggestions is 925px of rows,
    // which filled a 1000px viewport edge to edge and read as a page, not as a
    // popup — measured on the first pass.
    var fits = Math.round(window.innerHeight - r.bottom - 24);
    SURF.box.style.maxHeight = Math.max(220, Math.min(fits, Math.round(window.innerHeight * 0.68))) + 'px';
  }

  function onSurfaceKey(e) {
    if (!SURF || !SURF.open) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setSel(SURF.items.length ? (SURF.sel + 1) % SURF.items.length : -1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setSel(SURF.items.length ? (SURF.sel <= 0 ? SURF.items.length - 1 : SURF.sel - 1) : -1);
    } else if (e.key === 'Home' && SURF.items.length) { e.preventDefault(); setSel(0); }
    else if (e.key === 'End' && SURF.items.length) { e.preventDefault(); setSel(SURF.items.length - 1); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      e.stopPropagation();
      var n = SURF.items[SURF.sel];
      if (n && n.tagName === 'A') { remember(SURF.query); n.click(); }
      else if (n) n.click();
      else go(SURF.query);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      closeSurface();
    } else if (e.key === 'Tab') {
      closeSurface(true);
    }
  }

  function openSurface(prefill, anchor) {
    if (!SURF) buildSurface();
    var mobile = isMobile();
    SURF.mode = mobile ? 'sheet' : 'dropdown';
    SURF.anchor = mobile ? null : (anchor || $('.Search-input input') || null);
    if (!mobile && !SURF.anchor) SURF.mode = 'sheet'; // no field to hang off
    SURF.open = true;
    SURF.restore = document.activeElement;
    SURF.root.className = 'lmx-dd lmx-dd--' + SURF.mode + ' lmx-open';
    document.documentElement.classList.add('lmx-dd-on');
    if (SURF.mode === 'sheet') document.documentElement.classList.add('lmx-noscroll');

    var inp = surfaceInput();
    SURF.query = prefill != null ? prefill : (inp ? inp.value : '');
    if (SURF.mode === 'sheet') {
      SURF.input.value = SURF.query;
      SURF.input.focus();
      try { SURF.input.setSelectionRange(SURF.query.length, SURF.query.length); } catch (e) {}
    } else {
      SURF.anchor.setAttribute('role', 'combobox');
      SURF.anchor.setAttribute('aria-expanded', 'true');
      SURF.anchor.setAttribute('aria-controls', 'lmx-dd-list');
      SURF.anchor.setAttribute('aria-autocomplete', 'list');
      SURF.anchor.setAttribute('aria-haspopup', 'listbox');
      if (document.activeElement !== SURF.anchor) SURF.anchor.focus();
    }
    renderSurface(null);
    if ((SURF.query || '').trim().length >= CFG.minChars) run();
    position();
  }

  function closeSurface(keepFocus) {
    if (!SURF || !SURF.open) return;
    SURF.open = false;
    SURF.root.classList.remove('lmx-open');
    document.documentElement.classList.remove('lmx-dd-on', 'lmx-noscroll');
    if (SURF.anchor) {
      SURF.anchor.setAttribute('aria-expanded', 'false');
      SURF.anchor.removeAttribute('aria-activedescendant');
    }
    // Escape must put focus back where it came from, or a keyboard reader is
    // stranded on <body> with no way back into the page.
    if (!keepFocus) {
      var back = SURF.anchor || SURF.restore;
      try { if (back && back.focus) back.focus(); } catch (e) {}
    }
    SURF.restore = null;
  }

  function surfaceOpen() { return !!(SURF && SURF.open); }

  function go(q) {
    q = (q || '').trim();
    if (!q) return;
    remember(q);
    closeSurface(true);
    searchMode = true;
    location.href = '/search?q=' + encodeURIComponent(q);
  }

  window.addEventListener('resize', function () {
    if (!surfaceOpen()) return;
    // Crossing the breakpoint has to switch presentation, not stretch one.
    var wantSheet = isMobile();
    if ((SURF.mode === 'sheet') !== wantSheet) { var q = SURF.query; closeSurface(true); openSurface(q); }
    else position();
  });
  window.addEventListener('scroll', function () { if (surfaceOpen() && SURF.mode === 'dropdown') position(); }, true);
  document.addEventListener('mousedown', function (e) {
    if (!surfaceOpen() || SURF.mode !== 'dropdown') return;
    if (SURF.root.contains(e.target) || (SURF.anchor && SURF.anchor.contains(e.target))) return;
    closeSurface(true);
  });

  // ================================================================ results page

  var PAGE = { q: '', type: 'all', offset: 0, limit: 20, data: null, logId: null, root: null, adv: false };

  var SORTS = [
    ['', 'relevance'], ['new', 'new'], ['old', 'old'],
    ['top', 'top'], ['replies', 'replies'],
    ['views', 'views'], ['active', 'active'],
  ];
  var TYPES = [['all', 'all'], ['discussions', 'discussions'], ['posts', 'posts'], ['users', 'users'], ['tags', 'tags']];

  /*
   * Query-string surgery.
   *
   * The canonical state of a search is the query STRING — `mewing tag:"Best of
   * the Best" after:2023-01-01 sort:new` — because the server's parser is the
   * only thing that decides what a token means, and duplicating that decision
   * in the UI is how a filter shown as a chip and a filter actually applied
   * drift apart.
   *
   * So every control edits the string. `parsed.tags` comes back LOWERCASED
   * ("best of the best" for `tag:"Best of the Best"`), which is why removal
   * cannot be a literal `replace(token, '')` — that was the previous code, and
   * it silently failed to remove any filter whose value had a capital or a
   * space, leaving a chip that did nothing when clicked.
   */
  function esc(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  function dropOp(q, ops, value) {
    var alts = (Array.isArray(ops) ? ops : [ops]).map(esc).join('|');
    var re = value == null
      ? new RegExp('(^|\\s)-?(?:' + alts + '):("[^"]*"|\\S*)', 'gi')
      : new RegExp('(^|\\s)-?(?:' + alts + '):(?:"' + esc(value) + '"|' + esc(value) + ')(?=\\s|$)', 'gi');
    return String(q).replace(re, ' ').replace(/\s{2,}/g, ' ').trim();
  }

  function setOp(q, op, value, aliases) {
    var out = dropOp(q, aliases || [op]);
    if (value == null || value === '') return out;
    var v = /[\s"]/.test(String(value)) ? '"' + String(value).replace(/"/g, '') + '"' : value;
    return (out + ' ' + op + ':' + v).trim();
  }

  function addOp(q, op, value) {
    var v = /[\s"]/.test(String(value)) ? '"' + String(value).replace(/"/g, '') + '"' : value;
    var re = new RegExp('(^|\\s)' + esc(op) + ':(?:"' + esc(value) + '"|' + esc(value) + ')(?=\\s|$)', 'i');
    if (re.test(q)) return q;
    return (q + ' ' + op + ':' + v).trim();
  }

  function apply(q, opts) {
    PAGE.q = q;
    PAGE.offset = (opts && opts.offset) || 0;
    if (opts && opts.type) PAGE.type = opts.type;
    pushAndLoad(PAGE.root);
  }

  function urlFor() {
    return '/search?q=' + encodeURIComponent(PAGE.q)
      + (PAGE.type !== 'all' ? '&type=' + PAGE.type : '')
      + (PAGE.offset ? '&offset=' + PAGE.offset : '')
      + (PAGE.adv ? '&adv=1' : '');
  }

  // ------------------------------------------------------------- filter chips

  function chipsFor(parsed) {
    var p = parsed || {};
    var chips = [];
    function chip(label, remove, cls) {
      chips.push(el('button', {
        class: 'lmx-chip' + (cls ? ' ' + cls : ''), type: 'button',
        title: tr('forum.filters.remove', {}, 'Remove this filter'),
        'aria-label': tr('forum.filters.remove_named', { filter: label }, 'Remove filter: ' + label),
        onclick: function () { apply(remove()); },
      }, [el('span', { class: 'lmx-chip-t' }, [label]), icon('close', 'lmx-chip-x')]));
    }
    (p.tags || []).forEach(function (x) {
      chip(tr('forum.filters.tag', { value: x }, 'forum: ' + x), function () { return dropOp(PAGE.q, ['tag', 'in', 'forum', 'category'], x); }, 'lmx-chip--tag');
    });
    (p.prefixes || []).forEach(function (x) {
      chip(tr('forum.filters.prefix', { value: x }, 'prefix: ' + x), function () { return dropOp(PAGE.q, ['prefix'], x); });
    });
    (p.authors || []).forEach(function (x) {
      chip(tr('forum.filters.author', { value: x }, 'by: ' + x), function () { return dropOp(PAGE.q, ['by', 'author', 'from', 'user'], x); }, 'lmx-chip--by');
    });
    (p.langs || []).forEach(function (x) {
      chip(tr('forum.filters.lang', { value: x }, 'lang: ' + x), function () { return dropOp(PAGE.q, ['lang', 'language'], x); });
    });
    (p.flags || []).forEach(function (x) {
      chip(tr('forum.filters.flag', { value: x }, 'is: ' + x), function () { return dropOp(PAGE.q, ['is', 'has'], String(x).replace(/^!/, '')); });
    });
    if (p.after) {
      chip(tr('forum.filters.after', { value: ymd(p.after) }, 'after: ' + ymd(p.after)), function () { return dropOp(PAGE.q, ['after', 'since']); }, 'lmx-chip--date');
    }
    if (p.before) {
      chip(tr('forum.filters.before', { value: ymd(p.before) }, 'before: ' + ymd(p.before)), function () { return dropOp(PAGE.q, ['before', 'until']); }, 'lmx-chip--date');
    }
    (p.phrases || []).forEach(function (x) {
      chip(tr('forum.filters.phrase', { value: x }, '“' + x + '”'), function () {
        return String(PAGE.q).replace(new RegExp('"' + esc(x) + '"', 'gi'), ' ').replace(/\s{2,}/g, ' ').trim();
      }, 'lmx-chip--phrase');
    });
    (p.negatives || []).forEach(function (x) {
      chip(tr('forum.filters.negative', { value: x }, '−' + x), function () {
        return String(PAGE.q).replace(new RegExp('(^|\\s)-' + esc(x) + '(?=\\s|$)', 'gi'), ' ').replace(/\s{2,}/g, ' ').trim();
      }, 'lmx-chip--neg');
    });
    if (p.sort) {
      var sortName = tr('forum.sort.' + p.sort, {}, p.sort);
      chip(tr('forum.filters.sort', { value: sortName }, 'sort: ' + sortName), function () { return dropOp(PAGE.q, ['sort', 'order']); }, 'lmx-chip--sort');
    }
    if (PAGE.type !== 'all') {
      chip(tr('forum.filters.type', { value: tr('forum.types.' + PAGE.type, {}, PAGE.type) }, 'type: ' + PAGE.type),
        function () { PAGE.type = 'all'; return PAGE.q; }, 'lmx-chip--type');
    }
    if (chips.length > 1) {
      chips.push(el('button', {
        class: 'lmx-chip lmx-chip--clear', type: 'button',
        onclick: function () { PAGE.type = 'all'; apply(String(p.text || '').trim()); },
      }, [el('span', { class: 'lmx-chip-t' }, [tr('forum.filters.clear_all', {}, 'Clear all')])]));
    }
    return chips;
  }

  // ----------------------------------------------------------------- facet rail

  function facetPanel(d) {
    var p = d.parsed || {};
    var wrap = el('form', {
      class: 'lmx-facets', 'aria-label': tr('forum.filters.panel_label', {}, 'Refine results'),
      onsubmit: function (e) { e.preventDefault(); },
    });

    function section(titleKey, fallback, iconName, kids) {
      return el('section', { class: 'lmx-facet' }, [
        el('h3', {}, [icon(iconName), el('span', {}, [tr(titleKey, {}, fallback)])]),
      ].concat(kids));
    }

    // ---- date range
    var from = el('input', { class: 'lmx-date', type: 'date', value: p.after ? ymd(p.after) : null, 'aria-label': tr('forum.filters.date_from', {}, 'From') });
    var to = el('input', { class: 'lmx-date', type: 'date', value: p.before ? ymd(p.before) : null, 'aria-label': tr('forum.filters.date_to', {}, 'To') });
    function applyDates() {
      var q = setOp(PAGE.q, 'after', from.value || null, ['after', 'since']);
      q = setOp(q, 'before', to.value || null, ['before', 'until']);
      apply(q);
    }
    from.addEventListener('change', applyDates);
    to.addEventListener('change', applyDates);
    var quick = el('div', { class: 'lmx-quick' }, [
      ['1', 'day'], ['7', 'week'], ['30', 'month'], ['365', 'year'],
    ].map(function (r) {
      return el('button', {
        class: 'lmx-quick-b', type: 'button',
        onclick: function () {
          var t = new Date(Date.now() - Number(r[0]) * 86400000);
          var q = setOp(PAGE.q, 'after', t.getFullYear() + '-' + ('0' + (t.getMonth() + 1)).slice(-2) + '-' + ('0' + t.getDate()).slice(-2), ['after', 'since']);
          apply(setOp(q, 'before', null, ['before', 'until']));
        },
      }, [tr('forum.filters.last_' + r[1], {}, 'last ' + r[1])]);
    }));

    // ---- author box
    var byInput = el('input', {
      class: 'lmx-textfield', type: 'text', autocomplete: 'off',
      value: (p.authors || [])[0] || '', placeholder: tr('forum.filters.author_ph', {}, 'username'),
      'aria-label': tr('forum.facets.author', {}, 'Author'),
    });
    byInput.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      apply(setOp(PAGE.q, 'by', byInput.value.trim() || null, ['by', 'author', 'from', 'user']));
    });

    var saved = savedPanel();
    if (saved) wrap.appendChild(saved);

    wrap.appendChild(section('forum.filters.date', 'Date', 'calendar', [
      el('div', { class: 'lmx-dates' }, [from, el('span', { class: 'lmx-dash' }, ['–']), to]), quick,
    ]));

    // The author BOX and the author FACET are the same filter and were two
    // sections both headed "Author" on the first pass. The box is now the top
    // of that one section; if the engine returned no author facet (a query with
    // no hits) the box still gets a section of its own.
    var authorFacet = (d.facets || []).filter(function (f) { return f.operator === 'by' && (f.values || []).length; })[0];
    if (!authorFacet) wrap.appendChild(section('forum.facets.author', 'Author', 'user', [byInput]));

    /*
     * Advanced filters.
     *
     * Everything here maps onto an operator QueryParser already understands, so
     * a control and a hand-typed token are the same thing and cannot drift.
     * Each one was executed against the live engine before being exposed:
     *
     *   replies:>N  7,386 of 7,580 for `mewing replies:>20`   — applies
     *   views:>N    7,412                                      — applies
     *   length:>N   1,061 for `length:>500`                    — applies
     *   is:unanswered 7,130                                    — applies
     *   reactions:>0    0 of 7,580, and every hit in every
     *                   response carries reactions: 0          — NOT INDEXED
     *
     * The reactions control is therefore rendered ONLY when the current result
     * set proves the field carries data. Shipping a filter that can only ever
     * return an empty page is worse than not shipping it, and gating it on the
     * data means it appears by itself the day the backend indexes reactions.
     */
    function rangeOf(field) {
      var rs = (p.ranges && p.ranges[field]) || [];
      return rs.length ? rs[0].value : '';
    }
    function rangeRow(field, op, labelKey, fallback, quick) {
      var input = el('input', {
        class: 'lmx-textfield lmx-num', type: 'number', min: '0', inputmode: 'numeric',
        value: rangeOf(field) === '' ? null : rangeOf(field),
        placeholder: tr('forum.filters.any', {}, 'any'),
        'aria-label': tr(labelKey, {}, fallback),
      });
      function commit() {
        var v = String(input.value || '').trim();
        apply(setOp(PAGE.q, field, v === '' ? null : op + v, [field].concat(op === '>' ? [] : [])));
      }
      input.addEventListener('change', commit);
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
      return el('div', { class: 'lmx-advrow' }, [
        el('label', { class: 'lmx-advlabel' }, [tr(labelKey, {}, fallback)]),
        input,
        el('div', { class: 'lmx-quick' }, (quick || []).map(function (n) {
          var on = String(rangeOf(field)) === String(n);
          return el('button', {
            class: 'lmx-quick-b' + (on ? ' lmx-quick-b--on' : ''), type: 'button', 'aria-pressed': on ? 'true' : 'false',
            onclick: function () { apply(setOp(PAGE.q, field, on ? null : op + n, [field])); },
          }, [num(n) + '+']);
        })),
      ]);
    }

    var advRows = [
      rangeRow('replies', '>', 'forum.filters.min_replies', 'Minimum replies', [5, 25, 100]),
      rangeRow('views', '>', 'forum.filters.min_views', 'Minimum views', [1000, 10000]),
      rangeRow('length', '>', 'forum.filters.min_length', 'Minimum length (words)', [200, 1000]),
    ];
    var reactionsCarryData = (d.results || []).some(function (h) { return (h.reactions || 0) > 0; });
    if (reactionsCarryData) {
      advRows.splice(1, 0, rangeRow('reactions', '>', 'forum.filters.min_reactions', 'Minimum reactions', [1, 10, 50]));
    }

    // Flags are a toggle set, not a list: each is on or off and several can be
    // on at once, so they are pressed buttons rather than a select.
    var activeFlags = {};
    (p.flags || []).forEach(function (f) { activeFlags[String(f).replace(/^!/, '')] = String(f).charAt(0) !== '!'; });
    var FLAGS = ['unanswered', 'answered', 'guide', 'sticky', 'locked', 'op'];
    advRows.push(el('div', { class: 'lmx-flags' }, FLAGS.map(function (f) {
      var on = activeFlags[f] === true;
      return el('button', {
        class: 'lmx-flag' + (on ? ' lmx-flag--on' : ''), type: 'button', 'aria-pressed': on ? 'true' : 'false',
        onclick: function () { apply(on ? dropOp(PAGE.q, ['is', 'has'], f) : addOp(PAGE.q, 'is', f)); },
      }, [on ? icon('check') : null, el('span', {}, [tr('forum.flags.' + f, {}, f)])]);
    })));

    wrap.appendChild(section('forum.filters.advanced', 'Advanced', 'sliders', advRows));

    (d.facets || []).forEach(function (f) {
      if (!f.values || !f.values.length) return;
      var isTag = f.field === 'tag_names';
      var body = el('div', { class: 'lmx-facet-body' + (isTag ? ' lmx-facet-body--tags' : '') });
      var tagColours = {};
      (d.results || []).forEach(function (h) { (h.tags || []).forEach(function (t) { tagColours[t.name] = t.color; }); });
      f.values.slice(0, 10).forEach(function (v) {
        var active = (f.operator === 'tag' && (p.tags || []).indexOf(String(v.value).toLowerCase()) >= 0)
          || (f.operator === 'by' && (p.authors || []).indexOf(String(v.value).toLowerCase()) >= 0)
          || (f.operator === 'prefix' && (p.prefixes || []).indexOf(String(v.value).toLowerCase()) >= 0)
          || (f.operator === 'lang' && (p.langs || []).indexOf(String(v.value).toLowerCase()) >= 0);
        body.appendChild(el('button', {
          class: 'lmx-facet-v' + (active ? ' lmx-facet-v--on' : ''), type: 'button',
          'aria-pressed': active ? 'true' : 'false',
          css: isTag && COLOUR_OK.test(String(tagColours[v.value] || '')) ? { '--cat': tagColours[v.value] } : null,
          onclick: function () {
            apply(active ? dropOp(PAGE.q, [f.operator], v.value) : addOp(PAGE.q, f.operator, v.value));
          },
        }, [
          isTag ? el('i', { class: 'lmx-tag-dot', 'aria-hidden': 'true' }) : null,
          el('span', { class: 'lmx-facet-name' }, [v.value]),
          el('span', { class: 'lmx-facet-count' }, [num(v.count)]),
        ]));
      });
      wrap.appendChild(el('section', { class: 'lmx-facet' }, [
        el('h3', {}, [icon(f.operator === 'by' ? 'user' : f.operator === 'tag' ? 'tag' : 'filter'), el('span', {}, [f.label])]),
        f === authorFacet ? byInput : null,
        body,
      ]));
    });
    return wrap;
  }

  // ------------------------------------------------------------ saved searches
  //
  // `GET /api/looksmax/search/saved` answers 401 for a guest, so every call site
  // is gated on a session and the control simply is not drawn for a reader who
  // could not use it.

  function savedPanel() {
    if (!loggedIn()) return null;
    var body = el('div', { class: 'lmx-saved-body' }, [el('span', { class: 'lmx-sk lmx-sk--line' })]);
    var sec = el('section', { class: 'lmx-facet lmx-saved' }, [
      el('h3', {}, [icon('bookmark'), el('span', {}, [tr('forum.landing.saved', {}, 'Saved searches')])]),
      body,
    ]);

    function draw(items) {
      body.innerHTML = '';
      if (!items.length) {
        body.appendChild(el('p', { class: 'lmx-saved-none' }, [tr('forum.saved.none', {}, 'Nothing saved yet.')]));
      }
      items.forEach(function (sv) {
        var row = el('div', { class: 'lmx-saved-row' }, [
          el('button', {
            class: 'lmx-saved-go', type: 'button', title: sv.query,
            onclick: function () { PAGE.type = sv.type || 'all'; apply(sv.query); },
          }, [
            el('span', { class: 'lmx-saved-n' }, [sv.name || sv.query]),
            sv.newCount ? el('span', { class: 'lmx-saved-new' }, [tr('forum.saved.new_count', { count: sv.newCount, n: num(sv.newCount) }, num(sv.newCount) + ' new')]) : null,
          ]),
          el('button', {
            class: 'lmx-saved-x', type: 'button',
            'aria-label': tr('forum.saved.delete', { name: sv.name || sv.query }, 'Delete saved search'),
            onclick: function () {
              fetch(API + '/saved?id=' + encodeURIComponent(sv.id), {
                method: 'DELETE', credentials: 'same-origin',
                headers: { 'X-CSRF-Token': (function () { try { return window.flarum.core.app.session.csrfToken || ''; } catch (e) { return ''; } })() },
              }).then(load, load);
            },
          }, [icon('close')]),
        ]);
        body.appendChild(row);
      });
    }

    function load() {
      get(API + '/saved', {})
        .then(function (d) { draw((d && d.saved) || []); })
        .catch(function () { sec.remove(); });
    }
    load();
    sec._reload = load;
    return sec;
  }

  function saveButton() {
    if (!loggedIn() || !String(PAGE.q).trim()) return null;
    var btn = el('button', {
      class: 'lmx-btn lmx-save', type: 'button',
      onclick: function () {
        btn.disabled = true;
        post(API + '/saved', { query: PAGE.q, type: PAGE.type })
          .then(function (r) {
            btn.disabled = false;
            if (!r.ok) { btn.classList.add('lmx-save--failed'); return; }
            btn.classList.add('lmx-save--done');
            btn.replaceChildren(icon('check'), el('span', {}, [tr('forum.saved.done', {}, 'Saved')]));
            var panel = $('.lmx-saved');
            if (panel && panel._reload) panel._reload();
          })
          .catch(function () { btn.disabled = false; btn.classList.add('lmx-save--failed'); });
      },
    }, [icon('bookmark'), el('span', {}, [tr('forum.saved.save', {}, 'Save this search')])]);
    return btn;
  }

  // -------------------------------------------------------------- landing state

  function landing(root) {
    var wrap = el('div', { class: 'lmx-landing' });
    wrap.appendChild(el('div', { class: 'lmx-landing-hero' }, [
      icon('search', 'lmx-ico-hero'),
      el('h2', {}, [tr('forum.landing.heading', {}, 'What are you looking for?')]),
      el('p', {}, [tr('forum.landing.sub', {}, 'Search every thread, post and member.')]),
    ]));

    var rec = recent();
    if (rec.length) {
      wrap.appendChild(el('section', { class: 'lmx-land-sec' }, [
        el('h3', {}, [icon('clock'), el('span', {}, [tr('forum.palette.recent', {}, 'Recent')])]),
        el('div', { class: 'lmx-land-chips' }, rec.map(function (r) {
          return el('button', { class: 'lmx-qchip', type: 'button', onclick: function () { apply(r); } }, [
            icon('clock'), el('span', {}, [r]),
          ]);
        })),
      ]));
    }

    if (loggedIn()) {
      var savedSec = el('section', { class: 'lmx-land-sec lmx-land-saved' }, [
        el('h3', {}, [icon('bookmark'), el('span', {}, [tr('forum.landing.saved', {}, 'Saved searches')])]),
        el('div', { class: 'lmx-land-chips' }, [el('span', { class: 'lmx-sk lmx-sk--line' })]),
      ]);
      wrap.appendChild(savedSec);
      get(API + '/saved', {}).then(function (d) {
        var items = (d && (d.saved || d.data || d.searches)) || [];
        var body = savedSec.querySelector('.lmx-land-chips');
        body.innerHTML = '';
        if (!items.length) { savedSec.remove(); return; }
        items.slice(0, 10).forEach(function (s) {
          var q = s.query || s.q || '';
          body.appendChild(el('button', { class: 'lmx-qchip', type: 'button', onclick: function () { apply(q); } }, [
            icon('bookmark'), el('span', {}, [s.name || q]),
          ]));
        });
      }).catch(function () { savedSec.remove(); });
    }

    var tagSec = el('section', { class: 'lmx-land-sec' }, [
      el('h3', {}, [icon('trend'), el('span', {}, [tr('forum.landing.popular_tags', {}, 'Busiest forums')])]),
      el('div', { class: 'lmx-land-chips lmx-land-tags' }, [
        el('span', { class: 'lmx-sk lmx-sk--line' }), el('span', { class: 'lmx-sk lmx-sk--line' }),
      ]),
    ]);
    wrap.appendChild(tagSec);
    allTags().then(function (tags) {
      var body = tagSec.querySelector('.lmx-land-tags');
      if (!body) return;
      var top = tags.filter(function (t) { return t.count > 0; }).slice(0, 14);
      if (!top.length) { tagSec.remove(); return; }
      body.innerHTML = '';
      top.forEach(function (t) {
        body.appendChild(tagChip(t, {
          onclick: function () { apply('tag:"' + t.name + '"'); },
          title: tr('forum.landing.search_tag', { tag: t.name }, 'Search in ' + t.name),
        }));
      });
    });

    wrap.appendChild(el('section', { class: 'lmx-land-sec' }, [
      el('h3', {}, [icon('sparkle'), el('span', {}, [tr('forum.landing.operators_h', {}, 'Query operators')])]),
      el('ul', { class: 'lmx-ops' }, [
        ['tag:looksmaxing', 'op_tag'], ['by:username', 'op_by'], ['after:2023', 'op_after'],
        ['"exact phrase"', 'op_phrase'], ['-word', 'op_neg'], ['sort:new', 'op_sort'],
      ].map(function (o) {
        return el('li', {}, [
          el('button', { class: 'lmx-op', type: 'button', onclick: function () { apply(o[0]); } }, [o[0]]),
          el('span', { class: 'lmx-op-d' }, [tr('forum.landing.' + o[1], {}, o[0])]),
        ]);
      })),
    ]));

    root.appendChild(wrap);
  }

  // ------------------------------------------------------------- zero results

  /*
   * The server's recover() strips filters, phrases and whole words, so it
   * returns NOTHING for a single unknown word — measured: `zzqqxwv` came back
   * with `recovery.suggestions: []`, which is the most common zero-result shape
   * there is. This adds a client-side probe: shorten the longest word and ask
   * the engine whether the prefix has hits, offering only what actually does.
   * Every offer below has been executed before being shown; none is a guess.
   */
  function zeroState(root, d) {
    var rec = d.recovery || {};
    var p = d.parsed || {};
    var box = el('div', { class: 'lmx-empty' }, [
      el('div', { class: 'lmx-empty-hero' }, [
        icon('search', 'lmx-ico-hero'),
        el('h2', {}, [tr('forum.empty.heading', { query: PAGE.q }, 'Nothing matched “' + PAGE.q + '”')]),
        el('p', {}, [tr('forum.empty.sub', {}, 'Try a shorter query, or drop a filter.')]),
      ]),
    ]);
    var offers = el('div', { class: 'lmx-empty-offers' });
    box.appendChild(offers);

    function offer(label, query, count, opts) {
      offers.appendChild(el('button', {
        class: 'lmx-empty-sug', type: 'button',
        onclick: function () { if (opts && opts.type) PAGE.type = opts.type; apply(query); },
      }, [
        icon('arrow'),
        el('span', { class: 'lmx-empty-label' }, [label]),
        el('span', { class: 'lmx-empty-q' }, [query || tr('forum.empty.everything', {}, 'everything')]),
        count != null ? el('span', { class: 'lmx-empty-n' }, [tr('forum.empty.count', { count: count || 0, n: num(count) }, num(count) + ' results')]) : null,
      ]));
    }

    (rec.suggestions || []).forEach(function (s) {
      offer(tr('forum.empty.try', { label: s.label }, 'Try ' + s.label), s.query, s.count);
    });

    if (PAGE.type !== 'all') {
      offer(tr('forum.empty.all_types', {}, 'Search everything'), PAGE.q, null, { type: 'all' });
    }

    /*
     * Prefix probe. One truncation was not enough: `mewingggg` shortens to
     * `mewinggg`, which the engine also does not know, so the first pass
     * offered nothing for a typo that is one obvious step from 6,000 hits.
     * Several candidates are tried IN ORDER and the FIRST that the engine
     * confirms returns hits is offered — at most one, so the panel does not
     * fill up with near-identical guesses.
     */
    var words = String(p.text || PAGE.q).split(/\s+/).filter(Boolean);
    var longest = words.slice().sort(function (a, b) { return b.length - a.length; })[0] || '';
    if (longest.length >= 5 && !(rec.suggestions || []).length) {
      var cands = [];
      for (var cut = 1; cut <= 4; cut++) {
        var stem = longest.slice(0, longest.length - cut);
        if (stem.length >= 3) cands.push([stem, String(PAGE.q).replace(longest, stem).trim()]);
      }
      // …and, if there is more than one word, the query without the odd one.
      if (words.length > 1) {
        cands.push([null, words.filter(function (w) { return w !== longest; }).join(' ')]);
      }
      (function next(i) {
        if (i >= cands.length) return;
        get(API, { q: cands[i][1], type: PAGE.type, limit: 1, recover: '0', source: 'recover' })
          .then(function (r) {
            if (r && r.estimatedTotalHits > 0) {
              offer(
                cands[i][0]
                  ? tr('forum.empty.try_stem', { word: cands[i][0] }, 'Try “' + cands[i][0] + '”')
                  : tr('forum.empty.try_without', { word: longest }, 'Try without “' + longest + '”'),
                cands[i][1], r.estimatedTotalHits
              );
              return;
            }
            next(i + 1);
          })
          .catch(function () {});
      })(0);
    }

    if ((rec.relatedTags || []).length) {
      box.appendChild(el('div', { class: 'lmx-empty-tags' }, [
        el('span', { class: 'lmx-empty-tags-l' }, [tr('forum.empty.related_forums', {}, 'Related forums: ')]),
      ].concat(rec.relatedTags.map(function (t) { return tagChip(t, { href: t.url }); }))));
    } else {
      var tagsBox = el('div', { class: 'lmx-empty-tags' }, [
        el('span', { class: 'lmx-empty-tags-l' }, [tr('forum.landing.popular_tags', {}, 'Busiest forums')]),
      ]);
      box.appendChild(tagsBox);
      allTags().then(function (tags) {
        var top = tags.filter(function (t) { return t.count > 0; }).slice(0, 8);
        if (!top.length) { tagsBox.remove(); return; }
        top.forEach(function (t) {
          tagsBox.appendChild(tagChip(t, { onclick: function () { apply('tag:"' + t.name + '"'); } }));
        });
      });
    }
    root.appendChild(box);
  }

  // ------------------------------------------------------------------- render

  function renderPage(root, d) {
    root.innerHTML = '';
    PAGE.data = d;
    PAGE.logId = d.logId;
    var p = d.parsed || {};
    var isLanding = !String(PAGE.q).trim();

    var input = el('input', {
      class: 'lmx-s-input', type: 'search', value: PAGE.q, autocomplete: 'off', spellcheck: 'false',
      enterkeyhint: 'search',
      placeholder: tr('forum.results.placeholder', {}, 'Search…'),
      'aria-label': tr('forum.results.query_label', {}, 'Search query'),
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); apply(input.value); }
    });

    var header = el('div', { class: 'lmx-s-header' }, [
      el('form', {
        class: 'lmx-s-form',
        onsubmit: function (e) { e.preventDefault(); apply(input.value); },
      }, [
        el('span', { class: 'lmx-s-inputwrap' }, [icon('search'), input]),
        el('button', { class: 'lmx-btn lmx-btn--primary lmx-s-go', type: 'submit' }, [tr('forum.results.submit', {}, 'Search')]),
      ]),
    ]);

    if (!isLanding) {
      var chips = chipsFor(p);
      if (chips.length) header.appendChild(el('div', { class: 'lmx-chips' }, chips));
    }
    root.appendChild(header);

    if (isLanding) { landing(root); return; }

    var tabs = el('div', { class: 'lmx-tabs', role: 'tablist', 'aria-label': tr('forum.types.label', {}, 'Result type') }, TYPES.map(function (t) {
      return el('button', {
        class: 'lmx-tab' + (PAGE.type === t[0] ? ' lmx-tab-on' : ''), type: 'button',
        role: 'tab', 'aria-selected': PAGE.type === t[0] ? 'true' : 'false',
        onclick: function () { PAGE.type = t[0]; apply(PAGE.q); },
      }, [tr('forum.types.' + t[1], {}, t[1])]);
    }));

    var sortSel = el('select', {
      class: 'lmx-sort', 'aria-label': tr('forum.sort.label', {}, 'Sort results'),
      onchange: function () { apply(setOp(PAGE.q, 'sort', sortSel.value || null, ['sort', 'order'])); },
    }, SORTS.map(function (s) {
      return el('option', { value: s[0], selected: (p.sort || '') === s[0] ? 'selected' : null }, [tr('forum.sort.' + s[1], {}, s[1])]);
    }));

    var total = d.estimatedTotalHits || 0;
    var countEl = el('span', { class: 'lmx-s-count', role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true' }, [
      total
        ? tr('forum.results.count', { count: total, n: nnum(total) + (total >= 1000 ? '+' : '') }, nnum(total) + (total >= 1000 ? '+' : '') + ' result' + (total === 1 ? '' : 's'))
        : tr('forum.results.none', {}, 'No results'),
    ]);
    var bar = el('div', { class: 'lmx-s-bar' }, [
      countEl,
      d.engineMs != null ? el('span', { class: 'lmx-s-ms' }, [tr('forum.results.timing', { engine: nnum(d.engineMs), total: nnum(Math.round(d.totalMs)) }, 'engine ' + d.engineMs + ' ms · total ' + Math.round(d.totalMs) + ' ms')]) : null,
      d.degraded ? el('span', { class: 'lmx-s-degraded' }, [tr('forum.results.degraded', {}, 'degraded: database fallback')]) : null,
      el('span', { class: 'lmx-s-spacer' }),
      el('button', {
        class: 'lmx-filters-toggle', type: 'button', 'aria-expanded': 'false', 'aria-controls': 'lmx-facets',
        onclick: function (e) {
          var on = document.documentElement.classList.toggle('lmx-facets-open');
          e.currentTarget.setAttribute('aria-expanded', on ? 'true' : 'false');
        },
      }, [icon('sliders'), el('span', {}, [tr('forum.filters.toggle', {}, 'Filters')])]),
      el('label', { class: 'lmx-sortwrap' }, [el('span', { class: 'lmx-sr' }, [tr('forum.sort.label', {}, 'Sort results')]), sortSel]),
    ]);
    var save = saveButton();
    if (save) bar.insertBefore(save, bar.querySelector('.lmx-sortwrap'));

    var list = el('div', { class: 'lmx-s-list' });
    var terms = termsFrom(p, PAGE.q);
    var ctx = {
      logId: d.logId, query: PAGE.q, terms: terms,
      index: function (node) { return $$('.lmx-r', list).indexOf(node) + PAGE.offset; },
    };
    (d.results || []).forEach(function (h) { list.appendChild(resultRow(h, ctx)); });

    var main = el('div', { class: 'lmx-s-main' }, [bar, list]);

    if (!(d.results || []).length) zeroState(list, d);

    var pager = el('nav', { class: 'lmx-pager', 'aria-label': tr('forum.results.pager', {}, 'Result pages') });
    if (PAGE.offset > 0) {
      pager.appendChild(el('button', {
        class: 'lmx-page-btn', type: 'button',
        onclick: function () { PAGE.offset = Math.max(0, PAGE.offset - PAGE.limit); pushAndLoad(root); },
        // No icon: `results.prev` and `results.next` already carry ← and →,
        // and the pair rendered as "Next → →" on the first pass.
      }, [tr('forum.results.prev', {}, '← Previous')]));
    }
    if (PAGE.offset + PAGE.limit < total) {
      pager.appendChild(el('button', {
        class: 'lmx-page-btn', type: 'button',
        onclick: function () { PAGE.offset += PAGE.limit; pushAndLoad(root); },
      }, [tr('forum.results.next', {}, 'Next →')]));
    }
    if (total > PAGE.limit) {
      pager.appendChild(el('span', { class: 'lmx-page-of' }, [
        tr('forum.results.page_of', {
          from: nnum(PAGE.offset + 1),
          to: nnum(Math.min(PAGE.offset + PAGE.limit, total)),
          total: nnum(total),
        }, (PAGE.offset + 1) + '–' + Math.min(PAGE.offset + PAGE.limit, total) + ' / ' + total),
      ]));
    }
    main.appendChild(pager);

    var facets = facetPanel(d);
    facets.id = 'lmx-facets';

    root.appendChild(tabs);
    root.appendChild(el('div', { class: 'lmx-s-body' }, [
      main,
      el('div', { class: 'lmx-facets-wrap' }, [
        el('button', {
          class: 'lmx-facets-close', type: 'button', 'aria-label': tr('forum.surface.close', {}, 'Close'),
          onclick: function () { document.documentElement.classList.remove('lmx-facets-open'); },
        }, [icon('close')]),
        facets,
      ]),
    ]));
  }

  function pushAndLoad(root) {
    var url = urlFor();
    INITIAL_URL = url;
    searchMode = true;
    history.pushState({ lmx: true, q: PAGE.q, type: PAGE.type, offset: PAGE.offset }, '', url);
    document.documentElement.classList.remove('lmx-facets-open');
    loadPage(root);
  }

  function loadPage(root) {
    if (!String(PAGE.q).trim()) { renderPage(root, { results: [], facets: [], estimatedTotalHits: 0, parsed: {} }); return; }
    // Replace only the LIST with skeletons if a page is already drawn, so the
    // header, the chips and the facet rail hold their position. Redrawing the
    // whole page as a spinner is what makes a search feel like a page load.
    var list = $('.lmx-s-list', root);
    if (list) {
      list.innerHTML = '';
      list.appendChild(skeletonRows(6));
      root.classList.add('lmx-loading');
    } else {
      root.innerHTML = '';
      root.appendChild(skeletonRows(6));
    }
    var seq = ++loadPage._seq;
    get(API, { q: PAGE.q, type: PAGE.type, offset: PAGE.offset, limit: PAGE.limit })
      .then(function (d) {
        if (seq !== loadPage._seq) return;
        root.classList.remove('lmx-loading');
        renderPage(root, d);
        document.title = PAGE.q
          ? tr('forum.page.title_with_query', { query: PAGE.q }, 'Search: ' + PAGE.q)
          : tr('forum.page.title', {}, 'Search');
        window.scrollTo(0, 0);
      })
      .catch(function (e) {
        if (seq !== loadPage._seq) return;
        root.classList.remove('lmx-loading');
        root.innerHTML = '';
        try { console.warn('[lmx-search]', e.message, e.status, e.body); } catch (x) {}
        renderPage(root, { results: [], facets: [], estimatedTotalHits: 0, parsed: {}, error: e });
        var l = $('.lmx-s-list', root);
        if (l) { l.innerHTML = ''; l.appendChild(errorPanel(e, function () { loadPage(root); })); }
        else root.appendChild(errorPanel(e, function () { loadPage(root); }));
      });
  }
  loadPage._seq = 0;

  function readParams() {
    var params = new URLSearchParams(location.search);
    PAGE.q = params.get('q') || '';
    PAGE.type = params.get('type') || 'all';
    PAGE.offset = parseInt(params.get('offset') || '0', 10) || 0;
    PAGE.adv = params.get('adv') === '1';
  }

  function mountSearchPage() {
    // If we were served as /search but the router has since rewritten the bar,
    // put the real URL back. Without this the page a reader copies, bookmarks
    // or shares is the forum index, not their search.
    if (searchMode && !IS_SEARCH_ROUTE.test(location.pathname)) {
      try { history.replaceState(history.state, '', INITIAL_URL); } catch (e) {}
    }
    var onSearch = searchMode || IS_SEARCH_ROUTE.test(location.pathname);
    var existing = $('#lmx-search-page');

    if (!onSearch) {
      // Left the results page: give the SPA its own content back.
      if (existing) existing.remove();
      document.documentElement.classList.remove('lmx-searching', 'lmx-facets-open');
      PAGE.root = null;
      return false;
    }

    // Flarum 1.8 has no client-side /search route. Its router therefore falls
    // through to the index component and re-renders `#content` on every redraw
    // — which silently wiped this page when it was mounted INTO `#content`
    // (measured: the results page rendered the forum index instead, with 0 of
    // 20 results present in the DOM).
    //
    // So do not compete for that element. The results page is mounted as a
    // SIBLING of `#content`, outside the subtree Mithril manages, and `#content`
    // is hidden by a class on <html>. Nothing here is inside the SPA's vnode
    // tree, so no redraw can remove it and no other extension's redraw can
    // either.
    var host = $('.App-content') || $('.App') || $('#app') || document.body;
    document.documentElement.classList.add('lmx-searching');
    if (existing && existing.parentNode === host) { PAGE.root = existing; return true; }

    readParams();
    document.title = PAGE.q ? tr('forum.page.title_with_query', { query: PAGE.q }, 'Search: ' + PAGE.q) : tr('forum.page.title', {}, 'Search');

    if (existing) existing.remove();
    var root = el('div', { id: 'lmx-search-page', class: 'lmx-search-page' });
    host.appendChild(root);
    PAGE.root = root;

    // The server already ran this exact search and inlined the result, so the
    // first paint costs no round trip. Only a query the server did not answer
    // (or a subsequent interaction) fetches.
    var pre = document.getElementById('lmx-search-preload');
    var preloaded = null;
    try { preloaded = pre ? JSON.parse(pre.textContent) : null; } catch (e) {}
    if (PAGE.adv) {
      // Only meaningful where the rail is a sheet; at >=1024px it is already a
      // permanent column and forcing the overlay open would cover the results.
      if (window.innerWidth < 1024) document.documentElement.classList.add('lmx-facets-open');
    }
    if (preloaded && !preloaded.empty && PAGE.offset === 0) {
      renderPage(root, preloaded);
      if (pre) pre.remove();
    } else if (PAGE.q) {
      loadPage(root);
    } else {
      renderPage(root, { results: [], facets: [], estimatedTotalHits: 0, parsed: {} });
    }
    return true;
  }

  // ============================================================ discovery panel
  //
  // Related threads and more-from-this-author, on a thread page. This is the
  // half of search nobody types a query for: the reader who is already here and
  // would keep reading if there were an obvious next thing.

  function mountDiscovery() {
    var m = location.pathname.match(/^\/d\/(\d+)/);
    if (!m) return;
    var id = m[1];
    var sidebar = $('.DiscussionPage-nav > ul') || $('.DiscussionPage-nav') || $('.sideNavContainer');
    if (!sidebar || $('#lmx-related')) return;

    var title = ($('.DiscussionHero-title') || {}).textContent || document.title;
    var box = el('div', { id: 'lmx-related', class: 'lmx-related' }, [
      el('h3', {}, [icon('sparkle'), el('span', {}, [tr('forum.discovery.related', {}, 'Related threads')])]),
      el('div', { class: 'lmx-related-body' }, [
        el('div', { class: 'lmx-sk lmx-sk--line' }), el('div', { class: 'lmx-sk lmx-sk--line lmx-sk--short' }),
      ]),
    ]);
    sidebar.appendChild(box);

    // "Related" is the thread's own title used as the query, minus itself. It
    // is a genuine more-like-this over the same index that answers search, so
    // it needs no second system and no precomputed similarity table.
    get(API, { q: String(title).slice(0, 120), type: 'discussions', limit: 6, recover: '0', source: 'page' })
      .then(function (d) {
        var body = $('.lmx-related-body', box);
        body.innerHTML = '';
        var hits = (d.results || []).filter(function (h) { return String(h.id) !== id; }).slice(0, 5);
        if (!hits.length) { box.remove(); return; }
        hits.forEach(function (h) {
          body.appendChild(el('a', { class: 'lmx-rel', href: h.url }, [
            el('span', { class: 'lmx-rel-title' }, [h.title]),
            el('span', { class: 'lmx-rel-meta' }, [tr('forum.hit.related_meta', { count: h.commentCount || 0, n: num(h.commentCount), when: timeAgo(h.lastPostAt) }, num(h.commentCount) + ' replies · ' + timeAgo(h.lastPostAt))]),
          ]));
        });
      })
      .catch(function () { box.remove(); });
  }

  // ================================================================ header hook

  function focusSearch(prefill) {
    var input = $('.Search-input input') || $('.Search input[type=search]');
    // On a phone the header field lives inside `.App-drawer`, which is
    // transformed off-screen — measured rect x = -212 at 390px. Focusing it
    // scrolls nothing into view and shows nothing. The sheet is the surface.
    if (isMobile() || !input || input.getBoundingClientRect().width < 40) {
      openSurface(prefill == null ? (input ? input.value : '') : prefill);
      return;
    }
    input.focus();
    try { input.setSelectionRange(input.value.length, input.value.length); } catch (e) {}
    openSurface(prefill == null ? input.value : prefill, input);
  }

  function hookHeader() {
    var input = $('.Search-input input') || $('.Search input[type=search]');
    if (input && !input.dataset.lmxHooked) {
      input.dataset.lmxHooked = '1';
      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-expanded', 'false');
      input.setAttribute('aria-controls', 'lmx-dd-list');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-haspopup', 'listbox');

      input.addEventListener('input', function () {
        if (!surfaceOpen()) openSurface(input.value, input);
        else { SURF.query = input.value; run(); }
      });
      input.addEventListener('focus', function () {
        if (!surfaceOpen() && !isMobile()) openSurface(input.value, input);
        else if (isMobile()) { input.blur(); openSurface(input.value); }
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && surfaceOpen()) { onSurfaceKey(e); return; }
        if (!surfaceOpen() && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) { e.preventDefault(); openSurface(input.value, input); return; }
        if (surfaceOpen()) { onSurfaceKey(e); return; }
        if (e.key === 'Enter') {
          var v = (input.value || '').trim();
          if (v.length >= CFG.minChars) { e.preventDefault(); e.stopPropagation(); go(v); }
        }
      });
    }
    /*
     * The placeholder used to be written only when core's own placeholder began
     * with "search". Core renders "Buscar…" under the default locale, so the
     * test failed and the hint that names the `/` shortcut never appeared in
     * the language most readers use — the shortcut was undiscoverable exactly
     * where it mattered. It is set unconditionally now; the string itself is a
     * translation key, so it is not an English placeholder either.
     */
    if (input && input.placeholder !== tr('forum.header.placeholder', {}, 'Search — press /')) {
      input.placeholder = tr('forum.header.placeholder', {}, 'Search — press /');
    }

    /*
     * The mobile entry point.
     *
     * Measured at 390px: `.App-header` — logo, `.Header-controls` and the ONLY
     * search field in the document — sits at rect x = -276, entirely off screen,
     * because Flarum's mobile layout makes the whole header the slide-in drawer.
     * A button appended to `.Header-controls` therefore lands off screen too,
     * which is exactly what the first pass measured (x = -224).
     *
     * `.App-navigation` is the bar that IS on screen: fixed, 390x52, holding the
     * drawer toggle at x 0-40, the title control at x 95-295 and the primary
     * control at x 342-390. The gap between 40 and 95 is where this goes.
     */
    var navbar = $('.App-navigation');
    if (navbar && !$('#lmx-hdr-search')) {
      navbar.appendChild(el('button', {
        id: 'lmx-hdr-search', class: 'Button Button--icon lmx-hdr-search', type: 'button',
        'aria-label': tr('forum.palette.aria_label', {}, 'Search'),
        title: tr('forum.palette.aria_label', {}, 'Search'),
        onclick: function () { focusSearch(''); },
      }, [icon('search')]));
    }
  }

  // ===================================================================== boot

  function tick() {
    try { hookHeader(); } catch (e) {}
    try { mountSearchPage(); } catch (e) {}
    try { mountDiscovery(); } catch (e) {}
  }

  document.addEventListener('keydown', function (e) {
    if (!CFG.palette) return;
    var tag = (e.target && e.target.tagName) || '';
    var typing = /INPUT|TEXTAREA|SELECT/.test(tag) || (e.target && e.target.isContentEditable);
    if ((e.key === 'k' || e.key === 'K') && (e.metaKey || e.ctrlKey)) {
      e.preventDefault();
      surfaceOpen() ? closeSurface() : focusSearch('');
    } else if (e.key === '/' && !typing) {
      // The placeholder promises this. It focuses the real field on a pointer
      // screen and opens the full-screen surface on a phone, which is what
      // "focus search" means on a device with no visible field.
      e.preventDefault();
      focusSearch('');
    } else if (e.key === 'Escape' && surfaceOpen()) {
      closeSurface();
    } else if (e.key === 'Escape' && document.documentElement.classList.contains('lmx-facets-open')) {
      document.documentElement.classList.remove('lmx-facets-open');
    }
  });

  window.addEventListener('popstate', function () {
    // A genuine history navigation is the one URL change the reader asked for,
    // so this is where searchMode is allowed to turn off again.
    //
    // It is ALSO where back/forward inside the results page has to be honoured.
    // The previous revision returned early from mountSearchPage() whenever the
    // page element was already mounted, so going back from a filtered search to
    // an unfiltered one changed the address bar and nothing else — the results
    // on screen stayed as they were.
    searchMode = IS_SEARCH_ROUTE.test(location.pathname);
    INITIAL_URL = location.pathname + location.search;
    closeSurface(true);
    if (searchMode && PAGE.root && document.contains(PAGE.root)) {
      readParams();
      loadPage(PAGE.root);
      return;
    }
    setTimeout(tick, 0);
  });

  // Flarum is an SPA and gives no public "page changed" hook that is safe to
  // extend, so the DOM itself is the signal. Cheap: the callback only ever
  // reads location and a couple of selectors, and bails immediately.
  var mo = new MutationObserver(function () {
    clearTimeout(mo._t);
    mo._t = setTimeout(tick, 60);
  });

  function start() {
    tick();
    var app = $('#app') || document.body;
    mo.observe(app, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  window.lmxSearch = {
    open: focusSearch, close: closeSurface, go: go, cfg: CFG,
    // Exported so the audit harness can exercise the highlighter directly
    // rather than inferring it from pixels.
    _hl: { fold: fold, terms: termsFrom, ranges: rangesIn, mark: markText },
  };
})();
