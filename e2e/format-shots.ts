#!/usr/bin/env bun
/**
 * Look at the rendered pixels for post content, and fail on console errors and
 * failed network requests while doing it.
 *
 * -------------------------------------------------------------------------
 * Why this exists next to e2e/look.ts
 * -------------------------------------------------------------------------
 * look.ts is a general sweep owned by another lane and asserts nothing. This
 * one is specific to post content rendering, and it does three things look.ts
 * does not:
 *
 *   1. Screenshots the format showcase — one discussion containing every
 *      construct — plus a clipped shot of each individual construct, so a
 *      regression in one tag is visible on its own instead of buried in a
 *      3,000px page.
 *   2. Screenshots REAL corpus posts chosen by construct, because the
 *      showcase is synthetic and the corpus is the thing that has to render.
 *   3. Records every console message and every failed request, and exits
 *      non-zero if any error-level entry appears. A screenshot that looks
 *      fine while the console is full of 404s is not a passing surface.
 *
 * Also asserts the leak that started all of this: no visible text in any
 * rendered post may match `[tag]` syntax. That is checked against the DOM's
 * innerText, which is exactly what a reader sees.
 *
 *   bun e2e/format-shots.ts
 *   bun e2e/format-shots.ts --width 420          # mobile
 *   bun e2e/format-shots.ts --self-test          # prove the assertions fail
 */
import { mkdirSync, writeFileSync } from "node:fs";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CHROME =
  process.env.CHROME_PATH ||
  "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const OUT = process.env.SHOT_DIR || "/work/flarum/format-shots";
const PORT = Number(process.env.CDP_PORT || 21931);

const argv = process.argv.slice(2);
const flag = (n: string, d?: any) => {
  const i = argv.indexOf(`--${n}`);
  return i === -1 ? d : argv[i + 1];
};
const WIDTH = Number(flag("width", 1440));
const HEIGHT = Number(flag("height", 1100));
const SELF_TEST = argv.includes("--self-test");
/** Discussion id of the showcase; resolved by title if not given. */
const SHOWCASE = flag("showcase", "");

mkdirSync(OUT, { recursive: true });

const proc = Bun.spawn(
  [
    CHROME,
    `--remote-debugging-port=${PORT}`,
    "--headless=new",
    "--no-sandbox",
    "--disable-gpu",
    "--hide-scrollbars",
    `--window-size=${WIDTH},${HEIGHT}`,
    `--user-data-dir=/tmp/lmx-format-${process.pid}`,
  ],
  { stdout: "ignore", stderr: "ignore" },
);

async function endpoint(): Promise<string> {
  for (let i = 0; i < 60; i++) {
    try {
      const list = (await (
        await fetch(`http://127.0.0.1:${PORT}/json/list`)
      ).json()) as any[];
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

/** Console errors and failed requests, per surface. */
type Problem = { surface: string; kind: string; detail: string };
const problems: Problem[] = [];
let surface = "(startup)";

/**
 * Every request the browser made, so third-party fetches can be asserted on.
 *
 * The operator's requirement: a reader's browser must never hit a host we do
 * not control. Measured before this work, one thread page (/d/329) made 48
 * references to looksmax.org including 8 to their image host — handing them our
 * traffic volume, our readers' IPs and user agents, and the exact posts being
 * read, plus the ability to break or substitute every image on the forum.
 *
 * This is collected over CDP because it is the only way to see what the browser
 * ACTUALLY fetched. A DOM assertion on `img[src]` cannot see a CSS
 * `background-image`, a redirect that lands off-site, a font, a favicon, or an
 * `<iframe>` a script injected after load.
 */
const requests: Array<{ surface: string; url: string; type: string; id: string }> = [];

/**
 * Requests the browser REFUSED to make because of our own CSP.
 *
 * This distinction is the whole measurement. `Network.requestWillBeSent` fires
 * for an intent to fetch; a request blocked by Content-Security-Policy is then
 * reported via `Network.loadingFailed` with a `blockedReason` and NO connection
 * is ever opened, so nothing reaches the third party. Counting intents as
 * egress would have reported 214 leaks when the true number was zero — and,
 * worse, would have made the security control look like it had made things
 * worse rather than better.
 */
const blockedIds = new Set<string>();
const blockedByCsp: Array<{ surface: string; kind: string; detail: string }> = [];
const pendingUrls = new Map<string, string>();

ws.onmessage = (e) => {
  const m = JSON.parse(String(e.data));
  if (m.id && pending.has(m.id)) {
    pending.get(m.id)!(m);
    pending.delete(m.id);
    return;
  }

  // Console errors are defects.
  if (m.method === "Runtime.consoleAPICalled" && m.params?.type === "error") {
    problems.push({
      surface,
      kind: "console.error",
      detail: (m.params.args || [])
        .map((a: any) => a.value ?? a.description ?? a.type)
        .join(" ")
        .slice(0, 300),
    });
  }
  if (m.method === "Runtime.exceptionThrown") {
    problems.push({
      surface,
      kind: "uncaught",
      detail: String(
        m.params?.exceptionDetails?.exception?.description ??
          m.params?.exceptionDetails?.text,
      ).slice(0, 300),
    });
  }
  // Failed network requests are defects — EXCEPT ones our own Content-Security-
  // Policy deliberately refused. A CSP block is the policy working: the browser
  // never opens a connection, so nothing reaches the third party. Counting it
  // as a defect would mean the security control could never be green.
  if (m.method === "Network.loadingFailed" && !m.params?.canceled) {
    if (m.params?.blockedReason) {
      blockedIds.add(String(m.params.requestId));
      blockedByCsp.push({
        surface,
        kind: `blocked.${m.params.blockedReason}`,
        detail: pendingUrls.get(String(m.params.requestId)) || "",
      });
    } else {
      problems.push({
        surface,
        kind: "net.failed",
        detail: `${m.params?.type} ${m.params?.errorText}`,
      });
    }
  }
  if (m.method === "Network.requestWillBeSent") {
    const rid = String(m.params?.requestId || "");
    const url = String(m.params?.request?.url || "");
    pendingUrls.set(rid, url);
    requests.push({ surface, url, type: String(m.params?.type || ""), id: rid });
  }
  if (m.method === "Network.responseReceived") {
    const st = m.params?.response?.status;
    if (st >= 400) {
      problems.push({
        surface,
        kind: `http.${st}`,
        detail: String(m.params.response.url).slice(0, 220),
      });
    }
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
await send("Emulation.setDeviceMetricsOverride", {
  width: WIDTH,
  height: HEIGHT,
  deviceScaleFactor: 1,
  mobile: WIDTH < 700,
});

const evalJs = async (expr: string) => {
  const r = await send("Runtime.evaluate", {
    expression: expr,
    awaitPromise: true,
    returnByValue: true,
  });
  return r?.result?.result?.value;
};

async function go(url: string, name: string, settle = 4200) {
  surface = name;
  await send("Page.navigate", { url });
  await Bun.sleep(settle);
}

async function shoot(name: string) {
  const shot = await send("Page.captureScreenshot", {
    format: "png",
    captureBeyondViewport: false,
  });
  const data = shot?.result?.data;
  if (!data) {
    console.log(`  ! ${name}: no image returned`);
    return;
  }
  const file = `${OUT}/${name}-${WIDTH}.png`;
  writeFileSync(file, Buffer.from(data, "base64"));
  console.log(`  shot ${file}`);
}

/** Screenshot just one element, so a construct can be judged on its own. */
async function shootElement(selector: string, name: string) {
  const box = await evalJs(`(() => {
    const e = document.querySelector(${JSON.stringify(selector)});
    if (!e) return null;
    e.scrollIntoView({block: 'center'});
    const r = e.getBoundingClientRect();
    return {x: Math.max(0, r.x - 12), y: Math.max(0, r.y - 12),
            width: Math.min(${WIDTH}, r.width + 24), height: Math.min(2400, r.height + 24)};
  })()`);
  if (!box || box.height < 4) {
    console.log(`  ! ${name}: ${selector} not present or zero-height`);
    return false;
  }
  await Bun.sleep(400);
  const shot = await send("Page.captureScreenshot", {
    format: "png",
    clip: { ...box, scale: 1 },
  });
  const data = shot?.result?.data;
  if (!data) {
    console.log(`  ! ${name}: no image`);
    return false;
  }
  writeFileSync(`${OUT}/${name}-${WIDTH}.png`, Buffer.from(data, "base64"));
  console.log(`  shot ${OUT}/${name}-${WIDTH}.png`);
  return true;
}

// ------------------------------------------------------------------ assertions

let pass = 0;
let fail = 0;
const failures: string[] = [];

function check(name: string, ok: boolean, detail = "") {
  if (ok) {
    pass++;
    return;
  }
  fail++;
  failures.push(name);
  console.log(`  FAIL  ${name}`);
  if (detail) console.log(`        ${detail.slice(0, 600)}`);
}

/**
 * The leak assertion, read off the DOM the way a reader sees it.
 *
 * innerText, not innerHTML: s9e keeps the original markup inside <s>/<e>
 * elements so a post can be unparsed back to BBCode for the editor, and those
 * are not rendered. Asserting on innerHTML would report every healthy post as
 * broken.
 */
const LEAK_RE =
  "\\[\\/?(?:spoiler|quote|img|url|unfurl|embed|media|umention|gmention|emote|table|tr|td|th|list|code|c|color|size|font|align|background|hr|sup|sub|ins|noparse)(?=[\\]\\s=])";

async function assertNoVisibleBbcode(name: string) {
  const found = await evalJs(`(() => {
    const re = new RegExp(${JSON.stringify(LEAK_RE)}, 'i');
    const out = [];
    for (const el of document.querySelectorAll('.Post-body')) {
      // A [code] block legitimately shows BBCode as its content.
      const clone = el.cloneNode(true);
      clone.querySelectorAll('pre, code, .lmxCode').forEach(n => n.remove());
      const t = clone.innerText || '';
      const m = t.match(re);
      if (m) out.push(m[0] + ' :: ' + t.slice(Math.max(0, t.indexOf(m[0]) - 60), t.indexOf(m[0]) + 90));
    }
    return out;
  })()`);
  check(
    `${name}: no visible BBCode in any post body`,
    Array.isArray(found) && found.length === 0,
    Array.isArray(found) ? found.slice(0, 4).join("\n") : String(found),
  );
}

// ------------------------------------------------------------------ run

// Resolve the showcase discussion.
await go(`${BASE}/all`, "all");
let showcaseHref = SHOWCASE ? `/d/${SHOWCASE}` : "";
if (!showcaseHref) {
  showcaseHref =
    (await evalJs(
      `(() => { const a = [...document.querySelectorAll('a[href*="/d/"]')]
          .find(a => /format-showcase/.test(a.getAttribute('href')||''));
        return a ? a.getAttribute('href') : ''; })()`,
    )) || "";
}
if (!showcaseHref) {
  // Fall back to searching, since /all is paginated and the showcase may not
  // be on the first page.
  await go(`${BASE}/?q=Format+showcase`, "search-showcase");
  showcaseHref =
    (await evalJs(
      `(() => { const a = [...document.querySelectorAll('a[href*="/d/"]')]
          .find(a => /format-showcase/.test(a.getAttribute('href')||''));
        return a ? a.getAttribute('href') : ''; })()`,
    )) || "";
}

console.log(`showcase: ${showcaseHref || "(not found)"}`);

if (showcaseHref) {
  await go(`${BASE}${showcaseHref}`, "showcase", 5000);

  await assertNoVisibleBbcode("showcase");

  // Whole page, top and scrolled.
  await shoot("showcase-top");
  await evalJs("window.scrollTo(0, 1400)");
  await Bun.sleep(900);
  await shoot("showcase-mid");
  await evalJs("window.scrollTo(0, 3200)");
  await Bun.sleep(900);
  await shoot("showcase-deep");
  // The community-addon sections live at the very bottom of the showcase, past
  // the three fixed scroll positions above.
  await evalJs("window.scrollTo(0, 5200)");
  await Bun.sleep(900);
  await shoot("showcase-addons");
  await evalJs("window.scrollTo(0, 6600)");
  await Bun.sleep(900);
  await shoot("showcase-addons-2");
  await evalJs("window.scrollTo(0, 0)");
  await Bun.sleep(600);

  // Per-construct clips, so each one can be judged alone.
  const constructs: Array<[string, string]> = [
    ["spoiler", ".lmxSpoiler"],
    ["quote", ".lmxQuote"],
    ["unfurl", ".lmxUnfurl"],
    ["image", ".lmxImageWrap"],
    ["media", ".lmxMedia"],
    ["table", ".lmxTableWrap"],
    ["emote", ".lmxEmote"],
    ["mention-unimported", ".UserMention--unimported"],
    ["code", ".lmxCode"],
    // looksmax.org's own addons
    ["ispoiler", ".lmxISpoiler"],
    ["labels", ".lmxLabel"],
    ["heading", ".lmxHeading"],
    ["hidden", ".lmxHidden"],
    ["deleted", ".lmxDeleted"],
    ["lolquote", ".lmxQuote--lol"],
    ["quote-folded", ".lmxQuote[data-lmx-fold='collapsed']"],
  ];
  for (const [name, sel] of constructs) {
    await shootElement(sel, `construct-${name}`);
  }

  // Spoilers must actually open. A <details> that never expands hides content
  // permanently, and that is invisible to a screenshot of the closed state.
  const opened = await evalJs(`(() => {
    const d = document.querySelector('.lmxSpoiler');
    if (!d) return null;
    d.open = true;
    const body = d.querySelector('.lmxSpoiler-body');
    return body ? body.getBoundingClientRect().height : 0;
  })()`);
  check(
    "showcase: spoiler body has height when open",
    typeof opened === "number" && opened > 0,
    `height=${opened}`,
  );
  await Bun.sleep(500);
  await shootElement(".lmxSpoiler", "construct-spoiler-open");

  // Every image the post references must actually load. A broken image is a
  // failed request AND a visibly empty box.
  const broken = await evalJs(`(() => {
    return [...document.querySelectorAll('.Post-body img')]
      .filter(i => i.complete && i.naturalWidth === 0)
      .map(i => i.currentSrc || i.src).slice(0, 10);
  })()`);
  check(
    "showcase: no broken images in post bodies",
    Array.isArray(broken) && broken.length === 0,
    Array.isArray(broken) ? broken.join("\n") : String(broken),
  );
}

/*
 * Real corpus posts, not just the synthetic showcase. These are where the
 * third-party media actually lives, so they are the ones that prove the proxy.
 *
 * The ids are discovered from the forum rather than hardcoded: discussion ids
 * shift as the importer runs, and a hardcoded list quietly degenerated into
 * four 404s that still "passed" the media assertion — because a page that does
 * not exist loads no images and therefore leaks nothing.
 */
let REAL = (flag("real", "") || "").split(",").filter(Boolean);
if (REAL.length === 0) {
  await go(`${BASE}/all`, "all-for-ids", 4500);
  const hrefs: string[] =
    (await evalJs(
      `[...document.querySelectorAll('a[href*="/d/"]')]
         .map(a => (a.getAttribute('href')||'').match(/\\/d\\/(\\d+)/))
         .filter(Boolean).map(m => m[1])`,
    )) || [];
  REAL = [...new Set(hrefs)].slice(0, 5);
  console.log(`real discussions discovered: ${REAL.join(", ") || "(none)"}`);
}
for (const d of REAL) {
  await go(`${BASE}/d/${d}`, `real-${d}`, 6000);
  // Scroll so lazy-loaded images below the fold actually get requested — an
  // un-fetched image cannot prove anything either way.
  await evalJs("window.scrollTo(0, document.body.scrollHeight / 2)");
  await Bun.sleep(2500);
  await evalJs("window.scrollTo(0, document.body.scrollHeight)");
  await Bun.sleep(2500);
  await assertNoVisibleBbcode(`real-${d}`);
  await shoot(`real-${d}`);
}

/*
 * THE ASSERTION: no request to a host we do not control.
 *
 * The allowlist is the forum's own origin plus the schemes that never leave the
 * browser. Anything else — an image host, a CDN, a font provider, an analytics
 * beacon — is a third party that now knows a real person just read a specific
 * page on this forum.
 */
/*
 * "Ours" is more than the host the harness was pointed at.
 *
 * This forum is reachable both on its real domain and through a cloudflared
 * quick tunnel, and Flarum emits asset URLs on its CONFIGURED base url
 * regardless of which one you browsed in on. Testing via the tunnel therefore
 * saw 221 requests to looksmax.lat and called them third-party — they are our
 * own origin. The canonical link in the page is the authoritative answer to
 * "what does this install think it is", so it is read from the page rather
 * than hardcoded.
 */
const ownHosts = new Set<string>([new URL(BASE).host]);
const canonical: string =
  (await evalJs(
    `(document.querySelector('link[rel="canonical"]')||{}).href || ''`,
  )) || "";
if (canonical) {
  try {
    ownHosts.add(new URL(canonical).host);
  } catch {}
}
console.log(`own hosts: ${[...ownHosts].join(", ")}`);

/**
 * Hosts that are third-party but NOT a leak to the source board, each with the
 * reason it is here. Deliberately an explicit, printed list rather than a
 * silent filter: an allowlist nobody looks at is how a real leak gets waved
 * through.
 */
const KNOWN_THIRD_PARTY: Record<string, string> = {
  // Injected by Cloudflare at the edge on our own zone (Web Analytics), not by
  // anything in this application's HTML — it cannot be removed from the app
  // side, only from the Cloudflare dashboard. Flagged for the infra lane in
  // HANDOFF-FORMAT.md. It does still mean a third party sees our readers, so it
  // is reported on every run rather than hidden.
  "static.cloudflareinsights.com": "Cloudflare Web Analytics, injected at the edge on our own zone",
};

const thirdParty = requests.filter((r) => {
  if (!r.url) return false;
  // Refused by our own CSP => never left the browser => not egress.
  if (blockedIds.has(r.id)) return false;
  try {
    if (KNOWN_THIRD_PARTY[new URL(r.url).host]) return false;
  } catch {}
  // Only real network fetches count. data:/blob: never leave the browser, and
  // chrome:// / chrome-untrusted:// are the headless browser's own new-tab
  // furniture, not something this page caused.
  if (!/^https?:\/\//i.test(r.url)) return false;
  try {
    return !ownHosts.has(new URL(r.url).host);
  } catch {
    return false;
  }
});

// Group by host so the failure message names the offenders rather than dumping
// hundreds of URLs.
const byHost = new Map<string, { count: number; example: string; surfaces: Set<string> }>();
for (const r of thirdParty) {
  const host = new URL(r.url).host;
  const e = byHost.get(host) || { count: 0, example: r.url, surfaces: new Set<string>() };
  e.count++;
  e.surfaces.add(r.surface);
  byHost.set(host, e);
}

// Print the allowlist every run, so it stays visible and arguable.
const allowedSeen = new Map<string, number>();
for (const r of requests) {
  if (blockedIds.has(r.id)) continue;
  try {
    const h = new URL(r.url).host;
    if (KNOWN_THIRD_PARTY[h]) allowedSeen.set(h, (allowedSeen.get(h) || 0) + 1);
  } catch {}
}
if (allowedSeen.size) {
  console.log("\nknown third parties reached (allowlisted, still a disclosure):");
  for (const [h, n] of allowedSeen) {
    console.log(`  ${h}  x${n}  — ${KNOWN_THIRD_PARTY[h]}`);
  }
}

check(
  "no browser request goes to an unapproved third-party host",
  byHost.size === 0,
  [...byHost.entries()]
    .map(([h, e]) => `${h}  ×${e.count}  [${[...e.surfaces].join(", ")}]  e.g. ${e.example.slice(0, 130)}`)
    .join("\n"),
);

// Positive evidence, not just the absence of failure: the proxy must actually
// be carrying media. Zero proxied requests with zero third-party requests would
// also mean "no images rendered at all", which would pass the check above for
// entirely the wrong reason.
const proxied = requests.filter((r) => r.url.includes("/media/p/"));
check(
  "media is actually being served through the proxy",
  proxied.length > 0,
  `${proxied.length} proxied requests across ${requests.length} total`,
);

// Group the CSP-blocked attempts by host too: they are not a leak, but they
// ARE a to-do list of code paths still trying to hotlink.
const blockedHosts = new Map<string, number>();
for (const b of blockedByCsp) {
  if (!b.detail) continue;
  try {
    const h = new URL(b.detail).host;
    blockedHosts.set(h, (blockedHosts.get(h) || 0) + 1);
  } catch {}
}

console.log(
  `\nnetwork: ${requests.length} request intents, ${proxied.length} via /media/p/, ` +
    `${thirdParty.length} actually reached a third party across ${byHost.size} host(s)`,
);
if (blockedHosts.size) {
  console.log(
    `CSP refused ${blockedByCsp.length} attempt(s) — nothing left the browser, ` +
      `but these code paths still try:`,
  );
  for (const [h, n] of [...blockedHosts].sort((a, b) => b[1] - a[1])) {
    console.log(`  ${h}  x${n}`);
  }
}

// ------------------------------------------------------------------ self-test

if (SELF_TEST) {
  console.log("\n== self-test (these MUST fail)");
  const before = fail;
  check("SELFTEST-bare-false", false, "hard-coded failure");
  await go(`${BASE}${showcaseHref}`, "selftest", 3000);
  // Inject literal BBCode into a post body and prove the leak assertion sees it.
  await evalJs(`(() => {
    const b = document.querySelector('.Post-body');
    if (b) b.insertAdjacentHTML('beforeend', '<p>[spoiler=injected]leak[/spoiler]</p>');
  })()`);
  await assertNoVisibleBbcode("SELFTEST-injected-leak");
  const produced = fail - before;
  console.log(`\nself-test produced ${produced} failures (expected 2)`);
  console.log(
    produced === 2
      ? "SELF-TEST OK: the assertions can go red."
      : "SELF-TEST BROKEN.",
  );
  ws.close();
  proc.kill();
  process.exit(produced === 2 ? 0 : 1);
}

// ------------------------------------------------------------------ report

console.log("");
const errors = problems.filter(
  (p) =>
    // Favicon and analytics beacons are noise on this stack and not content
    // defects; everything else counts.
    !/favicon|\/api\/analytics|pusher/i.test(p.detail),
);
check(
  "no console errors or failed requests on any surface",
  errors.length === 0,
  errors
    .slice(0, 12)
    .map((p) => `[${p.surface}] ${p.kind}: ${p.detail}`)
    .join("\n"),
);

if (problems.length) {
  console.log(`\n${problems.length} console/network problem(s) observed:`);
  for (const p of problems.slice(0, 25)) {
    console.log(`  [${p.surface}] ${p.kind}: ${p.detail}`);
  }
}

console.log("");
console.log(fail === 0 ? `ok\t${pass} passed, 0 failed` : `FAILED\t${pass} passed, ${fail} failed`);
for (const f of failures) console.log(`  - ${f}`);

ws.close();
proc.kill();
process.exit(fail === 0 ? 0 : 1);
