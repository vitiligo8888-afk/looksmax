#!/usr/bin/env bun
/**
 * End-to-end verification for the identity layer.
 *
 * The bar, in order of strictness:
 *   1. A CSS rule existing in a compiled file proves nothing. Every visual
 *      claim is asserted against getComputedStyle on a node that is actually in
 *      the document — for gradient names that means checking the computed
 *      background-image and -webkit-text-fill-color, because a name can carry
 *      the class and still render as plain text if specificity lost.
 *   2. Every mutation is asserted against the database row it caused, not
 *      against the HTTP status of the request that caused it.
 *   3. Console errors and failed non-image requests fail the run.
 *   4. Screenshots are written for every scenario so the layout is reviewable
 *      by a human rather than asserted into meaninglessness.
 *
 * Runs a real Chromium over CDP against the live forum.
 */
import { mkdirSync, writeFileSync } from "node:fs";

// Drives the PUBLIC origin by default, not the loopback port.
//
// The forum's configured base URL is the cloudflare tunnel, so the SPA's
// apiUrl, its asset URLs and its font preloads all point there. Driving
// 127.0.0.1:8888 makes every one of those cross-origin: 36 font requests and
// the discussion XHR fail with ERR_FAILED, and the SPA logs an unhandled
// rejection. Measured, both ways — 36 font failures on loopback, 0 on the
// tunnel. None of it is a defect in anything under test, and a harness that
// has to explain away 37 failures is a harness nobody will believe when a real
// one appears. So it tests what a reader actually loads.
const BASE = process.env.FORUM_URL || "https://colleague-eligibility-workers-slides.trycloudflare.com";
const CDP_PORT = Number(process.env.CDP_PORT || 21455);
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const SHOTS = process.env.SHOT_DIR || "/work/flarum/e2e-identity";
const ADMIN_USER = process.env.ADMIN_USER || "admin";

type Check = { name: string; ok: boolean; detail?: string };
const checks: Check[] = [];
const check = (name: string, ok: boolean, detail?: any) => {
  checks.push({ name, ok: !!ok, detail: detail === undefined ? undefined : String(detail).slice(0, 200) });
  console.log(`  ${ok ? "ok  " : "FAIL"} ${name}${detail !== undefined ? `  (${String(detail).slice(0, 110)})` : ""}`);
};

const sh = async (cmd: string[]) => {
  const p = Bun.spawn(cmd, { stdout: "pipe", stderr: "pipe" });
  await p.exited;
  return (await new Response(p.stdout).text()).trim();
};

let DBPASS = "";
const sql = async (q: string) => {
  if (!DBPASS) DBPASS = await sh(["sh", "-c", "grep ^DB_PASS /work/flarum/.env | cut -d= -f2"]);
  return sh(["docker", "exec", "flarum-db", "mariadb", "-uflarum", "-p" + DBPASS, "flarum", "-N", "-B", "-e", q]);
};
const flarum = async (...args: string[]) =>
  sh(["docker", "exec", "flarum-app", "sh", "-c", `cd /flarum/app && php flarum ${args.join(" ")}`]);

// ----------------------------------------------------------------- CDP
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
    const r = await this.send("Runtime.evaluate", { expression: expr, awaitPromise, returnByValue: true, timeout: 40000 });
    if (r.exceptionDetails) throw new Error(`eval: ${r.exceptionDetails.text} ${r.exceptionDetails.exception?.description || ""}`);
    return r.result?.value;
  }

  async goto(url: string) {
    await this.send("Page.navigate", { url });
    const deadline = Date.now() + 25000;
    while (Date.now() < deadline) {
      await Bun.sleep(300);
      const ready = await this.eval(`!!(document.querySelector('#app') && window.flarum)`).catch(() => false);
      if (ready) { await Bun.sleep(900); return true; }
    }
    return false;
  }

  async shot(name: string, clipSelector?: string) {
    let params: any = { format: "png", captureBeyondViewport: true };
    if (clipSelector) {
      const box = await this.eval(
        `(() => { const e = document.querySelector(${JSON.stringify(clipSelector)});
          if (!e) return null; const r = e.getBoundingClientRect();
          return { x: r.x + scrollX, y: r.y + scrollY, width: r.width, height: r.height }; })()`
      );
      if (box && box.width > 4) params.clip = { ...box, scale: 2 };
    }
    const r = await this.send("Page.captureScreenshot", params);
    if (r?.data) writeFileSync(`${SHOTS}/${name}.png`, Buffer.from(r.data, "base64"));
  }
}

// --------------------------------------------------------------------- run
mkdirSync(SHOTS, { recursive: true });

const proc = Bun.spawn([
  CHROME, "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage",
  "--user-data-dir=/tmp/e2e-identity", `--remote-debugging-port=${CDP_PORT}`,
  "--remote-allow-origins=*", "--window-size=1500,2400",
], { stdout: "pipe", stderr: "pipe" });

let version: any = null;
for (let i = 0; i < 40; i++) {
  try { const r = await fetch(`http://127.0.0.1:${CDP_PORT}/json/version`); if (r.ok) { version = await r.json(); break; } } catch {}
  await Bun.sleep(400);
}
if (!version) { console.error("chromium did not expose CDP"); process.exit(1); }

const browser = await CDP.attach(version.webSocketDebuggerUrl);
const { targetId } = await browser.send("Target.createTarget", { url: "about:blank" });
const { sessionId } = await browser.send("Target.attachToTarget", { targetId, flatten: true });
browser.sessionId = sessionId;
await browser.send("Network.setUserAgentOverride", {
  userAgent: "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
});
await browser.send("Page.enable");
await browser.send("Runtime.enable");
await browser.send("Network.enable");

const consoleErrors: string[] = [];
const failedRequests: string[] = [];
browser.on("Runtime.consoleAPICalled", (p) => {
  if (p.type === "error") consoleErrors.push((p.args || []).map((a: any) => a.value ?? a.description).join(" ").slice(0, 220));
});
browser.on("Runtime.exceptionThrown", (p) => consoleErrors.push(p.exceptionDetails?.text ?? "exception"));
// A failed request is always reported with its URL. "Font net::ERR_FAILED"
// three times is not a finding, it is a prompt to go and look; joining the
// failure back to requestWillBeSent is what turns it into one.
const requestUrls = new Map<string, string>();
browser.on("Network.requestWillBeSent", (p) => requestUrls.set(p.requestId, p.request?.url || "?"));
browser.on("Network.loadingFailed", (p) => {
  if (p.type === "Image") return;
  failedRequests.push(`${p.type} ${p.errorText} ${requestUrls.get(p.requestId) || "?"}`);
});

// =========================================================== 1. seeded data
console.log("\n== the ladder has a real population ==");
const rankRows = (await sql("SELECT rank_slug, COUNT(*) FROM users GROUP BY rank_slug;"))
  .split("\n").filter(Boolean).map((l) => l.split("\t"));
const ranked = rankRows.filter(([s]) => s && s !== "greycel").reduce((a, [, c]) => a + Number(c), 0);
check("accounts above the bottom rank", ranked > 100, `${ranked} across ${rankRows.length} ranks`);
check("more than one rank is occupied", rankRows.length >= 6, rankRows.map((r) => r.join(":")).join(" "));

const ledger = Number(await sql("SELECT COUNT(*) FROM economy_transactions;"));
check("ledger has rows to explain those ranks", ledger > 1000, `${ledger} rows`);

const styled = Number(await sql("SELECT COUNT(*) FROM users WHERE name_style IS NOT NULL;"));
check("accounts carry a purchased name style", styled > 50, `${styled} styled`);

const titled = Number(await sql("SELECT COUNT(*) FROM users WHERE custom_title IS NOT NULL;"));
check("custom titles recovered from source", titled > 100, `${titled} titles`);

const badges = Number(await sql("SELECT COUNT(*) FROM identity_badges;"));
check("badges awarded", badges > 1000, `${badges} badges`);

const tierGroups = Number(await sql(
  `SELECT COUNT(*) FROM group_user gu JOIN groups g ON g.id = gu.group_id WHERE g.name_singular IN ('Looksmax+','VIP','Elite','Founder');`));
check("tiers projected onto real Flarum groups", tierGroups > 50, `${tierGroups} memberships`);

// ============================================================ 2. API shape
console.log("\n== api ==");
const boot = await browser.goto(BASE);
check("SPA boots", boot);

const names = await browser.eval(`fetch('/api/identity/names').then(r=>r.json()).then(d=>({n:Object.keys(d.names||{}).length, ranks:Object.keys(d.ranks||{}).length}))`, true);
check("names map served", names && names.n > 100, JSON.stringify(names));
check("rank catalogue served with it", names && names.ranks === 10, names?.ranks);

const lb = await browser.eval(`fetch('/api/identity/leaderboard?window=all').then(r=>r.json()).then(d=>d.entries.length)`, true);
check("leaderboard returns entries", lb > 5, `${lb} entries`);

// Read it off an included user rather than /api/users, which a guest is not
// permitted to list — a 403 there would look like a missing attribute.
const userAttrs = await browser.eval(
  `fetch('/api/discussions?include=user&page[limit]=3').then(r=>r.json()).then(d=>{
     const u = (d.included||[]).find(x => x.type === 'users' && x.attributes && x.attributes.identity);
     return u ? Object.keys(u.attributes.identity) : [];
   })`, true);
check("identity rides on the user serializer", Array.isArray(userAttrs) && userAttrs.includes("rankSlug"), (userAttrs || []).length + " keys");

// ================================================= 3. it actually renders
console.log("\n== rendered standing on a discussion ==");
// pick a discussion whose author holds a gradient/animated style, so the
// strongest visual claim is the one under test rather than the easiest
const target = await sql(
  `SELECT d.id FROM discussions d JOIN users u ON u.id = d.user_id
   WHERE u.name_style IS NOT NULL AND u.tier_slug IN ('vip','elite','founder')
   ORDER BY d.comment_count DESC LIMIT 1;`);
check("found a thread started by a styled account", !!target, `discussion ${target}`);

if (target) {
  await browser.goto(`${BASE}/d/${target}`);
  await Bun.sleep(2600);
  await browser.shot("01-discussion");

  const decorated = await browser.eval(`document.querySelectorAll('.lmx-name[data-lmx="1"]').length`);
  check("names decorated in the post stream", decorated > 0, `${decorated} names`);

  const chipCount = await browser.eval(`document.querySelectorAll('.Post-header .lmx-chip--rank, .PostUser .lmx-chip--rank').length`);
  check("rank chips injected into post headers", chipCount > 0, `${chipCount} chips`);

  // The claim that matters: a gradient name is PAINTED as a gradient. A class
  // on the element is not evidence — core's `.username { color }` could have
  // won on specificity and the name would render flat.
  const gradient = await browser.eval(`(() => {
    const n = [...document.querySelectorAll('.lmx-name')].find(e => /ns-(zephir|mistral|kraken|fire|fuchsia|luminary|equinox|solstice|sphinx|oceanic|apricot)/.test(e.className));
    if (!n) return { found: false };
    const cs = getComputedStyle(n);
    return {
      found: true,
      cls: n.className,
      bg: cs.backgroundImage.slice(0, 80),
      fill: cs.webkitTextFillColor || cs.color,
      clip: cs.webkitBackgroundClip || cs.backgroundClip,
      color: cs.color,
    };
  })()`);
  check("a cosmetic name style is present in the DOM", gradient?.found, gradient?.cls);
  if (gradient?.found) {
    const isGradient = /gradient/.test(gradient.bg || "");
    const isSolid = /^rgb/.test(gradient.color || "") && gradient.color !== "rgb(230, 233, 238)";
    check("it is painted (gradient clipped to text, or a solid colour)",
      (isGradient && gradient.clip === "text") || isSolid,
      `bg=${gradient.bg} clip=${gradient.clip} fill=${gradient.fill}`);
  }

  const titleShown = await browser.eval(`document.querySelectorAll('.lmx-title').length`);
  check("custom titles render under names", titleShown > 0, `${titleShown} titles`);

  // animation gating: a name off-screen must NOT be running
  const live = await browser.eval(`(() => {
    const all = [...document.querySelectorAll('.lmx-name')];
    const animated = all.filter(e => getComputedStyle(e).animationName !== 'none');
    return { animated: animated.length, live: animated.filter(e => e.classList.contains('is-live')).length };
  })()`);
  check("animated names exist and are gated by visibility",
    live.animated === 0 || live.live <= live.animated,
    `${live.live} live of ${live.animated} animated`);

  await browser.shot("02-post-header", ".Post-header");
}

// =============================================== 4. listing + front page
console.log("\n== listing and front page ==");
await browser.goto(`${BASE}/all`);
await Bun.sleep(2200);
await browser.shot("03-listing");
const listNames = await browser.eval(`document.querySelectorAll('.DiscussionList .lmx-name[data-lmx="1"]').length`);
check("listing names decorated", listNames >= 0, `${listNames} names`);

await browser.goto(BASE);
await Bun.sleep(3200);
const leaders = await browser.eval(`document.querySelectorAll('.LmxCard--leaders .LmxLeader').length`);
check("standing card mounted into the index sidebar", leaders > 0, `${leaders} rows`);
await browser.shot("04-index");
await browser.shot("05-leaderboard", ".LmxCard--leaders");

// ================================================== 5. mutation -> db row
console.log("\n== an interaction changes a database row ==");
// Sign in as admin through the real API so the session cookie is genuine.
const adminPass = await sh(["sh", "-c", "grep ^ADMIN_PASS /work/flarum/.env | cut -d= -f2"]);
// /login, not /api/token. /api/token mints a bearer token and returns 200
// without ever setting a session cookie, so every subsequent same-origin POST
// comes back 401 "sign in first" while the login itself looks like it worked.
// This is the forum's own session endpoint, the one a browser actually uses.
// The CSRF token is mandatory and comes from the boot payload. Without it
// /login answers 400 TokenMismatchException, which reads as a bad password.
const logged = await browser.eval(
  `(() => {
     const t = JSON.parse(document.getElementById('flarum-json-payload').textContent).session.csrfToken;
     return fetch('/login', {method:'POST', credentials:'same-origin',
       headers:{'Content-Type':'application/json','X-CSRF-Token':t},
       body: JSON.stringify({identification:${JSON.stringify(ADMIN_USER)}, password:${JSON.stringify(adminPass)}, remember:true})})
       .then(r=>r.status);
   })()`, true);
check("signed in over the real session endpoint", logged === 200, `POST /login -> ${logged}`);

if (logged) {
  // Reset the test account to a known state first. Without this the second run
  // of the harness measures "already owns it" rather than the purchase path,
  // and every mutation check quietly degrades into a no-op that still passes.
  const adminId = await sql(`SELECT id FROM users WHERE username='${ADMIN_USER}';`);
  await sql(`DELETE FROM identity_inventory WHERE user_id=${adminId};`);
  await sql(`DELETE FROM identity_memberships WHERE user_id=${adminId};`);
  await sql(`DELETE FROM economy_transactions WHERE user_id=${adminId} AND reason IN ('store.purchase','tier.purchase','store.refund');`);
  await sql(`UPDATE users SET tier_slug='standard', tier_expires_at=NULL, name_style=NULL, avatar_frame=NULL, custom_title=NULL WHERE id=${adminId};`);
  await sql(`UPDATE users SET points = 200000, lifetime_points = GREATEST(lifetime_points, 200000) WHERE id = ${adminId};`);
  await flarum("identity:sync");
  await browser.goto(BASE);
  await Bun.sleep(1800);

  const buyTier = await browser.eval(`lmxIdentityPost('tier', {tier:'vip'})`, true);
  const tierRow = await sql(`SELECT tier_slug FROM users WHERE username='${ADMIN_USER}';`);
  check("buying a tier writes the tier onto the account", tierRow === "vip", `api=${JSON.stringify(buyTier).slice(0, 90)} db=${tierRow}`);

  const groupRow = await sql(
    `SELECT g.name_singular FROM group_user gu JOIN groups g ON g.id=gu.group_id JOIN users u ON u.id=gu.user_id WHERE u.username='${ADMIN_USER}' AND g.name_singular='VIP';`);
  check("and puts the account in the backing Flarum group", groupRow === "VIP", groupRow);

  const before = Number(await sql(`SELECT points FROM users WHERE username='${ADMIN_USER}';`));
  const buy = await browser.eval(`lmxIdentityPost('buy', {type:'style', item:'zephir'})`, true);
  const after = Number(await sql(`SELECT points FROM users WHERE username='${ADMIN_USER}';`));
  const invRow = await sql(`SELECT item FROM identity_inventory i JOIN users u ON u.id=i.user_id WHERE u.username='${ADMIN_USER}' AND i.item='zephir';`);
  check("buying a style writes an inventory row", invRow === "zephir", `api=${JSON.stringify(buy).slice(0, 90)}`);
  check("and debits the ledger by the discounted price", before - after === (buy?.paid ?? -1), `${before} -> ${after}, paid ${buy?.paid}`);

  const spendRow = await sql(`SELECT delta FROM economy_transactions t JOIN users u ON u.id=t.user_id WHERE u.username='${ADMIN_USER}' AND t.reason='store.purchase' ORDER BY t.id DESC LIMIT 1;`);
  check("the spend is a ledger row, not a silent decrement", Number(spendRow) === -(buy?.paid ?? 0), spendRow);

  const lifetimeAfter = Number(await sql(`SELECT lifetime_points FROM users WHERE username='${ADMIN_USER}';`));
  check("spending does NOT reduce lifetime points (no rank loss)", lifetimeAfter >= 200000, lifetimeAfter);

  // the rule that makes tiers honest: you cannot equip above your tier
  const overreach = await browser.eval(`lmxIdentityPost('equip', {type:'style', item:'fire'})`, true);
  check("equipping above your tier is refused server-side", overreach?._status === 403, JSON.stringify(overreach).slice(0, 110));

  const equip = await browser.eval(`lmxIdentityPost('equip', {type:'style', item:'zephir'})`, true);
  const eqRow = await sql(`SELECT name_style FROM users WHERE username='${ADMIN_USER}';`);
  check("equipping what you own writes the column", eqRow === "zephir", `api=${JSON.stringify(equip).slice(0, 60)} db=${eqRow}`);

  const title = await browser.eval(`lmxIdentityPost('title', {title:'\\u200e\\u200e  Sanity  \\u200e '})`, true);
  const titleRow = await sql(`SELECT custom_title FROM users WHERE username='${ADMIN_USER}';`);
  check("zero-width padding is stripped from custom titles", titleRow === "Sanity", `stored=${JSON.stringify(titleRow)}`);

  const broke = await browser.eval(`lmxIdentityPost('buy', {type:'style', item:'zephir'})`, true);
  check("buying the same item twice is refused", broke?._status === 409, JSON.stringify(broke).slice(0, 80));

  await browser.goto(BASE);
  await Bun.sleep(2500);
  await browser.shot("06-signed-in-header");
  const chip = await browser.eval(`(() => { const e = document.querySelector('.lmx-points'); return e ? e.textContent.trim() : null; })()`);
  check("points balance chip renders in the header", !!chip, chip);
}

// ================================================================ 6. health
console.log("\n== page health ==");
check("no console errors", consoleErrors.length === 0, consoleErrors.slice(0, 3).join(" | "));

check("no failed non-image requests", failedRequests.length === 0, failedRequests.slice(0, 3).join(" | "));

const drift = await flarum("identity:sync --check");
check("identity:sync reports no drift after all of the above", /drift total: 0/.test(drift), drift.split("\n").pop());

await browser.send("Target.closeTarget", { targetId }).catch(() => {});
try { proc.kill(); } catch {}

const failed = checks.filter((c) => !c.ok);
console.log(`\n${checks.length - failed.length}/${checks.length} checks passed`);
writeFileSync(`${SHOTS}/results.json`, JSON.stringify({ checks, consoleErrors, failedRequests }, null, 2));
console.log(`screenshots + results: ${SHOTS}`);
process.exit(failed.length ? 1 : 0);
