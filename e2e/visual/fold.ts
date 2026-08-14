/**
 * Two audits that DOM assertions and screenshots both miss, plus the one the
 * operator asked for by name.
 *
 *   FOLD_AUDIT    — what actually occupies the first viewport height, measured
 *                   in pixels and classified, and whether the FEED is in it.
 *                   The brief: "ensure it's rich and has what we want front and
 *                   center the main content of the site, not polluted by bs
 *                   banners and 'welcome back' shit". That is an assertion about
 *                   composition, so it is asserted rather than eyeballed.
 *
 *   HEADER_HIT    — is the header CLICKABLE while the page is scrolled. Not "is
 *                   it visible", not "what is its z-index": every control in it
 *                   is hit-tested with document.elementFromPoint at its own
 *                   centre on the real composited page. The operator's report
 *                   was "text and post elements still go above the top bar when
 *                   scrolling, and it isn't clickable anymore" — the second half
 *                   of that sentence is the part a z-index check cannot see. A
 *                   transparent overlay that paints nothing still eats clicks.
 *
 * Both are source strings shipped to the browser, same as inject.ts. The
 * escaping rule from that file applies here too: this is a TEMPLATE LITERAL, so
 * every backslash in a regex must be doubled and there may be no backtick
 * anywhere inside.
 */

/**
 * Above-the-fold composition.
 *
 * Method: walk the boxes that actually intersect the first viewport height,
 * keep the ones that are big enough to be a region rather than a control, and
 * classify each. Classification is by ROLE, resolved from a small table of
 * selectors, because "is this a banner" is a question about what the element is
 * for and no geometric heuristic answers it.
 *
 * The verdict fields are what the gate reads:
 *   feedTop        — y of the first feed item, or null if no feed is on the page
 *   feedInFold     — feed items whose box is ENTIRELY inside the first viewport
 *   bannerPx       — vertical pixels of the fold spent on promotional bands
 *   contentPx      — vertical pixels spent on feed/list/section content
 */
export const FOLD_AUDIT = `(() => {
const vh = window.innerHeight;
const vw = window.innerWidth;

/* Role table. Order matters: the first match wins, so the specific entries
   come before the general ones. A selector that matches nothing costs nothing,
   which is why extensions that may not be installed are still listed. */
const ROLES = [
  ['banner',  '.Hero.WelcomeHero, .WelcomeHero, .LmxHero, .LmxCard--onboarding, .LmxNews.is-promo'],
  ['chrome',  '.App-header, .Header-secondary, .App-drawer, .DiscussionPage-nav, .IndexPage-toolbar'],
  ['nav',     '.LmxNav, .LmxChips, .IndexPage-nav, .sideNav, .LmxCard--nav'],
  ['feed',    '.LmxFeed, .DiscussionList, .DiscussionListItem, .LmxSections, .LmxTile, .LmxNewsItem, .PostStream, .LmxList'],
  ['aside',   '.LmxIndex-side, .LmxCard, .TagTiles'],
];

const roleOf = (el) => {
  for (const [role, sel] of ROLES) { if (el.matches(sel)) return role; }
  return null;
};

/* Only boxes that are REGIONS. A 20px chip inside a card is not competing for
   the fold; the card it lives in is. 56px is the smallest thing on this forum
   that is a band rather than a control (the header is 56). */
const MIN_H = 40;
const MIN_W = vw * 0.25;

const rows = [];
const seen = new Set();
for (const el of document.querySelectorAll('body *')) {
  const cs = getComputedStyle(el);
  if (cs.display === 'none' || cs.visibility !== 'visible' || parseFloat(cs.opacity) < 0.05) continue;
  const r = el.getBoundingClientRect();
  if (r.bottom <= 0 || r.top >= vh) continue;          /* not in the fold */
  if (r.height < MIN_H || r.width < MIN_W) continue;
  const role = roleOf(el);
  if (!role) continue;
  /* keep the OUTERMOST element of a role: a DiscussionList and its 20 rows are
     one region, not 21 */
  let dup = false;
  for (const p of rows) { if (p.node.contains(el) && p.role === role) { dup = true; break; } }
  if (dup) continue;
  const key = role + '|' + Math.round(r.top) + '|' + Math.round(r.height);
  if (seen.has(key)) continue;
  seen.add(key);
  rows.push({
    node: el, role,
    sel: (el.tagName.toLowerCase() + '.' + String(el.className || '').trim().split(/\\s+/).slice(0, 3).join('.')).replace(/\\.$/, ''),
    top: Math.round(r.top), height: Math.round(r.height),
    /* pixels of THIS box that are inside the first viewport */
    inFold: Math.round(Math.min(r.bottom, vh) - Math.max(r.top, 0)),
  });
}

const sum = (role) => rows.filter(r => r.role === role).reduce((a, b) => a + b.inFold, 0);

/* Feed items specifically: how many are FULLY visible without scrolling. A feed
   whose first row is cut in half is not "in the fold" in any sense a reader
   would recognise. */
/* Only items that are actually PAINTED. The feed renders all six tab panels
   into the DOM and hides five of them, so an unfiltered count reported "50
   whole feed items in the fold" on a page showing ten — a gate reading a
   number five times too generous, which is worse than no gate. A hidden panel's
   rows have a zero-height box, so height is the discriminator. */
const items = [...document.querySelectorAll('.LmxFeed-item, .DiscussionListItem, .LmxTile, .LmxNewsItem')]
  .filter(el => { const r = el.getBoundingClientRect(); return r.height > 8 && r.width > 8; });
const whole = items.filter(el => { const r = el.getBoundingClientRect(); return r.top >= 0 && r.bottom <= vh; });
const first = items.map(el => el.getBoundingClientRect()).sort((a, b) => a.top - b.top)[0];

return JSON.stringify({
  viewport: { w: vw, h: vh },
  scrollY: Math.round(window.scrollY),
  regions: rows.map(({ node, ...rest }) => rest).sort((a, b) => a.top - b.top),
  bannerPx: sum('banner'),
  contentPx: sum('feed'),
  navPx: sum('nav'),
  feedTop: first ? Math.round(first.top) : null,
  feedItemsTotal: items.length,
  feedInFold: whole.length,
  /* the headline number: how far down the page the reader has to look before
     the first piece of actual content starts */
  firstContentY: first ? Math.round(first.top + window.scrollY) : null,
  /* Operator acceptance criterion, stated exactly: "at 1440x900 and at 390x844,
     at least the first 'Por dónde empezar' card must be within the first
     viewport height". Selected by CLASS, not by the Spanish heading text — the
     heading is a translation key and an English reader would silently pass a
     text match. null means the section block is not on this page at all, which
     the gate treats as not-applicable rather than as a pass. */
  /* Duplicate rows in any feed panel.
     Operator report: "on guias in the home page there's 2 of every item".
     Cause was a join against a SET of qualifying tags, so a discussion carrying
     two of them matched twice — measured as guides cards=10 distinct=5. Counted
     by the ROW's own overlay anchor, one per card: counting every /d/ link
     double-counts, because each row legitimately has both an overlay link and a
     title link, and that miscount makes every panel look duplicated. */
  feedDupes: (() => {
    const out = [];
    for (const list of document.querySelectorAll('.LmxFeed-list')) {
      const ids = [...list.querySelectorAll('a.LmxFeed-hit')]
        .map(a => (a.getAttribute('href') || '').match(/\/d\/(\d+)/))
        .filter(Boolean).map(m => m[1]);
      const uniq = new Set(ids);
      if (ids.length !== uniq.size) {
        out.push({ panel: list.getAttribute('data-feed'), cards: ids.length, distinct: uniq.size });
      }
    }
    return out;
  })(),
  startHere: (() => {
    const tiles = [...document.querySelectorAll('.LmxSections .LmxTile')]
      .filter(el => { const r = el.getBoundingClientRect(); return r.height > 8; });
    if (!tiles.length) return null;
    const r = tiles[0].getBoundingClientRect();
    return { top: Math.round(r.top), bottom: Math.round(r.bottom), inFold: r.top < vh, whollyInFold: r.top >= 0 && r.bottom <= vh };
  })(),
});
})()`;

/**
 * Header hit-testing, with the page scrolled.
 *
 * For every control in the header, elementFromPoint at its centre must land on
 * that control or inside it. Anything else is named: the element that is eating
 * the click, its z-index, its position, and which stacking context created it —
 * because "raise the child" is the wrong fix in almost every case, and the
 * ancestor that made the trap is the thing that has to be reported.
 */
export const HEADER_HIT = `(() => {
const header = document.querySelector('.App-header');
if (!header) return JSON.stringify({ header: false, controls: [] });

const CONTROLS = [
  ['wordmark',      '.Header-title a, .Header-title #home-link'],
  ['search',        '.Search-input input, .Search input'],
  ['drawer',        '.Header-controls .Button--drawer, .App-header .Button--drawer, .Header-primary .Button--drawer'],
  ['notifications', '.item-notifications button, .NotificationsDropdown .Dropdown-toggle'],
  ['session',       '.SessionDropdown .Dropdown-toggle, .Header-secondary .Dropdown-toggle'],
  ['signup',        '.item-signUp button'],
  ['login',         '.item-logIn button'],
  ['locale',        '.item-lmxLocale button, .lmx-locale-toggle, [class*=Locale] button'],
  ['nav-first',     '.Header-primary .Header-controls > li > .Button'],
];

/* Which ancestor created the stacking context the hit element is trapped in.
   This is the actual diagnosis for "the dropdown will not rise": a transform,
   a filter or a backdrop-filter on an ancestor pins every descendant below its
   own level no matter what z-index the descendant asks for. */
const trapOf = (el) => {
  let n = el && el.parentElement;
  while (n && n !== document.documentElement) {
    const cs = getComputedStyle(n);
    if (cs.transform !== 'none') return 'transform on ' + n.className;
    if (cs.filter !== 'none') return 'filter on ' + n.className;
    if (cs.backdropFilter && cs.backdropFilter !== 'none') return 'backdrop-filter on ' + n.className;
    if (parseFloat(cs.opacity) < 1) return 'opacity on ' + n.className;
    if (cs.willChange && /transform|opacity|filter/.test(cs.willChange)) return 'will-change on ' + n.className;
    if (cs.contain && /paint|layout|strict|content/.test(cs.contain)) return 'contain on ' + n.className;
    n = n.parentElement;
  }
  return null;
};

const brief = (el) => {
  if (!el) return 'null';
  const c = String(el.className || '').trim().split(/\\s+/).slice(0, 3).join('.');
  return el.tagName.toLowerCase() + (c ? '.' + c : '');
};

/*
 * Pick the instance that is actually ON SCREEN.
 *
 * Flarum renders the header controls TWICE below the tablet breakpoint: once in
 * the visible bar and once inside .App-drawer, which is off-canvas. A bare
 * querySelector picks whichever comes first in the DOM — the drawer copy — and
 * hit-testing its centre reports the visible control as "the thing eating the
 * click". That produced six phantom failures per mobile surface, all of them
 * the audit's own fault. An element whose centre is outside the viewport cannot
 * be hit-tested at all (elementFromPoint returns null there), so those are
 * skipped rather than failed.
 */
const onScreen = (el) => {
  if (!el || el.closest('.App-drawer')) return false;
  const r = el.getBoundingClientRect();
  if (r.width < 2 || r.height < 2) return false;
  const x = r.left + r.width / 2, y = r.top + r.height / 2;
  return x >= 0 && y >= 0 && x <= window.innerWidth && y <= window.innerHeight;
};

const out = [];
for (const [name, sel] of CONTROLS) {
  const all = [...document.querySelectorAll(sel)];
  const el = all.find(onScreen) || null;
  if (!el) {
    out.push({ name, present: all.length > 0, sized: false, offscreen: all.length > 0 });
    continue;
  }
  const r = el.getBoundingClientRect();
  const x = Math.round(r.left + r.width / 2);
  const y = Math.round(r.top + r.height / 2);
  const hit = document.elementFromPoint(x, y);
  const ok = !!hit && (hit === el || el.contains(hit) || hit.contains(el));
  out.push({
    name, present: true, sized: true, x, y,
    w: Math.round(r.width), h: Math.round(r.height),
    ok,
    hit: ok ? undefined : brief(hit),
    hitZ: ok ? undefined : (hit ? getComputedStyle(hit).zIndex : undefined),
    hitPosition: ok ? undefined : (hit ? getComputedStyle(hit).position : undefined),
    trappedBy: ok ? undefined : trapOf(hit),
  });
}

const hr = header.getBoundingClientRect();
return JSON.stringify({
  header: true,
  scrollY: Math.round(window.scrollY),
  headerBox: { top: Math.round(hr.top), height: Math.round(hr.height) },
  headerPosition: getComputedStyle(header).position,
  headerZ: getComputedStyle(header).zIndex,
  controls: out,
});
})()`;

/**
 * Icon sanity — the systemic form of "the loading spinner is a weird stretched
 * rotating icon".
 *
 * An <iconify-icon> that has not resolved is a replaced element with NO
 * intrinsic dimensions, so a flex or grid parent stretches it to whatever space
 * is going. The symptom is a huge distorted glyph; the cause is an unsized box,
 * and it is invisible to every check that looks at the icon NAME. So: measure
 * the box against the font size and against the glyph's own aspect ratio.
 */
export const ICON_AUDIT = `(() => {
const bad = [];
const all = [...document.querySelectorAll('iconify-icon, i.fa, i[class*="fa-"], .icon, svg.icon')];
for (const el of all) {
  const cs = getComputedStyle(el);
  if (cs.display === 'none' || cs.visibility !== 'visible') continue;
  const r = el.getBoundingClientRect();
  if (r.width < 1 && r.height < 1) continue;
  const fs = parseFloat(cs.fontSize) || 16;
  const cls = String(el.getAttribute('class') || '').trim().split(/\\s+/).slice(0, 3).join('.');
  const name = el.getAttribute('icon') || '';
  const svg = el.shadowRoot ? el.shadowRoot.querySelector('svg') : el.querySelector && el.querySelector('svg');
  /* the glyph's own aspect ratio, from its viewBox — the only correct
     reference. A 1:1 assumption is wrong for wide glyphs and would produce
     false failures, which train you to ignore the gate. */
  let intrinsic = null;
  if (svg) {
    const vb = (svg.getAttribute('viewBox') || '').trim().split(/[\\s,]+/).map(Number);
    if (vb.length === 4 && vb[2] > 0 && vb[3] > 0) intrinsic = vb[2] / vb[3];
  }
  const ratio = r.height > 0 ? r.width / r.height : 0;
  const problems = [];
  if (el.tagName === 'ICONIFY-ICON' && !svg) problems.push('unresolved: no svg in shadow root');
  if (intrinsic && ratio > 0 && Math.abs(ratio - intrinsic) / intrinsic > 0.12) problems.push('aspect ' + ratio.toFixed(2) + ' vs intrinsic ' + intrinsic.toFixed(2));
  if (r.height > fs * 1.9) problems.push('box ' + Math.round(r.height) + 'px vs font-size ' + Math.round(fs) + 'px');
  if (r.width > fs * 1.9 && (!intrinsic || intrinsic < 1.5)) problems.push('width ' + Math.round(r.width) + 'px vs font-size ' + Math.round(fs) + 'px');
  if (problems.length) {
    bad.push({
      tag: el.tagName.toLowerCase(), cls, name,
      w: Math.round(r.width), h: Math.round(r.height), fontSize: Math.round(fs),
      problems,
    });
  }
}
return JSON.stringify({ total: all.length, failing: bad.length, worst: bad.slice(0, 40) });
})()`;
