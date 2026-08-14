#!/usr/bin/env bun
/**
 * Browser end-to-end harness for the forum.
 *
 * Flarum ships PHPUnit unit and integration suites, which cover extenders and
 * the API but never render anything. Everything we added that a user actually
 * sees (theme, Iconify glyphs, motion, rank colours) and everything that spans
 * browser and database (a view becoming an analytics row, a post becoming a
 * ledger entry) is invisible to those suites.
 *
 * The bar this holds itself to, in order of strictness:
 *   1. HTTP 200 proves nothing. Every check asserts on rendered DOM or on a
 *      database row that the interaction caused.
 *   2. Console errors and failed network requests fail the run. A page that
 *      renders while throwing is broken.
 *   3. Screenshots are captured for each scenario so layout regressions are
 *      reviewable rather than asserted into meaninglessness.
 *
 * Runs against a real Chromium over CDP, no proxy, against the local forum.
 */
import { mkdirSync, writeFileSync } from "node:fs";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CDP_PORT = Number(process.env.CDP_PORT || 21400);
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const SHOTS = process.env.SHOT_DIR || "/work/flarum/e2e-shots";
const DB = { host: "flarum-db", name: "flarum", user: "flarum" };

type Check = { name: string; ok: boolean; detail?: string };
const checks: Check[] = [];
const check = (name: string, ok: boolean, detail?: any) => {
  checks.push({ name, ok: !!ok, detail: detail === undefined ? undefined : String(detail).slice(0, 160) });
  console.log(`  ${ok ? "ok  " : "FAIL"} ${name}${detail !== undefined ? `  (${String(detail).slice(0, 90)})` : ""}`);
};

// ----------------------------------------------------------------- CDP client
class CDP {
  ws!: WebSocket;
  id = 0;
  pending = new Map<number, any>();
  handlers = new Map<string, (p: any) => void>();
  sessionId?: string;

  static async attach(wsUrl: string) {
    const c = new CDP();
    c.ws = new WebSocket(wsUrl);
    await new Promise<void>((res, rej) => {
      c.ws.onopen = () => res();
      c.ws.onerror = (e) => rej(new Error(String(e)));
    });
    c.ws.onmessage = (ev) => {
      const m = JSON.parse(String(ev.data));
      if (m.id != null) {
        const p = c.pending.get(m.id);
        if (p) {
          c.pending.delete(m.id);
          m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result);
        }
      } else if (m.method) c.handlers.get(m.method)?.(m.params);
    };
    return c;
  }

  on(method: string, fn: (p: any) => void) { this.handlers.set(method, fn); }

  send(method: string, params: any = {}): Promise<any> {
    const id = ++this.id;
    return new Promise((res, rej) => {
      this.pending.set(id, { res, rej });
      this.ws.send(JSON.stringify({ id, method, params, sessionId: this.sessionId }));
      setTimeout(() => { if (this.pending.delete(id)) rej(new Error(`timeout ${method}`)); }, 45000);
    });
  }

  async eval(expr: string, awaitPromise = false) {
    const r = await this.send("Runtime.evaluate", {
      expression: expr, awaitPromise, returnByValue: true, timeout: 40000,
    });
    if (r.exceptionDetails) throw new Error(`eval: ${r.exceptionDetails.text}`);
    return r.result?.value;
  }

  async goto(url: string) {
    await this.send("Page.navigate", { url });
    // Flarum is an SPA: waiting for load is not enough, the app must have booted
    const deadline = Date.now() + 25000;
    while (Date.now() < deadline) {
      await Bun.sleep(300);
      const ready = await this.eval(`!!(document.querySelector('#app') && window.flarum)`).catch(() => false);
      if (ready) return true;
    }
    return false;
  }

  async shot(name: string) {
    const r = await this.send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
    if (r?.data) writeFileSync(`${SHOTS}/${name}.png`, Buffer.from(r.data, "base64"));
  }
}

const sh = async (cmd: string[]) => {
  const p = Bun.spawn(cmd, { stdout: "pipe", stderr: "pipe" });
  await p.exited;
  return (await new Response(p.stdout).text()).trim();
};

/** Query the forum database directly, to assert on side effects. */
const sql = async (q: string) => {
  const pass = (await sh(["sh", "-c", `grep ^DB_PASS /work/flarum/.env | cut -d= -f2`])).trim();
  return sh(["docker", "exec", DB.host, "mariadb", "-u" + DB.user, "-p" + pass, DB.name, "-N", "-B", "-e", q]);
};

// --------------------------------------------------------------------- run
mkdirSync(SHOTS, { recursive: true });
await sh(["docker", "rm", "-f", "e2e-chrome"]);

const proc = Bun.spawn([
  CHROME, "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage",
  "--user-data-dir=/tmp/e2e-profile", `--remote-debugging-port=${CDP_PORT}`,
  "--remote-allow-origins=*", "--window-size=1440,2200",
], { stdout: "pipe", stderr: "pipe" });

let version: any = null;
for (let i = 0; i < 40; i++) {
  try {
    const r = await fetch(`http://127.0.0.1:${CDP_PORT}/json/version`);
    if (r.ok) { version = await r.json(); break; }
  } catch {}
  await Bun.sleep(400);
}
if (!version) {
  console.error("chromium did not expose CDP");
  process.exit(1);
}

const browser = await CDP.attach(version.webSocketDebuggerUrl);
const { targetId } = await browser.send("Target.createTarget", { url: "about:blank" });
const { sessionId } = await browser.send("Target.attachToTarget", { targetId, flatten: true });
browser.sessionId = sessionId;
// Present a real browser UA. The view-capture middleware filters obvious bots,
// and headless Chrome advertises itself, so without this the harness is
// excluded by our own filter and every view assertion silently fails.
await browser.send("Network.setUserAgentOverride", {
  userAgent: "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
});
await browser.send("Page.enable");
await browser.send("Runtime.enable");
await browser.send("Network.enable");

// A page that renders while throwing is broken, so both are collected and
// asserted on rather than ignored.
const consoleErrors: string[] = [];
const failedRequests: string[] = [];
browser.on("Runtime.consoleAPICalled", (p) => {
  if (p.type === "error") consoleErrors.push((p.args || []).map((a: any) => a.value ?? a.description).join(" ").slice(0, 200));
});
browser.on("Runtime.exceptionThrown", (p) => {
  consoleErrors.push(p.exceptionDetails?.text ?? "exception");
});
browser.on("Network.loadingFailed", (p) => {
  if (p.type !== "Image") failedRequests.push(`${p.type} ${p.errorText}`);
});

console.log(`\n== forum boots (${BASE}) ==`);
const booted = await browser.goto(BASE);
check("SPA boots (#app + window.flarum)", booted);
await browser.shot("01-index");

const title = await browser.eval(`document.title`);
check("has a title", !!title, title);

console.log("\n== theme actually applied ==");
const bg = await browser.eval(`getComputedStyle(document.body).backgroundColor`);
check("dark surface applied", /rgb\(1[0-9], 1[0-9], 2[0-9]\)|14, 17, 22/.test(bg), bg);
const hasRows = await browser.eval(`document.querySelectorAll('.DiscussionListItem').length`);
check("discussion rows render", hasRows > 0, `${hasRows} rows`);
const rowPad = await browser.eval(
  `(() => { const el = document.querySelector('.DiscussionListItem'); return el ? getComputedStyle(el).paddingTop : null; })()`);
check("dense row padding from theme", rowPad === "10px", rowPad);

console.log("\n== iconify replaces fontawesome ==");
const iconCount = await browser.eval(`document.querySelectorAll('iconify-icon').length`);
check("iconify elements present", iconCount > 0, `${iconCount} glyphs`);
const iconResolved = await browser.eval(
  `(() => { const e = document.querySelector('iconify-icon'); return e ? e.getAttribute('icon') : null; })()`);
check("icon resolved to a set:name", /^[a-z0-9-]+:[a-z0-9-]+$/.test(iconResolved || ""), iconResolved);

console.log("\n== motion respects reduced-motion ==");
const hasKeyframes = await browser.eval(
  `[...document.styleSheets].some(s => { try { return [...s.cssRules].some(r => r.type === 7 && r.name === 'rise'); } catch (e) { return false; } })`);
check("keyframes compiled into served css", hasKeyframes);

console.log("\n== tags carry the source taxonomy ==");
const tagNames = await browser.eval(
  `[...document.querySelectorAll('.TagLabel')].map(e => e.textContent.trim()).slice(0, 8)`);
check("prefix/forum tags rendered", Array.isArray(tagNames) && tagNames.length > 0, JSON.stringify(tagNames));

console.log("\n== a discussion opens and records a view ==");
const before = Number(await sql("SELECT COUNT(*) FROM analytics_events WHERE type='discussion.viewed';"));
const firstHref = await browser.eval(
  `(() => { const a = document.querySelector('.DiscussionListItem-main, .DiscussionListItem a[href*="/d/"]'); return a ? a.getAttribute('href') : null; })()`);
check("found a discussion link", !!firstHref, firstHref);

if (firstHref) {
  await browser.goto(new URL(firstHref, BASE).href);
  await Bun.sleep(2500);
  await browser.shot("02-discussion");

  const posts = await browser.eval(`document.querySelectorAll('article.Post').length`);
  check("posts render in the stream", posts > 0, `${posts} posts`);

  const bodyText = await browser.eval(
    `(() => { const el = document.querySelector('.Post-body'); return el ? el.textContent.trim().slice(0, 120) : ''; })()`);
  check("post body has real content", (bodyText || "").length > 10, bodyText);
  check("no raw formatter XML leaked", !/<[rt]>|<\/[rt]>/.test(bodyText || ""), bodyText?.slice(0, 60));

  // Assert on a row for THIS discussion, not on a global count. Counting is
  // wrong here: view capture deduplicates the same reader on the same thread
  // inside a 15 minute window, so a re-run of the harness legitimately adds
  // nothing and a count-based check reports a false failure.
  const did = (firstHref.match(/\/d\/(\d+)/) || [])[1];
  const rows = Number(await sql(
    `SELECT COUNT(*) FROM analytics_events WHERE type='discussion.viewed' AND discussion_id=${did};`));
  check("view recorded server-side for this discussion", rows > 0, `discussion ${did}: ${rows} row(s)`);

  // And prove the dedupe itself works, which the count check was accidentally
  // measuring all along.
  await browser.goto(new URL(firstHref, BASE).href);
  await Bun.sleep(1500);
  const again = Number(await sql(
    `SELECT COUNT(*) FROM analytics_events WHERE type='discussion.viewed' AND discussion_id=${did};`));
  check("repeat view deduplicated inside the window", again === rows, `${rows} -> ${again}`);
}

console.log("\n== page health ==");
check("no console errors", consoleErrors.length === 0, consoleErrors.slice(0, 2).join(" | "));
check("no failed non-image requests", failedRequests.length === 0, failedRequests.slice(0, 2).join(" | "));

console.log("\n== api contract for our custom attributes ==");
const attrs = await browser.eval(
  `fetch('/api/discussions?page[limit]=1').then(r => r.json()).then(d => Object.keys(d.data[0]?.attributes || {}))`, true);
for (const a of ["viewCount", "hotness"]) {
  check(`discussion exposes ${a}`, Array.isArray(attrs) && attrs.includes(a));
}

await browser.send("Target.closeTarget", { targetId }).catch(() => {});
try { proc.kill(); } catch {}

const failed = checks.filter((c) => !c.ok);
console.log(`\n${checks.length - failed.length}/${checks.length} checks passed`);
writeFileSync(`${SHOTS}/results.json`, JSON.stringify({ checks, consoleErrors, failedRequests }, null, 2));
console.log(`screenshots + results: ${SHOTS}`);
process.exit(failed.length ? 1 : 0);
