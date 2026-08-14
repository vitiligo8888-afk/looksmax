#!/usr/bin/env bun
/**
 * Icon geometry audit — the gate for the looksmax-icons lane.
 *
 *   cd /work/flarum && bun extensions/looksmax-icons/e2e/audit.ts
 *   ... --shots /work/flarum/icon-audit          # also write screenshots
 *   ... --surface header-search --viewport mobile
 *   ... --self-test                              # prove the audit can fail
 *   ... --json /tmp/audit.json
 *
 * Exit code is the deliverable: 0 only when every icon-shaped element on every
 * surface, at both widths, logged out AND logged in, measures correctly.
 *
 * ── Why this exists ────────────────────────────────────────────────────────
 *
 * The operator's report was "the search icon is STILL weird and stretched" and
 * "the loading spinner is a weird stretched rotating big icon … and prolly
 * other shit too", followed by "the bugs aren't caught". Both symptoms are the
 * same measurable fact — a box whose aspect ratio does not match its artwork —
 * and neither was caught because nothing measured it. `e2e/icon-probe.ts`
 * checked two paths, logged out, at one width, and only looked at
 * <iconify-icon>: it could not see a CSS mask, a spinner, or anything behind a
 * login.
 *
 * So: every surface, both widths, both sessions, every icon-shaped element
 * including masks, background images, light-DOM svgs and spinners, compared
 * against the SOURCE artwork's ratio rather than against an eyeball.
 *
 * ── Reading the output ─────────────────────────────────────────────────────
 *
 * Each line is `surface/viewport/auth  kind  id  WxH  fails`. The summary is
 * "N icons checked on M surface-runs, K failing". A pass with no denominator is
 * not evidence, so the denominator is always printed.
 */
import { Browser, type Viewport } from "../../../e2e/visual/cdp.ts";
import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";

const argv = process.argv.slice(2);
const flag = (n: string) => argv.includes(n);
const opt = (n: string, d?: string) => {
  const i = argv.indexOf(n);
  return i >= 0 && argv[i + 1] ? argv[i + 1] : d;
};

const HERE = dirname(new URL(import.meta.url).pathname);
/*
 * ── Why the default base is the PUBLIC origin and not 127.0.0.1:8888 ───────
 *
 * config.php sets `url => https://looksmax.lat`, and Flarum builds every asset
 * and api url from it absolutely. Driving the container directly on
 * http://127.0.0.1:8888 therefore renders a page whose stylesheet, fonts, and
 * api all point at a DIFFERENT ORIGIN. Measured on this box:
 *
 *   Font net::ERR_FAILED https://looksmax.lat/assets/fonts/fa-solid-900.woff2
 *   Font net::ERR_FAILED https://looksmax.lat/assets/fonts/fa-regular-400.woff2
 *   Font net::ERR_FAILED .../local-looksmax-brand/font/archivo-800.woff2
 *   app.session.login -> status 0, empty responseText   (blocked cross-origin)
 *
 * Every one of those is an artefact of the harness, not a site defect — and
 * they poisoned the result twice over: no logged-in surface could be reached at
 * all, and every screenshot was taken with FontAwesome and the brand typeface
 * missing. Chromium's --host-resolver-rules cannot fix it either, because the
 * scheme is https and the container speaks plaintext on 8888.
 *
 * looksmax.lat resolves to Cloudflare in front of this same container and
 * serves the same build (verified: the document carries data-local-icons-*, and
 * the document itself is cf-cache-status DYNAMIC, so an icon change is live
 * immediately; forum.css is versioned with ?v=<hash> so a LESS change busts the
 * edge cache on its own). Auditing it is auditing what a reader gets.
 */
const BASE = opt("--base") || process.env.FORUM_URL || "https://looksmax.lat";
/* 21830 is this lane's port. Other lanes drive chromium at 21800/21993; sharing
 * a port means one run kills the other's browser mid-navigation and the failure
 * looks like a site bug. */
const PORT = Number(process.env.CDP_PORT || 21830);
const ADMIN_USER = process.env.FORUM_ADMIN || "admin";
const ADMIN_PASS = process.env.FORUM_ADMIN_PASS || "1IxmV2IMZ8cnulvIHBT0";

const PROBE = readFileSync(join(HERE, "probe.js"), "utf8");

/* Both widths the operator named. 1440 is the desktop breakpoint the theme is
 * designed at; 390 is an iPhone 14/15 and is below Flarum's 767px phone
 * breakpoint, so it exercises the drawer, the mobile header and the composer
 * sheet — three places icons live that a desktop run never renders. */
const VIEWPORTS: Viewport[] = [
  { name: "desktop", width: 1440, height: 1000 },
  { name: "mobile", width: 390, height: 844, mobile: true },
];

type Surface = {
  name: string;
  path: string;
  auth?: "in" | "out" | "both";
  only?: string[];
  /** in-page expression run after load, before measuring */
  after?: string;
  /** ms to wait after `after` before measuring */
  settle?: number;
  /**
   * Hold the page mid-fetch by throttling the network to a crawl.
   *
   * Applied AFTER the document and its scripts have loaded, never before. The
   * first version throttled the navigation itself and the SPA never booted
   * inside the timeout: both loading surfaces reported "0 icons, custom element
   * NOT defined" and passed, which is a gate measuring nothing. Throttling
   * after boot slows only the XHR the action triggers, which is the thing whose
   * spinner is being measured.
   */
  throttle?: boolean;
};

/* Real slugs, checked against /api/discussions on this forum. A surface that
 * 404s still renders chrome and still passes, which is how a "green" audit
 * covers nothing. */
const D_GUIDE = process.env.D_GUIDE || "/d/1177-complete-guide-to-psl-ratings";
const D_LONG = process.env.D_LONG || "/d/288-ban-discussion-megathread";
const D_MEDIA = process.env.D_MEDIA || "/d/6389-vip-supporters-avatar-frames-name-changes-and-more";

const SURFACES: Surface[] = [
  { name: "index", path: "/" },
  { name: "all", path: "/all" },
  { name: "tags", path: "/tags" },
  { name: "tag", path: "/t/p-serious" },
  { name: "discussion", path: D_GUIDE },
  { name: "discussion-long", path: D_LONG },
  { name: "discussion-media", path: D_MEDIA },
  { name: "profile", path: "/u/admin" },
  { name: "search", path: "/?q=looksmax" },
  { name: "search-empty", path: "/?q=zzqqxxnothingmatches" },

  /* The header search field: focused, because the glyph changes colour on
   * focus-within and the field grows, which is when a mis-sized mask shows. */
  {
    name: "header-search",
    path: "/",
    settle: 600,
    after: `(() => {
      const i = document.querySelector('.Search-input input, .Search input, #header-search');
      if (!i) return 'no-search-input';
      i.focus(); i.click();
      return 'focused';
    })()`,
  },
  {
    name: "header-search-typed",
    path: "/",
    settle: 1600,
    after: `(() => {
      const i = document.querySelector('.Search-input input, .Search input, #header-search');
      if (!i) return 'no-search-input';
      i.focus();
      const set = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      set.call(i, 'looks');
      i.dispatchEvent(new Event('input', { bubbles: true }));
      return 'typed';
    })()`,
  },

  // ---------------------------------------------------------- logged out
  {
    name: "modal-login",
    path: "/",
    auth: "out",
    settle: 900,
    after: `(() => {
      const b = [...document.querySelectorAll('.Header-secondary button, .Header-secondary a, .App-drawer button, .Button')]
        .find(e => /log ?in|sign ?in|iniciar/i.test(e.textContent || ''));
      if (b) { b.click(); return 'clicked'; }
      const app = window.flarum?.core?.app;
      if (app?.modal && window.flarum.core.compat['components/LogInModal']) {
        app.modal.show(window.flarum.core.compat['components/LogInModal']); return 'api';
      }
      return 'not-found';
    })()`,
  },
  {
    name: "modal-signup",
    path: "/",
    auth: "out",
    settle: 900,
    after: `(() => {
      const b = [...document.querySelectorAll('.Header-secondary button, .Header-secondary a, .App-drawer button, .Button')]
        .find(e => /sign ?up|register|registr/i.test(e.textContent || ''));
      if (b) { b.click(); return 'clicked'; }
      return 'not-found';
    })()`,
  },

  // ---------------------------------------------------------- logged in
  { name: "in-index", path: "/", auth: "in" },
  { name: "in-settings", path: "/settings", auth: "in" },
  { name: "in-notifications", path: "/notifications", auth: "in" },
  { name: "in-discussion", path: D_GUIDE, auth: "in" },
  {
    name: "in-notif-tray",
    path: "/",
    auth: "in",
    settle: 1400,
    after: `(() => {
      const t = document.querySelector('.NotificationsDropdown .Dropdown-toggle, .Notifications .Dropdown-toggle, [class*=Notifications] button');
      if (t) { t.click(); return 'clicked'; }
      return 'not-found';
    })()`,
  },
  {
    /* The complaint verbatim: "for notifications the loading spinner thingy is
     * a weird stretched like rotating big icon". The tray only shows its
     * spinner while the fetch is in flight, which on localhost is ~20ms — far
     * too fast to catch. Throttling to 20kbps holds it on screen. */
    name: "in-notif-tray-loading",
    path: "/",
    auth: "in",
    throttle: true,
    settle: 700,
    after: `(() => {
      const t = document.querySelector('.NotificationsDropdown .Dropdown-toggle, .Notifications .Dropdown-toggle, [class*=Notifications] button');
      if (t) { t.click(); return 'clicked'; }
      return 'not-found';
    })()`,
  },
  {
    name: "in-session-dropdown",
    path: "/",
    auth: "in",
    settle: 700,
    after: `(() => {
      const t = document.querySelector('.SessionDropdown .Dropdown-toggle, .Header-secondary .SessionDropdown button');
      if (t) { t.click(); return 'clicked'; }
      return 'not-found';
    })()`,
  },
  {
    name: "in-composer",
    path: D_GUIDE,
    auth: "in",
    settle: 1600,
    after: `(() => {
      const b = [...document.querySelectorAll('.Post-controls button, .item-reply button, .DiscussionPage button, .Button')]
        .find(e => /reply|responder/i.test(e.textContent || ''));
      if (b) { b.click(); return 'clicked-reply'; }
      return 'not-found';
    })()`,
  },
  {
    name: "in-post-controls",
    path: D_GUIDE,
    auth: "in",
    settle: 800,
    after: `(() => {
      const t = document.querySelector('.Post-controls .Dropdown-toggle, .Post .Dropdown-toggle');
      if (t) { t.click(); return 'clicked'; }
      return 'not-found';
    })()`,
  },
  {
    /* "flagged posts and prolly other shit too" — the flag UI is admin-only and
     * has its own list, its own spinner and its own icons. */
    name: "in-flags",
    path: "/flags",
    auth: "in",
  },
  {
    /* The discussion list's own spinner. It only exists during an in-app route
     * change — a full navigation to /all is server-rendered and never shows
     * one — so this loads the index, throttles, then follows the link the way a
     * reader would. */
    name: "in-discussion-list-loading",
    path: "/",
    auth: "in",
    throttle: true,
    settle: 1500,
    after: `(() => {
      const a = [...document.querySelectorAll('a[href$="/all"], a[href*="/all"]')]
        .find(x => /\\/all$/.test(new URL(x.href, location.href).pathname));
      if (a) { a.click(); return 'clicked-all'; }
      const app = window.flarum?.core?.app;
      if (app && window.m) { window.m.route.set('/all'); return 'route-set'; }
      return 'not-found';
    })()`,
  },
  {
    /* Scrolling the list to the bottom triggers the "load more" fetch, which is
     * a different spinner in a different container from the route-change one. */
    name: "in-list-loadmore-loading",
    path: "/all",
    auth: "in",
    throttle: true,
    settle: 1500,
    after: `(() => {
      const b = [...document.querySelectorAll('.DiscussionList button, .LoadMore, .Button')]
        .find(e => /more|más|mas/i.test(e.textContent || ''));
      if (b) { b.click(); return 'clicked-more'; }
      window.scrollTo(0, document.body.scrollHeight);
      return 'scrolled';
    })()`,
  },
  {
    /* The mobile drawer is a separate icon surface that no desktop run renders. */
    name: "drawer",
    path: "/",
    only: ["mobile"],
    settle: 700,
    after: `(() => {
      const b = document.querySelector('.Header-controls .Button--link, #drawer-toggle, .App-backControl button, .Button--icon');
      const t = [...document.querySelectorAll('header button, .App-header button')].find(e => e.className.includes('drawer') || e.getAttribute('aria-label'));
      const el = document.querySelector('.Drawer-toggle, [data-drawer-toggle]') || t || b;
      if (el) { el.click(); return 'clicked'; }
      return 'not-found';
    })()`,
  },
];

type Rec = {
  kind: string; id: string; path: string; w: number; h: number; ratio: number;
  fontSize: number; intrinsic: number | null; resolved: boolean | null;
  fails: string[]; pass: boolean; hidden: boolean; [k: string]: any;
};

/**
 * Log in with a plain fetch to /login rather than through app.session.login.
 *
 * e2e/visual/cdp.ts's Browser.login() calls app.session.login(), and on this
 * forum that resolves with `status: 0` and an empty responseText — a mithril
 * request that never reached the network — even on the public origin where the
 * request is same-origin. Measured side by side in the same page: the identical
 * credentials POSTed with window.fetch return
 *
 *     200 :: {"token":"…","userId":1}
 *
 * so it is the session helper, not the endpoint or the credentials. That is a
 * defect in cdp.ts and it is reported rather than patched, because cdp.ts is
 * another lane's file. Here the working call is used directly; the cookie it
 * sets is what every logged-in surface below depends on, and a silent login
 * failure would make all of them pass vacuously.
 */
async function login(b: Browser) {
  await b.goto(BASE + "/", 500);
  const already = await b.eval(
    `(() => { const u = window.flarum?.core?.app?.session?.user; return u ? u.username() : null; })()`,
  );
  if (already) return `already:${already}`;

  const res = await b.eval(
    `(async () => {
       const app = window.flarum && window.flarum.core && window.flarum.core.app;
       if (!app) return 'no-app';
       try {
         const r = await fetch(${JSON.stringify(BASE)} + '/login', {
           method: 'POST',
           headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.session.csrfToken },
           body: JSON.stringify({ identification: ${JSON.stringify(ADMIN_USER)}, password: ${JSON.stringify(ADMIN_PASS)} }),
           credentials: 'same-origin',
         });
         const t = await r.text();
         return r.status + ' ' + t.slice(0, 200);
       } catch (e) { return 'threw ' + (e && e.message); }
     })()`,
    true,
  );
  if (!String(res).startsWith("200")) return `fail ${res}`;

  await b.goto(BASE + "/", 900);
  const who = await b.eval(
    `(() => { const u = window.flarum?.core?.app?.session?.user; return u ? u.username() : null; })()`,
  );
  return who ? `ok:${who}` : `no-session-after-reload (login POST said ${String(res).slice(0, 60)})`;
}

const shotsDir = opt("--shots");
const jsonOut = opt("--json");
const onlySurface = opt("--surface");
const onlyViewport = opt("--viewport");
const verbose = flag("--verbose");
const quiet = flag("--quiet");

async function main() {
  const b = await Browser.launch(PORT, "1440,1000");
  const all: { surface: string; viewport: string; auth: string; rec: Rec }[] = [];
  const notes: string[] = [];
  /** fa-* names the swap script met and has no icon for, across every run */
  const unmapped = new Map<string, number>();
  let runs = 0;

  const run = async (auth: "out" | "in") => {
    if (auth === "in") {
      const who = await login(b);
      if (!String(who).startsWith("ok") && who !== "already") {
        notes.push(`LOGIN FAILED: ${who} — every logged-in surface below is meaningless`);
        console.error(`  login: ${who}`);
        return false;
      }
      notes.push(`login: ${who}`);
    }

    for (const s of SURFACES) {
      const want = s.auth || "both";
      if (want !== "both" && want !== auth) continue;
      for (const v of VIEWPORTS) {
        if (s.only && !s.only.includes(v.name)) continue;
        if (onlySurface && s.name !== onlySurface) continue;
        if (onlyViewport && v.name !== onlyViewport) continue;

        await b.viewport(v);
        b.resetNetwork();
        await b.goto(BASE + s.path, 300);
        if (s.throttle) {
          /* Boot first, then crawl. 200ms of latency at 30kbps holds a
           * notification or discussion-list XHR on screen for seconds without
           * stalling anything that has already loaded. */
          await b.send("Network.emulateNetworkConditions", {
            offline: false, latency: 900, downloadThroughput: (30 * 1024) / 8,
            uploadThroughput: (30 * 1024) / 8,
          });
        }
        let afterResult = "";
        if (s.after) {
          afterResult = String(await b.eval(s.after).catch((e) => "after-threw:" + e.message));
          await Bun.sleep(s.settle ?? 800);
        } else if (s.settle) {
          await Bun.sleep(s.settle);
        }

        let r: any;
        try {
          r = await b.eval(PROBE);
        } catch (e: any) {
          notes.push(`${s.name}/${v.name}/${auth}: PROBE THREW ${e.message}`);
          if (s.throttle) await b.send("Network.emulateNetworkConditions", { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
          continue;
        }
        if (s.throttle) {
          await b.send("Network.emulateNetworkConditions", { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
        }
        runs++;

        const recs: Rec[] = r.records || [];
        for (const rec of recs) all.push({ surface: s.name, viewport: v.name, auth, rec });
        for (const [name, n] of Object.entries(r.unmapped || {})) {
          unmapped.set(name, (unmapped.get(name) || 0) + Number(n));
        }
        if (!r.defined) notes.push(`${s.name}/${v.name}/${auth}: custom element NOT defined`);

        const visible = recs.filter((x) => !x.hidden);
        const failing = visible.filter((x) => !x.pass);
        const tag = `${s.name}/${v.name}/${auth}`;
        if (!quiet) {
          console.log(
            `${tag.padEnd(42)} ${String(visible.length).padStart(4)} icons  ` +
            `${failing.length ? "FAIL " + String(failing.length).padStart(3) : "ok      "}` +
            `${s.after ? "  [" + afterResult + "]" : ""}` +
            `${r.defined ? "" : "  [!custom element NOT defined]"}`,
          );
        }
        if (failing.length && !quiet) {
          const groups = new Map<string, Rec[]>();
          for (const f of failing) {
            const key = `${f.kind}|${f.id}|${f.fails.map((x) => x.split(":")[0]).join(",")}`;
            groups.set(key, [...(groups.get(key) || []), f]);
          }
          for (const [, g] of groups) {
            const f = g[0];
            console.log(
              `      ${f.kind.padEnd(8)} ${String(f.id).slice(0, 40).padEnd(42)} ` +
              `${f.w}x${f.h} fs=${f.fontSize} ar=${f.ratio}${f.intrinsic != null ? "/" + Math.round(f.intrinsic * 100) / 100 : ""} ` +
              `${f.fails.join(" ")}${g.length > 1 ? `  (x${g.length})` : ""}`,
            );
            if (verbose) console.log(`               in ${f.path} [${f.parentDisplay}]`);
          }
        }
        if (b.failedRequests.length && !quiet) {
          for (const fr of [...new Set(b.failedRequests)].slice(0, 4)) console.log(`      net  ${fr}`);
        }

        if (shotsDir) {
          mkdirSync(shotsDir, { recursive: true });
          await b.shot(`${shotsDir}/${s.name}-${v.name}-${auth}.png`);
        }
      }
    }
    return true;
  };

  await run("out");
  await run("in");

  await b.close();

  const visible = all.filter((x) => !x.rec.hidden);
  const failing = visible.filter((x) => !x.rec.pass);

  console.log(`\n${"=".repeat(78)}`);
  console.log(`surface-runs      : ${runs}`);
  console.log(`icon-shaped boxes : ${all.length} (${visible.length} visible, ${all.length - visible.length} hidden)`);
  console.log(`by kind           : ${[...new Set(visible.map((x) => x.rec.kind))]
    .map((k) => `${k}=${visible.filter((x) => x.rec.kind === k).length}`).join(" ")}`);
  console.log(`FAILING           : ${failing.length}`);

  const byReason = new Map<string, number>();
  for (const f of failing) for (const r of f.rec.fails) {
    const k = r.split(":")[0];
    byReason.set(k, (byReason.get(k) || 0) + 1);
  }
  if (byReason.size) {
    console.log(`by reason         : ${[...byReason].map(([k, n]) => `${k}=${n}`).join(" ")}`);
    const byId = new Map<string, number>();
    for (const f of failing) byId.set(`${f.rec.kind} ${f.rec.id}`, (byId.get(`${f.rec.kind} ${f.rec.id}`) || 0) + 1);
    console.log("worst offenders   :");
    for (const [k, n] of [...byId].sort((a, b2) => b2[1] - a[1]).slice(0, 15)) {
      console.log(`   ${String(n).padStart(4)}  ${k.slice(0, 70)}`);
    }
  }
  /* An unmapped fa-* name renders nothing at all: the <i> is empty, and
   * less/forum.less hides empty ones so the page does not show a blank square.
   * That is the right thing to render and the wrong thing to leave unreported —
   * the control it labels silently has no icon. These fail the gate. */
  if (unmapped.size) {
    console.log(`\nUNMAPPED fa-* names (no entry in the MAP in js/dist/forum.js):`);
    for (const [k, n] of [...unmapped].sort((a, b2) => b2[1] - a[1])) {
      console.log(`   ${String(n).padStart(5)}  ${k}`);
    }
  }
  for (const n of notes) console.log(`note: ${n}`);

  if (jsonOut) {
    writeFileSync(jsonOut, JSON.stringify({ base: BASE, runs, all, notes }, null, 1));
    console.log(`json              : ${jsonOut}`);
  }
  if (shotsDir) console.log(`shots             : ${shotsDir}`);

  const bad = failing.length + unmapped.size;
  console.log(
    bad === 0
      ? `\n  PASS  ${visible.length} visible icon boxes on ${runs} surface-runs, 0 failing, 0 unmapped names`
      : `\n  FAIL  ${failing.length} icon boxes, ${unmapped.size} unmapped fa-* names`,
  );
  process.exit(bad === 0 ? 0 : 1);
}

/**
 * Prove the audit can go red.
 *
 * A green gate that has never been seen red is not evidence, it is an untested
 * assertion. This injects four defects that are exactly the ones the operator
 * reported — an unsized element stretched by a flex parent, a squashed icon, an
 * oversized one, and a non-circular spinner — and asserts each is caught.
 */
async function selfTest() {
  const b = await Browser.launch(PORT, "1440,1000");
  await b.viewport(VIEWPORTS[0]);
  await b.goto(BASE + "/", 400);

  const setup = `(() => {
    const host = document.createElement('div');
    // The probe builds its path string from tag + class, so the marker must be
    // a class: an id would be invisible to the filter below.
    host.id = 'audit-self-test';
    host.className = 'audit-self-test';
    host.style.cssText = 'display:flex;width:600px;height:80px;align-items:stretch;font-size:14px';
    document.body.appendChild(host);

    // 1. an unresolved iconify-icon with our CSS floor removed: exactly the
    //    "big weird rotating icon" — no intrinsic size in a stretching flex row
    const a = document.createElement('iconify-icon');
    a.setAttribute('icon', 'ph:this-icon-does-not-exist-at-all');
    a.style.cssText = 'flex:1 1 auto;width:auto;height:auto;aspect-ratio:auto;align-self:stretch';
    host.appendChild(a);

    // 2. a resolved icon squashed to 3:1 by an explicit override
    const c = document.createElement('iconify-icon');
    c.setAttribute('icon', 'ph:bell-ringing-fill');
    c.style.cssText = 'width:60px;height:20px;aspect-ratio:auto;flex:none';
    host.appendChild(c);

    // 3. a resolved icon far larger than its font-size
    const d = document.createElement('iconify-icon');
    d.setAttribute('icon', 'ph:star-fill');
    d.style.cssText = 'width:64px;height:64px;font-size:12px;aspect-ratio:auto;flex:none';
    host.appendChild(d);

    // 4. a LoadingIndicator that is not a circle
    const s = document.createElement('div');
    s.className = 'LoadingIndicator-container';
    s.innerHTML = '<div class="LoadingIndicator" style="width:300px;height:24px"></div>';
    host.appendChild(s);
    return 'injected';
  })()`;

  await b.eval(setup);
  await Bun.sleep(900);
  const r: any = await b.eval(PROBE);
  await b.close();

  const inTest = (r.records as Rec[]).filter((x) => String(x.path).includes('audit-self-test'));
  const reasons = new Set<string>();
  for (const x of inTest) for (const f of x.fails) reasons.add(f.split(":")[0]);

  console.log(`self-test: ${inTest.length} injected boxes measured`);
  for (const x of inTest) {
    console.log(`   ${x.kind.padEnd(8)} ${String(x.id).slice(0, 38).padEnd(40)} ${x.w}x${x.h} fs=${x.fontSize} -> ${x.fails.join(" ") || "PASSED (bad!)"}`);
  }
  /* Named individually so a rule that silently stops firing is a self-test
   * failure, not a quieter report. `hostbox-ratio` rather than `artwork-ratio`
   * for the squashed one: the component paints its svg at 1em regardless of
   * what the host is forced to, so an override on the host distorts the BOX. */
  const want = ["unresolved", "oversize", "hostbox-ratio", "ratio"];
  const missing = want.filter((w) => !reasons.has(w));
  const anyPassed = inTest.some((x) => x.pass);
  console.log(`caught: ${[...reasons].join(" ")}`);
  if (missing.length || anyPassed) {
    console.log(`\n  FAIL  self-test: the audit did not catch ${missing.join(",") || "(a deliberately broken box passed)"}`);
    process.exit(2);
  }
  console.log(`\n  PASS  self-test: every injected defect was caught, none passed`);
  process.exit(0);
}

if (flag("--self-test")) await selfTest();
else await main();
