/**
 * Contrast in the HOVER state, measured by actually hovering.
 *
 * Why this is a separate pass and not another selector in CONTRAST_AUDIT: a
 * hover style does not exist in the computed style until the pointer is over
 * the element, and there is no way to ask for it. CSS.forcePseudoState over CDP
 * can fake it, but it fakes only the pseudo-class — it does not run the
 * cascade the way a real pointer does through :hover on ANCESTORS, which is
 * where this project's actual bug lived: `.Button:hover` (0,2,0) beat
 * `.Button--primary` (0,1,0) and repainted the primary button grey-on-grey the
 * moment the mouse touched it. At rest it measured 9.6:1 and passed.
 *
 * So the pointer is really moved, over the real composited page, and the colours
 * are read afterwards.
 *
 * Cost: one CDP round trip per element. Bounded by TARGETS and by a cap per
 * surface, because a full page has hundreds of hoverable things and the ones
 * that matter are the controls.
 */
import type { Browser } from "./cdp";

/** The things a reader actually points at. One representative of each kind. */
const TARGETS = [
  ".Button--primary",
  ".Button",
  ".LmxBtn",
  ".LmxBtn--primary",
  ".LmxBtn--ghost",
  ".Header-controls > li > .Button",
  ".Header-controls .Dropdown-toggle",
  ".item-signUp button",
  ".item-logIn button",
  ".DiscussionListItem",
  ".DiscussionListItem-main",
  ".LmxFeed-item",
  ".LmxFeed-tab",
  ".LmxTile",
  ".LmxNav-link",
  ".LmxList-link",
  ".LmxChip",
  ".TagLabel",
  ".TagTile",
  ".Post-body a",
  ".PostUser a",
  ".Search-input input",
  ".Dropdown-menu li > a",
  ".LmxCard-head a",
  ".Scrubber-first",
  ".App-footer a",
];

export type HoverFail = {
  sel: string;
  what: string;
  fg: string;
  bg: string;
  ratio: number;
  need: number;
  text: string;
};

/**
 * Collect one instance of each target that is visible and in the viewport, with
 * the point to aim at. Done in-page so the coordinates are the composited ones.
 */
const COLLECT = (targets: string[]) => `(() => {
const out = [];
const seen = new Set();
for (const sel of ${JSON.stringify(targets)}) {
  for (const el of [...document.querySelectorAll(sel)].slice(0, 3)) {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility !== 'visible') continue;
    const r = el.getBoundingClientRect();
    if (r.width < 6 || r.height < 6) continue;
    if (r.top < 0 || r.bottom > window.innerHeight || r.left < 0 || r.right > window.innerWidth) continue;
    const x = Math.round(r.left + Math.min(r.width / 2, 40));
    const y = Math.round(r.top + r.height / 2);
    const k = sel + '|' + x + ',' + y;
    if (seen.has(k)) continue;
    seen.add(k);
    out.push({ sel, x, y });
    break; // one representative per selector; the rest are the same component
  }
}
return JSON.stringify(out);
})()`;

/**
 * Measure the element under (x,y) WHILE it is hovered.
 *
 * Deliberately re-uses the same compositing walk as the resting audit rather
 * than a simplified one, because the whole point of this project's dark theme
 * is translucent surfaces and a naive backgroundColor read reports the page
 * colour for most of them.
 */
const MEASURE = (x: number, y: number, sel: string) => `(() => {
const parseColor = (s) => {
  if (!s || s === 'transparent') return [0,0,0,0];
  let m = s.match(/^rgba?\\(([^)]+)\\)$/);
  if (m) { const p = m[1].split(/[,\\s\\/]+/).filter(Boolean).map(Number); return [p[0]||0,p[1]||0,p[2]||0,p.length>3?p[3]:1]; }
  m = s.match(/^#([0-9a-f]{3,8})$/i);
  if (m) { let h = m[1]; if (h.length === 3) h = h.split('').map(c=>c+c).join('');
    const n = parseInt(h.slice(0,6),16); const a = h.length===8?parseInt(h.slice(6,8),16)/255:1;
    return [(n>>16)&255,(n>>8)&255,n&255,a]; }
  m = s.match(/^color\\(srgb ([^)]+)\\)$/);
  if (m) { const parts = m[1].split('/'); const p = parts[0].trim().split(/\\s+/).map(Number);
    return [Math.round(p[0]*255),Math.round(p[1]*255),Math.round(p[2]*255), parts[1]!==undefined?parseFloat(parts[1]):1]; }
  return null;
};
const over = (f,b) => { const a=f[3]; if(a>=1) return [f[0],f[1],f[2],1]; if(a<=0) return b;
  return [f[0]*a+b[0]*(1-a), f[1]*a+b[1]*(1-a), f[2]*a+b[2]*(1-a), 1]; };
const lum = (c) => { const f=(v)=>{v/=255; return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4);};
  return 0.2126*f(c[0])+0.7152*f(c[1])+0.0722*f(c[2]); };
const ratio = (a,b) => { const l1=lum(a), l2=lum(b); return (Math.max(l1,l2)+0.05)/(Math.min(l1,l2)+0.05); };
const hex = (c) => '#' + [c[0],c[1],c[2]].map(v=>Math.round(v).toString(16).padStart(2,'0')).join('');
const stops = (img) => { const o=[]; if(!img||img==='none'||!/gradient\\(/.test(img)) return o;
  const re=/(rgba?\\([^)]*\\)|#[0-9a-f]{3,8}\\b|color\\(srgb[^)]*\\))/gi; let m;
  while((m=re.exec(img))){ const c=parseColor(m[1]); if(c&&c[3]>0) o.push(c);} return o; };
const resolveBg = (el) => {
  const stack=[]; const grads=[]; let opaque=false; let n=el;
  while(n && n.nodeType===1){ const cs=getComputedStyle(n);
    if(cs.backgroundImage && cs.backgroundImage!=='none'){ const s=stops(cs.backgroundImage);
      if(s.length){ grads.push({stops:s, below:stack.slice()}); if(s.every(x=>x[3]>=1)) opaque=true; } }
    const c=parseColor(cs.backgroundColor);
    if(c&&c[3]>0){ stack.push(c); if(c[3]>=1){opaque=true;break;} }
    if(opaque) break; n=n.parentElement; }
  let base=[255,255,255,1];
  if(!opaque){ const rt=parseColor(getComputedStyle(document.documentElement).backgroundColor);
    if(rt&&rt[3]>0) base=[rt[0],rt[1],rt[2],1]; }
  const comp=(l)=>{ let o=base; for(let i=l.length-1;i>=0;i--) o=over(l[i],o); return o; };
  const flat=comp(stack);
  const cands=[[flat[0],flat[1],flat[2]]];
  for(const g of grads) for(const s of g.stops){ const c=comp([s].concat(g.below)); cands.push([c[0],c[1],c[2]]); }
  return { color: grads.length ? cands[cands.length-1] : cands[0], candidates: cands };
};

const host = document.elementFromPoint(${x}, ${y});
if (!host) return JSON.stringify([]);
/* the element we aimed at, or the nearest ancestor that is it */
const root = host.closest(${JSON.stringify(sel)}) || host;
const out = [];
const nodes = [root, ...root.querySelectorAll('*')].slice(0, 24);
for (const el of nodes) {
  let own = '';
  for (const n of el.childNodes) if (n.nodeType === 3) own += n.nodeValue;
  if (!own.trim()) continue;
  const cs = getComputedStyle(el);
  if (cs.visibility !== 'visible' || cs.display === 'none') continue;
  if (cs.webkitTextFillColor === 'rgba(0, 0, 0, 0)') continue;
  const fgRaw = parseColor(cs.color);
  if (!fgRaw) continue;
  const bg = resolveBg(el);
  const fg = over(fgRaw, bg.color);
  let r = ratio(fg, bg.color); let worst = bg.color;
  for (const c of bg.candidates) { const cr = ratio(fg, c); if (cr < r) { r = cr; worst = c; } }
  const size = parseFloat(cs.fontSize); const weight = parseInt(cs.fontWeight) || 400;
  const need = (size >= 24 || (size >= 18.66 && weight >= 700)) ? 3 : 4.5;
  out.push({ what: el.tagName.toLowerCase() + '.' + String(el.className||'').trim().split(/\\s+/).slice(0,2).join('.'),
             fg: hex(fg), bg: hex(worst), ratio: Math.round(r*100)/100, need,
             text: own.trim().slice(0, 36) });
}
return JSON.stringify(out);
})()`;

export async function hoverContrast(browser: Browser, cap = 26): Promise<HoverFail[]> {
  const raw = await browser.eval(COLLECT(TARGETS)).catch(() => "[]");
  const points: { sel: string; x: number; y: number }[] = JSON.parse(raw || "[]");
  const fails: HoverFail[] = [];

  for (const p of points.slice(0, cap)) {
    // A real pointer. mouseMoved is what triggers :hover; dispatching it twice
    // (once away, once on) makes the transition actually start from the resting
    // state rather than from whatever the previous target left behind.
    await browser.send("Input.dispatchMouseEvent", { type: "mouseMoved", x: 1, y: 1, buttons: 0 });
    await Bun.sleep(30);
    await browser.send("Input.dispatchMouseEvent", { type: "mouseMoved", x: p.x, y: p.y, buttons: 0 });
    // Long enough for --t-slow (260ms) to finish; a colour sampled mid-
    // transition is a real number for a state no one ever sees.
    await Bun.sleep(340);
    const r = await browser.eval(MEASURE(p.x, p.y, p.sel)).catch(() => "[]");
    for (const row of JSON.parse(r || "[]")) {
      if (row.ratio < row.need) fails.push({ sel: p.sel, ...row });
    }
  }
  await browser.send("Input.dispatchMouseEvent", { type: "mouseMoved", x: 1, y: 1, buttons: 0 }).catch(() => {});
  return fails;
}
