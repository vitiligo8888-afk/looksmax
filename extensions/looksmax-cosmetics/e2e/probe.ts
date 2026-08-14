/**
 * The evidence run for the cosmetics lane.
 *
 * Every call site an avatar appears on, at 1440 and 390, logged out and logged
 * in, screenshotted AND measured. A screenshot alone proves a page rendered; the
 * counters next to it prove WHICH avatars got a frame and which did not, which
 * is the thing a screenshot of a busy page hides.
 *
 * It also does the three things a green check cannot do on its own:
 *   * elementFromPoint over a framed avatar, to prove the wrapper's stacking
 *     context does not swallow the click target underneath it
 *   * a frame-time measurement with every frame on the page animating
 *   * the negative: an account with no entitlement gets no ring
 *
 *   bun e2e/probe.ts            # everything
 *   bun e2e/probe.ts --quick    # skip the phone pass
 */
import { Browser } from "../../../e2e/visual/cdp";
import { mkdirSync, writeFileSync } from "node:fs";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const OUT = process.env.OUT || "/work/flarum/cos-shots";
const PORT = 21860;
const ADMIN = { user: "admin", pass: "1IxmV2IMZ8cnulvIHBT0" };

const QUICK = process.argv.includes("--quick");

/** The nine places an avatar is drawn on this forum. */
const SURFACES: { id: string; path: string; note: string }[] = [
  { id: "index", path: "/", note: "index rows + last-poster rail (looksmax-index)" },
  { id: "all", path: "/all", note: "core discussion list" },
  { id: "discussion", path: "/d/58157", note: "post author rail, 20+ avatars" },
  { id: "profile-framed", path: "/u/maarda", note: "profile hero + the user's own posts" },
  { id: "profile-plain", path: "/u/Chad", note: "NEGATIVE: no entitlement, must be bare" },
  { id: "search", path: "/?q=looksmax", note: "search results" },
  { id: "settings", path: "/settings", note: "the wardrobe (logged in only)" },
  { id: "notifications", path: "/notifications", note: "notification list (logged in only)" },
];

const VIEWPORTS = QUICK
  ? [{ name: "desktop", width: 1440, height: 1100 }]
  : [
      { name: "desktop", width: 1440, height: 1100 },
      { name: "phone", width: 390, height: 844, mobile: true },
    ];

/** Everything the decorator did on the page, read back out of the DOM. */
const AUDIT = `(() => {
  const wrap = [...document.querySelectorAll('.LmxCosFrame[data-cf]')];
  const avatars = [...document.querySelectorAll('.Avatar')];
  const rankRings = [...document.querySelectorAll('.lmx-frame[class*="fr-"]')];
  const byslug = {};
  for (const w of wrap) {
    const s = w.getAttribute('data-cf');
    byslug[s] = (byslug[s] || 0) + 1;
  }
  const doubled = wrap.filter(w => w.querySelectorAll('.Avatar').length !== 1).length;
  const nested = [...document.querySelectorAll('.LmxCosFrame .LmxCosFrame')].length;

  // Is any ring clipped by an ancestor's overflow:hidden? Measured, not assumed:
  // compare the ring's painted box against every scrollable/clipping ancestor.
  let clipped = 0;
  for (const w of wrap) {
    const r = w.getBoundingClientRect();
    let p = w.parentElement;
    while (p && p !== document.body) {
      const cs = getComputedStyle(p);
      if (cs.overflow !== 'visible' && cs.overflow !== '') {
        const pr = p.getBoundingClientRect();
        if (r.left - 6 < pr.left || r.top - 6 < pr.top || r.right + 6 > pr.right || r.bottom + 6 > pr.bottom) { clipped++; break; }
      }
      p = p.parentElement;
    }
  }

  // Does the frame swallow the pointer? The avatar (or its link) must still be
  // what is under the cursor at its centre.
  const hits = [];
  for (const w of wrap.slice(0, 8)) {
    const r = w.getBoundingClientRect();
    if (r.width === 0) continue;
    const el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    hits.push(el ? (el.tagName.toLowerCase() + '.' + String(el.className || '').split(' ')[0]) : 'null');
  }

  // Which accounts are actually ON this page, and which of them the decorator
  // knows about. Without this, "framed=0" is ambiguous between "nobody here
  // owns a frame" and "the decorator failed on this surface", and those are
  // very different bugs.
  const names = new Set();
  for (const a of avatars) {
    const l = a.closest('a[href*="/u/"]');
    const m = l && (l.getAttribute('href')||'').match(/\\/u\\/([^\\/?#]+)/);
    names.add(m ? decodeURIComponent(m[1]) : (a.getAttribute('title') || a.getAttribute('alt') || '?'));
  }

  const t = window.__lmxCos || {};
  const known = t.lookup ? [...names].filter(n => t.lookup(n) && t.lookup(n).frame) : null;
  return {
    names: [...names].slice(0, 60),
    knownFramed: known,
    avatars: avatars.length,
    framed: wrap.length,
    slugs: byslug,
    rankRingsLeft: rankRings.length,
    doubled, nested, clipped,
    hits,
    live: document.querySelectorAll('.LmxCosFrame.is-live').length,
    banners: document.querySelectorAll('.LmxCosBanner').length,
    trace: { avatarsSeen: t.avatarsSeen, framed: t.framed, adopted: t.adopted, unresolved: t.unresolved, mapFetches: t.mapFetches, defs: t.defs, panel: t.panel, lastError: t.lastError },
  };
})()`;

/**
 * Frame time with every ring on the page animating.
 *
 * requestAnimationFrame deltas over two seconds. This measures the whole
 * document's frame budget, not the animation in isolation, which is the number
 * that decides whether scrolling stutters.
 */
const FRAMETIME = `new Promise(res => {
  const deltas = [];
  let last = performance.now();
  const t0 = last;
  function tick(now) {
    deltas.push(now - last);
    last = now;
    if (now - t0 < 2000) requestAnimationFrame(tick);
    else {
      deltas.sort((a, b) => a - b);
      const p = q => deltas[Math.min(deltas.length - 1, Math.floor(deltas.length * q))];
      res({
        frames: deltas.length,
        animating: document.querySelectorAll('.LmxCosFrame.is-live').length,
        avatars: document.querySelectorAll('.Avatar').length,
        p50: +p(0.5).toFixed(2), p95: +p(0.95).toFixed(2), max: +deltas[deltas.length - 1].toFixed(2),
      });
    }
  }
  requestAnimationFrame(tick);
})`;

const results: any = { surfaces: [], frametime: [], equip: null, negatives: [] };

mkdirSync(OUT, { recursive: true });

/*
 * Refuse to attach to somebody else's browser.
 *
 * Browser.launch() polls the CDP port and, if anything already answers there,
 * uses it. A crashed earlier run leaves its Chromium alive — and its SESSION
 * COOKIE with it, which is exactly how the previous pass produced a "logged
 * out" set of screenshots with the admin header on every one of them. The whole
 * guest half of the evidence was wrong and nothing about the numbers said so.
 */
try {
  const stale = await fetch(`http://127.0.0.1:${PORT}/json/version`);
  if (stale.ok) {
    throw new Error(
      `a browser is already listening on CDP ${PORT}; kill it before running ` +
        `(pkill -f 'remote-debugging-port=${PORT}') — attaching to it would reuse its cookies`,
    );
  }
} catch (e: any) {
  if (String(e.message).includes("already listening")) throw e;
}

const b = await Browser.launch(PORT, "1440,1100");

/*
 * Spanish, because Spanish is this forum's default locale and therefore what a
 * real visitor gets. Headless Chromium advertises en-US and Flarum honours it,
 * so without this every screenshot is of a language most of this audience does
 * not read.
 */
await b.send("Network.setExtraHTTPHeaders", { headers: { "Accept-Language": "es-MX,es;q=0.9" } });
await b.send("Network.clearBrowserCookies");

async function capture(state: "guest" | "auth") {
  for (const v of VIEWPORTS) {
    await b.viewport(v as any);
    for (const s of SURFACES) {
      if (state === "guest" && (s.id === "settings" || s.id === "notifications")) continue;
      b.resetNetwork();
      const ok = await b.goto(BASE + s.path, 1800);
      // Let the decorator's interval passes and the IntersectionObserver settle.
      await Bun.sleep(1400);
      const audit = await b.eval(AUDIT).catch((e) => ({ error: String(e) }));
      const file = `${OUT}/${state}-${v.name}-${s.id}.png`;
      await b.shot(file);
      const row = {
        state,
        viewport: v.name,
        surface: s.id,
        path: s.path,
        note: s.note,
        booted: ok,
        file,
        audit,
        consoleErrors: b.consoleErrors.filter((e) => !/pusher/i.test(e)).slice(0, 6),
        pusherNoise: b.consoleErrors.filter((e) => /pusher/i.test(e)).length,
        bad: b.badResponses.filter((r) => !/pusher/i.test(r.url)).slice(0, 6),
      };
      results.surfaces.push(row);
      console.log(
        `  ${state}/${v.name}/${s.id.padEnd(16)} avatars=${audit.avatars ?? "?"} framed=${audit.framed ?? "?"}` +
          ` clipped=${audit.clipped ?? "?"} nested=${audit.nested ?? "?"} banners=${audit.banners ?? "?"}` +
          ` err=${row.consoleErrors.length}`,
      );
    }
  }
}

console.log("\n== logged out ==");
await b.send("Network.clearBrowserCookies");
const isGuest = await (async () => {
  await b.goto(BASE + "/", 1200);
  return await b.eval(
    `(() => { const n = document.getElementById('flarum-json-payload'); return n ? JSON.parse(n.textContent).session.userId : -1; })()`,
  );
})();
console.log("  session userId before the guest pass:", isGuest, isGuest === 0 ? "(guest, correct)" : "(NOT GUEST — the pass below is invalid)");
results.guestCheck = isGuest;
await capture("guest");

console.log("\n== logging in ==");
/*
 * The shared driver's login() calls app.session.login(), which came back
 * `status: 0` here — an aborted XHR, because Flarum reloads the page the moment
 * the session cookie lands and the in-flight request dies with it. POST /login
 * from the page with the document's own CSRF token is the same server path and
 * survives, which is what e2e/grant.ts already does over plain HTTP.
 */
async function signIn() {
  await b.goto(BASE + "/", 1400);
  const pre = await b.eval(
    `(() => { const n = document.getElementById('flarum-json-payload');
              if (!n) return { ok:false, why:'no payload node' };
              const p = JSON.parse(n.textContent);
              return { ok:true, uid: (p.session && p.session.userId) || 0, csrf: (p.session && p.session.csrfToken) || null }; })()`,
  );
  if (!pre.ok) return "no payload: " + pre.why;
  if (pre.uid) return "already:" + pre.uid;

  const r = await b.eval(
    `(async () => {
       const res = await fetch('/login', { method:'POST', credentials:'same-origin',
         headers: {'Content-Type':'application/json','X-CSRF-Token': ${JSON.stringify(pre.csrf)}},
         body: JSON.stringify({ identification: ${JSON.stringify(ADMIN.user)}, password: ${JSON.stringify(ADMIN.pass)}, remember: true }) });
       return res.status + ' ' + (await res.text()).slice(0, 100);
     })()`,
    true,
  );
  await b.goto(BASE + "/", 1800);
  const who = await b.eval(
    `(() => { const n = document.getElementById('flarum-json-payload');
              const p = n ? JSON.parse(n.textContent) : null;
              return p && p.session ? p.session.userId : null; })()`,
  );
  return `POST /login -> ${r} | session userId=${who}`;
}

const who = await signIn();
console.log("  session:", who);
results.login = who;

console.log("\n== logged in ==");
await capture("auth");

// ---------------------------------------------------------------- frame time
console.log("\n== frame time, everything animating ==");
await b.viewport({ name: "desktop", width: 1440, height: 1100 } as any);
/*
 * A/B on the SAME page in the SAME session, three times each, interleaved.
 *
 * The first version of this measured "with rings" on one page and "without" on
 * another and reported a 17ms difference that was mostly the page. Headless
 * Chromium composites in software (--disable-gpu), so absolute numbers here are
 * a pessimistic floor, not what a user sees; only the DELTA between the two
 * arms on one page is worth anything, and it is only worth anything if the two
 * arms are the same page.
 */
async function arm(on: boolean) {
  await b.eval(
    on
      ? `document.querySelectorAll('.LmxCosFrame[data-cf-x]').forEach(w => { w.setAttribute('data-cf', w.getAttribute('data-cf-x')); w.removeAttribute('data-cf-x'); w.classList.add('is-live'); })`
      : `document.querySelectorAll('.LmxCosFrame[data-cf]').forEach(w => { w.setAttribute('data-cf-x', w.getAttribute('data-cf')); w.removeAttribute('data-cf'); w.classList.remove('is-live'); })`,
  );
  await Bun.sleep(250);
  return await b.eval(FRAMETIME, true);
}

for (const path of ["/d/58157", "/all", "/"]) {
  await b.goto(BASE + path, 2000);
  await Bun.sleep(1600);
  const rings = await b.eval(
    `(() => { const w=[...document.querySelectorAll('.LmxCosFrame[data-cf]')]; w.forEach(x=>x.classList.add('is-live'));
              return { total: w.length, animated: w.filter(x=>['conic','dashed','dual'].includes(x.getAttribute('data-cf-render')) || x.classList.contains('is-pulse')).length }; })()`,
  );
  const on: any[] = [], off: any[] = [];
  for (let i = 0; i < 3; i++) {
    on.push(await arm(true));
    off.push(await arm(false));
  }
  await arm(true);
  const med = (xs: any[], k: string) => +(xs.map((x) => x[k]).sort((a, b) => a - b)[1]).toFixed(2);
  const row = {
    path,
    rings: rings.total,
    animated: rings.animated,
    on: { p50: med(on, "p50"), p95: med(on, "p95"), max: med(on, "max") },
    off: { p50: med(off, "p50"), p95: med(off, "p95"), max: med(off, "max") },
  };
  results.frametime.push(row);
  console.log(
    `  ${path.padEnd(12)} rings=${row.rings} (animated ${row.animated})  ` +
      `WITH p50=${row.on.p50} p95=${row.on.p95} max=${row.on.max}  |  WITHOUT p50=${row.off.p50} p95=${row.off.p95} max=${row.off.max}`,
  );
}

/*
 * How does it SCALE? A fixed one-frame cost and a per-ring cost are different
 * problems, and eleven rings cannot tell them apart. This clones the existing
 * rings up to 60 on one page and measures the same way.
 */
await b.goto(BASE + "/d/58157", 2000);
await Bun.sleep(1500);
for (const n of [0, 10, 30, 60]) {
  const made = await b.eval(
    `(() => {
       document.querySelectorAll('.LmxCosClone').forEach(e => e.remove());
       const src = document.querySelector('.LmxCosFrame[data-cf]');
       if (!src) return 0;
       const host = document.createElement('div');
       host.className = 'LmxCosClone';
       host.style.cssText = 'position:fixed;top:0;left:0;z-index:9999;display:flex;flex-wrap:wrap;width:100%;pointer-events:none;opacity:0.999';
       for (let i = 0; i < ${n}; i++) {
         const c = src.cloneNode(true);
         c.classList.add('is-live');
         host.appendChild(c);
       }
       if (${n}) document.body.appendChild(host);
       return host.children.length;
     })()`,
  );
  await Bun.sleep(300);
  const ft = await b.eval(FRAMETIME, true);
  results.frametime.push({ path: "/d/58157 scaling", clones: made, ...ft });
  console.log(`  clones=${String(made).padStart(2)}  p50=${ft.p50}ms p95=${ft.p95}ms max=${ft.max}ms  (live=${ft.animating})`);
}
await b.eval(`document.querySelectorAll('.LmxCosClone').forEach(e => e.remove())`);

// ------------------------------------------------------------------ equip UI
console.log("\n== equip: select -> preview -> apply -> reload ==");
await b.goto(BASE + "/settings", 2200);
await Bun.sleep(1800);

const before = await b.eval(
  `(() => { const p=document.querySelector('.LmxCosPanel'); return { panel: !!p, tiles: document.querySelectorAll('.LmxCosTile').length, owned: document.querySelectorAll('.LmxCosTile:not(.is-locked)').length, locked: document.querySelectorAll('.LmxCosTile.is-locked').length, equipped: [...document.querySelectorAll('.LmxCosTile.is-equipped .LmxCosTile-name')].map(n=>n.textContent) }; })()`,
);
console.log("  panel:", JSON.stringify(before));
await b.shot(`${OUT}/equip-1-open.png`);

// Pick a frame that is owned and NOT the one already worn.
const picked = await b.eval(
  `(() => {
     const tiles=[...document.querySelectorAll('.LmxCosTile:not(.is-locked):not(.is-equipped):not(.LmxCosTile-none)')];
     const t = tiles.find(x => x.closest('.LmxCosSection').querySelector('.LmxCosSection-title span').textContent.match(/Marcos|frames/i));
     if (!t) return null;
     const name = t.querySelector('.LmxCosTile-name').textContent;
     t.click();
     return name;
   })()`,
);
console.log("  picked:", picked);
await Bun.sleep(900);
await b.shot(`${OUT}/equip-2-preview.png`);

const previewState = await b.eval(
  `(() => { const m = document.querySelector('.LmxCosMirror .LmxCosFrame[data-cf]');
            const btn = document.querySelector('.LmxCosMirror-actions .Button--primary');
            return { note: (document.querySelector('.LmxCosMirror-note')||{}).textContent || null,
                     mirrorFrame: m ? m.getAttribute('data-cf') : null,
                     pageFrames: [...document.querySelectorAll('.LmxCosFrame[data-cf]')].map(w=>w.getAttribute('data-cf')),
                     applyEnabled: btn ? !btn.disabled : null }; })()`,
);
console.log("  preview:", JSON.stringify(previewState));

await b.eval(`(() => { const b = document.querySelector('.LmxCosMirror-actions .Button--primary'); if (b) b.click(); return !!b; })()`);
await Bun.sleep(2200);
await b.shot(`${OUT}/equip-3-applied.png`);

const applied = await b.eval(
  `(() => ({ note: (document.querySelector('.LmxCosMirror-note')||{}).textContent,
             equipped: [...document.querySelectorAll('.LmxCosTile.is-equipped .LmxCosTile-name')].map(n=>n.textContent),
             mirrorFrame: document.querySelector('.LmxCosMirror .LmxCosFrame[data-cf]') ? document.querySelector('.LmxCosMirror .LmxCosFrame[data-cf]').getAttribute('data-cf') : null }))()`,
);
console.log("  applied:", JSON.stringify(applied));

await b.goto(BASE + "/settings", 2200);
await Bun.sleep(1800);
const afterReload = await b.eval(
  `(() => ({ equipped: [...document.querySelectorAll('.LmxCosTile.is-equipped .LmxCosTile-name')].map(n=>n.textContent),
             mirrorFrame: document.querySelector('.LmxCosMirror .LmxCosFrame[data-cf]') ? document.querySelector('.LmxCosMirror .LmxCosFrame[data-cf]').getAttribute('data-cf') : null }))()`,
);
console.log("  after reload:", JSON.stringify(afterReload));
await b.shot(`${OUT}/equip-4-reloaded.png`);

// A banner too, so the profile cover has something to render.
await b.goto(BASE + "/settings", 2200);
await Bun.sleep(1600);
const pickedBanner = await b.eval(
  `(() => {
     const sec = [...document.querySelectorAll('.LmxCosSection')].find(s => s.querySelector('.LmxCosSection-title span').textContent.match(/Portadas|covers/i));
     if (!sec) return null;
     const t = [...sec.querySelectorAll('.LmxCosTile:not(.is-locked):not(.LmxCosTile-none)')][0];
     if (!t) return 'none-owned';
     const n = t.querySelector('.LmxCosTile-name').textContent;
     t.click();
     return n;
   })()`,
);
await Bun.sleep(700);
await b.eval(`(() => { const b = document.querySelector('.LmxCosMirror-actions .Button--primary'); if (b) b.click(); })()`);
await Bun.sleep(2200);
await b.shot(`${OUT}/equip-5-banner.png`);
console.log("  banner picked:", pickedBanner);

await b.goto(BASE + "/u/admin", 2200);
await Bun.sleep(1500);
const cover = await b.eval(
  `(() => { const p = document.querySelector('.LmxCosBanner');
            return { present: !!p, slug: p ? p.getAttribute('data-cb') : null, pattern: p ? p.getAttribute('data-cb-pattern') : null,
                     h: p ? Math.round(p.getBoundingClientRect().height) : 0 }; })()`,
);
console.log("  profile cover:", JSON.stringify(cover));
await b.shot(`${OUT}/equip-6-profile-cover.png`);
await b.viewport({ name: "phone", width: 390, height: 844, mobile: true } as any);
await b.goto(BASE + "/u/admin", 2200);
await Bun.sleep(1200);
await b.shot(`${OUT}/equip-7-profile-cover-phone.png`);
await b.viewport({ name: "desktop", width: 1440, height: 1100 } as any);

results.equip = { before, picked, previewState, applied, afterReload, pickedBanner, cover };

// ---------------------------------------------------------------- negatives
console.log("\n== negatives ==");
// 1. an account with no entitlement
await b.goto(BASE + "/u/Chad", 1800);
await Bun.sleep(1200);
const negProfile = await b.eval(
  `(() => {
     // Scoped to the profile hero on purpose: the page-wide count includes the
     // HEADER avatar, which belongs to the signed-in admin and legitimately has
     // a frame. Counting that as a failure of the gate would be a false alarm,
     // and counting it as a pass would be worse.
     const hero = document.querySelector('.UserCard') || document.body;
     return { pageAvatars: document.querySelectorAll('.Avatar').length,
              pageFramed: document.querySelectorAll('.LmxCosFrame[data-cf]').length,
              heroAvatars: hero.querySelectorAll('.Avatar').length,
              heroFramed: hero.querySelectorAll('.LmxCosFrame[data-cf]').length,
              heroFrames: [...hero.querySelectorAll('.LmxCosFrame[data-cf]')].map(w=>w.getAttribute('data-cf')),
              banners: document.querySelectorAll('.LmxCosBanner').length };
   })()`,
);
console.log("  /u/Chad (no entitlement):", JSON.stringify(negProfile));
results.negatives.push({ what: "profile of an account with no entitlement", ...negProfile });

// 2. equipping something not owned, straight at the API
const forged = await b.eval(
  `(async () => {
     const p = JSON.parse(document.getElementById('flarum-json-payload').textContent);
     const r = await fetch('/api/cosmetics/equip', { method:'POST', credentials:'same-origin',
       headers: {'Content-Type':'application/json','X-CSRF-Token': p.session.csrfToken},
       body: JSON.stringify({ kind:'frame', item:'staff' }) });
     return { status: r.status, body: (await r.text()).slice(0,200) };
   })()`,
  true,
);
console.log("  POST equip frame=staff (admin has no staff badge):", JSON.stringify(forged));
results.negatives.push({ what: "forged equip of an unowned frame", ...forged });

const bogus = await b.eval(
  `(async () => {
     const p = JSON.parse(document.getElementById('flarum-json-payload').textContent);
     const r = await fetch('/api/cosmetics/equip', { method:'POST', credentials:'same-origin',
       headers: {'Content-Type':'application/json','X-CSRF-Token': p.session.csrfToken},
       body: JSON.stringify({ kind:'frame', item:'does-not-exist' }) });
     return { status: r.status, body: (await r.text()).slice(0,200) };
   })()`,
  true,
);
console.log("  POST equip frame=does-not-exist:", JSON.stringify(bogus));
results.negatives.push({ what: "equip of a slug that has no definition", ...bogus });

writeFileSync(`${OUT}/results.json`, JSON.stringify(results, null, 2));
console.log("\nwrote", `${OUT}/results.json`);

await b.close();
