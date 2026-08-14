/**
 * In-page audit routines, shipped to the browser as source strings.
 *
 * These run inside the rendered page rather than over the LESS source, because
 * the failures this is meant to catch are cascade failures: a rule that compiles
 * fine and then loses to a core selector, or a colour that is legible in
 * isolation and not once its ancestor's alpha is composited under it. Only the
 * computed style knows.
 */

/** Shared colour maths + background resolution, prepended to each audit. */
const COLOR_LIB = `
const parseColor = (s) => {
  if (!s || s === 'transparent') return [0, 0, 0, 0];
  let m = s.match(/^rgba?\\(([^)]+)\\)$/);
  if (m) {
    const p = m[1].split(/[,\\s\\/]+/).filter(Boolean).map(Number);
    return [p[0] || 0, p[1] || 0, p[2] || 0, p.length > 3 ? p[3] : 1];
  }
  m = s.match(/^#([0-9a-f]{3,8})$/i);
  if (m) {
    let h = m[1];
    if (h.length === 3) h = h.split('').map(c => c + c).join('');
    const n = parseInt(h.slice(0, 6), 16);
    const a = h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1;
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255, a];
  }
  // color(srgb r g b / a) — Chromium emits this for color-mix() results
  m = s.match(/^color\\(srgb ([^)]+)\\)$/);
  if (m) {
    const parts = m[1].split('/');
    const p = parts[0].trim().split(/\\s+/).map(Number);
    const a = parts[1] !== undefined ? parseFloat(parts[1]) : 1;
    return [Math.round(p[0] * 255), Math.round(p[1] * 255), Math.round(p[2] * 255), a];
  }
  return null;
};

const over = (fg, bg) => {
  const a = fg[3];
  if (a >= 1) return [fg[0], fg[1], fg[2], 1];
  if (a <= 0) return bg;
  return [
    fg[0] * a + bg[0] * (1 - a),
    fg[1] * a + bg[1] * (1 - a),
    fg[2] * a + bg[2] * (1 - a),
    1,
  ];
};

const lum = (c) => {
  const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
};

const ratio = (a, b) => {
  const l1 = lum(a), l2 = lum(b);
  return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
};

const hex = (c) => '#' + [c[0], c[1], c[2]].map(v => Math.round(v).toString(16).padStart(2, '0')).join('');

/** Effective opacity of an element including every ancestor's opacity. */
const chainOpacity = (el) => {
  let o = 1, n = el;
  while (n && n.nodeType === 1) {
    const v = parseFloat(getComputedStyle(n).opacity);
    if (!isNaN(v)) o *= v;
    n = n.parentElement;
  }
  return o;
};

/**
 * Resolve what is actually painted behind an element: walk up compositing each
 * translucent background-color, and report whether a background-image
 * (gradient, noise) sits in the chain so the result can be flagged approximate.
 */
/*
 * Pull the colour stops out of a gradient background-image.
 *
 * A filled button paints its surface with a linear-gradient, so its
 * background-color is transparent and a naive walk up the tree reports the
 * PAGE colour behind it. That produced "Start a Discussion: #1a1305 on #070910,
 * 1.08:1" for a gold button whose label is perfectly legible — a false failure,
 * which is as damaging as a missed one because it trains you to ignore the
 * gate. The stops are the real candidate backgrounds.
 *
 * NOTE: no backticks anywhere in this block. It lives inside a JS template
 * literal that is shipped to the browser as a source string, and a backtick
 * here terminates that string — the whole module then fails to parse.
 */
const gradientStops = (bgImage) => {
  if (!bgImage || bgImage === 'none' || !/gradient\\(/.test(bgImage)) return [];
  const out = [];
  // NOTE the doubled backslashes: this whole file is a TEMPLATE LITERAL
  // shipped to the browser as source, so one level of escaping is consumed
  // before the browser ever sees it. Written singly, /gradient\\(/ arrives as
  // /gradient(/ and throws "Unterminated group" — which made BOTH audits
  // return nothing and every gate pass on zero samples.
  const re = /(rgba?\\([^)]*\\)|#[0-9a-f]{3,8}\\b|color\\(srgb[^)]*\\))/gi;
  let m;
  while ((m = re.exec(bgImage))) {
    const c = parseColor(m[1]);
    if (c && c[3] > 0) out.push(c);
  }
  return out;
};

const resolveBg = (el) => {
  // Collect painted layers nearest-first, stopping at the first opaque one,
  // then composite far-to-near. Compositing near-to-far (the intuitive
  // direction) is wrong and silently reports the wrong colour for every
  // translucent surface, which is most of a dark theme.
  const stack = [];
  const gradients = [];
  let img = false, opaque = false;
  let n = el;
  while (n && n.nodeType === 1) {
    const cs = getComputedStyle(n);
    if (cs.backgroundImage && cs.backgroundImage !== 'none') {
      img = true;
      const stops = gradientStops(cs.backgroundImage);
      if (stops.length) {
        gradients.push({ stops, below: stack.slice() });
        // an opaque gradient stop is a real painted surface: stop walking
        if (stops.every(s => s[3] >= 1)) opaque = true;
      }
    }
    const c = parseColor(cs.backgroundColor);
    if (c && c[3] > 0) {
      stack.push(c);
      if (c[3] >= 1) { opaque = true; break; }
    }
    if (opaque) break;
    n = n.parentElement;
  }
  let base = [255, 255, 255, 1];
  if (!opaque) {
    const root = parseColor(getComputedStyle(document.documentElement).backgroundColor);
    if (root && root[3] > 0) base = [root[0], root[1], root[2], 1];
  }
  const composite = (layers) => {
    let out = base;
    for (let i = layers.length - 1; i >= 0; i--) out = over(layers[i], out);
    return out;
  };
  const flat = composite(stack);
  // Every surface the text could actually be sitting on. When a gradient is in
  // the chain, each of its stops is a candidate and the WORST one is the
  // verdict — a label is unreadable if it is unreadable anywhere along the fill.
  const candidates = [[flat[0], flat[1], flat[2]]];
  for (const g of gradients) {
    for (const s of g.stops) {
      const c = composite([s].concat(g.below));
      candidates.push([c[0], c[1], c[2]]);
    }
  }
  const primary = gradients.length ? candidates[candidates.length - 1] : candidates[0];
  return { color: primary, candidates, img, opaque, gradient: gradients.length > 0 };
};

const shortSel = (el) => {
  if (!el || el.nodeType !== 1) return '?';
  const parts = [];
  let n = el, depth = 0;
  while (n && n.nodeType === 1 && depth < 4) {
    let s = n.tagName.toLowerCase();
    const cls = (n.className && typeof n.className === 'string')
      ? n.className.trim().split(/\\s+/).filter(c => c && !/^(is-|has-)/.test(c)).slice(0, 3)
      : [];
    if (cls.length) s += '.' + cls.join('.');
    else if (n.id) s += '#' + n.id;
    parts.unshift(s);
    n = n.parentElement;
    depth++;
  }
  return parts.join(' > ');
};

const visible = (el) => {
  const cs = getComputedStyle(el);
  if (cs.display === 'none' || cs.visibility !== 'visible') return false;
  const r = el.getBoundingClientRect();
  if (r.width < 2 || r.height < 2) return false;
  return true;
};
`;

/**
 * Contrast audit.
 *
 * Rules applied (WCAG 2.1 AA):
 *   1.4.3 text        — 4.5:1, or 3:1 for >=24px, or >=18.66px at weight >=700
 *   1.4.11 non-text   — 3:1 for the boundary of a control that carries meaning
 */
export const CONTRAST_AUDIT = `(() => {
${COLOR_LIB}

const results = [];
const seen = new Set();

const record = (kind, el, fg, bg, need, note, candidates) => {
  // Worst candidate wins: a gradient-filled control has several real
  // backgrounds and the label has to clear the bar against all of them.
  let r = ratio(fg, bg);
  let worstBg = bg;
  for (const c of candidates || []) {
    const cr = ratio(fg, c);
    if (cr < r) { r = cr; worstBg = c; }
  }
  bg = worstBg;
  const sel = shortSel(el);
  const key = kind + '|' + sel + '|' + hex(fg) + '|' + hex(bg);
  if (seen.has(key)) return;
  seen.add(key);
  results.push({
    kind, sel, fg: hex(fg), bg: hex(bg),
    ratio: Math.round(r * 100) / 100,
    need, pass: r >= need,
    note: note || undefined,
    text: kind === 'text' ? (el.textContent || '').trim().slice(0, 42) : undefined,
  });
};

// ---- text ---------------------------------------------------------------
for (const el of document.querySelectorAll('body *')) {
  if (!visible(el)) continue;
  // only elements that own text directly; otherwise every wrapper reports its
  // descendants' text against its own colour, which is not what is rendered
  let own = '';
  for (const n of el.childNodes) if (n.nodeType === 3) own += n.nodeValue;
  if (!own.trim()) continue;

  const cs = getComputedStyle(el);
  const op = chainOpacity(el);
  if (op < 0.06) continue;
  // gradient-clipped text (animated rank names) has color:transparent by design
  if (cs.webkitTextFillColor === 'rgba(0, 0, 0, 0)' || (cs.color === 'rgba(0, 0, 0, 0)' && cs.backgroundClip === 'text')) continue;

  const fgRaw = parseColor(cs.color);
  if (!fgRaw) continue;
  const bgInfo = resolveBg(el);
  const fg = over([fgRaw[0], fgRaw[1], fgRaw[2], fgRaw[3] * op], bgInfo.color);

  const size = parseFloat(cs.fontSize);
  const weight = parseInt(cs.fontWeight) || 400;
  const large = size >= 24 || (size >= 18.66 && weight >= 700);
  record('text', el, fg, bgInfo.color, large ? 3 : 4.5,
         bgInfo.gradient ? 'gradient-worst-stop' : (bgInfo.img ? 'bg-image-in-chain' : undefined),
         bgInfo.candidates);
}

// ---- meaningful boundaries (1.4.11) -------------------------------------
// Only where the border IS the affordance: inputs, buttons, cards, separators
// that delimit a row. A decorative hairline is out of scope by the SC itself.
const BOUNDARY = [
  '.Button', '.FormControl', 'input[type=text]', 'textarea', '.Search-input input',
  '.TagTile', '.Post', '.DiscussionListItem', '.Dropdown-menu', '.Modal-content',
  '.Reaction', '.Avatar', '.Badge',
];
for (const sel of BOUNDARY) {
  for (const el of [...document.querySelectorAll(sel)].slice(0, 6)) {
    if (!visible(el)) continue;
    const cs = getComputedStyle(el);
    for (const side of ['Top', 'Right', 'Bottom', 'Left']) {
      if (parseFloat(cs['border' + side + 'Width']) < 0.5) continue;
      const bc = parseColor(cs['border' + side + 'Color']);
      if (!bc || bc[3] === 0) continue;
      // a border is seen against whatever is OUTSIDE the element
      const outside = resolveBg(el.parentElement || document.body);
      const fg = over(bc, outside.color);
      record('border', el, fg, outside.color, 3, 'border-' + side.toLowerCase());
      break; // one representative side per element
    }
  }
}

// ---- placeholders -------------------------------------------------------
for (const el of document.querySelectorAll('input[placeholder], textarea[placeholder]')) {
  if (!visible(el)) continue;
  const cs = getComputedStyle(el, '::placeholder');
  const fgRaw = parseColor(cs.color);
  if (!fgRaw) continue;
  const bgInfo = resolveBg(el);
  record('placeholder', el, over(fgRaw, bgInfo.color), bgInfo.color, 4.5);
}

return JSON.stringify(results);
})()`;

/**
 * Layering audit.
 *
 * Reports every element that participates in stacking, plus the empirical test
 * that matters: is anything from the content column painting over the sticky
 * header. That is answered with elementFromPoint on the real composited page,
 * not by reading z-index values and reasoning about them.
 */
export const LAYER_AUDIT = `(() => {
${COLOR_LIB}

const CREATES_CONTEXT = (el, cs) => {
  if (el === document.documentElement) return 'root';
  if (cs.position === 'fixed' || cs.position === 'sticky') return cs.position;
  if (cs.zIndex !== 'auto' && ['relative', 'absolute'].includes(cs.position)) return 'positioned+z';
  if (parseFloat(cs.opacity) < 1) return 'opacity';
  if (cs.transform !== 'none') return 'transform';
  if (cs.filter !== 'none') return 'filter';
  if (cs.backdropFilter && cs.backdropFilter !== 'none') return 'backdrop-filter';
  if (cs.mixBlendMode !== 'normal') return 'mix-blend-mode';
  if (cs.isolation === 'isolate') return 'isolation';
  if (/paint|layout|strict|content/.test(cs.contain || '')) return 'contain';
  if (cs.willChange && /transform|opacity|filter/.test(cs.willChange)) return 'will-change';
  if (cs.perspective !== 'none') return 'perspective';
  return null;
};

const layers = [];
for (const el of document.querySelectorAll('html, body, body *')) {
  const cs = getComputedStyle(el);
  if (cs.display === 'none') continue;
  const ctx = CREATES_CONTEXT(el, cs);
  const z = cs.zIndex;
  if (!ctx && z === 'auto' && cs.position === 'static') continue;
  const r = el.getBoundingClientRect();
  layers.push({
    sel: shortSel(el),
    position: cs.position,
    z,
    ctx,
    w: Math.round(r.width), h: Math.round(r.height),
    // a z-index that is not one of our tokens is a component inventing a layer
    tokened: /var\\(/.test(el.style.zIndex || '') || z === 'auto',
  });
}

// ---- the empirical overlap test ----------------------------------------
const header = document.querySelector('.App-header') || document.querySelector('#header');
const overlaps = [];
let headerInfo = null;
if (header) {
  const hs = getComputedStyle(header);
  const hr = header.getBoundingClientRect();
  headerInfo = { position: hs.position, z: hs.zIndex, top: Math.round(hr.top), height: Math.round(hr.height) };
  if (hr.height > 0 && hr.bottom > 0) {
    for (let fx = 0.05; fx <= 0.96; fx += 0.05) {
      for (let fy = 0.25; fy <= 0.8; fy += 0.25) {
        const x = Math.round(hr.left + hr.width * fx);
        const y = Math.round(hr.top + hr.height * fy);
        const hit = document.elementFromPoint(x, y);
        if (!hit) continue;
        if (header.contains(hit) || hit === header) continue;
        // legitimately-above layers are fine
        if (hit.closest('.Dropdown-menu, .ModalManager, .Modal, .tooltip, .AlertManager, .App-drawer, .Composer')) continue;
        overlaps.push({ x, y, sel: shortSel(hit), z: getComputedStyle(hit).zIndex, position: getComputedStyle(hit).position });
      }
    }
  }
}

return JSON.stringify({
  header: headerInfo,
  overlaps,
  scrollY: window.scrollY,
  layers: layers.sort((a, b) => (parseInt(b.z) || 0) - (parseInt(a.z) || 0)).slice(0, 120),
});
})()`;

/**
 * Structural sanity: elements that render but carry no styling of ours, plus
 * the constructs the operator named (quotes, spoilers, code, tables).
 */
export const STRUCTURE_AUDIT = `(() => {
${COLOR_LIB}
const probe = (sel) => {
  const el = document.querySelector(sel);
  if (!el) return null;
  const cs = getComputedStyle(el);
  const bg = resolveBg(el);
  return {
    sel, found: true,
    bg: cs.backgroundColor, resolvedBg: hex(bg.color), color: cs.color,
    border: cs.borderLeftWidth + ' ' + cs.borderLeftStyle + ' ' + cs.borderLeftColor,
    radius: cs.borderRadius, padding: cs.padding, font: cs.fontSize + '/' + cs.fontFamily.split(',')[0],
    rect: (r => ({ w: Math.round(r.width), h: Math.round(r.height) }))(el.getBoundingClientRect()),
  };
};
const SELS = [
  'blockquote', '.Post-body blockquote', '.spoiler', '.Post-body pre', '.Post-body code',
  '.Post-body table', '.Post-body ul', '.Post-body hr', '.PostMention', '.UserMention',
  '.Post-body .bbCodeBlock', '.Post-body img', '.Post-body iframe',
  '.DiscussionListItem', '.TagTile', '.Button', '.Header-controls .Button',
  '.App-header', '.Hero', '.Search-input input', '.Dropdown-menu', '.Post-footer',
  '.item-reply .Button', '.Scrubber', '.PostStream', '.CommentPost', '.Post-header',
];
const out = {};
for (const s of SELS) out[s] = probe(s);
out._counts = {
  posts: document.querySelectorAll('article.Post, .Post').length,
  images: document.querySelectorAll('.Post-body img').length,
  quotes: document.querySelectorAll('.Post-body blockquote').length,
  spoilers: document.querySelectorAll('.spoiler, .Post-body [class*=spoiler]').length,
  rows: document.querySelectorAll('.DiscussionListItem').length,
  tiles: document.querySelectorAll('.TagTile').length,
  iconify: document.querySelectorAll('iconify-icon').length,
};
return JSON.stringify(out);
})()`;

/**
 * Layout audit for the constructs that DOM assertions kept passing on.
 *
 * Each entry here exists because a real defect shipped past everything else:
 *
 *   railGap        — the author rail was a grid item spanning 50 auto tracks,
 *                    which distributed its 390px height above the post body.
 *                    Measured as the vertical distance from the top of the rail
 *                    to the top of the body; it should be the post header's
 *                    height, never the rail's.
 *   glyphOverflow  — line-height:0 on an avatar wrapper is inherited by the
 *                    generated-initial <span>, whose glyph then paints outside
 *                    the avatar square. Measured with a Range over the text
 *                    node, because the SPAN's own box is the right size while
 *                    the ink is not inside it.
 *   oneLine        — rail footnotes that must not wrap, checked by comparing
 *                    rendered height against one line-height.
 *   stylesheet     — the sentinel. less.php fails silently: it throws while
 *                    compiling, forum.css is never written, and the forum
 *                    serves with NO stylesheet while forum.js still builds
 *                    perfectly. Every visual assertion downstream is
 *                    meaningless if this is false, so it is checked first.
 */
export const LAYOUT_AUDIT = `(() => {
${COLOR_LIB}
const px = (n) => Math.round(n * 10) / 10;
const out = { railGap: [], glyphOverflow: [], oneLine: [], stylesheet: null, tapTargets: [] };

// ---- stylesheet sentinel -------------------------------------------------
const rootStyle = getComputedStyle(document.documentElement);
out.stylesheet = {
  // declared only by looksmax-theme/less/tokens.less
  zHeader: rootStyle.getPropertyValue('--z-header').trim(),
  ink: rootStyle.getPropertyValue('--ink').trim(),
  sheets: document.styleSheets.length,
  ok: rootStyle.getPropertyValue('--z-header').trim() !== '',
};

// ---- author rail vs post body -------------------------------------------
for (const post of [...document.querySelectorAll('article.Post')].slice(0, 8)) {
  const rail = post.querySelector('.LmxAuthor');
  const body = post.querySelector('.Post-body');
  const head = post.querySelector('.Post-header');
  if (!rail || !body) continue;
  const rb = rail.getBoundingClientRect();
  const bb = body.getBoundingClientRect();
  const hb = head ? head.getBoundingClientRect() : null;
  out.railGap.push({
    sel: shortSel(post).slice(-60),
    gap: px(bb.top - rb.top),
    // what the gap SHOULD be: the post header sitting above the body
    expected: hb ? px(hb.height + parseFloat(getComputedStyle(head).marginBottom || '0')) : 0,
    railW: px(rb.width), railH: px(rb.height), bodyH: px(bb.height),
    // the rail must be beside the body, not above it
    sideBySide: rb.right <= bb.left + 1,
    railLeftOfBody: px(bb.left - rb.right),
  });
}

// ---- avatar initials inside their box ------------------------------------
// A Range around the text node reports where the INK is. The span's own box is
// the right size in the broken case, which is why box-vs-box never caught it.
for (const av of [...document.querySelectorAll('.Avatar, .LmxAvatar')].slice(0, 40)) {
  if (av.tagName === 'IMG' || av.querySelector('img')) continue;
  if (!visible(av)) continue;
  let node = null;
  for (const n of av.childNodes) if (n.nodeType === 3 && n.nodeValue.trim()) { node = n; break; }
  const host = node ? av : (av.querySelector('.Avatar') || null);
  if (!node && host) for (const n of host.childNodes) if (n.nodeType === 3 && n.nodeValue.trim()) { node = n; break; }
  if (!node) continue;
  const r = document.createRange();
  r.selectNodeContents(node.parentElement);
  const ink = r.getBoundingClientRect();
  const box = (host || av).getBoundingClientRect();
  if (!ink.height || !box.height) continue;
  const overTop = px(box.top - ink.top);
  const overBottom = px(ink.bottom - box.bottom);
  const overLeft = px(box.left - ink.left);
  const overRight = px(ink.right - box.right);
  const worst = Math.max(overTop, overBottom, overLeft, overRight);
  out.glyphOverflow.push({
    sel: shortSel(host || av).slice(-70),
    text: (node.nodeValue || '').trim().slice(0, 3),
    box: { w: px(box.width), h: px(box.height) },
    overTop, overBottom, overLeft, overRight,
    lineHeight: getComputedStyle(node.parentElement).lineHeight,
    // 1px of tolerance for hinting; anything more is ink outside the square
    pass: worst <= 1,
  });
}

// ---- single-line rail footnotes ------------------------------------------
for (const sel of ['.LmxLegacy', '.LmxAuthor-seen', '.LmxRank', '.DiscussionListItem-stats']) {
  for (const el of [...document.querySelectorAll(sel)].slice(0, 4)) {
    if (!visible(el)) continue;
    const cs = getComputedStyle(el);
    const lh = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.2;
    const h = el.getBoundingClientRect().height;
    out.oneLine.push({ sel, h: px(h), lineHeight: px(lh), lines: Math.round(h / lh), pass: h <= lh * 1.6 });
  }
}

// ---- tap targets (mobile only; the caller decides) -----------------------
if (window.innerWidth <= 480) {
  const seen = new Set();
  for (const el of document.querySelectorAll('a, button, .Button, [role=button], input[type=checkbox]')) {
    if (!visible(el)) continue;
    const r = el.getBoundingClientRect();
    if (r.top > window.innerHeight || r.bottom < 0) continue;
    // links inside a paragraph of prose are text, not controls
    if (el.tagName === 'A' && el.closest('.Post-body')) continue;
    const k = shortSel(el);
    if (seen.has(k)) continue;
    seen.add(k);
    const small = Math.min(r.width, r.height);
    if (small < 32) out.tapTargets.push({ sel: k, w: px(r.width), h: px(r.height), text: (el.textContent||'').trim().slice(0,24) });
  }
}

return JSON.stringify(out);
})()`;
