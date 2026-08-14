#!/usr/bin/env node
/**
 * corpus-survey.mjs — whole-corpus census of the scraped looksmax.org (XenForo)
 * post HTML, and the work order it implies for the Flarum side.
 *
 * Design rules, in order of importance:
 *
 *  1. PRIOR-FREE EXTRACTION. Nothing here starts from a list of tags someone
 *     expected to find. Every element name, class token, data-* attribute and
 *     literal `[bbcode]` is pulled out of the corpus itself and tallied. A
 *     hand-written keyword list can only ever confirm what you already knew.
 *  2. WHOLE CORPUS, NOT A SAMPLE. Every row is streamed. The only sampled
 *     number in the output is the lossless-conversion estimate (§9), and it is
 *     labelled with its sample size and its sampling rule everywhere it appears.
 *  3. DISTINCT THREADS ALONGSIDE OCCURRENCES. 13k quote blocks in 200 threads
 *     and 13k spread over 13k threads are different problems. Every census row
 *     carries occurrences / distinct posts / distinct threads.
 *  4. READ-ONLY. The scraper is still writing to this database. The connection
 *     is opened `mode=ro` with `PRAGMA query_only=1`, and nothing here writes,
 *     VACUUMs or takes a write lock.
 *
 * Parallelism: the corpus is partitioned into equal-post-count THREAD id ranges
 * (never post-id ranges — a thread must not straddle two shards, or the
 * distinct-thread counts double-count at the seam) and each range is handled by
 * a forked child. Rows come back ordered by thread_id, so distinct-post and
 * distinct-thread counting is a last-seen comparison and costs no memory.
 *
 * Usage:
 *   node tools/corpus-survey.mjs --db /work/lmx/scraper/looksmax.db --out reports/
 *   node tools/corpus-survey.mjs --db … --out /tmp/smoke --limit 5000   # fast smoke
 *
 * Flags:
 *   --db PATH        sqlite file (required)
 *   --out DIR        output directory for corpus-survey.json + CORPUS-SURVEY.md
 *   --workers N      forked children (default: min(16, cpus))
 *   --limit N        stop after N posts per shard — SMOKE RUN ONLY, the report
 *                    is then marked as partial
 *   --sample N       target size of the lossless-conversion sample (default 50000)
 *   --ext DIR        extensions dir to read the converter from
 *                    (default: <repo>/extensions)
 *   --json-only      skip the markdown render
 */

import { DatabaseSync } from 'node:sqlite';
import { fork } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const SELF = fileURLToPath(import.meta.url);
const REPO = path.resolve(path.dirname(SELF), '..');

// --------------------------------------------------------------------------- cli

function parseArgs(argv) {
  const a = { workers: Math.min(16, os.cpus().length), sample: 50000 };
  for (let i = 0; i < argv.length; i++) {
    const k = argv[i];
    const next = () => argv[++i];
    switch (k) {
      case '--db': a.db = next(); break;
      case '--out': a.out = next(); break;
      case '--workers': a.workers = Number(next()); break;
      case '--limit': a.limit = Number(next()); break;
      case '--sample': a.sample = Number(next()); break;
      case '--ext': a.ext = next(); break;
      case '--json-only': a.jsonOnly = true; break;
      case '--worker': a.worker = true; break;
      case '--from': a.from = Number(next()); break;
      case '--to': a.to = Number(next()); break;
      case '--shard': a.shard = Number(next()); break;
      case '--partial': a.partial = next(); break;
      case '--sample-mod': a.sampleMod = Number(next()); break;
      default:
        if (k.startsWith('--')) throw new Error(`unknown flag ${k}`);
    }
  }
  return a;
}

// ------------------------------------------------------------------- census type

/**
 * token -> [occurrences, distinct posts, distinct threads]; the last two are
 * maintained with a last-seen id, which is exact as long as rows arrive grouped
 * by thread (they do — every query is `order by thread_id`).
 */
class Census {
  constructor() { this.m = new Map(); }
  hit(tok, postId, threadId, n = 1) {
    let r = this.m.get(tok);
    if (r === undefined) { r = [0, 0, 0, -1, -1]; this.m.set(tok, r); }
    r[0] += n;
    if (r[3] !== postId) { r[1]++; r[3] = postId; }
    if (r[4] !== threadId) { r[2]++; r[4] = threadId; }
  }
  toJSON() {
    const o = Object.create(null);
    for (const [k, v] of this.m) o[k] = [v[0], v[1], v[2]];
    return o;
  }
}

function mergeCensus(into, from) {
  for (const k of Object.keys(from)) {
    const v = from[k];
    const cur = into[k];
    if (cur) { cur[0] += v[0]; cur[1] += v[1]; cur[2] += v[2]; }
    else into[k] = [v[0], v[1], v[2]];
  }
  return into;
}

function mergeCounter(into, from) {
  for (const k of Object.keys(from)) into[k] = (into[k] || 0) + from[k];
  return into;
}

const bump = (o, k, n = 1) => { o[k] = (o[k] || 0) + n; };

function sortCensus(obj, key = 0) {
  return Object.entries(obj).sort((a, b) => b[1][key] - a[1][key] || a[0].localeCompare(b[0]));
}

function pct(part, whole) {
  if (!whole) return '0%';
  const v = (part / whole) * 100;
  return v >= 10 ? v.toFixed(1) + '%' : v >= 0.1 ? v.toFixed(2) + '%' : v.toFixed(3) + '%';
}

const num = (n) => (typeof n === 'number' ? n.toLocaleString('en-US') : String(n));

// ------------------------------------------------------------------- html scan

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
  'link', 'meta', 'param', 'source', 'track', 'wbr']);

// text that is chrome, not body: its characters must not count toward a quote or
// spoiler body length.
const CHROME_CLASS = new Set(['bbCodeBlock-title', 'bbCodeBlock-expandLink',
  'bbCodeSpoiler-button', 'js-unfurl-favicon', 'bbCodeBlockUnfurl-icon']);

// markup the board generated around content the author wrote. Anchors in here
// are not links the author typed, and counting them as such was the difference
// between 659k "links" and the real figure.
const CHROME_FRAME = new Set([...CHROME_CLASS, 'js-unfurl', 'bbCodeBlock--unfurl']);

const TAG_RE = /<(\/?)([a-zA-Z][a-zA-Z0-9:._-]*)((?:'[^']*'|"[^"]*"|[^'">])*)>/g;
const ATTR_RE = /([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g;

// A literal bbcode tag surviving in a text node. Deliberately permissive about
// the tag NAME — the point is to discover names, not to confirm them — but the
// argument must start with `=`. An earlier version allowed a space-separated
// argument and matched ordinary prose in brackets ("[the guy in the photo]"),
// which put `the`, `in` and `for` in the results as if they were bbcode tags.
const BB_RE = /\[(\/?)([a-zA-Z][a-zA-Z0-9_*]{0,24})(=[^\]\n]{0,300})?\]/g;

// An opening bracket that was eaten somewhere upstream: `url=https://…` sitting
// in prose where `[url=https://…]` was meant.
// `align=` and `width=` are deliberately NOT in this list: they are ordinary
// html attributes, and posts that paste raw html put them in text nodes, which
// made every one of them a false positive.
const EATEN_RE = /(^|[\s>("'])((?:url|img|quote|spoiler|ispoiler|size|color|colour|font|lolquote|media|attach|list)=)(?=["']?(?:https?:\/\/|\d|#|[a-z]))/gi;

// A text node that ends mid-tag: `…[SPO` with the rest of the tag on the far
// side of some markup. The fragment must be a prefix of a real bbcode name, or
// ordinary prose that happens to end in "[see" would count.
const BB_NAMES = ['b', 'i', 'u', 's', 'url', 'img', 'quote', 'spoiler', 'ispoiler',
  'size', 'color', 'font', 'list', 'code', 'media', 'attach', 'center', 'user',
  'lolquote', 'uwsl', 'email', 'table', 'hide'];
const SPLIT_TAIL_RE = /\[(\/?)([A-Za-z][A-Za-z0-9_]{0,14})$/;
function endsMidBbTag(s) {
  const m = SPLIT_TAIL_RE.exec(s);
  if (!m) return false;
  const frag = m[2].toLowerCase();
  return frag.length >= 2 && BB_NAMES.some((n) => n.startsWith(frag));
}

const ENT_RE = /&(?:#(\d{1,6})|#x([0-9a-fA-F]{1,5})|([a-zA-Z][a-zA-Z0-9]{1,7}));/g;
const NAMED_ENT = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', hellip: '…', mdash: '—', ndash: '–', rsquo: '’', lsquo: '‘', ldquo: '“', rdquo: '”' };
function decodeEntities(s) {
  if (s.indexOf('&') === -1) return s;
  return s.replace(ENT_RE, (m, dec, hex, name) => {
    if (dec) return String.fromCodePoint(Number(dec));
    if (hex) return String.fromCodePoint(parseInt(hex, 16));
    const v = NAMED_ENT[name];
    return v === undefined ? m : v;
  });
}

function hostOf(url) {
  const m = /^(?:([a-z][a-z0-9+.-]*):)?\/\/([^/?#]+)/i.exec(url);
  if (m) return m[2].toLowerCase().replace(/^www\./, '').replace(/:\d+$/, '');
  if (/^mailto:/i.test(url)) return '(mailto)';
  if (url.startsWith('/')) return 'looksmax.org (relative)';
  if (url.startsWith('#')) return '(anchor)';
  if (url.startsWith('data:')) return '(data uri)';
  if (url === '') return '(empty)';
  return '(other/relative)';
}

// A 1x1 / inline placeholder standing in for a lazyloaded image.
const isPlaceholder = (v) => v.startsWith('data:') || /\/styles\/default\/xenforo\/(?:clear|spacer)/i.test(v);

function newAccumulator() {
  return {
    posts: 0, threads: 0, htmlBytes: 0, textBytes: 0,
    elements: new Census(),
    classes: new Census(),
    dataAttrs: new Census(),
    tagAttrs: new Census(),
    constructs: new Census(),
    linkHosts: new Census(),
    imgHosts: new Census(),
    bbLiteral: new Census(),
    bbLiteralForms: new Census(),
    langs: new Census(),
    counts: Object.create(null),
    quoteLen: Object.create(null),      // char length -> quotes
    quoteBr: Object.create(null),       // <br> count -> quotes
    quoteDepthOcc: Object.create(null), // depth -> quote blocks opened at it
    quoteDepthPost: Object.create(null),// max depth -> posts
    spoilerDepthOcc: Object.create(null),
    spoilerDepthPost: Object.create(null),
    comboDepthPost: Object.create(null),
    quoteSources: Object.create(null),  // source post id -> references
    examples: Object.create(null),      // key -> [{post, thread, snippet}]
    badIds: Object.create(null),        // kind -> [post ids]
    lossless: { sampled: 0, strictOk: 0, lenientOk: 0, reasons: Object.create(null), failPosts: [] },
  };
}

function example(A, key, postId, threadId, snippet, cap = 3) {
  const arr = A.examples[key] || (A.examples[key] = []);
  if (arr.length < cap) arr.push({ post: postId, thread: threadId, snippet: snippet.slice(0, 400) });
}

function badId(A, kind, postId, cap = 40) {
  const arr = A.badIds[kind] || (A.badIds[kind] = []);
  if (arr.length < cap) arr.push(postId);
}

// --------------------------------------------------------------- the per-post pass

function analyzePost(row, A, sample) {
  const id = row.id, tid = row.thread_id;
  let html = row.html || '';
  A.posts++;
  A.htmlBytes += html.length;

  const isSample = sample.on && (id % sample.mod) === sample.rem;
  const seenTokens = isSample ? new Set() : null;
  const leaks = isSample ? new Set() : null;

  if (html.indexOf('<!--') !== -1) html = html.replace(/<!--[\s\S]*?(?:-->|$)/g, '');

  const stack = [];
  let quoteDepth = 0, spoilerDepth = 0;
  let maxQuote = 0, maxSpoiler = 0, maxCombo = 0, combo = 0;
  let mismatched = 0;
  let inRaw = null;         // script/style: text is not prose
  let noscriptDepth = 0;
  let codeDepth = 0;        // literal bbcode inside a code block is content
  let chromeDepth = 0;      // inside quote/spoiler/unfurl chrome, not body
  const bbOpen = Object.create(null), bbClose = Object.create(null);

  const addText = (t, offset) => {
    const dec = decodeEntities(t);
    const norm = dec.replace(/\s+/g, ' ');
    const len = norm === ' ' ? 0 : norm.length;
    if (stack.length && len) {
      const f = stack[stack.length - 1];
      f.len += len;
    }
    if (inRaw) return;

    // literal bbcode
    if (dec.indexOf('[') !== -1) {
      BB_RE.lastIndex = 0;
      let b;
      while ((b = BB_RE.exec(dec))) {
        const closing = b[1] === '/';
        const name = b[2].toLowerCase();
        const arg = b[3] || '';
        const key = (closing ? '/' : '') + name;
        A.bbLiteral.hit(name, id, tid);
        if (closing) bump(bbClose, name); else bump(bbOpen, name);
        if (codeDepth) A.constructs.hit('bb-literal.inside-code', id, tid);
        if (quoteDepth) A.constructs.hit('bb-literal.inside-quote', id, tid);
        let form;
        if (closing) form = `[/${name}]`;
        else if (!arg) form = `[${name}]`;
        else {
          const v = arg.replace(/^=/, '').trim();
          const shape = /^https?:\/\//i.test(v) ? '<url>'
            : /^["']?\d+["']?$/.test(v) ? '<int>'
              : /^["']?#?[0-9a-f]{3,8}["']?$/i.test(v) ? '<color>'
                : /^\s/.test(arg) ? '<attrs>' : '<text>';
          form = `[${name}=${shape}]`;
        }
        A.bbLiteralForms.hit(form, id, tid);
        if (leaks) leaks.add(name);
        example(A, 'bb:' + name, id, tid, contextAround(html, offset + b.index, 160), 4);
        if (arg) example(A, 'bbform:' + form, id, tid, dec.slice(Math.max(0, b.index - 60), b.index + 160), 3);
      }
    }

    // A bbcode tag torn in half by markup: the source html contains
    // `[SPO</div></div>ILER="spoiler"]`, i.e. XenForo itself emitted a broken
    // post. The opener can never be matched by anything downstream.
    if (endsMidBbTag(dec)) {
      A.constructs.hit('malformed.bbcode-split-by-markup', id, tid);
      badId(A, 'bbcode-split-by-markup', id);
      example(A, 'split-bb', id, tid, contextAround(html, offset + dec.length, 200), 3);
    }

    if (dec.indexOf('=') !== -1) {
      EATEN_RE.lastIndex = 0;
      let e;
      while ((e = EATEN_RE.exec(dec))) {
        A.constructs.hit('malformed.eaten-open-bracket', id, tid);
        A.bbLiteralForms.hit(`(eaten) ${e[2].toLowerCase()}`, id, tid);
        badId(A, 'eaten-open-bracket', id);
        example(A, 'eaten:' + e[2].toLowerCase(), id, tid, dec.slice(Math.max(0, e.index - 60), e.index + 160), 3);
      }
    }
  };

  let pos = 0, m;
  TAG_RE.lastIndex = 0;
  while ((m = TAG_RE.exec(html))) {
    if (m.index > pos) addText(html.slice(pos, m.index), pos);
    pos = TAG_RE.lastIndex;

    const closing = m[1] === '/';
    const name = m[2].toLowerCase();
    const attrStr = m[3];
    const selfClose = attrStr.endsWith('/');

    if (inRaw) {
      if (closing && name === inRaw) inRaw = null;
      continue;
    }

    if (closing) {
      // pop to the matching open; anything skipped was never closed
      let at = -1;
      for (let i = stack.length - 1; i >= 0; i--) if (stack[i].name === name) { at = i; break; }
      if (at === -1) {
        if (!VOID.has(name)) { mismatched++; A.constructs.hit('parse.stray-close-tag', id, tid); }
        continue;
      }
      while (stack.length > at) closeFrame(stack, A, id, tid);
      quoteDepth = countFlag(stack, 'isQuote');
      spoilerDepth = countFlag(stack, 'isSpoiler');
      chromeDepth = countFlag(stack, 'chrome');
      combo = quoteDepth + spoilerDepth;
      if (name === 'code' || name === 'pre') codeDepth = Math.max(0, codeDepth - 1);
      if (name === 'noscript') noscriptDepth = Math.max(0, noscriptDepth - 1);
      continue;
    }

    A.elements.hit(name, id, tid);
    if (seenTokens) seenTokens.add('el:' + name);

    // --- attributes
    let cls = '', classList = null;
    const attrs = attrStr ? readAttrs(attrStr) : null;
    if (attrs) {
      for (const [an, av] of attrs) {
        A.tagAttrs.hit(`${name}[${an}]`, id, tid);
        if (an.startsWith('data-')) {
          A.dataAttrs.hit(an, id, tid);
          if (seenTokens) seenTokens.add('data:' + an);
        }
        if (an === 'class') cls = av;
      }
      if (cls) {
        classList = cls.split(/\s+/).filter(Boolean);
        for (const c of classList) {
          A.classes.hit(c, id, tid);
          if (seenTokens) seenTokens.add('class:' + c);
        }
      }
    }
    const has = (c) => classList !== null && classList.includes(c);
    const at = (k) => {
      if (!attrs) return '';
      for (const [an, av] of attrs) if (an === k) return decodeEntities(av);
      return '';
    };

    // ------------------------------------------------------------- constructs
    if (name === 'script' || name === 'style') { inRaw = name; continue; }
    if (name === 'noscript') noscriptDepth++;
    const insidePre = codeDepth > 0;
    if (name === 'code' || name === 'pre') codeDepth++;

    const isQuote = has('bbCodeBlock--quote');
    const isSpoiler = has('bbCodeSpoiler');
    if (name === 'blockquote' && !isQuote) A.constructs.hit('quote.blockquote-without-class', id, tid);

    if (isQuote) {
      quoteDepth++; combo++;
      if (quoteDepth > maxQuote) maxQuote = quoteDepth;
      if (combo > maxCombo) maxCombo = combo;
      bump(A.quoteDepthOcc, quoteDepth);
      A.constructs.hit('quote', id, tid);
      const dq = at('data-quote'), ds = at('data-source'), da = at('data-attributes');
      A.constructs.hit(dq ? 'quote.data-quote' : 'quote.no-data-quote', id, tid);
      const pm = /post:\s*(\d+)/.exec(ds);
      if (pm) {
        A.constructs.hit('quote.data-source-post', id, tid);
        bump(A.quoteSources, pm[1]);
      } else if (ds) A.constructs.hit('quote.data-source-other', id, tid);
      else A.constructs.hit('quote.no-data-source', id, tid);
      if (da) {
        A.constructs.hit('quote.data-attributes', id, tid);
        if (/member:\s*\d+/.test(da)) A.constructs.hit('quote.data-attributes-member', id, tid);
      }
      if (spoilerDepth > 0) A.constructs.hit('nesting.quote-inside-spoiler', id, tid);
      if (quoteDepth > 1) A.constructs.hit('nesting.quote-inside-quote', id, tid);
      if (!dq) example(A, 'quote-no-author', id, tid, html.slice(m.index, m.index + 260), 3);
    }
    if (isSpoiler) {
      spoilerDepth++; combo++;
      if (spoilerDepth > maxSpoiler) maxSpoiler = spoilerDepth;
      if (combo > maxCombo) maxCombo = combo;
      bump(A.spoilerDepthOcc, spoilerDepth);
      A.constructs.hit('spoiler', id, tid);
      if (quoteDepth > 0) A.constructs.hit('nesting.spoiler-inside-quote', id, tid);
      if (spoilerDepth > 1) A.constructs.hit('nesting.spoiler-inside-spoiler', id, tid);
    }
    if (has('bbCodeSpoiler-button-title')) A.constructs.hit('spoiler.button-title', id, tid);
    if (has('bbCodeBlock--unfurl')) {
      A.constructs.hit('unfurl', id, tid);
      const u = at('data-url');
      A.constructs.hit(u ? 'unfurl.data-url' : 'unfurl.no-data-url', id, tid);
      if (u) A.linkHosts.hit(hostOf(u), id, tid);
      if (at('data-host')) A.constructs.hit('unfurl.data-host', id, tid);
      // XenForo never resolved the preview for these: the card shows the bare url
      if (has('is-pending')) A.constructs.hit('unfurl.is-pending', id, tid);
    }
    if (name === 'table' && classList) {
      for (const c of classList) {
        if (c === 'alternate' || c === 'collapse' || c === 'nobackground' || c === 'noborder' || c === 'centered') {
          A.constructs.hit('table.style:' + c, id, tid);
        }
      }
    }
    if (has('bbCodeBlock--code')) {
      A.constructs.hit('code-block', id, tid);
    }
    if (name === 'pre') {
      const lang = at('data-lang');
      A.constructs.hit(lang ? 'code-block.data-lang' : 'code-block.no-lang', id, tid);
      if (lang) A.constructs.hit('code-lang:' + lang.toLowerCase().slice(0, 20), id, tid);
    }
    // inline code only: a <code> inside a <pre> is the body of a code BLOCK
    if (has('bbCodeInline') || (name === 'code' && !insidePre && !has('bbCodeCode'))) {
      A.constructs.hit('code-inline', id, tid);
    }

    if (has('bbImageWrapper')) {
      A.constructs.hit('image-wrapper', id, tid);
      if (at('data-src')) A.constructs.hit('image-wrapper.data-src', id, tid);
    }
    if (has('bbImageAligned--left') || has('bbImageAligned--right')) A.constructs.hit('image.aligned', id, tid);

    if (name === 'img') {
      const src = at('src'), dsrc = at('data-src'), durl = at('data-url');
      const smilie = has('smilie');
      if (smilie) {
        A.constructs.hit('smilie', id, tid);
        if (has('smilie--emoji')) A.constructs.hit('smilie.emoji', id, tid);
        else if (classList && classList.some((c) => /^smilie--sprite/.test(c))) {
          A.constructs.hit('smilie.sprite', id, tid);
          // every sprite class, not just the first: XenForo writes the generic
          // `smilie--sprite` AND the indexed `smilie--sprite74` on the same img
          for (const c of classList) if (/^smilie--sprite\d+$/.test(c)) A.constructs.hit('sprite:' + c, id, tid);
        } else A.constructs.hit('smilie.other', id, tid);
        if (at('data-shortname')) A.constructs.hit('smilie.data-shortname', id, tid);
      } else {
        A.constructs.hit('img', id, tid);
        const real = [durl, dsrc, src].find((v) => v && !isPlaceholder(v)) || '';
        if (durl) A.constructs.hit('img.data-url', id, tid);
        if (dsrc) A.constructs.hit('img.data-src', id, tid);
        if (src) A.constructs.hit('img.src', id, tid);
        if (src && isPlaceholder(src)) A.constructs.hit('img.src-is-placeholder', id, tid);
        if (!real) A.constructs.hit('img.no-real-url', id, tid);
        if (noscriptDepth > 0) A.constructs.hit('img.inside-noscript', id, tid);
        if (real.includes('proxy.php?image=')) A.constructs.hit('img.xf-proxy', id, tid);
        if (real) A.imgHosts.hit(hostOf(real), id, tid);
        if (at('width') || at('height')) A.constructs.hit('img.has-dimensions', id, tid);
      }
    }

    if (name === 'a') {
      const href = at('href');
      A.constructs.hit('link', id, tid);
      // an anchor inside quote/spoiler/unfurl chrome is not a link the author
      // wrote — the quote header's "↑" jump link alone is ~266k of them, and
      // counting those as content links overstates the link total by 40%
      if (chromeDepth > 0 || has('bbCodeBlock-sourceJump') || has('bbCodeBlock-expandLink')) {
        A.constructs.hit('link.chrome', id, tid);
      } else {
        A.constructs.hit('link.in-body', id, tid);
        // hosts are counted over BODY anchors only, so the "what does this board
        // link to" list is not swamped by 266k internal quote-jump anchors
        A.linkHosts.hit(hostOf(href), id, tid);
      }
      if (has('link--internal')) A.constructs.hit('link.internal', id, tid);
      else if (has('link--external')) A.constructs.hit('link.external', id, tid);
      else A.constructs.hit('link.unclassed', id, tid);
      if (/\/attachments\//.test(href)) A.constructs.hit('attachment.link', id, tid);
      if (/\/members\//.test(href)) A.constructs.hit('mention.member-link', id, tid);
      if (/\/goto\/post\?id=(\d+)/.test(href)) A.constructs.hit('link.goto-post', id, tid);
      if (at('data-usergroup-id')) A.constructs.hit('mention.usergroup', id, tid);
      if (at('data-cfemail') || has('__cf_email__')) A.constructs.hit('cf-email', id, tid);
      if (at('data-xf-init')) A.constructs.hit('link.data-xf-init:' + at('data-xf-init').slice(0, 30), id, tid);
    }

    if (has('username') && at('data-user-id')) {
      A.constructs.hit('mention.username-span', id, tid);
      // display names on the source board can themselves contain bbcode
      // (`data-username="[SIZE=6]5foot8Paki[/SIZE]"`), and the converter builds
      // the mention label straight out of that attribute
      const un = at('data-username');
      if (un.includes('[')) {
        A.constructs.hit('mention.username-contains-bbcode', id, tid);
        example(A, 'username-bbcode', id, tid, un, 4);
      }
    }
    if (has('__cf_email__')) A.constructs.hit('cf-email.span', id, tid);
    if (at('data-s9e-mediaembed')) {
      A.constructs.hit('media.s9e', id, tid);
      A.constructs.hit('media-site:' + at('data-s9e-mediaembed').slice(0, 30), id, tid);
    }
    if (has('bbMediaWrapper')) {
      A.constructs.hit('media.bbMediaWrapper', id, tid);
      const site = at('data-media-site-id');
      if (site) A.constructs.hit('media-site:' + site.slice(0, 30), id, tid);
    }
    if (name === 'iframe') A.constructs.hit('media.iframe', id, tid);
    if (name === 'video') A.constructs.hit('media.video', id, tid);
    if (name === 'audio') A.constructs.hit('media.audio', id, tid);
    if (name === 'table') A.constructs.hit('table', id, tid);
    if (name === 'ul' || name === 'ol') A.constructs.hit('list.' + name, id, tid);
    if (at('data-xf-list-type')) A.constructs.hit('list.data-xf-list-type:' + at('data-xf-list-type'), id, tid);
    if (name === 'hr') A.constructs.hit('hr', id, tid);
    if (name === 's' || name === 'strike' || name === 'del') A.constructs.hit('strike', id, tid);
    if (name === 'ins') A.constructs.hit('ins', id, tid);
    if (name === 'sup') A.constructs.hit('sup', id, tid);
    if (name === 'sub') A.constructs.hit('sub', id, tid);
    if (/^h[1-6]$/.test(name)) A.constructs.hit('heading.' + name, id, tid);

    const style = attrs ? at('style') : '';
    if (style) {
      const styleFact = (fact, cond) => {
        if (!cond) return;
        A.constructs.hit('style.' + fact, id, tid);
        if (seenTokens) seenTokens.add('style:' + fact);
      };
      styleFact('font-size', /font-size:/i.test(style));
      styleFact('font-family', /font-family:/i.test(style));
      styleFact('color', /(?:^|;|\s)color:/i.test(style));
      styleFact('background', /background(?:-color)?:/i.test(style));
      styleFact('text-align', /text-align:/i.test(style));
      if (/text-align:/i.test(style)) {
        const al = /text-align:\s*([a-z]+)/i.exec(style);
        if (al) A.constructs.hit('align:' + al[1].toLowerCase(), id, tid);
      }
      styleFact('text-decoration', /text-decoration:/i.test(style));
      styleFact('dimension', /(?:^|;)\s*(?:width|height|max-width|max-height)\s*:/i.test(style));
    }

    if (!selfClose && !VOID.has(name)) {
      const chrome = classList !== null && classList.some((c) => CHROME_FRAME.has(c));
      if (chrome) chromeDepth++;
      stack.push({
        name, len: 0,
        isQuote, isSpoiler, chrome,
        skip: classList !== null && classList.some((c) => CHROME_CLASS.has(c)),
        qd: quoteDepth,
      });
    } else if (name === 'br' && stack.length) {
      stack[stack.length - 1].brs = (stack[stack.length - 1].brs || 0) + 1;
    }
  }
  if (pos < html.length) addText(html.slice(pos), pos);

  const unclosed = stack.length;
  while (stack.length) closeFrame(stack, A, id, tid);

  if (unclosed) { A.constructs.hit('parse.unclosed-elements', id, tid, unclosed); badId(A, 'unclosed-elements', id); }
  if (mismatched) badId(A, 'stray-close-tag', id);

  if (maxQuote) bump(A.quoteDepthPost, maxQuote);
  if (maxSpoiler) bump(A.spoilerDepthPost, maxSpoiler);
  if (maxCombo) bump(A.comboDepthPost, maxCombo);

  // unbalanced literal bbcode within the post
  for (const t of new Set([...Object.keys(bbOpen), ...Object.keys(bbClose)])) {
    const o = bbOpen[t] || 0, c = bbClose[t] || 0;
    if (o === c) continue;
    if (c > o) {
      A.constructs.hit('malformed.bb-stray-closer', id, tid, c - o);
      A.bbLiteralForms.hit(`(stray closer) [/${t}]`, id, tid, c - o);
      badId(A, 'bb-stray-closer', id);
    } else {
      A.constructs.hit('malformed.bb-unclosed-opener', id, tid, o - c);
      A.bbLiteralForms.hit(`(unclosed) [${t}]`, id, tid, o - c);
      badId(A, 'bb-unclosed-opener', id);
    }
  }

  // ------------------------------------------------------------ language
  const text = row.text || '';
  A.textBytes += text.length;
  A.langs.hit(detectLang(text), id, tid);

  // ------------------------------------------------------------ lossless sample
  if (isSample) evaluateLossless(A, id, tid, seenTokens, leaks, unclosed, mismatched);

  return { maxQuote, maxSpoiler };
}

function closeFrame(stack, A, id, tid) {
  const f = stack.pop();
  const parent = stack[stack.length - 1];
  if (parent && !f.skip) {
    parent.len += f.len;
    parent.brs = (parent.brs || 0) + (f.brs || 0);
  }
  if (f.isQuote) {
    const len = f.len;
    bump(A.quoteLen, len < 4000 ? len : Math.round(len / 100) * 100);
    bump(A.quoteBr, Math.min(f.brs || 0, 400));
  }
}

function countFlag(stack, flag) {
  let n = 0;
  for (const f of stack) if (f[flag]) n++;
  return n;
}

function readAttrs(s) {
  const out = [];
  ATTR_RE.lastIndex = 0;
  let m;
  while ((m = ATTR_RE.exec(s))) {
    const name = m[1].toLowerCase();
    if (name === '/' || name === '') continue;
    out.push([name, m[2] ?? m[3] ?? m[4] ?? '']);
  }
  return out;
}

function contextAround(html, idx, span) {
  return html.slice(Math.max(0, idx - span), idx + span).replace(/\s+/g, ' ');
}

// -------------------------------------------------------------- language guess

/**
 * Script detection first, then stopword scoring for latin-script text.
 *
 * Limits, stated plainly: this is a heuristic, not a trained classifier. Very
 * short posts ("+1", "cope") carry no signal and land in `und`. English is
 * over-represented by construction because forum jargon (looksmax, mog, NT,
 * PSL) is English regardless of the poster's language, so a mostly-Spanish post
 * with heavy jargon can score English. Portuguese/Spanish and the
 * Serbo-Croatian group are the most likely confusions.
 */
const STOP = {
  en: 'the and you that for are with this have not but your just like what all get from have was they can about would there their',
  es: 'que de la el no en es por con para los una como más pero este muy todo se ser hay eso tengo porque',
  pt: 'que não de para com uma você mais isso mas como meu muito ele ela também então tem sou fazer aqui',
  fr: 'que pas les des une est pour dans avec sur mais tout plus comme être cette vous suis moi bien',
  de: 'und der die das nicht ich ist du mit auf für ein eine aber auch wie sich sind haben mich schon',
  it: 'che non per con una sono come più anche questo mio molto quando fare essere della sei',
  nl: 'het een niet van dat ook maar voor met zijn heb deze ben mij hoe naar',
  pl: 'nie jest się że jak ale tak mnie tylko jestem dla czy jego już bardzo',
  tr: 'bir ve bu için ben çok ama daha gibi var ne sen değil olarak kadar sonra',
  ro: 'nu este ca cu pentru care mai dar sunt sau doar ceva foarte',
  id: 'yang dan tidak ini saya ada untuk dengan itu aku gak juga bisa',
  sv: 'och att det som inte för med han den jag har vara',
  fi: 'että ole niin kuin mutta vain sitä hän ovat myös',
  cs: 'jsem není jsou ale jako tak jeho když nebo více',
  hu: 'hogy nem egy volt csak vagy már mint még ezt',
  vi: 'không của và là được người những cho với anh',
  hr: 'nije samo kao ali koji ovo jer sam ima znam',
  sq: 'nuk është për një dhe unë por edhe kam shumë',
  al: '',
};
const STOPSETS = Object.fromEntries(Object.entries(STOP)
  .filter(([, v]) => v)
  .map(([k, v]) => [k, new Set(v.split(' '))]));

function detectLang(textRaw) {
  const text = (textRaw || '').slice(0, 800);
  if (text.trim().length < 20) return 'und (too short)';
  let cyr = 0, arab = 0, greek = 0, heb = 0, cjk = 0, kana = 0, hangul = 0, thai = 0, deva = 0, arm = 0, geo = 0, latin = 0, letters = 0;
  for (const ch of text) {
    const c = ch.codePointAt(0);
    if (c < 0x41) continue;
    if ((c >= 0x41 && c <= 0x5a) || (c >= 0x61 && c <= 0x7a) || (c >= 0xc0 && c <= 0x24f)) { latin++; letters++; }
    else if (c >= 0x400 && c <= 0x4ff) { cyr++; letters++; }
    else if (c >= 0x600 && c <= 0x6ff) { arab++; letters++; }
    else if (c >= 0x370 && c <= 0x3ff) { greek++; letters++; }
    else if (c >= 0x590 && c <= 0x5ff) { heb++; letters++; }
    else if ((c >= 0x4e00 && c <= 0x9fff) || (c >= 0x3400 && c <= 0x4dbf)) { cjk++; letters++; }
    else if ((c >= 0x3040 && c <= 0x30ff)) { kana++; letters++; }
    else if (c >= 0xac00 && c <= 0xd7af) { hangul++; letters++; }
    else if (c >= 0xe00 && c <= 0xe7f) { thai++; letters++; }
    else if (c >= 0x900 && c <= 0x97f) { deva++; letters++; }
    else if (c >= 0x530 && c <= 0x58f) { arm++; letters++; }
    else if (c >= 0x10a0 && c <= 0x10ff) { geo++; letters++; }
  }
  if (!letters) return 'und (no letters)';
  const frac = (n) => n / letters;
  if (frac(hangul) > 0.15) return 'ko';
  if (frac(kana) > 0.10) return 'ja';
  if (frac(cjk) > 0.15) return 'zh';
  if (frac(thai) > 0.15) return 'th';
  if (frac(deva) > 0.15) return 'hi';
  if (frac(arm) > 0.15) return 'hy';
  if (frac(geo) > 0.15) return 'ka';
  if (frac(heb) > 0.15) return 'he';
  if (frac(greek) > 0.15) return 'el';
  if (frac(arab) > 0.15) return /[پچژگ]/.test(text) ? 'fa' : 'ar';
  if (frac(cyr) > 0.15) return /[іїєґ]/.test(text) ? 'uk' : /[ђћџњљ]/.test(text) ? 'sr' : 'ru';

  const words = text.toLowerCase().match(/[a-zà-öø-ÿıçğşœ]+/g);
  if (!words || words.length < 4) return 'und (too short)';
  const score = Object.create(null);
  let best = 'und (no stopword match)', bestN = 0;
  for (const w of words) {
    for (const k in STOPSETS) if (STOPSETS[k].has(w)) { score[k] = (score[k] || 0) + 1; }
  }
  if (/[ığşı]/.test(text) && (score.tr || 0) > 0) score.tr = (score.tr || 0) + 1;
  for (const k in score) if (score[k] > bestN) { bestN = score[k]; best = k; }
  if (bestN < 2) return words.length > 25 ? 'und (latin, unmatched)' : 'und (too short)';
  return best;
}

// ------------------------------------------------------- converter coverage table

/**
 * The one hand-written table in this tool, and it is deliberately NOT a list of
 * constructs to look for — the census above is prior-free and finds those. This
 * maps a construct token that the census produced onto the converter branch
 * that handles it, so the report can carry a coverage column.
 *
 * `marker` is resolved against the real file at run time, so the cited line
 * numbers cannot go stale, and a branch that gets deleted shows up as MISSING
 * rather than silently staying green.
 *
 * Statuses:
 *   implemented — a branch exists that consumes the construct and emits a tag
 *   partial     — a branch exists but demonstrably drops part of the meaning
 *   none        — no branch; the construct is dropped or leaks
 *   n/a         — structural/decorative, nothing to carry across
 *
 * This column is a STATIC READ of the converter source. It is not a test of the
 * live site and says nothing about whether the result renders correctly.
 */
const IMPORT = 'extensions/looksmax-import/src/HtmlToBbcode.php';
const FORMAT = 'extensions/looksmax-format/src/Configure.php';

const COVERAGE = [
  ['quote', 'implemented', IMPORT, /hasClass\(\$n, 'bbCodeBlock--quote'\)/, 'author + source post id carried; [QUOTE] defined ' + FORMAT],
  ['quote.data-quote', 'implemented', IMPORT, /\$author = trim\(\$n->getAttribute\('data-quote'\)\)/, ''],
  ['quote.no-data-quote', 'implemented', IMPORT, /said:\\s\*\$\/iu/, 'falls back to parsing the "X said:" title'],
  ['quote.data-source-post', 'implemented', IMPORT, /post:\\s\*\(\\d\+\)/, 'resolved to a local link at render time by ResolveQuoteLinks'],
  ['quote.data-attributes', 'partial', IMPORT, null, 'the display name is taken from data-quote, but the `member: N` source user id inside data-attributes is never read — so the quoted user is not linked to their imported account and [QUOTE]\'s own `avatar=` attribute (declared in Configure.php) is never populated. Present on essentially every quote in the corpus.'],
  ['quote.data-attributes-member', 'partial', IMPORT, null, 'the `member: N` half of data-attributes specifically: present on essentially every quote, read by nothing'],
  ['link.chrome', 'implemented', IMPORT, /private const CHROME/, 'anchors belonging to quote/spoiler/unfurl chrome (the "↑" jump link, "Click to expand") — dropped on purpose; they are not links the author wrote'],
  ['link.in-body', 'implemented', IMPORT, /private function anchor/, 'anchors the author actually wrote; this, not the `link` total, is the number that matters'],
  ['link.unclassed', 'implemented', IMPORT, /private function anchor/, 'XenForo only adds link--internal/link--external to anchors it recognises; the rest are quote jumps, attachment links and profile links'],
  ['mention.username-contains-bbcode', 'none', IMPORT, null, 'the mention label is taken verbatim from data-username, and some source display names contain bbcode ("[SIZE=6]5foot8Paki[/SIZE]"), which lands in the mention text'],
  ['table.style', 'none', IMPORT, null, 'XenForo table style modifiers (alternate / collapse / nobackground / noborder / centered on the <table>) are dropped; every table renders identically'],
  ['unfurl.is-pending', 'partial', IMPORT, /js-unfurl-title/, 'XenForo never resolved the preview for these cards, so the title is the bare url and the card renders as a link to nowhere useful'],
  ['spoiler', 'implemented', IMPORT, /hasClass\(\$n, 'bbCodeSpoiler'\)/, ''],
  ['spoiler.button-title', 'implemented', IMPORT, /bbCodeSpoiler-button-title/, ''],
  ['unfurl', 'implemented', IMPORT, /hasClass\(\$n, 'bbCodeBlock--unfurl'\)/, ''],
  ['code-block', 'implemented', IMPORT, /hasClass\(\$n, 'bbCodeBlock--code'\)/, ''],
  ['code-block.data-lang', 'implemented', IMPORT, /\$lang = \$pre \? trim\(\$pre->getAttribute\('data-lang'\)\)/, ''],
  ['code-inline', 'implemented', IMPORT, /return \$t === '' \? '' : '\[c\]'/, ''],
  ['image-wrapper', 'implemented', IMPORT, /hasClass\(\$n, 'bbImageWrapper'\)/, ''],
  ['img', 'implemented', IMPORT, /case 'img':/, ''],
  ['img.data-url', 'implemented', IMPORT, /foreach \(\['data-url', 'data-src', 'src'\] as \$a\)/, 'attribute preference order data-url > data-src > src'],
  ['img.xf-proxy', 'implemented', IMPORT, /proxy\.php\?image=/, 'unwrapped to the real url'],
  ['img.inside-noscript', 'implemented', IMPORT, /case 'noscript':/, '<noscript> duplicate dropped'],
  ['img.aligned', 'implemented', IMPORT, /bbImageAligned--left/, ''],
  ['image.aligned', 'implemented', IMPORT, /bbImageAligned--left/, ''],
  ['smilie', 'implemented', IMPORT, /hasClass\(\$n, 'smilie'\)/, ''],
  ['smilie.emoji', 'implemented', IMPORT, /hasClass\(\$n, 'smilie--emoji'\)/, 'alt holds the unicode char; flarum/emoji renders it'],
  ['smilie.sprite', 'partial', IMPORT, /\[emote name="/, 'sprite sheet is not scraped, so the emote becomes a text chip with the emote name — the picture is lost'],
  ['link', 'implemented', IMPORT, /private function anchor/, ''],
  ['link.internal', 'partial', IMPORT, /private function anchor/, 'converted as a plain [url]; a link to a thread/post on the source board is not rewritten to the local url'],
  ['link.goto-post', 'partial', IMPORT, /private function anchor/, 'same: /goto/post?id=N points at looksmax.org, not the imported post'],
  ['link.external', 'implemented', IMPORT, /private function anchor/, ''],
  ['mention.username-span', 'implemented', IMPORT, /hasClass\(\$n, 'username'\) && \$n->getAttribute\('data-user-id'\)/, ''],
  ['mention.member-link', 'implemented', IMPORT, /#\/members\/\[\^\/\]\*\\\.\(\\d\+\)#/, ''],
  ['mention.usergroup', 'implemented', IMPORT, /data-usergroup-id/, ''],
  ['media.s9e', 'implemented', IMPORT, /data-s9e-mediaembed/, ''],
  ['media.bbMediaWrapper', 'implemented', IMPORT, /hasClass\(\$n, 'bbMediaWrapper'\)/, ''],
  ['media.iframe', 'implemented', IMPORT, /case 'iframe':/, ''],
  ['media.video', 'implemented', IMPORT, /case 'video':/, ''],
  ['media.audio', 'implemented', IMPORT, /case 'audio':/, ''],
  ['table', 'implemented', IMPORT, /private function table/, ''],
  ['list.ul', 'implemented', IMPORT, /case 'ul':/, ''],
  ['list.ol', 'implemented', IMPORT, /case 'ol':/, ''],
  ['hr', 'implemented', IMPORT, /case 'hr':/, ''],
  ['strike', 'implemented', IMPORT, /case 'strike':/, ''],
  ['ins', 'implemented', IMPORT, /case 'ins':/, ''],
  ['sup', 'implemented', IMPORT, /case 'sup':/, ''],
  ['sub', 'implemented', IMPORT, /case 'sub':/, ''],
  ['style.font-size', 'implemented', IMPORT, /font-size:\\s\*\(\\d\+\)\\s\*px/, ''],
  ['style.font-family', 'implemented', IMPORT, /font-family:\\s\*\(\[\^;\]\+\)/, ''],
  ['style.color', 'implemented', IMPORT, /color:\\s\*\(\[\^;\]\+\)/, ''],
  ['style.background', 'implemented', IMPORT, /background\(\?:-color\)\?:/, ''],
  ['style.text-align', 'implemented', IMPORT, /text-align:\\s\*\(left\|right\|center\|justify\)/, ''],
  ['cf-email', 'implemented', IMPORT, /private function cfEmail/, ''],
  ['attachment.link', 'partial', IMPORT, /private function anchor/, 'an /attachments/ url is kept as a plain link to looksmax.org; the file itself is not fetched or re-hosted, and [ATTACH] is declared in Configure::OWNED but no branch emits it'],
  ['heading.h1', 'partial', IMPORT, /\$sizes = \['h1' => 26/, 'headings become [size][b], not a heading element — the semantic level is lost, and so is any anchor/outline built from it'],
  ['heading.h2', 'partial', IMPORT, /\$sizes = \['h1' => 26/, 'as h1'],
  ['heading.h3', 'partial', IMPORT, /\$sizes = \['h1' => 26/, 'as h1'],
  ['heading.h4', 'partial', IMPORT, /\$sizes = \['h1' => 26/, 'as h1'],
  ['heading.h5', 'partial', IMPORT, /\$sizes = \['h1' => 26/, 'as h1'],
  ['heading.h6', 'partial', IMPORT, /\$sizes = \['h1' => 26/, 'as h1'],
  ['style.text-decoration', 'none', IMPORT, null, 'styledInline reads font-family/font-size/color/background only; an underline or line-through applied as a css style (rather than <u>/<s>) is dropped silently'],
  ['style.dimension', 'none', IMPORT, null, 'inline width/height/max-width on a span or div is dropped; only <img width/height> survives'],
  ['list.data-xf-list-type', 'partial', IMPORT, /case 'ol':/, 'XenForo records the ordered-list marker style (1/a/A/i/I) in data-xf-list-type; the converter always emits [LIST=1] (decimal)'],
  ['quote.blockquote-without-class', 'none', IMPORT, null, 'a <blockquote> that is not bbCodeBlock--quote falls through to the generic walk and loses its blockquote-ness entirely'],
  ['img.has-dimensions', 'implemented', IMPORT, /ctype_digit\(\$w\)/, 'intrinsic width/height carried into [IMG] so images do not reflow the thread'],
  ['img.no-real-url', 'implemented', IMPORT, /if \(\$src === ''\) \{/, 'an image with only a data: placeholder and no real url is dropped rather than emitted broken'],
  ['code-block.no-lang', 'implemented', IMPORT, /\^code:\?\$\/i/, 'falls back to the "PHP:"/"Code:" block title'],
];

/**
 * EVERY token the corpus contains, classified. This is affordable because the
 * vocabulary turns out to be tiny and completely enumerable — 30 element names,
 * 31 data-* attributes and 176 class tokens (99 of which are sprite indices), so
 * there is no long tail to wave at. Anything that appears and is NOT here comes
 * out of the sample check as `unclassified`, loudly, rather than being silently
 * treated as harmless.
 *
 *   implemented — the converter reads it and carries the meaning across
 *   partial     — read, but something measurable is dropped
 *   none        — nothing reads it; the meaning is lost
 *   chrome      — XenForo markup scaffolding or JS hooks; there is no meaning to
 *                 carry, the target theme draws its own
 */
const TOKEN_CLASS = new Map(Object.entries({
  // ---- elements (all 30 that occur)
  'el:br': 'implemented', 'el:div': 'implemented', 'el:span': 'implemented',
  'el:a': 'implemented', 'el:b': 'implemented', 'el:strong': 'implemented',
  'el:i': 'implemented', 'el:em': 'implemented', 'el:u': 'implemented',
  'el:s': 'implemented', 'el:strike': 'implemented', 'el:del': 'implemented',
  'el:ins': 'implemented', 'el:sup': 'implemented', 'el:sub': 'implemented',
  'el:blockquote': 'implemented', 'el:img': 'implemented', 'el:li': 'implemented',
  'el:ul': 'implemented', 'el:ol': 'implemented', 'el:table': 'implemented',
  'el:tbody': 'implemented', 'el:thead': 'implemented', 'el:tr': 'implemented',
  'el:td': 'implemented', 'el:th': 'implemented', 'el:hr': 'implemented',
  'el:code': 'implemented', 'el:pre': 'implemented', 'el:video': 'implemented',
  'el:audio': 'implemented', 'el:source': 'implemented', 'el:iframe': 'implemented',
  'el:noscript': 'implemented', 'el:p': 'implemented',
  'el:h1': 'partial', 'el:h2': 'partial', 'el:h3': 'partial',
  'el:h4': 'partial', 'el:h5': 'partial', 'el:h6': 'partial',
  'el:button': 'chrome', 'el:script': 'chrome', 'el:style': 'chrome',

  // ---- class tokens
  'class:bbCodeBlock--quote': 'implemented',
  'class:bbCodeSpoiler': 'implemented',
  'class:bbCodeSpoiler-button-title': 'implemented',
  'class:bbCodeBlock--unfurl': 'implemented',
  'class:bbCodeBlockUnfurl-icon': 'implemented',
  'class:bbCodeBlock--code': 'implemented',
  'class:bbCodeCode': 'implemented',
  'class:bbCodeInline': 'implemented',
  'class:bbImageWrapper': 'implemented',
  'class:bbImage': 'implemented',
  'class:bbImageAligned--left': 'implemented',
  'class:bbImageAligned--right': 'implemented',
  'class:bbMediaWrapper': 'implemented',
  'class:smilie': 'implemented',
  'class:smilie--emoji': 'implemented',
  'class:smilie--sprite': 'partial',
  'class:username': 'implemented',
  'class:ug': 'implemented',
  'class:__cf_email__': 'implemented',
  'class:uw_large_emoji': 'implemented',
  'class:link--external': 'implemented',
  'class:link--internal': 'partial',
  'class:centered': 'partial',
  'class:right': 'partial',
  // table style modifiers — the converter emits a bare [table]
  'class:alternate': 'none', 'class:collapse': 'none',
  'class:nobackground': 'none', 'class:noborder': 'none',
  // scaffolding / JS hooks
  'class:bbCodeBlock': 'chrome', 'class:bbCodeBlock-content': 'chrome',
  'class:bbCodeBlock--expandable': 'chrome', 'class:bbCodeBlock-expandContent': 'chrome',
  'class:bbCodeBlock-expandLink': 'chrome', 'class:bbCodeBlock-title': 'chrome',
  'class:bbCodeBlock-sourceJump': 'chrome', 'class:bbCodeBlock--spoiler': 'chrome',
  'class:bbCodeBlock--screenLimited': 'chrome', 'class:bbCodeSpoiler-button': 'chrome',
  'class:bbCodeSpoiler-content': 'chrome', 'class:bbTable': 'chrome',
  'class:bbMediaWrapper-inner': 'chrome', 'class:bbMediaWrapper--inline': 'chrome',
  'class:bbMediaWrapper-fallback': 'chrome', 'class:bbMediaWrapper-inner--audio': 'chrome',
  'class:bbMediaWrapper-inner--150px': 'chrome',
  'class:js-expandWatch': 'chrome', 'class:js-expandContent': 'chrome',
  'class:js-expandLink': 'chrome', 'class:js-lbImage': 'chrome',
  'class:js-unfurl': 'chrome', 'class:js-unfurl-title': 'chrome',
  'class:js-unfurl-desc': 'chrome', 'class:js-unfurl-favicon': 'chrome',
  'class:js-unfurl-figure': 'chrome',
  'class:link': 'chrome', 'class:lazyload': 'chrome', 'class:fauxBlockLink': 'chrome',
  'class:fauxBlockLink-blockLink': 'chrome', 'class:contentRow': 'chrome',
  'class:contentRow-main': 'chrome', 'class:contentRow-header': 'chrome',
  'class:contentRow-snippet': 'chrome', 'class:contentRow-minor': 'chrome',
  'class:contentRow-minor--hideLinks': 'chrome', 'class:contentRow-figure': 'chrome',
  'class:contentRow-figure--fixedSmall': 'chrome',
  'class:button': 'chrome', 'class:button-text': 'chrome', 'class:button--longText': 'chrome',
  'class:is-pending': 'chrome', 'class:is-recrawl': 'chrome',
  'class:fa-2x': 'chrome', 'class:fa--xf': 'chrome', 'class:fas': 'chrome',
  'class:fa-spinner': 'chrome', 'class:fa-pulse': 'chrome', 'class:u-muted': 'chrome',

  // ---- data-* attributes
  'data:data-quote': 'implemented', 'data:data-source': 'implemented',
  'data:data-user-id': 'implemented', 'data:data-username': 'implemented',
  'data:data-usergroup-id': 'implemented', 'data:data-groupname': 'implemented',
  'data:data-url': 'implemented', 'data:data-src': 'implemented',
  'data:data-host': 'implemented', 'data:data-lang': 'implemented',
  'data:data-shortname': 'implemented', 'data:data-cfemail': 'implemented',
  'data:data-s9e-mediaembed': 'implemented', 'data:data-s9e-mediaembed-iframe': 'implemented',
  'data:data-media-site-id': 'implemented', 'data:data-proxy-href': 'implemented',
  // read, but only partly
  'data:data-attributes': 'partial',   // display name taken from data-quote; the `member: N` id is dropped
  'data:data-xf-list-type': 'partial', // the 1/a/A/i/I marker style is dropped
  // pure client-side plumbing
  'data:data-xf-click': 'chrome', 'data:data-xf-init': 'chrome',
  'data:data-content-selector': 'chrome', 'data:data-zoom-target': 'chrome',
  'data:data-type': 'chrome', 'data:data-lb-sidebar-href': 'chrome',
  'data:data-lb-caption-extra-html': 'chrome', 'data:data-single-image': 'chrome',
  'data:data-onerror': 'chrome', 'data:data-unfurl': 'chrome',
  'data:data-result-id': 'chrome', 'data:data-pending': 'chrome',
  'data:data-media-key': 'chrome',

  // ---- inline style facts (pseudo-tokens, so the style axis is measured too)
  'style:font-size': 'implemented', 'style:font-family': 'implemented',
  'style:color': 'implemented', 'style:background': 'implemented',
  'style:text-align': 'implemented',
  'style:text-decoration': 'none',
  'style:dimension': 'none',
}));

function classifyToken(tok) {
  const direct = TOKEN_CLASS.get(tok);
  if (direct) return direct;
  if (/^class:smilie--sprite\d+$/.test(tok)) return 'partial';
  return null;
}

/** every token whose classification is not `chrome` — chrome has no meaning to lose */
function tokensThatMatter(seen) {
  const out = [];
  for (const t of seen) if (classifyToken(t) !== 'chrome') out.push(t);
  return out;
}

function evaluateLossless(A, id, tid, seen, leaks, unclosed, mismatched) {
  const L = A.lossless;
  L.sampled++;
  const reasons = [];

  for (const tok of tokensThatMatter(seen)) {
    const st = classifyToken(tok);
    if (!st) { reasons.push('unclassified-token:' + tok); continue; }
    if (st === 'partial') reasons.push('partial-token:' + tok);
    else if (st === 'none') reasons.push('unhandled-token:' + tok);
  }
  for (const t of leaks) {
    reasons.push((REGISTERED.has(t.toUpperCase()) ? 'bb-literal-registered:' : 'bb-literal-unregistered:') + t);
  }
  if (unclosed) reasons.push('parse:unclosed-elements');
  if (mismatched) reasons.push('parse:stray-close-tag');

  const strictFail = reasons.length > 0;
  const lenientFail = reasons.some((r) => r.startsWith('unhandled-token:')
    || r.startsWith('unclassified-token:') || r.startsWith('bb-literal-unregistered:'));
  if (!strictFail) L.strictOk++;
  if (!lenientFail) L.lenientOk++;
  for (const r of new Set(reasons)) bump(L.reasons, r);
  if (lenientFail && L.failPosts.length < 40) L.failPosts.push(id);
}

/**
 * The tag vocabulary that exists downstream: flarum/bbcode's 15 plus everything
 * local/looksmax-format declares in Configure::OWNED. A literal `[tag]` whose
 * name is NOT in here can never render as anything but visible text.
 */
const FLARUM_BBCODE = ['B', 'I', 'U', 'S', 'URL', 'IMG', 'EMAIL', 'CODE', 'QUOTE',
  'LIST', 'DEL', 'COLOR', 'CENTER', 'SIZE', '*'];
let REGISTERED = new Set(FLARUM_BBCODE);

function loadRegistered(extDir) {
  const p = path.join(extDir, 'looksmax-format/src/Configure.php');
  const src = fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '';
  const m = /public const OWNED = \[([\s\S]*?)\];/.exec(src);
  const owned = m ? [...m[1].matchAll(/'([A-Z*]+)'/g)].map((x) => x[1]) : [];
  REGISTERED = new Set([...FLARUM_BBCODE, ...owned]);
  return { owned, registered: [...REGISTERED].sort() };
}

function resolveCoverage(extDir) {
  const cache = new Map();
  const read = (rel) => {
    if (!cache.has(rel)) {
      const p = path.join(extDir, rel.replace(/^extensions\//, ''));
      cache.set(rel, fs.existsSync(p) ? fs.readFileSync(p, 'utf8').split('\n') : null);
    }
    return cache.get(rel);
  };
  const rows = [];
  for (const [token, status, file, marker, note] of COVERAGE) {
    let line = null, resolved = marker === null ? 'n/a' : 'MISSING';
    if (marker) {
      const lines = read(file);
      if (lines) {
        for (let i = 0; i < lines.length; i++) {
          if (marker.test(lines[i])) { line = i + 1; resolved = 'ok'; break; }
        }
      } else resolved = 'FILE NOT FOUND';
    }
    rows.push({ token, status, file, line, resolved, note });
  }
  return rows;
}

// ------------------------------------------------------------------ worker mode

function runWorker(args) {
  // the downstream tag vocabulary decides which literal [tag] can only ever be
  // visible text, so the worker needs it too, not just the report renderer
  loadRegistered(args.ext ? path.resolve(args.ext) : path.join(REPO, 'extensions'));
  const db = new DatabaseSync(`file:${args.db}?mode=ro`, { readOnly: true });
  db.exec('PRAGMA query_only=1');
  const A = newAccumulator();
  const sample = { on: args.sampleMod > 0, mod: args.sampleMod || 1, rem: 0 };

  // The first shard also takes the orphan rows (posts whose thread_id is NULL —
  // 896 of them, and they would otherwise fall out of every range silently), and
  // the last shard's upper bound is open, so rows the live scraper inserts into
  // brand-new threads during the run are still scanned instead of vanishing.
  const range = args.shard === 0
    ? '(thread_id is null or (thread_id >= ? and thread_id <= ?))'
    : '(thread_id >= ? and thread_id <= ?)';
  const sql = `select id, thread_id, html, text from posts
               where ${range} and html is not null and html <> ''
               order by thread_id, id` + (args.limit ? ` limit ${args.limit}` : '');
  const stmt = db.prepare(sql);
  let lastThread = -1;
  for (const row of stmt.iterate(args.from, args.to)) {
    if (row.thread_id !== lastThread) { A.threads++; lastThread = row.thread_id; }
    try {
      analyzePost(row, A, sample);
    } catch (e) {
      bump(A.counts, 'analyzer-exception');
      badId(A, 'analyzer-exception', row.id);
      A.constructs.hit('parse.analyzer-exception', row.id, row.thread_id);
    }
  }
  db.close();

  const out = {
    posts: A.posts, threads: A.threads, htmlBytes: A.htmlBytes, textBytes: A.textBytes,
    elements: A.elements.toJSON(), classes: A.classes.toJSON(), dataAttrs: A.dataAttrs.toJSON(),
    tagAttrs: A.tagAttrs.toJSON(), constructs: A.constructs.toJSON(),
    linkHosts: A.linkHosts.toJSON(), imgHosts: A.imgHosts.toJSON(),
    bbLiteral: A.bbLiteral.toJSON(), bbLiteralForms: A.bbLiteralForms.toJSON(),
    langs: A.langs.toJSON(),
    counts: A.counts, quoteLen: A.quoteLen, quoteBr: A.quoteBr,
    quoteDepthOcc: A.quoteDepthOcc, quoteDepthPost: A.quoteDepthPost,
    spoilerDepthOcc: A.spoilerDepthOcc, spoilerDepthPost: A.spoilerDepthPost,
    comboDepthPost: A.comboDepthPost,
    quoteSources: A.quoteSources, examples: A.examples, badIds: A.badIds,
    lossless: A.lossless,
  };
  fs.writeFileSync(args.partial, JSON.stringify(out));
  process.exit(0);
}

// ------------------------------------------------------------------ parent mode

function partitionThreads(db, workers) {
  const rows = db.prepare(`select thread_id, count(*) c from posts
                           where html is not null and html <> ''
                           group by thread_id order by thread_id`).all();
  const total = rows.reduce((a, r) => a + r.c, 0);
  const target = Math.ceil(total / workers);
  const parts = [];
  let start = rows.length ? rows[0].thread_id : 0, acc = 0;
  for (let i = 0; i < rows.length; i++) {
    acc += rows[i].c;
    const last = i === rows.length - 1;
    if (acc >= target || last) {
      parts.push({ from: start, to: rows[i].thread_id, posts: acc });
      acc = 0;
      if (!last) start = rows[i + 1].thread_id;
    }
  }
  return { parts, total, threads: rows.length };
}

async function runParent(args) {
  const t0 = Date.now();
  if (!args.db) throw new Error('--db is required');
  const out = args.out ? path.resolve(args.out) : path.join(REPO, 'reports');
  fs.mkdirSync(out, { recursive: true });
  const extDir = args.ext ? path.resolve(args.ext) : path.join(REPO, 'extensions');

  const db = new DatabaseSync(`file:${args.db}?mode=ro`, { readOnly: true });
  db.exec('PRAGMA query_only=1');

  const meta = db.prepare(`select count(*) posts, count(distinct thread_id) threads,
    sum(length(html)) htmlBytes, sum(length(text)) textBytes,
    sum(case when html is null or html='' then 1 else 0 end) postsWithoutHtml,
    sum(has_hidden_content) hiddenContent, sum(is_first) firstPosts,
    min(id) minId, max(id) maxId, min(posted_ts) minTs, max(posted_ts) maxTs
    from posts`).get();

  const { parts } = partitionThreads(db, args.workers);
  if (parts.length) {
    parts[0].from = 0;                                  // + the NULL-thread orphans
    parts[parts.length - 1].to = Number.MAX_SAFE_INTEGER; // + whatever the scraper adds mid-run
  }
  const sampleMod = args.limit ? 0 : Math.max(1, Math.round(meta.posts / Math.max(1, args.sample)));

  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'corpus-survey-'));
  process.stderr.write(`[survey] ${num(meta.posts)} posts / ${num(meta.threads)} threads, ` +
    `${parts.length} shards, sample 1-in-${sampleMod || 'off'}\n`);

  const results = await Promise.all(parts.map((p, i) => new Promise((res, rej) => {
    const partial = path.join(tmp, `part-${i}.json`);
    const child = fork(SELF, ['--worker', '--db', args.db, '--from', String(p.from),
      '--to', String(p.to), '--shard', String(i), '--partial', partial,
      '--sample-mod', String(sampleMod), '--ext', extDir,
      ...(args.limit ? ['--limit', String(args.limit)] : [])],
      { execArgv: ['--no-warnings', '--max-old-space-size=3500'], stdio: ['ignore', 'inherit', 'inherit', 'ipc'] });
    child.on('exit', (code) => {
      if (code !== 0) return rej(new Error(`shard ${i} exited ${code}`));
      process.stderr.write(`[survey] shard ${i} done (+${((Date.now() - t0) / 1000).toFixed(1)}s)\n`);
      res(JSON.parse(fs.readFileSync(partial, 'utf8')));
    });
    child.on('error', rej);
  })));

  // -------------------------------------------------------------------- merge
  const M = {
    posts: 0, threads: 0, htmlBytes: 0, textBytes: 0,
    elements: {}, classes: {}, dataAttrs: {}, tagAttrs: {}, constructs: {},
    linkHosts: {}, imgHosts: {}, bbLiteral: {}, bbLiteralForms: {}, langs: {},
    counts: {}, quoteLen: {}, quoteBr: {}, quoteDepthOcc: {}, quoteDepthPost: {},
    spoilerDepthOcc: {}, spoilerDepthPost: {}, comboDepthPost: {},
    quoteSources: {}, examples: {}, badIds: {},
    lossless: { sampled: 0, strictOk: 0, lenientOk: 0, reasons: {}, failPosts: [] },
  };
  for (const r of results) {
    M.posts += r.posts; M.threads += r.threads;
    M.htmlBytes += r.htmlBytes; M.textBytes += r.textBytes;
    for (const k of ['elements', 'classes', 'dataAttrs', 'tagAttrs', 'constructs',
      'linkHosts', 'imgHosts', 'bbLiteral', 'bbLiteralForms', 'langs']) mergeCensus(M[k], r[k]);
    for (const k of ['counts', 'quoteLen', 'quoteBr', 'quoteDepthOcc', 'quoteDepthPost',
      'spoilerDepthOcc', 'spoilerDepthPost', 'comboDepthPost', 'quoteSources']) mergeCounter(M[k], r[k]);
    for (const k of Object.keys(r.examples)) {
      const arr = M.examples[k] || (M.examples[k] = []);
      for (const e of r.examples[k]) if (arr.length < 4) arr.push(e);
    }
    for (const k of Object.keys(r.badIds)) {
      const arr = M.badIds[k] || (M.badIds[k] = []);
      for (const e of r.badIds[k]) if (arr.length < 40) arr.push(e);
    }
    M.lossless.sampled += r.lossless.sampled;
    M.lossless.strictOk += r.lossless.strictOk;
    M.lossless.lenientOk += r.lossless.lenientOk;
    mergeCounter(M.lossless.reasons, r.lossless.reasons);
    for (const p of r.lossless.failPosts) if (M.lossless.failPosts.length < 40) M.lossless.failPosts.push(p);
  }

  // ------------------------------------------------- parent-side db-level facts
  const postIds = new Set();
  for (const r of db.prepare('select id from posts').iterate()) postIds.add(r.id);

  let refTotal = 0, refDistinct = 0, refPresent = 0, refPresentOcc = 0;
  const danglingExamples = [];
  for (const k of Object.keys(M.quoteSources)) {
    const n = M.quoteSources[k];
    refTotal += n; refDistinct++;
    if (postIds.has(Number(k))) { refPresent++; refPresentOcc += n; }
    else if (danglingExamples.length < 20) danglingExamples.push(Number(k));
  }
  const dangling = {
    quoteBlocksWithSourceId: refTotal,
    distinctSourceIds: refDistinct,
    distinctPresentInCorpus: refPresent,
    distinctMissing: refDistinct - refPresent,
    occurrencesResolvable: refPresentOcc,
    occurrencesDangling: refTotal - refPresentOcc,
    fractionDangling: refTotal ? (refTotal - refPresentOcc) / refTotal : 0,
    sampleMissingSourceIds: danglingExamples,
  };
  delete M.quoteSources;

  // the scraper is writing while this runs; re-count so the delta is visible
  // rather than looking like rows the tool missed
  const postsAtEnd = db.prepare('select count(*) c from posts').get().c;
  const orphanPosts = db.prepare('select count(*) c from posts where thread_id is null').get().c;

  const reactions = db.prepare(`select name, count(*) c, count(distinct post_id) posts
    from post_reactions group by name order by c desc`).all();
  const prefixes = db.prepare(`select coalesce(prefix,'(none)') prefix, count(*) threads
    from threads where id in (select distinct thread_id from posts)
    group by prefix order by threads desc`).all();
  const forums = db.prepare(`select f.id, f.title, count(distinct t.id) threads, count(p.id) posts
    from posts p join threads t on t.id = p.thread_id left join forums f on f.id = t.forum_id
    group by f.id, f.title order by posts desc limit 40`).all();
  const postImages = db.prepare(`select count(*) rows, count(distinct url) urls,
    count(distinct post_id) posts, sum(case when caption is not null and caption <> '' then 1 else 0 end) withCaption
    from post_images`).get();
  const userExtras = db.prepare(`select count(*) users,
    sum(case when title is not null and title <> '' then 1 else 0 end) withTitle,
    sum(case when banners is not null and banners <> '' and banners <> '[]' then 1 else 0 end) withBanners,
    sum(case when style_class is not null and style_class <> '' then 1 else 0 end) withStyleClass,
    sum(case when badge_id is not null and badge_id <> '' then 1 else 0 end) withBadge
    from users`).get();
  const postExtras = db.prepare(`select
    sum(case when user_title is not null and user_title <> '' then 1 else 0 end) withUserTitle,
    sum(case when user_banners is not null and user_banners <> '' and user_banners <> '[]' then 1 else 0 end) withBanners,
    sum(case when edited_ts is not null and edited_ts > 0 then 1 else 0 end) edited,
    sum(case when reaction_score is not null and reaction_score <> 0 then 1 else 0 end) withReactionScore
    from posts`).get();
  db.close();

  const registered = loadRegistered(extDir);
  const coverage = resolveCoverage(extDir);
  const converterFiles = [IMPORT, FORMAT].map((rel) => {
    const p = path.join(extDir, rel.replace(/^extensions\//, ''));
    if (!fs.existsSync(p)) return { file: rel, missing: true };
    const st = fs.statSync(p);
    return { file: rel, bytes: st.size, mtime: new Date(st.mtimeMs).toISOString() };
  });

  // Classify every token the census actually produced, so the coverage claim is
  // exhaustive rather than a spot check.
  const tokenRows = [];
  const kinds = { element: 0, class: 0, data: 0 };
  for (const [prefix, obj, kind] of [['el:', M.elements, 'element'], ['class:', M.classes, 'class'], ['data:', M.dataAttrs, 'data']]) {
    for (const [k, v] of sortCensus(obj)) {
      kinds[kind]++;
      const tok = prefix + k;
      tokenRows.push({ token: tok, kind, status: classifyToken(tok) || 'UNCLASSIFIED', occurrences: v[0], posts: v[1], threads: v[2] });
    }
  }
  for (const fact of ['font-size', 'font-family', 'color', 'background', 'text-align', 'text-decoration', 'dimension']) {
    const v = M.constructs['style.' + fact];
    if (v) tokenRows.push({ token: 'style:' + fact, kind: 'style', status: classifyToken('style:' + fact) || 'UNCLASSIFIED', occurrences: v[0], posts: v[1], threads: v[2] });
  }

  const elapsedMs = Date.now() - t0;
  const report = {
    tool: 'tools/corpus-survey.mjs',
    generatedAt: new Date().toISOString(),
    runtime: `node ${process.version} (node:sqlite DatabaseSync)`,
    host: os.hostname(),
    db: { path: args.db, bytes: fs.statSync(args.db).size, mtime: new Date(fs.statSync(args.db).mtimeMs).toISOString() },
    partial: Boolean(args.limit),
    invocation: `node ${path.relative(REPO, SELF)} --db ${args.db} --out ${args.out || 'reports/'}` + (args.limit ? ` --limit ${args.limit}` : ''),
    wallClockMs: elapsedMs,
    workers: parts.length,
    shards: parts.map((p) => ({ from: p.from, to: p.to === Number.MAX_SAFE_INTEGER ? 'open' : p.to, postsAtPartitionTime: p.posts })),
    corpus: {
      postsInTable: meta.posts, postsInTableAtEnd: postsAtEnd, orphanPosts,
      postsScanned: M.posts, postsWithoutHtml: meta.postsWithoutHtml,
      threadsWithPosts: meta.threads, htmlBytes: M.htmlBytes, textBytes: M.textBytes,
      firstPosts: meta.firstPosts, hasHiddenContentRows: meta.hiddenContent,
      minPostId: meta.minId, maxPostId: meta.maxId,
      earliestPost: meta.minTs ? new Date(meta.minTs * 1000).toISOString() : null,
      latestPost: meta.maxTs ? new Date(meta.maxTs * 1000).toISOString() : null,
    },
    census: {
      elements: M.elements, classes: M.classes, dataAttrs: M.dataAttrs, tagAttrs: M.tagAttrs,
    },
    constructs: M.constructs,
    linkHosts: M.linkHosts,
    imgHosts: M.imgHosts,
    bbcodeLiteral: { tags: M.bbLiteral, forms: M.bbLiteralForms },
    nesting: {
      quoteDepthOccurrences: M.quoteDepthOcc, quoteMaxDepthPerPost: M.quoteDepthPost,
      spoilerDepthOccurrences: M.spoilerDepthOcc, spoilerMaxDepthPerPost: M.spoilerDepthPost,
      combinedMaxDepthPerPost: M.comboDepthPost,
    },
    quoteBodies: { charLengthHistogram: M.quoteLen, brCountHistogram: M.quoteBr, percentiles: percentiles(M.quoteLen), thresholds: thresholds(M.quoteLen) },
    quoteBacklinks: dangling,
    malformed: { badIds: M.badIds },
    languages: M.langs,
    dbLevel: { reactions, threadPrefixes: prefixes, forums, postImages, users: userExtras, posts: postExtras },
    converter: { files: converterFiles, registeredBbcodeTags: registered, coverage },
    tokenClassification: { total: tokenRows.length, counts: kinds, tokens: tokenRows },
    lossless: {
      definition: LOSSLESS_DEFINITION,
      samplingRule: sampleMod ? `every post whose id % ${sampleMod} == 0, taken across the whole id range ${meta.minId}..${meta.maxId}` : 'disabled (--limit run)',
      sampled: M.lossless.sampled,
      strictOk: M.lossless.strictOk,
      lenientOk: M.lossless.lenientOk,
      strictFraction: M.lossless.sampled ? M.lossless.strictOk / M.lossless.sampled : null,
      lenientFraction: M.lossless.sampled ? M.lossless.lenientOk / M.lossless.sampled : null,
      reasons: M.lossless.reasons,
      failingPostIdSample: M.lossless.failPosts,
    },
    examples: M.examples,
  };

  fs.writeFileSync(path.join(out, 'corpus-survey.json'), JSON.stringify(report, null, 1));
  if (!args.jsonOnly) fs.writeFileSync(path.join(out, 'CORPUS-SURVEY.md'), renderReport(report));
  fs.rmSync(tmp, { recursive: true, force: true });

  process.stderr.write(`[survey] done in ${(elapsedMs / 1000).toFixed(1)}s -> ${out}\n`);
}

const LOSSLESS_DEFINITION = [
  'A sampled post is counted LOSSLESS when all of the following hold:',
  '(a) every token in its html — element name, class token, data-* attribute and inline-style fact — is classified `implemented` or `chrome` in the exhaustive token table of §8.1 (`chrome` = XenForo scaffolding and JS hooks, which carry no meaning to lose);',
  '(b) no literal `[tag]` survives in a text node whose tag name is outside the downstream vocabulary (flarum/bbcode\'s 15 tags plus Configure::OWNED), because such a tag can only render as visible text;',
  '(c) the html tokenizes with a balanced element stack (no stray close tag, no unclosed element).',
  'STRICT additionally fails a post on any token classified `partial` and on any literal `[tag]` even when the name IS registered (it will be escaped and shown literally, faithful to the source board but still not a rendered construct).',
  'Weaknesses, stated: this is a STATIC token check, not an execution of the PHP converter (no php runtime exists on the analysis host), so it cannot catch a branch that exists but produces wrong output; it treats every construct as equally important, so a post failing only on a sprite smilie counts the same as one failing on a dropped attachment; and tokens absent from the coverage table count as failures by design, so the number moves when the table is extended as well as when the converter is fixed.',
].join(' ');

function percentiles(hist) {
  const keys = Object.keys(hist).map(Number).sort((a, b) => a - b);
  const total = keys.reduce((a, k) => a + hist[k], 0);
  const want = { p10: 0.10, p25: 0.25, p50: 0.5, p75: 0.75, p90: 0.9, p95: 0.95, p99: 0.99, p999: 0.999 };
  const out = { count: total };
  let acc = 0, wi = Object.entries(want);
  let idx = 0;
  for (const k of keys) {
    acc += hist[k];
    while (idx < wi.length && acc >= wi[idx][1] * total) { out[wi[idx][0]] = k; idx++; }
  }
  out.max = keys.length ? keys[keys.length - 1] : 0;
  out.mean = total ? Math.round(keys.reduce((a, k) => a + k * hist[k], 0) / total) : 0;
  return out;
}

function thresholds(hist) {
  const keys = Object.keys(hist).map(Number);
  const total = keys.reduce((a, k) => a + hist[k], 0);
  const out = {};
  for (const t of [200, 300, 400, 500, 600, 800, 1000, 1500, 2000, 3000]) {
    const over = keys.reduce((a, k) => a + (k > t ? hist[k] : 0), 0);
    out[t] = { over, fraction: total ? over / total : 0 };
  }
  return out;
}

// ---------------------------------------------------------------- md rendering

function mdTable(headers, rows) {
  const head = `| ${headers.join(' | ')} |\n|${headers.map(() => '---').join('|')}|`;
  return [head, ...rows.map((r) => `| ${r.join(' | ')} |`)].join('\n');
}

function esc(s) {
  return String(s).replace(/\|/g, '\\|').replace(/\n/g, ' ');
}

function censusRows(obj, { min = 0, top = Infinity, posts = 0 } = {}) {
  return sortCensus(obj).filter(([, v]) => v[0] >= min).slice(0, top)
    .map(([k, v]) => [`\`${esc(k)}\``, num(v[0]), num(v[1]), num(v[2]), posts ? pct(v[1], posts) : null].filter((x) => x !== null));
}

function tailSummary(obj, min) {
  const all = Object.entries(obj);
  const tail = all.filter(([, v]) => v[0] < min);
  const sample = tail.sort((a, b) => b[1][0] - a[1][0]).slice(0, 25).map(([k, v]) => `\`${k}\` (${v[0]})`);
  return { distinct: tail.length, occurrences: tail.reduce((a, [, v]) => a + v[0], 0), sample };
}

function renderReport(R) {
  const C = R.constructs;
  const posts = R.corpus.postsScanned;
  const get = (k) => C[k] || [0, 0, 0];
  const L = [];
  const p = (s = '') => L.push(s);

  const covByToken = new Map(R.converter.coverage.map((c) => [c.token, c]));
  /** exact match first, then the parent construct (`img.src` -> `img`), then honest ignorance */
  const covOf = (tok) => {
    if (covByToken.has(tok)) return { c: covByToken.get(tok), via: null };
    const cut = tok.lastIndexOf('.');
    if (cut > 0) {
      const parent = tok.slice(0, cut);
      if (covByToken.has(parent)) return { c: covByToken.get(parent), via: parent };
    }
    return { c: null, via: null };
  };
  const covCell = (tok) => {
    if (/^(nesting|malformed|parse)\./.test(tok)) return 'n/a — structural fact, not a construct';
    const { c, via } = covOf(tok);
    if (!c) return '**UNCLASSIFIED — this tool has no entry for it**';
    const where = c.line ? `${c.file}:${c.line}` : (c.resolved === 'MISSING' ? 'marker NOT FOUND' : 'no branch');
    return `${c.status} — ${where}${via ? ` (via \`${via}\`)` : ''}`;
  };
  const noteOf = (tok) => {
    if (/^(nesting|malformed|parse)\./.test(tok)) return '';
    const { c } = covOf(tok);
    return c ? (c.note || '') : 'not in the coverage table — decide what it means before importing';
  };

  p(`# Looksmax.org corpus survey`);
  p();
  p(`**What this is.** A prior-free census of every HTML construct, literal BBCode tag, nesting shape and`);
  p(`malformation in the scraped looksmax.org post corpus, joined against a static read of the importer's`);
  p(`HTML→BBCode converter, so that what still has to be built is a table rather than an opinion.`);
  p(`Nothing here is sampled except §9, which is labelled with its sample size wherever it appears.`);
  p();
  p(`**How it was produced.** \`${R.invocation}\``);
  p();
  p(mdTable(['field', 'value'], [
    ['generated', R.generatedAt],
    ['runtime', R.runtime],
    ['host', R.host],
    ['wall clock', `${(R.wallClockMs / 1000).toFixed(1)}s across ${R.workers} forked shards`],
    ['database', `\`${R.db.path}\` (${(R.db.bytes / 1e9).toFixed(2)} GB, mtime ${R.db.mtime}) opened \`mode=ro\` + \`PRAGMA query_only=1\``],
    ['posts in table', `${num(R.corpus.postsInTable)} when the run started, ${num(R.corpus.postsInTableAtEnd)} when it finished — the scraper is still writing, which is why the two differ`],
    ['posts scanned', `${num(R.corpus.postsScanned)}${R.partial ? ' **(PARTIAL — --limit run, do not cite)**'
      : ` (${pct(R.corpus.postsScanned, R.corpus.postsInTableAtEnd)} of the table as it stood at the end; the shard ranges are open at both ends and include the ${num(R.corpus.orphanPosts)} posts with a NULL thread_id)`}`],
    ['threads with posts', num(R.corpus.threadsWithPosts)],
    ['html scanned', `${(R.corpus.htmlBytes / 1e6).toFixed(1)} MB (plain text ${(R.corpus.textBytes / 1e6).toFixed(1)} MB)`],
    ['post date range', `${(R.corpus.earliestPost || '').slice(0, 10)} … ${(R.corpus.latestPost || '').slice(0, 10)}`],
    ['converter read', R.converter.files.map((f) => `\`${f.file}\` (${f.bytes} B, mtime ${f.mtime})`).join('<br>')],
  ]));
  p();
  p(`> The **converter coverage** column is a STATIC READ of the two PHP files above: it says a branch`);
  p(`> exists and cites its line, nothing more. It is not a test of the live site and makes no claim that`);
  p(`> the construct renders correctly. Every number in this report is reproducible from`);
  p(`> \`reports/corpus-survey.json\`, which is the same data unrounded.`);
  p();

  // ------------------------------------------------------------- work order
  p(`## Work order`);
  p();
  p(`Ranked by occurrences. "distinct posts"/"distinct threads" are the blast radius: 13k blocks in 200`);
  p(`threads is a very different problem from 13k spread over 13k threads.`);
  p();
  // Prior-free: every construct token the census produced, ranked. The excluded
  // prefixes are enumerated VALUE families (one row per sprite index, per media
  // site, per code language) which get their own sections — not a filter on
  // which constructs are interesting.
  const VALUE_FAMILY = /^(sprite:|media-site:|code-lang:|align:|link\.data-xf-init:|list\.data-xf-list-type:|bb-literal\.)/;
  const workRows = Object.keys(C).filter((k) => !VALUE_FAMILY.test(k))
    .sort((a, b) => C[b][0] - C[a][0])
    .map((k) => {
      const v = get(k);
      return [`\`${k}\``, num(v[0]), num(v[1]), num(v[2]), covCell(k), esc(noteOf(k))];
    });
  p(mdTable(['construct', 'occurrences', 'distinct posts', 'distinct threads', 'converter coverage (static read)', 'gap'], workRows));
  p();
  const litRows = sortCensus(R.bbcodeLiteral.tags).slice(0, 25)
    .map(([k, v]) => [`\`[${k}]\``, num(v[0]), num(v[1]), num(v[2]),
      REGISTERED.has(k.toUpperCase()) ? 'name exists downstream' : '**no downstream tag — renders as literal text**']);
  p(`### Literal BBCode still in the stored html (top 25 — full list in §4)`);
  p();
  p(mdTable(['tag', 'occurrences', 'distinct posts', 'distinct threads', 'downstream'], litRows));
  p();

  // ---------------------------------------------------------------- §1 census
  p(`## 1. HTML construct census (prior-free)`);
  p();
  p(`Extraction is blind: every \`<element>\`, every whitespace-separated class token and every \`data-*\``);
  p(`attribute name that occurs anywhere in \`posts.html\`, with no expectation list. Head = ≥100 occurrences.`);
  p();
  for (const [name, key] of [['Elements', 'elements'], ['Class tokens', 'classes'], ['data-* attributes', 'dataAttrs']]) {
    const obj = R.census[key];
    const head = censusRows(obj, { min: 100, posts });
    const tail = tailSummary(obj, 100);
    p(`### 1.${key === 'elements' ? 1 : key === 'classes' ? 2 : 3} ${name} — ${num(Object.keys(obj).length)} distinct`);
    p();
    p(mdTable(['token', 'occurrences', 'distinct posts', 'distinct threads', '% of posts'], head));
    p();
    p(`_Tail: ${num(tail.distinct)} further tokens below 100 occurrences (${num(tail.occurrences)} occurrences total). Largest: ${tail.sample.join(', ')}._`);
    p();
  }
  const ta = censusRows(R.census.tagAttrs, { min: 500 });
  p(`### 1.4 element[attribute] pairs (≥500 occurrences) — ${num(Object.keys(R.census.tagAttrs).length)} distinct`);
  p();
  p(mdTable(['pair', 'occurrences', 'distinct posts', 'distinct threads'], ta));
  p();

  // ------------------------------------------------------- §2 attribute forms
  p(`## 2. Attribute forms for the data-carrying constructs`);
  p();
  p(`### 2.1 Quotes`);
  p();
  p(mdTable(['form', 'occurrences', 'distinct posts', 'distinct threads'], [
    'quote', 'quote.data-quote', 'quote.no-data-quote', 'quote.data-source-post',
    'quote.data-source-other', 'quote.no-data-source', 'quote.data-attributes', 'quote.data-attributes-member',
  ].filter((k) => C[k]).map((k) => [`\`${k}\``, num(get(k)[0]), num(get(k)[1]), num(get(k)[2])])));
  p();
  if (R.examples['quote-no-author']) {
    p(`Quote blocks with no \`data-quote\` (converter falls back to the "X said:" title, ${IMPORT}):`);
    p();
    for (const e of R.examples['quote-no-author']) p(`- post ${e.post}: \`${esc(e.snippet.slice(0, 200))}\``);
    p();
  }
  p(`### 2.2 Spoilers`);
  p();
  p(mdTable(['form', 'occurrences', 'distinct posts', 'distinct threads'],
    ['spoiler', 'spoiler.button-title'].filter((k) => C[k]).map((k) => [`\`${k}\``, num(get(k)[0]), num(get(k)[1]), num(get(k)[2])])
      .concat([['`spoiler` without a title', num(get('spoiler')[0] - get('spoiler.button-title')[0]), '—', '—']])));
  p();
  p(`### 2.3 Images — which attribute holds the real url`);
  p();
  p(mdTable(['form', 'occurrences', 'distinct posts', 'distinct threads'],
    ['img', 'img.src', 'img.data-src', 'img.data-url', 'img.src-is-placeholder', 'img.no-real-url',
      'img.inside-noscript', 'img.xf-proxy', 'img.has-dimensions', 'image-wrapper', 'image-wrapper.data-src', 'image.aligned']
      .filter((k) => C[k]).map((k) => [`\`${k}\``, num(get(k)[0]), num(get(k)[1]), num(get(k)[2])])));
  p();
  p(`Top image hosts:`);
  p();
  p(mdTable(['host', 'occurrences', 'distinct posts', 'distinct threads'], censusRows(R.imgHosts, { top: 30 })));
  p();
  p(`### 2.4 Links`);
  p();
  p(mdTable(['form', 'occurrences', 'distinct posts', 'distinct threads'],
    ['link', 'link.internal', 'link.external', 'link.unclassed', 'link.goto-post', 'attachment.link', 'mention.member-link']
      .filter((k) => C[k]).map((k) => [`\`${k}\``, num(get(k)[0]), num(get(k)[1]), num(get(k)[2])])));
  p();
  p(`Top 50 link target hosts — this is the list that decides which domains are worth an unfurl/preview:`);
  p();
  p(mdTable(['host', 'links', 'distinct posts', 'distinct threads'], censusRows(R.linkHosts, { top: 50 })));
  p();

  // --------------------------------------------------------------- §3 nesting
  p(`## 3. Nesting and quote body lengths`);
  p();
  const nest = R.nesting;
  const depthRows = (occ, per) => {
    const keys = [...new Set([...Object.keys(occ), ...Object.keys(per)])].map(Number).sort((a, b) => a - b);
    return keys.map((k) => [k, num(occ[k] || 0), num(per[k] || 0)]);
  };
  p(`### 3.1 Quote depth`);
  p();
  p(mdTable(['depth', 'quote blocks opened at this depth', 'posts whose deepest quote is this'],
    depthRows(nest.quoteDepthOccurrences, nest.quoteMaxDepthPerPost)));
  p();
  p(`### 3.2 Spoiler depth`);
  p();
  p(mdTable(['depth', 'spoiler blocks opened at this depth', 'posts whose deepest spoiler is this'],
    depthRows(nest.spoilerDepthOccurrences, nest.spoilerMaxDepthPerPost)));
  p();
  p(`### 3.3 Mixed nesting`);
  p();
  p(mdTable(['case', 'occurrences', 'distinct posts', 'distinct threads'],
    ['nesting.quote-inside-quote', 'nesting.spoiler-inside-spoiler', 'nesting.quote-inside-spoiler', 'nesting.spoiler-inside-quote']
      .filter((k) => C[k]).map((k) => [`\`${k}\``, num(get(k)[0]), num(get(k)[1]), num(get(k)[2])])));
  p();
  p(`Combined quote+spoiler depth per post (this is what has to stay under s9e's \`nestingLimit\`, set to 30 at ${FORMAT}):`);
  p();
  p(mdTable(['combined depth', 'posts'], Object.entries(nest.combinedMaxDepthPerPost)
    .sort((a, b) => Number(a[0]) - Number(b[0])).map(([k, v]) => [k, num(v)])));
  p();
  p(`### 3.4 Quote body length`);
  p();
  const pc = R.quoteBodies.percentiles;
  p(`Body = the whitespace-normalised character count of everything inside the quote block, chrome`);
  p(`("X said:", the expand link) excluded, nested quotes included, because that is what a reader sees.`);
  p(`n = ${num(pc.count)} quote blocks (all of them, not a sample). Lengths above 4,000 chars are bucketed to 100.`);
  p();
  p(mdTable(['p10', 'p25', 'p50', 'p75', 'p90', 'p95', 'p99', 'p99.9', 'max', 'mean'],
    [[num(pc.p10), num(pc.p25), num(pc.p50), num(pc.p75), num(pc.p90), num(pc.p95), num(pc.p99), num(pc.p999), num(pc.max), num(pc.mean)]]));
  p();
  p(`Fraction of quotes above candidate pre-collapse thresholds:`);
  p();
  p(mdTable(['threshold (chars)', 'quotes above', '% of quotes'],
    Object.entries(R.quoteBodies.thresholds).map(([t, v]) => [num(Number(t)), num(v.over), pct(v.over, pc.count)])));
  p();
  const brp = percentiles(R.quoteBodies.brCountHistogram);
  p(`Hard line breaks (\`<br>\`) inside a quote body — p50 ${num(brp.p50)}, p90 ${num(brp.p90)}, p99 ${num(brp.p99)}, max ${num(brp.max)}.`);
  p(`A rendered-line estimate at ~90 chars per line puts p50 at ~${Math.max(1, Math.ceil(pc.p50 / 90))} lines, p90 at ~${Math.ceil(pc.p90 / 90)}, p99 at ~${Math.ceil(pc.p99 / 90)} (estimate: character count / 90 + hard breaks, not a layout measurement).`);
  p();

  // ------------------------------------------------------- §4 literal bbcode
  p(`## 4. Literal BBCode surviving in the stored html`);
  p();
  p(`Extracted blind with \`${BB_RE.source}\` over text nodes only (never inside a tag), so the tag list is`);
  p(`discovered, not assumed. These are tags XenForo did **not** render — they are literal text on the`);
  p(`source board too.`);
  p();
  p(`**How to read this list.** The examples in §4.2 show it is four different things wearing one shape,`);
  p(`and they need different treatment:`);
  p();
  p(`1. **Real custom-BBCode addons used as tags** — \`[ISPOILER]…[/ISPOILER]\` (inline spoiler),`);
  p(`   \`[SERIOUS]\`, \`[GUIDE]\`, \`[GTFIH]\`, \`[DELETED]\`, \`[HIQM]\`, \`[MEME]\`, \`[HIDEPOSTS]\`. These are`);
  p(`   looksmax.org's own semantic post labels. Nothing downstream has a tag for them, so today they`);
  p(`   render as visible \`[ISPOILER]\` text. Each needs a tag in \`Configure.php\` plus a converter branch,`);
  p(`   or an explicit decision to strip it.`);
  p(`2. **BBCode that lived inside a XenForo attribute** and was escaped into text on render — quote`);
  p(`   titles and, notably, display names: see \`mention.username-contains-bbcode\` in the work order.`);
  p(`3. **Prose and prompt templates in square brackets** — \`[Value]\`, \`[TRUE_SCORE]\`, \`[HARM_10]\`,`);
  p(`   \`[Insert …]\`. Post 29713470 is one pasted LLM rating prompt and accounts for most of them.`);
  p(`   These must stay literal; escaping them is the correct behaviour, not a bug.`);
  p(`4. **URL query parameters** — \`c[users]=…\` inside a looksmax.org search link.`);
  p();
  p(mdTable(['tag', 'occurrences', 'distinct posts', 'distinct threads', 'downstream vocabulary'],
    sortCensus(R.bbcodeLiteral.tags).map(([k, v]) => [`\`[${k}]\``, num(v[0]), num(v[1]), num(v[2]),
      REGISTERED.has(k.toUpperCase()) ? 'registered' : '**absent — renders literally**'])));
  p();
  p(`### 4.1 Attribute forms`);
  p();
  p(mdTable(['form', 'occurrences', 'distinct posts', 'distinct threads'],
    sortCensus(R.bbcodeLiteral.forms).slice(0, 60).map(([k, v]) => [`\`${esc(k)}\``, num(v[0]), num(v[1]), num(v[2])])));
  p();
  p(`### 4.2 Real examples`);
  p();
  for (const [k, arr] of Object.entries(R.examples).filter(([k]) => k.startsWith('bb:'))
    .sort((a, b) => (R.bbcodeLiteral.tags[b[0].slice(3)]?.[0] || 0) - (R.bbcodeLiteral.tags[a[0].slice(3)]?.[0] || 0))
    .slice(0, 30)) {
    const tag = k.slice(3);
    const v = R.bbcodeLiteral.tags[tag] || [0, 0, 0];
    p(`**\`[${tag}]\`** — ${num(v[0])} occurrences in ${num(v[1])} posts / ${num(v[2])} threads`);
    p();
    for (const e of arr.slice(0, 3)) p(`- post ${e.post} (thread ${e.thread}): \`${esc(e.snippet.slice(0, 240))}\``);
    p();
  }

  // ------------------------------------------------------------- §5 malformed
  p(`## 5. Malformed cases`);
  p();
  p(mdTable(['case', 'occurrences', 'distinct posts', 'distinct threads', 'post ids to pull up'],
    ['malformed.bb-unclosed-opener', 'malformed.bb-stray-closer', 'malformed.eaten-open-bracket',
      'malformed.bbcode-split-by-markup', 'parse.stray-close-tag', 'parse.unclosed-elements',
      'parse.analyzer-exception']
      .filter((k) => C[k]).map((k) => {
        const idKey = k.replace(/^(malformed|parse)\./, '');
        return [`\`${k}\``, num(get(k)[0]), num(get(k)[1]), num(get(k)[2]),
          (R.malformed.badIds[idKey] || []).slice(0, 12).join(', ') || '—'];
      })));
  p();
  p(`Definitions, because each of these means something different:`);
  p();
  p(`- \`bb-unclosed-opener\` / \`bb-stray-closer\` — within one post, literal \`[tag]\` openers and \`[/tag]\``);
  p(`  closers that do not balance. Mostly the tail of case 2 and 3 above, not a converter bug.`);
  p(`- \`eaten-open-bracket\` — \`url=…\` / \`img=…\` sitting in prose where \`[url=…]\` was meant. **The count`);
  p(`  here is the count in the SOURCE data**; if this pattern is visible on the imported site at a`);
  p(`  higher rate, the converter produced it and this row is the baseline that proves it.`);
  p(`- \`bbcode-split-by-markup\` — a bbcode tag torn in half by html, e.g. post 5823021 stores`);
  p(`  \`[SPO</div></div>ILER="spoiler"]\`. The source board renders that broken too; nothing downstream`);
  p(`  can repair it, so the only question is whether to strip the orphan halves.`);
  p(`- \`stray-close-tag\` / \`unclosed-elements\` — html that does not tokenize with a balanced element`);
  p(`  stack. Measured: **${num(get('parse.stray-close-tag')[0])} stray close tags and ${num(get('parse.unclosed-elements')[0])} unclosed elements** across`);
  p(`  ${num(posts)} posts` + (get('parse.stray-close-tag')[0] + get('parse.unclosed-elements')[0] === 0
    ? `, i.e. every post's html is well-formed, so a parse failure downstream is the converter's, not the data's.`
    : `; the post ids are listed so they can be pulled up.`));
  p();
  if (R.examples['split-bb']) {
    for (const e of R.examples['split-bb'].slice(0, 2)) p(`- split example, post ${e.post}: \`${esc(e.snippet.slice(0, 200))}\``);
    p();
  }
  if (R.examples['username-bbcode']) {
    p(`Display names that themselves contain bbcode (\`mention.username-contains-bbcode\`) — the converter`);
    p(`takes the mention label straight from \`data-username\`:`);
    p();
    for (const e of R.examples['username-bbcode'].slice(0, 4)) p(`- post ${e.post}: \`${esc(e.snippet.slice(0, 160))}\``);
    p();
  }
  for (const [k, arr] of Object.entries(R.examples).filter(([k]) => k.startsWith('eaten:')).slice(0, 6)) {
    p(`- \`${k.slice(6)}\`: ${arr.slice(0, 2).map((e) => `post ${e.post} \`${esc(e.snippet.slice(0, 160))}\``).join(' · ')}`);
  }
  p();

  // ---------------------------------------------- §6 no flarum equivalent
  p(`## 6. XenForo constructs with no Flarum equivalent`);
  p();
  p(mdTable(['construct', 'occurrences', 'distinct posts', 'distinct threads', 'converter coverage (static read)', 'what supporting it takes'], [
    ['`attachment.link`', ...cells(get('attachment.link')), covCell('attachment.link'), 'Flarum has no attachment concept without an extension. The scrape stores only the /attachments/ url, so the file has to be fetched from looksmax.org, stored, and emitted as [ATTACH] (declared in `Configure::OWNED` but with no BBCode definition and no converter branch). Until then these are outbound links that will 404 for logged-out readers.'],
    ['`media.s9e` + `media.bbMediaWrapper` + `media.iframe`', ...cells3(get('media.s9e'), get('media.bbMediaWrapper'), get('media.iframe')), covCell('media.s9e'), '[MEDIA] exists and renders a click-to-play facade. Nothing to build; verify the per-site thumbnails resolve.'],
    ['`media.video` / `media.audio`', ...cells3(get('media.video'), get('media.audio'), [0, 0, 0]), covCell('media.video'), 'Uploaded media is served from looksmax.org and is not mirrored; [VIDEO]/[AUDIO] point at the source host.'],
    ['polls', num(countMatching(R.census.classes, /poll/i)), '—', '—', 'no branch', 'No poll markup appears in post html at all — XenForo renders polls outside the message body, and the scraper did not capture them. If polls matter they need a separate scrape of the thread page.'],
    ['reactions', num(sum(R.dbLevel.reactions, 'c')), num(R.dbLevel.posts.withReactionScore), '—', 'not in the converter', `Reactions live in the post_reactions table (${R.dbLevel.reactions.length} distinct names), not in post html. Flarum's likes are a single verb; carrying ${R.dbLevel.reactions.length} named reactions needs a reactions extension plus the custom images.`],
    ['hidden / reply-to-view content', num(R.corpus.hasHiddenContentRows), '—', '—', 'n/a', `posts.has_hidden_content is 0 for every one of the ${num(R.corpus.postsInTable)} rows: either the board does not use hide tags or the scraper never saw them while logged out. Nothing to port.`],
    ['`table`', ...cells(get('table')), covCell('table'), '[TABLE]/[TR]/[TD] defined in Configure.php with parent rules. Done.'],
    ['`code-block` (of which with a language)', `${num(get('code-block')[0])} (${num(get('code-block.data-lang')[0])})`, num(get('code-block')[1]), num(get('code-block')[2]), covCell('code-block'), `Every one of the ${num(get('code-block')[0])} code blocks carries \`data-lang\` but the attribute is EMPTY in all of them, so there is no language to carry and the converter's fallback to the "PHP:"/"Code:" block title is what decides. Flarum has no highlighter by default either way.`],
    ['alignment', ...cells(get('style.text-align')), covCell('style.text-align'), '[ALIGN] from the s9e repository. Done.'],
    ['colour / size / font spans', ...cells3(get('style.color'), get('style.font-size'), get('style.font-family')), covCell('style.color'), 'All three convert. Note the size scale: XenForo px sizes are clamped to 8–72 on import.'],
    ['strikethrough / sub / sup / ins', ...cells3(get('strike'), get('sup'), get('sub')), covCell('strike'), '[S] is core; [SUP]/[SUB]/[INS] come from the s9e repository in Configure::fromRepository.'],
    ['user mentions', ...cells(get('mention.username-span')), covCell('mention.username-span'), 'Resolved through the imported-id map, with [UMENTION] as the fallback for members that were never imported.'],
    ['usergroup mentions', ...cells(get('mention.usergroup')), covCell('mention.usergroup'), '[GMENTION] renders the name only — Flarum groups are not linked, and the source group id is not mapped to a local group.'],
    ['sprite smilies', ...cells(get('smilie.sprite')), covCell('smilie.sprite'), 'The sprite sheet was never scraped, so the picture is unrecoverable from this corpus; the emote renders as a named chip. Supporting them properly means fetching the smilie sprite CSS + sheet from looksmax.org and slicing it.'],
    ['unicode smilies', ...cells(get('smilie.emoji')), covCell('smilie.emoji'), 'alt holds the real character; flarum/emoji renders it. Done.'],
    ['thread prefixes', num(sum(R.dbLevel.threadPrefixes.filter((x) => x.prefix !== '(none)'), 'threads')), '—', num(R.dbLevel.threadPrefixes.length - 1), 'not in the converter', 'XenForo thread prefixes have no Flarum equivalent; the natural target is a tag or a title prefix. Distribution in §6.1.'],
    ['user titles / banners', num(R.dbLevel.posts.withUserTitle), '—', '—', 'not in the converter', `${num(R.dbLevel.users.withTitle)} of ${num(R.dbLevel.users.users)} users carry a custom title and ${num(R.dbLevel.users.withBanners)} carry banners; Flarum has neither out of the box.`],
  ]));
  p();
  p(`### 6.1 Thread prefixes (threads that have posts scraped)`);
  p();
  p(mdTable(['prefix', 'threads'], R.dbLevel.threadPrefixes.slice(0, 40).map((x) => [esc(x.prefix), num(x.threads)])));
  p();
  p(`### 6.2 Reaction vocabulary (post_reactions)`);
  p();
  p(mdTable(['reaction', 'occurrences', 'distinct posts'], R.dbLevel.reactions.map((x) => [esc(x.name), num(x.c), num(x.posts)])));
  p();
  p(`### 6.3 Quote backlinks that point at posts outside the corpus`);
  p();
  const d = R.quoteBacklinks;
  p(mdTable(['metric', 'value'], [
    ['quote blocks carrying `data-source="post: N"`', num(d.quoteBlocksWithSourceId)],
    ['distinct source post ids referenced', num(d.distinctSourceIds)],
    ['…present in the `posts` table', `${num(d.distinctPresentInCorpus)} (${pct(d.distinctPresentInCorpus, d.distinctSourceIds)})`],
    ['…missing from the corpus', `${num(d.distinctMissing)} (${pct(d.distinctMissing, d.distinctSourceIds)})`],
    ['**quote blocks whose backlink would dangle**', `**${num(d.occurrencesDangling)} of ${num(d.quoteBlocksWithSourceId)} = ${pct(d.occurrencesDangling, d.quoteBlocksWithSourceId)}**`],
    ['sample of missing source ids', d.sampleMissingSourceIds.slice(0, 10).join(', ')],
  ]));
  p();
  p(`ResolveQuoteLinks resolves \`post=N\` at render time, so a dangling id is not an error — it simply`);
  p(`produces a quote with no jump link. The fraction is what it is because the scrape is a subset of the board.`);
  p();
  p(`### 6.4 Media sites seen`);
  p();
  p(mdTable(['site', 'occurrences', 'distinct posts', 'distinct threads'],
    censusRows(Object.fromEntries(Object.entries(C).filter(([k]) => k.startsWith('media-site:'))), { top: 40 })));
  p();
  p(`### 6.5 Sprite smilie indices`);
  p();
  const sprites = Object.fromEntries(Object.entries(R.census.classes).filter(([k]) => /^smilie--sprite\d+$/.test(k)));
  p(`${num(Object.keys(sprites).length)} distinct sprite indices (every one of them, from the class census).`);
  p(`Each is one custom emote whose image lives in a CSS sprite sheet that was never scraped, so the`);
  p(`picture cannot be recovered from this corpus — only the name.`);
  p();
  p(mdTable(['sprite class', 'occurrences', 'distinct posts', 'distinct threads'], censusRows(sprites, { top: 200 })));
  p();

  // -------------------------------------------------------------- §7 language
  p(`## 7. Language mix`);
  p();
  p(`Method: script-block ratios over \`posts.text\` first (Cyrillic → ru/uk/sr by distinctive letters,`);
  p(`Arabic → ar/fa, plus Greek/Hebrew/CJK/Hangul/Kana/Thai/Devanagari/Armenian/Georgian), then stopword`);
  p(`scoring against ${Object.keys(STOPSETS).length} Latin-script languages, needing ≥2 stopword hits to commit.`);
  p(`**This is a heuristic, not a classifier.** Known limits: posts under 20 characters carry no signal and`);
  p(`land in \`und\`; English is over-counted because the board's jargon (looksmax, mog, NT, PSL, cope) is`);
  p(`English whatever the poster's language is; es/pt and the Slavic Latin-script group are the likeliest`);
  p(`confusions. Every post is classified (no sampling) — ${num(posts)} posts.`);
  p();
  p(mdTable(['language', 'posts', 'distinct threads', '% of posts'],
    sortCensus(R.languages).map(([k, v]) => [k, num(v[0]), num(v[2]), pct(v[0], posts)])));
  p();

  // ------------------------------------------------------------- §8 coverage
  p(`## 8. Converter coverage (static read)`);
  p();
  p(`Each row was resolved against the file at run time — a cited line number that no longer exists shows`);
  p(`as \`marker NOT FOUND\` instead of silently staying green. **This is a source read. It proves a branch`);
  p(`exists; it does not prove anything renders.**`);
  p();
  p(mdTable(['construct', 'status', 'branch', 'note'], R.converter.coverage.map((c) => [
    `\`${c.token}\``, c.status,
    c.line ? `\`${c.file}:${c.line}\`` : (c.resolved === 'MISSING' ? '**marker NOT FOUND**' : '_no branch_'),
    esc(c.note || ''),
  ])));
  p();
  p(`Downstream tag vocabulary (flarum/bbcode + \`Configure::OWNED\`), ${R.converter.registeredBbcodeTags.registered.length} tags: ` +
    R.converter.registeredBbcodeTags.registered.map((t) => `\`${t}\``).join(', '));
  p();
  p(`### 8.1 Every token in the corpus, classified`);
  p();
  p(`The whole vocabulary is ${num(R.tokenClassification.total)} tokens — ${num(R.tokenClassification.counts.element)} element names,`);
  p(`${num(R.tokenClassification.counts.class)} class tokens and ${num(R.tokenClassification.counts.data)} data-* attributes — so there is no tail to`);
  p(`hand-wave. Every one is listed. \`chrome\` = XenForo scaffolding or a JS hook with no meaning to carry.`);
  p(`**\`UNCLASSIFIED\` rows are the tool admitting ignorance and are the first thing to look at after a re-run.**`);
  p();
  for (const status of ['UNCLASSIFIED', 'none', 'partial', 'implemented', 'chrome']) {
    const rows = R.tokenClassification.tokens.filter((t) => t.status === status);
    if (!rows.length) continue;
    p(`**${status}** — ${rows.length} tokens`);
    p();
    p(mdTable(['token', 'occurrences', 'distinct posts', 'distinct threads'],
      rows.map((t) => [`\`${esc(t.token)}\``, num(t.occurrences), num(t.posts), num(t.threads)])));
    p();
  }

  // ------------------------------------------------------------- §9 lossless
  p(`## 9. Lossless-conversion measurement (SAMPLED)`);
  p();
  const LO = R.lossless;
  p(`**Definition.** ${LO.definition}`);
  p();
  p(`**Sampling rule.** ${LO.samplingRule} — n = ${num(LO.sampled)} posts, spread across the whole id range`);
  p(`(not \`order by id limit N\`, which would have sampled only the oldest threads).`);
  p();
  p(mdTable(['metric', 'value'], [
    ['sampled posts', num(LO.sampled)],
    ['**lenient pass** (no unhandled/unclassified token, no unregistered literal bbcode, parses clean)', `**${num(LO.lenientOk)} = ${pct(LO.lenientOk, LO.sampled)}**`],
    ['**strict pass** (also fails on `partial` handlers and on any literal bbcode at all)', `**${num(LO.strictOk)} = ${pct(LO.strictOk, LO.sampled)}**`],
    ['failing post ids (sample)', LO.failingPostIdSample.slice(0, 12).join(', ')],
  ]));
  p();
  p(`Why posts fail, ranked (a post can fail for several reasons):`);
  p();
  p(mdTable(['reason', 'posts', '% of sample'],
    Object.entries(LO.reasons).sort((a, b) => b[1] - a[1]).slice(0, 40)
      .map(([k, v]) => [`\`${esc(k)}\``, num(v), pct(v, LO.sampled)])));
  p();
  p(`**Read the two numbers together.** The lenient figure says almost nothing is dropped outright. The`);
  p(`strict figure is low because of a handful of specific, named partial losses, and the table above is`);
  p(`ranked so their sizes are visible: the top reason alone accounts for ${pct((Object.values(LO.reasons).sort((a, b) => b - a)[0] || 0), LO.sampled)} of the sample. Fixing`);
  p(`that one item would move the strict number by roughly that much — which is the point of measuring it`);
  p(`this way rather than as a single opaque score.`);
  p();
  p(`This is the "before" number. Re-run this tool after converter changes and compare — the JSON keeps`);
  p(`the same shape, and the reason table shows which fix moved which posts.`);
  p();

  p(`## Re-running`);
  p();
  p('```sh');
  p(`# full pass (this report)`);
  p(`node tools/corpus-survey.mjs --db /work/lmx/scraper/looksmax.db --out reports/`);
  p(`# smoke run`);
  p(`node tools/corpus-survey.mjs --db /work/lmx/scraper/looksmax.db --out /tmp/smoke --limit 2000`);
  p('```');
  p();
  p(`The database is opened read-only (\`file:…?mode=ro\` + \`PRAGMA query_only=1\`); the tool never writes to it.`);
  p();
  return L.join('\n');
}

const cells = (v) => [num(v[0]), num(v[1]), num(v[2])];
const cells3 = (a, b, c) => [`${num(a[0])} / ${num(b[0])} / ${num(c[0])}`, `${num(a[1])} / ${num(b[1])} / ${num(c[1])}`, `${num(a[2])} / ${num(b[2])} / ${num(c[2])}`];
const sum = (rows, k) => rows.reduce((a, r) => a + (r[k] || 0), 0);
const countMatching = (obj, re) => Object.entries(obj).filter(([k]) => re.test(k)).reduce((a, [, v]) => a + v[0], 0);

// ------------------------------------------------------------------------ main

const args = parseArgs(process.argv.slice(2));
if (args.worker) runWorker(args);
else runParent(args).catch((e) => { console.error(e); process.exit(1); });
