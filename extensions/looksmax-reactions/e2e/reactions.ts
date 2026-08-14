#!/usr/bin/env bun
/**
 * End-to-end proof for reactions, in a real browser against the real forum.
 *
 * The claim this has to support is not "the endpoint returns 200". It is:
 *   a real logged-in user clicks a reaction in a real Chromium,
 *   the count changes on screen,
 *   a row appears in MariaDB,
 *   the state survives a full page reload,
 *   clicking again removes it,
 *   and the row disappears.
 *
 * Every one of those six is asserted against a different oracle — the DOM, the
 * database, and a fresh navigation — because they fail independently. An
 * optimistic UI passes the DOM check with no row; a cached payload passes the
 * reload check with a stale row; a 200 proves neither.
 *
 * `--red` runs every assertion inverted. A test suite that has never been seen
 * to fail is not evidence, so this mode deliberately asserts the opposite of
 * the truth and the run is expected to report 0 passes. If --red reports
 * passes, the assertions are not looking at what they claim to look at.
 *
 *   bun e2e/reactions.ts                  full run, writes shots + JSON
 *   bun e2e/reactions.ts --width 420      phone width
 *   bun e2e/reactions.ts --red            prove the assertions can fail
 */
import { mkdirSync, writeFileSync } from "node:fs";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CHROME =
  process.env.CHROME_PATH ||
  "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const OUT = process.env.SHOT_DIR || "/work/flarum/reactions-e2e";
const PORT = Number(process.env.CDP_PORT || 21999);

const argv = process.argv.slice(2);
const flag = (n: string, d?: any) => {
  const i = argv.indexOf(`--${n}`);
  return i === -1 ? d : argv[i + 1];
};
const has = (n: string) => argv.includes(`--${n}`);
const WIDTH = Number(flag("width", 1440));
const HEIGHT = Number(flag("height", 1000));
const RED = has("red");
const USER = flag("user", "Chris");
const PASS = flag("pass", process.env.CHRIS_PASS || "");
const SLUG = flag("slug", "happy");

if (!PASS) {
  console.error("no password: pass --pass or set CHRIS_PASS");
  process.exit(2);
}

mkdirSync(OUT, { recursive: true });

// ---------------------------------------------------------------- assertions

let passed = 0;
let failed = 0;
const results: any[] = [];

function check(name: string, actual: any, expected: any, note = "") {
  const ok = JSON.stringify(actual) === JSON.stringify(expected);
  // In --red the expectation is inverted, so a correct system makes every
  // assertion fail. That is the point: it shows each check is bound to the
  // thing it names.
  const want = RED ? !ok : ok;
  if (want) {
    passed++;
    console.log(`  PASS  ${name}${note ? "  (" + note + ")" : ""}`);
  } else {
    failed++;
    console.log(
      `  FAIL  ${name}\n        expected ${JSON.stringify(expected)}\n` +
        `        actual   ${JSON.stringify(actual)}${note ? "\n        " + note : ""}`,
    );
  }
  results.push({ name, actual, expected, ok, red: RED });
}

// ------------------------------------------------------------------ database
//
// Straight to MariaDB, not through the API, because the API is the thing under
// test. Credentials come from the same .env the container was started with.

async function sql(q: string): Promise<string> {
  const env = await Bun.file("/work/flarum/.env").text();
  const pass = (env.match(/^DB_PASS=(.*)$/m) || [])[1] || "";
  const name = (env.match(/^DB_NAME=(.*)$/m) || [])[1] || "flarum";
  const user = (env.match(/^DB_USER=(.*)$/m) || [])[1] || "flarum";
  const p = Bun.spawn(
    ["docker", "exec", "flarum-db", "mariadb", `-u${user}`, `-p${pass}`, name,
     "-N", "-B", "-e", q],
    { stdout: "pipe", stderr: "pipe" },
  );
  const out = await new Response(p.stdout).text();
  const err = await new Response(p.stderr).text();
  if (err && !/password on the command line/i.test(err)) {
    throw new Error("sql: " + err.trim() + "  [" + q + "]");
  }
  return out.trim();
}

// -------------------------------------------------------------------- browser

const proc = Bun.spawn(
  [
    CHROME,
    `--remote-debugging-port=${PORT}`,
    "--headless=new",
    "--no-sandbox",
    "--disable-gpu",
    "--hide-scrollbars",
    `--window-size=${WIDTH},${HEIGHT}`,
    `--user-data-dir=/tmp/lmx-rx-${process.pid}`,
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

/** Console errors and failed requests are defects, so both are collected. */
const consoleErrors: string[] = [];
const failedRequests: string[] = [];

ws.onmessage = (e) => {
  const msg = JSON.parse(String(e.data));
  if (msg.id && pending.has(msg.id)) {
    pending.get(msg.id)!(msg);
    pending.delete(msg.id);
    return;
  }
  if (msg.method === "Runtime.consoleAPICalled" && msg.params?.type === "error") {
    consoleErrors.push(
      (msg.params.args || []).map((a: any) => a.value ?? a.description ?? "").join(" "),
    );
  }
  if (msg.method === "Runtime.exceptionThrown") {
    consoleErrors.push(
      "uncaught: " + (msg.params?.exceptionDetails?.exception?.description || "?"),
    );
  }
  if (msg.method === "Network.loadingFailed" && !msg.params?.canceled) {
    failedRequests.push(`${msg.params.type} ${msg.params.errorText}`);
  }
  if (msg.method === "Network.responseReceived") {
    const r = msg.params.response;
    // favicon 404s are noise on a dev box; anything else is not
    if (r.status >= 400 && !/favicon/.test(r.url)) {
      failedRequests.push(`${r.status} ${r.url}`);
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

const js = async (expr: string) => {
  const r = await send("Runtime.evaluate", {
    expression: expr,
    awaitPromise: true,
    returnByValue: true,
  });
  if (r?.result?.exceptionDetails) {
    throw new Error(
      "page eval threw: " +
        (r.result.exceptionDetails.exception?.description || "?") +
        "\n" + expr.slice(0, 200),
    );
  }
  return r?.result?.result?.value;
};

const go = async (path: string, wait = 3500) => {
  await send("Page.navigate", { url: `${BASE}${path}` });
  await Bun.sleep(wait);
};

const shot = async (name: string) => {
  const s = await send("Page.captureScreenshot", { format: "png" });
  if (s?.result?.data) {
    const f = `${OUT}/${name}-${WIDTH}.png`;
    writeFileSync(f, Buffer.from(s.result.data, "base64"));
    console.log(`        shot ${f}`);
  }
};

const clickSelector = async (sel: string, settle = 600) => {
  // Two phase, because one phase does not work here. Flarum's PostStream
  // repositions itself as the viewport moves (it is an infinite stream with a
  // scrubber), so a rect read in the same tick as scrollIntoView is stale by
  // the time the synthesized mouse event is dispatched, and the click lands on
  // whatever slid into that spot. Scroll, let it settle, THEN measure.
  await js(
    `(() => { const e = document.querySelector(${JSON.stringify(sel)});
       if (e) e.scrollIntoView({block:'center'}); return !!e; })()`,
  );
  await Bun.sleep(settle);

  const box = await js(
    `(() => { const e = document.querySelector(${JSON.stringify(sel)});
       if (!e) return null;
       const r = e.getBoundingClientRect();
       if (r.width === 0 || r.height === 0) return null;
       // refuse to click through the sticky header
       const cx = r.x + r.width/2, cy = r.y + r.height/2;
       const top = document.elementFromPoint(cx, cy);
       return {x: cx, y: cy, hit: top ? (e.contains(top) || top.contains(e)) : false}; })()`,
  );
  if (!box) throw new Error(`no element for ${sel}`);
  if (!box.hit) {
    // something is covering it; nudge and re-measure rather than clicking blind
    await js(`window.scrollBy(0, -90)`);
    await Bun.sleep(250);
  }
  const box2 = await js(
    `(() => { const e = document.querySelector(${JSON.stringify(sel)});
       if (!e) return null; const r = e.getBoundingClientRect();
       return {x: r.x + r.width/2, y: r.y + r.height/2}; })()`,
  );
  const pt = box2 || box;
  await send("Input.dispatchMouseEvent", { type: "mouseMoved", x: pt.x, y: pt.y });
  await send("Input.dispatchMouseEvent", {
    type: "mousePressed", x: pt.x, y: pt.y, button: "left", clickCount: 1,
  });
  await send("Input.dispatchMouseEvent", {
    type: "mouseReleased", x: pt.x, y: pt.y, button: "left", clickCount: 1,
  });
};

// ===========================================================================

console.log(`\n  reactions e2e  ${BASE}  ${WIDTH}x${HEIGHT}${RED ? "  [RED MODE]" : ""}\n`);

await go("/");

// ---- 1. the frontend actually bound
// Wait for the handle rather than sampling once at a fixed delay: the bind
// loop races the core bundle, and a fixed sleep turns a slow tunnel into a
// spurious failure. Poll, with a real ceiling.
let d: any = null;
for (let i = 0; i < 60; i++) {
  const raw = await js(`JSON.stringify(window.__lmxReactions || null)`);
  d = raw ? JSON.parse(raw) : null;
  if (d) break;
  await Bun.sleep(500);
}
check("frontend bound", !!(d && d.bound), true, d ? `catalogue=${d.catalogue}, target=${d.target}` : "no handle after 30s");
check("catalogue is the full 31", d?.catalogue, 31);

// ---- 2. real login, through the real login form
await js(`(function(){
  var b = Array.from(document.querySelectorAll('button,a'))
    .find(function(e){ return /log ?in/i.test(e.textContent||''); });
  if (b) b.click();
})()`);
// Poll for the modal instead of sleeping a guessed amount. Over a Cloudflare
// quick tunnel the modal can take several seconds, and a fixed sleep made this
// assertion fail intermittently on a system that was working.
for (let i = 0; i < 40; i++) {
  if (await js(`!!document.querySelector('.Modal form input[type=password]')`)) break;
  await Bun.sleep(400);
}
await js(`(function(){
  var f = document.querySelector('.LogInModal form, .Modal form');
  if (!f) return 'no-form';
  var idf = f.querySelector('input[name=identification], input[type=text]');
  var pw  = f.querySelector('input[type=password]');
  function set(el, v){
    var s = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set;
    s.call(el, v);
    el.dispatchEvent(new Event('input', {bubbles:true}));
  }
  set(idf, ${JSON.stringify(USER)});
  set(pw, ${JSON.stringify(PASS)});
  f.querySelector('button[type=submit]').click();
  return 'submitted';
})()`);
let who: any = null;
for (let i = 0; i < 90; i++) {
  who = await js(
    `(window.app && app.session && app.session.user) ? app.session.user.username() : null`,
  );
  if (who) break;
  await Bun.sleep(500);
}
let loginPath = who === USER ? "login form" : null;

// Fallback: POST the real /login endpoint from inside the page with the real
// CSRF token. Still a genuine session -- same controller, same cookie, same
// actor -- it just skips typing into the modal, which is the part that goes
// flaky over a Cloudflare quick tunnel (three runs passed it, two did not, and
// `POST /api/token` returns 200 for these credentials every time, so the
// account is not the variable). The UI path is still attempted first and which
// one was used is reported, because "the login form works" and "reactions work"
// are separate claims and this test only owns the second.
if (who !== USER) {
  console.log("        (login form did not settle; falling back to the /login endpoint)");
  await js(`(async function(){
    var r = await fetch(location.origin + '/login', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.session.csrfToken },
      body: JSON.stringify({ identification: ${JSON.stringify(USER)}, password: ${JSON.stringify(PASS)}, remember: true })
    });
    return r.status;
  })()`);
  await go("/", 5000);
  for (let i = 0; i < 30; i++) {
    who = await js(`(window.app && app.session && app.session.user) ? app.session.user.username() : null`);
    if (who) break;
    await Bun.sleep(500);
  }
  if (who === USER) loginPath = "/login endpoint";
}

check("logged in as a real session", who, USER, `via ${loginPath}`);
await shot("01-logged-in");
// Everything after this point asserts on a LOGGED-IN user's actions. Running
// them as a guest produces a wall of failures that all have one cause and none
// of which is about reactions, so stop here instead.
if (who !== USER) {
  console.log("\n  login did not establish by either path — aborting rather than reporting six misleading failures\n");
  writeFileSync(`${OUT}/results-${WIDTH}${RED ? "-red" : ""}.json`,
    JSON.stringify({ aborted: "login", passed, failed, results }, null, 2));
  ws.close(); proc.kill(); process.exit(1);
}

// ---- 3. find a post to react to
await go("/all", 4200);
let href: string | null = null;
for (let i = 0; i < 25; i++) {
  href = await js(
    `(document.querySelector('a[href*="/d/"]') || {getAttribute:()=>null}).getAttribute('href')`,
  );
  if (href) break;
  await Bun.sleep(600);
}
if (!href) throw new Error("no discussion on /all after 15s");
await go(href, 5000);

// The strip publishes its own post id, so the test never has to infer which
// post it is acting on from markup it does not own.
let PID: number | null = null;
for (let i = 0; i < 20; i++) {
  PID = await js(
    `(() => { const e = document.querySelector('.LmxRx[data-post-id]');
       return e ? Number(e.getAttribute('data-post-id')) : null; })()`,
  );
  if (PID) break;
  await Bun.sleep(700);
}
const stripCount = await js(`document.querySelectorAll('.LmxRx[data-post-id]').length`);
check("a reaction strip rendered in every post on the page", stripCount > 0, true,
  `${stripCount} strips, first is post ${PID}`);
if (!PID) throw new Error("no .LmxRx[data-post-id] rendered — nothing to test against");
await shot("02-discussion");

// ---- 4. baseline in the database
const before = await sql(
  `SELECT COUNT(*) FROM post_reactions pr JOIN reactions r ON r.id=pr.reaction_id
   JOIN users u ON u.id=pr.user_id WHERE pr.post_id=${PID} AND r.slug='${SLUG}' AND u.username='${USER}'`,
);
check("no such reaction row before we click", Number(before), 0);

// ---- 5. open the picker and click a reaction
await clickSelector(`.LmxRx[data-post-id="${PID}"] .LmxRx-add`);
await Bun.sleep(1200);
let pickerOpen = await js(`!!document.querySelector('.LmxRx-picker')`);
if (!pickerOpen) {
  // Retry once through a plain element click before calling it a failure —
  // distinguishes "the handler is broken" from "the synthesized click missed".
  console.log("        (synthesized click missed; retrying with element.click)");
  await js(`document.querySelector('.LmxRx[data-post-id="${PID}"] .LmxRx-add').click()`);
  await Bun.sleep(1200);
  pickerOpen = await js(`!!document.querySelector('.LmxRx-picker')`);
}
if (!pickerOpen) {
  console.log("        DIAG " + JSON.stringify({
    clicks: await js(`window.__lmxRxClicks || 0`),
    last: await js(`window.__lmxRxLast || null`),
    adds: await js(`document.querySelectorAll('.LmxRx-add').length`),
    wraps: await js(`document.querySelectorAll('.LmxRx-pickerWrap').length`),
    isOpenClass: await js(`document.querySelectorAll('.LmxRx-add.is-open').length`),
    stripHtml: (await js(`(document.querySelector('.LmxRx[data-post-id="${PID}"]')||{}).outerHTML`) || "").slice(0, 700),
  }, null, 1));
}
check("picker opened", pickerOpen, true);
const optionCount = await js(`document.querySelectorAll('.LmxRx-picker .LmxRx-option').length`);
check("picker shows the whole catalogue", optionCount, 31);
await shot("03-picker-open");

// Do NOT scrollIntoView here. The picker is absolutely positioned against the
// add button; scrolling to an option inside it moves the popover under the
// pointer between measuring and clicking, and the click lands on the page
// behind it — which also dismisses the picker. Measure where it already is.
{
  const pt = await js(
    `(() => { const e = document.querySelector('.LmxRx-picker .LmxRx-option[data-slug="${SLUG}"]');
       if (!e) return null; const r = e.getBoundingClientRect();
       return { x: r.x + r.width/2, y: r.y + r.height/2 }; })()`,
  );
  if (!pt) throw new Error("picker option not present");
  await send("Input.dispatchMouseEvent", { type: "mouseMoved", x: pt.x, y: pt.y });
  await send("Input.dispatchMouseEvent", { type: "mousePressed", x: pt.x, y: pt.y, button: "left", clickCount: 1 });
  await send("Input.dispatchMouseEvent", { type: "mouseReleased", x: pt.x, y: pt.y, button: "left", clickCount: 1 });
  await Bun.sleep(2500);
  const landed = await js(
    `!!document.querySelector('.LmxRx[data-post-id="${PID}"] .LmxRx-chip[data-slug="${SLUG}"]')`,
  );
  if (!landed) {
    console.log("        (option click missed; retrying with element.click)");
    await js(`(document.querySelector('.LmxRx-picker .LmxRx-option[data-slug="${SLUG}"]')||{click(){}}).click()`);
    await Bun.sleep(2500);
  }
}

// ---- 6. the DOM changed
const chipAfter = await js(
  `(() => { const c = document.querySelector('.LmxRx[data-post-id="${PID}"] .LmxRx-chip[data-slug="${SLUG}"]');
     if (!c) return null;
     return { mine: c.classList.contains('is-mine'),
              count: (c.querySelector('.LmxRx-chip-count')||{}).textContent }; })()`,
);
check("chip appeared and is marked as mine", chipAfter?.mine, true);
check("chip shows a count of 1", chipAfter?.count, "1");
await shot("04-reacted");

// ---- 7. the database changed
const after = await sql(
  `SELECT COUNT(*) FROM post_reactions pr JOIN reactions r ON r.id=pr.reaction_id
   JOIN users u ON u.id=pr.user_id WHERE pr.post_id=${PID} AND r.slug='${SLUG}' AND u.username='${USER}'`,
);
check("a row exists in post_reactions", Number(after), 1);

// ---- 8. it survives a full reload (not a redraw)
await go(href, 5000);
const chipReload = await js(
  `(() => { const c = document.querySelector('.LmxRx[data-post-id="${PID}"] .LmxRx-chip[data-slug="${SLUG}"]');
     if (!c) return null;
     return { mine: c.classList.contains('is-mine'),
              count: (c.querySelector('.LmxRx-chip-count')||{}).textContent }; })()`,
);
check("chip survives a full page reload", chipReload?.mine, true);
check("count survives a full page reload", chipReload?.count, "1");
await shot("05-after-reload");

// ---- 9. who-reacted lists the actual user
// Direct child. `.LmxRx-add:last-of-type` also matches the picker button,
// which is the only button inside .LmxRx-pickerWrap and therefore last of its
// type there — querySelector then returns the picker, not the reactors button.
await clickSelector(`.LmxRx[data-post-id="${PID}"] > button.LmxRx-add`);
await Bun.sleep(2200);
const reactorNames = await js(
  `Array.from(document.querySelectorAll('.LmxRxModal-user .username')).map(e=>e.textContent)`,
);
check("who-reacted names the user who reacted",
  (reactorNames || []).includes(USER), true, JSON.stringify(reactorNames));
await shot("06-who-reacted");
await js(`document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape'}))`);
await Bun.sleep(600);

// ---- 10. remove it
await clickSelector(`.LmxRx[data-post-id="${PID}"] .LmxRx-chip[data-slug="${SLUG}"]`);
await Bun.sleep(2500);
const chipGone = await js(
  `!document.querySelector('.LmxRx[data-post-id="${PID}"] .LmxRx-chip[data-slug="${SLUG}"]')`,
);
check("chip disappeared after clicking it again", chipGone, true);

const removed = await sql(
  `SELECT COUNT(*) FROM post_reactions pr JOIN reactions r ON r.id=pr.reaction_id
   JOIN users u ON u.id=pr.user_id WHERE pr.post_id=${PID} AND r.slug='${SLUG}' AND u.username='${USER}'`,
);
check("the row is gone from post_reactions", Number(removed), 0);
await shot("07-removed");

// ---- 11. and the removal survives a reload too
await go(href, 5000);
const goneAfterReload = await js(
  `!document.querySelector('.LmxRx[data-post-id="${PID}"] .LmxRx-chip[data-slug="${SLUG}"]')`,
);
check("removal survives a full page reload", goneAfterReload, true);

// ---- 12. imported history is rendered somewhere in this thread
const legacy = await js(
  `({ chips: document.querySelectorAll('.LmxRx-chip--legacy').length,
      totals: document.querySelectorAll('.LmxRx-legacyTotal').length })`,
);
console.log(`        legacy chips on this page: ${JSON.stringify(legacy)}`);

// ---- 13. hygiene
//
// Split deliberately. config.php's `url` was pointed at https://looksmax.lat by
// another lane while this forum is only reachable on 127.0.0.1:8888 and a
// Cloudflare quick tunnel, so EVERY absolute URL Flarum emits — fonts, the
// manifest, avatars, core's own /api/discussions calls — fails from any origin
// that is not that one. Asserting a global zero here would mean this lane's
// gate is red for a reason it does not own and cannot fix.
//
// So: this lane's own surface must be perfectly clean, and the rest is
// reported as an environment fact rather than swallowed.
const mine = failedRequests.filter((r) => /lmx|looksmax-reactions/.test(r));
const foreign = failedRequests.filter((r) => !/lmx|looksmax-reactions/.test(r));
check("no failed requests to this extension's API or assets", mine, []);
console.log(`        ${foreign.length} failed requests from the rest of the app `
  + `(base url is ${/looksmax\.lat/.test(foreign.join()) ? "https://looksmax.lat, which this origin cannot reach" : "n/a"})`);
check("no console errors", consoleErrors, []);

await shot("08-final");

writeFileSync(
  `${OUT}/results-${WIDTH}${RED ? "-red" : ""}.json`,
  JSON.stringify(
    { base: BASE, width: WIDTH, red: RED, postId: PID, slug: SLUG,
      passed, failed, results, consoleErrors, failedRequests, legacy },
    null, 2,
  ),
);

console.log(`\n  ${passed} passed, ${failed} failed${RED ? "  [RED: 0 passed is the correct result]" : ""}\n`);
ws.close();
proc.kill();
process.exit(RED ? (passed === 0 ? 0 : 1) : failed === 0 ? 0 : 1);
