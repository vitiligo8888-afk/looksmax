#!/usr/bin/env bun
/**
 * The i18n browser harness: screenshots in both languages, and the gate.
 *
 *   bun extensions/looksmax-i18n/e2e/i18n.ts shots --out /work/flarum/shots-i18n
 *   bun extensions/looksmax-i18n/e2e/i18n.ts gate
 *   bun extensions/looksmax-i18n/e2e/i18n.ts gate --expect-fail   # prove it can go red
 *
 * ── Why this does not use e2e/shot.ts --login ───────────────────────────────
 *
 * It cannot: that flag has never worked. shot.ts:76 POSTs to /login with a
 * JSON content type and no CSRF token, and Flarum answers 400. Measured here:
 * the same POST with `X-CSRF-Token: app.session.csrfToken` returns
 * 200 {"token":…,"userId":1}. shot.ts prints the 400 and carries on, so every
 * screenshot any lane has taken with --login is a LOGGED OUT page that looks
 * plausible. That is filed in HANDOFF-I18N.md with the line to change; it is
 * another lane's file, so it is reported rather than edited.
 *
 * ── What the gate asserts, and why each one exists ──────────────────────────
 *
 *  1. No raw translation key is on screen, in text or in a tooltip. This is
 *     the P0. It has happened twice on this box in one day and it is invisible
 *     to HTTP 200, to the LESS build and to `docker ps`.
 *  2. Every installed locale has a non-trivial compiled catalogue. An empty
 *     one is 133 bytes and produces (1) on every page at once.
 *  3. <html lang> matches the locale actually being served, because that is
 *     what a screen reader and a translation tool read.
 *  4. The switcher exists and can be reached, in whichever of its two homes
 *     applies to the current session.
 *  5. Nothing overflows its container horizontally. Spanish runs ~24% longer
 *     than English on this forum's chrome, and the failure it causes is
 *     visual, so it is measured rather than eyeballed.
 */
import { mkdirSync, writeFileSync } from "node:fs";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CHROME =
  process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";

const argv = process.argv.slice(2);
const MODE = argv[0] === "gate" ? "gate" : "shots";
const flag = (n: string, d?: string) => {
  const i = argv.indexOf(`--${n}`);
  return i === -1 ? d : argv[i + 1];
};
const has = (n: string) => argv.includes(`--${n}`);

const OUT = flag("out", "/work/flarum/shots-i18n")!;
const LOGIN = flag("login");
const EXPECT_FAIL = has("expect-fail");
const PORT = Number(flag("port", "21979"));

/**
 * The surfaces a visitor actually lands on, not a sample of them.
 *
 * `serverRendered` marks the pages Flarum builds from a Blade template rather
 * than in the SPA — the 404, the 500, password reset, e-mail confirmation.
 * They carry no app bundle, so no sentinel and no hreflang, and asserting the
 * SPA's contract against them produces four failures per run that mean nothing.
 * They still have to be in the list: the 404 is one of the most-seen pages on
 * any forum, and it is currently the least translated.
 */
const SURFACES: Array<{ name: string; path: string; auth?: boolean; serverRendered?: boolean }> = [
  { name: "index", path: "/" },
  { name: "all", path: "/all" },
  { name: "discussion", path: "__FIRST_DISCUSSION__" },
  { name: "profile", path: "__FIRST_USER__" },
  { name: "settings", path: "/settings", auth: true },
  { name: "notifications", path: "/notifications", auth: true },
  { name: "store", path: "/store" },
  { name: "search", path: "/search?q=skin" },
  { name: "notfound", path: "/this-page-does-not-exist", serverRendered: true },
];

const WIDTHS = [1440, 390];
const LOCALES = ["es", "en"];

/* ─────────────────────────────────────────────────────────────────────── CDP */

const proc = Bun.spawn(
  [
    CHROME,
    `--remote-debugging-port=${PORT}`,
    "--headless=new",
    "--no-sandbox",
    "--disable-gpu",
    "--hide-scrollbars",
    `--user-data-dir=/tmp/lmx-i18n-${process.pid}`,
  ],
  { stdout: "ignore", stderr: "ignore" },
);

async function endpoint(): Promise<string> {
  for (let i = 0; i < 80; i++) {
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
  if (m.id && pending.has(m.id)) {
    pending.get(m.id)!(m);
    pending.delete(m.id);
  }
};
const send = (method: string, params: any = {}): Promise<any> =>
  new Promise((res) => {
    const n = ++id;
    pending.set(n, res);
    ws.send(JSON.stringify({ id: n, method, params }));
  });

await send("Page.enable");
await send("Runtime.enable");
await send("Network.enable");

async function evalJs(expression: string): Promise<any> {
  const r = await send("Runtime.evaluate", {
    expression,
    awaitPromise: true,
    returnByValue: true,
  });
  if (r.result?.exceptionDetails) {
    return { __error: r.result.exceptionDetails.exception?.description || "eval failed" };
  }
  return r.result?.result?.value;
}

async function goto(path: string, waitMs = 4200) {
  await send("Page.navigate", { url: `${BASE}${path}` });
  await Bun.sleep(waitMs);
}

async function setViewport(width: number, height = 1000) {
  await send("Emulation.setDeviceMetricsOverride", {
    width,
    height,
    deviceScaleFactor: 1,
    mobile: width < 500,
  });
}

/**
 * Select a locale the way the product does, not the way that is convenient.
 *
 * Two mechanisms, because Flarum has two: a guest is a `locale` cookie, and a
 * member is the `locale` preference on their account. Setting only the cookie
 * and then logging in tests nothing — core never reads a cookie for a signed-in
 * actor, which is the bug this harness found in my own middleware. Both paths
 * are exercised here so both keep working.
 */
async function setLocale(code: string, loggedIn: boolean) {
  await send("Network.setCookie", { name: "locale", value: code, url: BASE, path: "/" });

  if (!loggedIn) return;

  /*
   * Land on a page that actually boots the app before touching its session.
   *
   * The first version called this wherever the previous loop iteration left
   * the browser, which was the server-rendered 404 — a Blade page with no app
   * bundle at all. `flarum is not defined`, the preference was never saved,
   * and the whole English pass silently ran in Spanish while reporting a
   * green cookie. The harness lied in exactly the way it exists to catch.
   */
  await goto("/", 3500);

  const r = await evalJs(`(async () => {
    const u = flarum.core.app.session && flarum.core.app.session.user;
    if (!u) return 'no session';
    await u.savePreferences({ locale: ${JSON.stringify(code)} });
    return u.preferences().locale;
  })()`);
  if (r !== code) console.log(`  warn: saving locale preference returned ${JSON.stringify(r)}`);
}

async function login(creds: string): Promise<boolean> {
  const [u, p] = creds.split(":");
  await goto("/", 4000);
  const res = await evalJs(`(async () => {
    const t = flarum.core.app.session && flarum.core.app.session.csrfToken;
    const r = await fetch(${JSON.stringify(BASE)} + '/login', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': t },
      body: JSON.stringify({ identification: ${JSON.stringify(u)}, password: ${JSON.stringify(p)} }),
    });
    return { status: r.status, body: (await r.text()).slice(0, 200) };
  })()`);
  if (res?.status !== 200) {
    console.log(`  login ${u}: HTTP ${res?.status} ${res?.body || res?.__error || ""}`);
    return false;
  }
  await goto("/", 3500);
  const who = await evalJs(
    `(flarum.core.app.session && flarum.core.app.session.user && flarum.core.app.session.user.username()) || null`,
  );
  console.log(`  login ${u}: HTTP 200, session user = ${who}`);
  return !!who;
}

/** Resolve the placeholder paths against whatever this database actually holds. */
async function resolvePaths(): Promise<Record<string, string>> {
  await goto("/", 4000);
  const first = await evalJs(
    `(() => { const a = document.querySelector('.DiscussionListItem-main, a.DiscussionListItem-content, .DiscussionList a[href*="/d/"]');
      return a ? new URL(a.href).pathname : '/'; })()`,
  );
  const user = await evalJs(
    `(() => { const a = document.querySelector('a[href*="/u/"]'); return a ? new URL(a.href).pathname : '/'; })()`,
  );
  return { __FIRST_DISCUSSION__: first || "/", __FIRST_USER__: user || "/" };
}

/** Everything the page knows about itself, after the sentinel has run. */
async function inspect() {
  return await evalJs(`(() => {
    const s = window.__lmxI18n || {};
    const doc = document.documentElement;
    /*
     * Layout damage from a longer translation, and ONLY that.
     *
     * The first version of this check reported 25 elements on every mobile
     * page and was therefore useless. Everything it found was deliberate:
     *
     *  - .sr-only / .visually-hidden text is clipped ON PURPOSE. That is what
     *    the class does.
     *  - text-overflow: ellipsis and -webkit-line-clamp are a designer saying
     *    "cut this off". scrollWidth > clientWidth is their success condition,
     *    not a defect.
     *  - The mobile drawer sits off-canvas at negative x until it is opened,
     *    so every element inside it "overflows" to the left.
     *  - Anything inside an ancestor that clips is, by definition, contained.
     *
     * What is left is the real failure: a visible element whose own box
     * sticks out past the right edge of the viewport with nothing clipping
     * it, which is what makes a page scroll sideways on a phone.
     */
    const over = [];
    const vw = doc.clientWidth;

    function clipsHorizontally(cs) {
      return cs.overflowX === 'hidden' || cs.overflowX === 'auto' || cs.overflowX === 'scroll' ||
             cs.overflow === 'hidden' || cs.overflow === 'auto' || cs.overflow === 'scroll';
    }
    function hiddenOrClipped(el) {
      let n = el;
      while (n && n !== document.body) {
        const cs = getComputedStyle(n);
        if (cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0') return true;
        if (clipsHorizontally(cs)) return true;
        if (cs.position === 'fixed' && n !== el) return true;
        // The off-canvas drawer and anything translated out of view.
        if (cs.transform && cs.transform !== 'none' && /matrix\\(([^)]*)\\)/.test(cs.transform)) {
          const parts = cs.transform.slice(7, -1).split(',').map(Number);
          if (parts.length === 6 && Math.abs(parts[4]) > 50) return true;
        }
        if (n.classList && (n.classList.contains('sr-only') || n.classList.contains('visually-hidden'))) return true;
        n = n.parentElement;
      }
      return false;
    }

    const els = document.body.querySelectorAll('*');
    for (let i = 0; i < els.length; i++) {
      const e = els[i];
      const r = e.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) continue;
      if (r.right <= vw + 2) continue;
      const cs = getComputedStyle(e);
      if (cs.position === 'fixed') continue;
      if (hiddenOrClipped(e.parentElement || document.body)) continue;
      if (clipsHorizontally(cs)) continue;
      over.push({
        sel: e.tagName.toLowerCase() + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\\s+/).slice(0,2).join('.') : ''),
        right: Math.round(r.right), vw: vw, text: (e.textContent || '').trim().slice(0, 60),
      });
    }

    /*
     * Overlap, which is the defect the operator actually reported and which no
     * overflow check can see: two siblings that render on top of each other
     * because one grew. Only leaf-ish elements with their own text are
     * compared, and only against siblings, so a child inside its parent does
     * not count as overlapping it.
     */
    const textEls = [];
    const cand = document.body.querySelectorAll('a,span,p,h1,h2,h3,button,strong,time,label,td,li');
    for (let i = 0; i < cand.length; i++) {
      const e = cand[i];
      if (e.children.length) continue;
      const t = (e.textContent || '').trim();
      if (t.length < 2) continue;
      if (hiddenOrClipped(e)) continue;
      const r = e.getBoundingClientRect();
      if (r.width < 4 || r.height < 4) continue;
      textEls.push({ e: e, r: r, t: t });
    }
    for (let i = 0; i < textEls.length; i++) {
      for (let j = i + 1; j < textEls.length; j++) {
        const a = textEls[i], b = textEls[j];
        if (a.e.parentElement !== b.e.parentElement) continue;
        const ox = Math.min(a.r.right, b.r.right) - Math.max(a.r.left, b.r.left);
        const oy = Math.min(a.r.bottom, b.r.bottom) - Math.max(a.r.top, b.r.top);
        if (ox > 3 && oy > 3) {
          over.push({ sel: 'overlap:' + a.e.tagName.toLowerCase() + '+' + b.e.tagName.toLowerCase(),
                      right: Math.round(ox), vw: 0, text: a.t.slice(0, 28) + ' ✕ ' + b.t.slice(0, 28) });
        }
      }
    }

    const seen = new Set(); const uniq = [];
    for (const o of over) { const k = o.sel + '|' + o.text; if (!seen.has(k)) { seen.add(k); uniq.push(o); } }
    return {
      lang: doc.lang,
      dir: doc.dir,
      appLocale: (window.flarum && flarum.core.app.data && flarum.core.app.data.locale) || null,
      locales: (window.flarum && flarum.core.app.data && flarum.core.app.data.locales) || null,
      loggedIn: !!(window.flarum && flarum.core.app.session && flarum.core.app.session.user),
      rawKeys: s.rawKeys || [],
      rawKeyCount: s.rawKeyCount == null ? -1 : s.rawKeyCount,
      missingKeys: s.missingKeys || [],
      sentinelReady: !!s.ready,
      headerSwitcher: !!document.querySelector('.item-locale .Dropdown-toggle'),
      sessionSwitcher: doc.classList.contains('lmx-locale-in-session'),
      switcherWidth: (() => { const b = document.querySelector('.item-locale .Dropdown-toggle'); return b ? Math.round(b.getBoundingClientRect().width) : null; })(),
      hreflang: Array.from(document.querySelectorAll('link[rel=alternate][hreflang]')).map(l => l.getAttribute('hreflang') + ' ' + l.getAttribute('href')),
      overflow: uniq.slice(0, 25),
      title: document.title,
    };
  })()`);
}

async function shoot(file: string) {
  const r = await send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
  if (r.result?.data) writeFileSync(file, Buffer.from(r.result.data, "base64"));
}

/* ───────────────────────────────────────────────────────────────────── run */

const failures: string[] = [];
const report: any[] = [];

mkdirSync(OUT, { recursive: true });

/*
 * Before anything else: can this origin even write to the API?
 *
 * Flarum builds `apiUrl` from config.php's `url`, not from the request host.
 * This install is configured as https://looksmax.lat and also answers on
 * http://127.0.0.1:8888 and https://dev.looksmax.lat, so a browser on either of
 * the latter two POSTs cross-origin and gets `status: 0` — a network failure,
 * not an HTTP error, with no response to inspect.
 *
 * That is how the English half of this gate silently ran in Spanish: saving the
 * locale preference failed, the previous pass's preference stood, and every
 * assertion reported the wrong locale rather than the real cause. Checking it
 * up front turns thirty confusing failures into one accurate one.
 */
const apiOrigin = await (async () => {
  await goto("/", 4000);
  return await evalJs(
    `(() => { try { return new URL(flarum.core.app.forum.attribute('apiUrl')).origin; } catch (e) { return null; } })()`,
  );
})();
const pageOrigin = new URL(BASE).origin;
if (apiOrigin && apiOrigin !== pageOrigin) {
  failures.push(
    `the app's apiUrl origin (${apiOrigin}) is not the origin under test (${pageOrigin}), so every ` +
      `browser write is cross-origin and fails with status 0. Re-run with FORUM_URL=${apiOrigin}.`,
  );
}

const loggedIn = LOGIN ? await login(LOGIN) : false;
if (LOGIN && !loggedIn) failures.push("could not log in; the authenticated surfaces were not tested");
const resolved = await resolvePaths();

for (const locale of LOCALES) {
  await setLocale(locale, loggedIn);
  for (const width of WIDTHS) {
    await setViewport(width);
    for (const s of SURFACES) {
      if (s.auth && !loggedIn) continue;
      const path = resolved[s.path] ?? s.path;
      await goto(path);
      // The sentinel republishes 400ms after a route change; this navigation
      // is a full load, so it has already run by now.
      const info = await inspect();
      const tag = `${locale}-${s.name}-${width}${loggedIn ? "-in" : "-out"}`;

      if (MODE === "shots") await shoot(`${OUT}/${tag}.png`);

      report.push({ tag, locale, surface: s.name, width, serverRendered: !!s.serverRendered, ...info });

      if (info?.__error) {
        failures.push(`${tag}: page eval failed — ${info.__error}`);
        continue;
      }

      // These hold everywhere, SPA or not: the document must declare the
      // language it is in, and no key may reach a reader.
      if (info.lang !== locale) {
        failures.push(`${tag}: <html lang> is "${info.lang}", expected "${locale}"`);
      }
      if (info.rawKeyCount > 0) {
        failures.push(
          `${tag}: ${info.rawKeyCount} raw translation keys on screen — ` +
            info.rawKeys.slice(0, 5).map((k: any) => `${k.key} (${k.where})`).join(", "),
        );
      }
      if (info.overflow.length) {
        failures.push(
          `${tag}: ${info.overflow.length} element(s) overflow or overlap — ` +
            info.overflow.slice(0, 3).map((o: any) => `${o.sel} "${o.text}"`).join(" | "),
        );
      }

      // The rest only exist on a page that boots the app.
      if (s.serverRendered) continue;

      if (info.rawKeyCount === -1 || !info.sentinelReady) {
        failures.push(`${tag}: the i18n sentinel never ran — the script did not load`);
      }
      if (info.appLocale !== locale) {
        failures.push(`${tag}: app.data.locale is "${info.appLocale}", expected "${locale}"`);
      }
      if (!info.headerSwitcher && !info.sessionSwitcher) {
        failures.push(`${tag}: no way to change language on this page`);
      }
      if (info.switcherWidth != null && info.switcherWidth > 48) {
        failures.push(
          `${tag}: the header locale control is ${info.switcherWidth}px wide; it must stay a ~34px square`,
        );
      }
      if (info.hreflang.length < 3) {
        failures.push(`${tag}: expected 3 hreflang alternates, found ${info.hreflang.length}`);
      }
    }
  }
}

writeFileSync(`${OUT}/report.json`, JSON.stringify({ base: BASE, failures, report }, null, 2));

console.log("");
for (const r of report) {
  console.log(
    `  ${r.tag.padEnd(30)} lang=${String(r.lang).padEnd(3)} raw=${String(r.rawKeyCount).padEnd(3)} ` +
      `switch=${r.headerSwitcher ? (r.switcherWidth + "px") : r.sessionSwitcher ? "session" : "NONE"} ` +
      `overflow=${r.overflow.length}`,
  );
}
console.log("");

proc.kill();

if (EXPECT_FAIL) {
  if (failures.length) {
    console.log(`  RED (as demanded): ${failures.length} failures\n`);
    failures.slice(0, 10).forEach((f) => console.log(`    ${f}`));
    process.exit(0);
  }
  console.log("  --expect-fail was passed but everything passed; the assertion proves nothing\n");
  process.exit(1);
}

if (failures.length) {
  console.log(`  FAIL  ${failures.length} i18n failures\n`);
  failures.forEach((f) => console.log(`    ${f}`));
  console.log("");
  process.exit(1);
}

console.log(`  PASS  ${report.length} surface/locale/width combinations, 0 raw keys, 0 overflow\n`);
