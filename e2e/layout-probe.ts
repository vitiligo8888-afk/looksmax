#!/usr/bin/env bun
/**
 * Layout / stacking diagnostic.
 *
 * Screenshots show that something is wrong; this says WHAT. It reports, for a
 * real discussion page:
 *   - every child of the post grid with its grid placement and box, so dead
 *     vertical space can be attributed to a specific track rather than guessed
 *   - every element that paints above the sticky header, by comparing painted
 *     stacking order at the header's own coordinates (elementsFromPoint), which
 *     is the only thing that decides both what you SEE and what you CLICK
 *   - the computed contrast of the discussion hero title against what is
 *     actually behind it
 *   - avatars whose initial glyph overflows its box
 *
 * Nothing here asserts. It prints JSON so a fix can be aimed.
 */
const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21978);
const WIDTH = Number(process.env.WIDTH || 1440);
const HEIGHT = Number(process.env.HEIGHT || 1000);
const PATHS = (process.env.PROBE_PATHS || "").split(",").filter(Boolean);


// Chromium's children (gpu-process, renderers, zygote) are NOT killed by killing
// the parent: they reparent to init and keep burning CPU forever. Measured on
// this box — 20 orphaned gpu-processes, five of them at 56-97% CPU, ~4 cores
// gone. Kill the process GROUP and remove the profile dir.
function cleanup(proc: any, profileDir: string) {
  try { process.kill(-proc.pid, "SIGKILL"); } catch {}
  try { proc.kill(); } catch {}
  try { Bun.spawnSync(["rm", "-rf", profileDir]); } catch {}
}

const proc = Bun.spawn(
  [CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox", "--disable-gpu",
   "--hide-scrollbars", `--window-size=${WIDTH},${HEIGHT}`, `--user-data-dir=/tmp/lmx-probe-${process.pid}`],
  { stdout: "ignore", stderr: "ignore", detached: true },
);

async function endpoint(): Promise<string> {
  for (let i = 0; i < 60; i++) {
    try {
      const list = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()) as any[];
      const page = list.find((t) => t.type === "page" && t.webSocketDebuggerUrl);
      if (page) return page.webSocketDebuggerUrl;
    } catch {}
    await Bun.sleep(200);
  }
  throw new Error("chromium never exposed a page target");
}

const ws = new WebSocket(await endpoint());
await new Promise((r) => (ws.onopen = r));
let id = 0;
const pending = new Map<number, (v: any) => void>();
ws.onmessage = (e) => {
  const m = JSON.parse(String(e.data));
  if (m.id && pending.has(m.id)) { pending.get(m.id)!(m); pending.delete(m.id); }
};
const send = (method: string, params: any = {}): Promise<any> =>
  new Promise((res) => { const n = ++id; pending.set(n, res); ws.send(JSON.stringify({ id: n, method, params })); });

await send("Page.enable");
await send("Runtime.enable");
await send("Emulation.setDeviceMetricsOverride", { width: WIDTH, height: HEIGHT, deviceScaleFactor: 1, mobile: false });

const evalJs = async (expr: string) => {
  const r = await send("Runtime.evaluate", { expression: expr, awaitPromise: true, returnByValue: true });
  if (r?.result?.exceptionDetails) return { __throw: r.result.exceptionDetails.exception?.description };
  return r?.result?.result?.value;
};

await send("Page.navigate", { url: `${BASE}/all` });
await Bun.sleep(4000);
const firstHref: string =
  (await evalJs(`(document.querySelector('a[href*="/d/"]')||{getAttribute:()=>null}).getAttribute('href')`)) || "/all";

const targets = PATHS.length ? PATHS : [firstHref];

const PROBE = `(() => {
  const px = (n) => Math.round(n * 10) / 10;
  const box = (e) => { const r = e.getBoundingClientRect(); return {x: px(r.x), y: px(r.y), w: px(r.width), h: px(r.height)}; };
  const desc = (e) => e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') +
    (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\\s+/).slice(0,4).join('.') : '');
  const out = {};

  // ---- post grid: where does the vertical space go
  const post = document.querySelector('article.Post.has-lmx-author') || document.querySelector('article.Post');
  if (post) {
    const wrap = post.firstElementChild;
    const cs = getComputedStyle(wrap);
    out.postGrid = {
      post: desc(post), wrapper: desc(wrap),
      display: cs.display, cols: cs.gridTemplateColumns, rows: cs.gridTemplateRows,
      rowGap: cs.rowGap, alignItems: cs.alignItems,
      wrapperBox: box(wrap),
      children: [...wrap.children].map((c) => {
        const s = getComputedStyle(c);
        return { el: desc(c), box: box(c), gridRow: s.gridRow, gridColumn: s.gridColumn,
                 alignSelf: s.alignSelf, marginTop: s.marginTop, marginBottom: s.marginBottom };
      }),
    };
    const body = post.querySelector('.Post-body');
    const panel = post.querySelector('.LmxAuthor');
    if (body && panel) out.postGrid.gapAbovePostBody = px(box(body).y - box(panel).y);
  }

  // ---- what paints above the sticky header, at the header's own points
  const header = document.querySelector('#header, .App-header, .Header');
  if (header) {
    const hb = box(header);
    const cs = getComputedStyle(header);
    out.header = { el: desc(header), box: hb, position: cs.position, zIndex: cs.zIndex, isolation: cs.isolation };
    const samples = [];
    for (const [label, x, y] of [
      ['left', hb.x + 40, hb.y + hb.h / 2],
      ['centre', hb.x + hb.w / 2, hb.y + hb.h / 2],
      ['search', hb.x + hb.w * 0.68, hb.y + hb.h / 2],
      ['right', hb.x + hb.w - 60, hb.y + hb.h / 2],
    ]) {
      const stack = document.elementsFromPoint(x, y).slice(0, 6).map((e) => {
        const s = getComputedStyle(e);
        return desc(e) + ' [z=' + s.zIndex + ' pos=' + s.position + ']';
      });
      samples.push({ label, x: px(x), y: px(y), top: stack[0] || null, stack });
    }
    out.header.hitTest = samples;
    out.header.headerIsTopmost = samples.every((s) => (s.top || '').match(/header|Header|Search|Button|nav|App-header/));
  }

  // ---- every positioned/stacking element with a z-index, ranked
  const zs = [];
  document.querySelectorAll('body *').forEach((e) => {
    const s = getComputedStyle(e);
    const z = s.zIndex;
    if (z !== 'auto' && Number(z) !== 0) {
      const b = box(e);
      if (b.w === 0 && b.h === 0) return;
      zs.push({ el: desc(e), z: Number(z), position: s.position, transform: s.transform !== 'none',
                filter: s.filter !== 'none', opacity: s.opacity, box: b });
    }
  });
  zs.sort((a, b) => b.z - a.z);
  out.zIndexTop = zs.slice(0, 25);

  // ---- contrast of the hero title against its backdrop
  const rgb = (v) => { const m = v.match(/[\\d.]+/g) || []; return m.slice(0,3).map(Number); };
  const lum = (c) => { const f = c.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2]; };
  const ratio = (a, b) => { const l1 = lum(a), l2 = lum(b); const [hi, lo] = l1 > l2 ? [l1, l2] : [l2, l1]; return px((hi + 0.05) / (lo + 0.05)); };
  const backdrop = (e) => {
    let n = e;
    while (n && n !== document.documentElement) {
      const bg = getComputedStyle(n).backgroundColor;
      const a = (bg.match(/[\\d.]+/g) || [])[3];
      if (bg && bg !== 'rgba(0, 0, 0, 0)' && a !== '0') return { el: desc(n), bg };
      n = n.parentElement;
    }
    return { el: 'html', bg: getComputedStyle(document.documentElement).backgroundColor };
  };
  out.contrast = [];
  for (const sel of ['.DiscussionHero-title', '.Hero-title', '.DiscussionPage-hero h2', '.DiscussionHero h2',
                     '.DiscussionListItem-title', '.LmxAuthor-name', '.Post-body', '.item-title']) {
    document.querySelectorAll(sel).forEach((e, i) => {
      if (i > 0) return;
      const b = box(e);
      if (!b.w) return;
      const fg = getComputedStyle(e).color;
      const bd = backdrop(e);
      out.contrast.push({ sel, el: desc(e), color: fg, behind: bd.el, bg: bd.bg, ratio: ratio(rgb(fg), rgb(bd.bg)), box: b });
    });
  }

  // ---- avatar initials overflowing their box
  out.avatarOverflow = [];
  document.querySelectorAll('.LmxAvatar').forEach((av, i) => {
    if (i > 3) return;
    const img = av.querySelector('img');
    const initial = av.querySelector('.Avatar:not(img), span.Avatar');
    const target = initial || av.firstElementChild;
    if (!target || img) return;
    const s = getComputedStyle(target);
    out.avatarOverflow.push({
      avatarBox: box(av), innerBox: box(target), text: (target.textContent || '').trim().slice(0, 3),
      display: s.display, lineHeight: s.lineHeight, fontSize: s.fontSize, alignItems: s.alignItems,
      overflowsTopBy: px(box(av).y - box(target).y),
    });
  });

  // ---- the "Carried over" line and its overflow
  const leg = document.querySelector('.LmxLegacy');
  if (leg) {
    out.legacy = { text: leg.textContent.trim(), box: box(leg), parentBox: box(leg.parentElement),
                   overflowsRightBy: px((box(leg).x + box(leg).w) - (box(leg.parentElement).x + box(leg.parentElement).w)),
                   scrollW: leg.scrollWidth, clientW: leg.clientWidth };
  }

  // ---- header user area spacing (nav crowding)
  const sess = document.querySelector('.Header-secondary');
  if (sess) out.headerSecondary = [...sess.querySelectorAll('li')].map((li) => ({ el: desc(li), box: box(li), text: (li.textContent||'').trim().slice(0,24) }));

  return out;
})()`;

const results: any = {};
for (const p of targets) {
  await send("Page.navigate", { url: `${BASE}${p}` });
  await Bun.sleep(4200);
  const top = await evalJs(PROBE);
  await evalJs(`window.scrollTo(0, 1200)`);
  await Bun.sleep(900);
  const scrolled = await evalJs(PROBE);
  results[p] = { top, scrolled: { header: scrolled?.header, zIndexTop: scrolled?.zIndexTop } };
}

console.log(JSON.stringify(results, null, 2));
ws.close();
cleanup(proc, `/tmp/lmx-layout-probe-${process.pid}`);
