#!/usr/bin/env bun
/**
 * The store in a real browser.
 *
 * Everything the API suite proves is invisible to a user. This one drives
 * Chromium over CDP against the live forum, signs in with a real session (not
 * an API key), clicks the buttons a member clicks, and asserts on rendered DOM
 * and on database rows the clicks caused.
 *
 * Screenshots are captured at 1440 and 390 wide for every screen, because
 * every layout defect on this project so far was invisible to DOM assertions
 * and obvious in a picture.
 *
 * Console errors and failed requests fail the run: a page that renders while
 * throwing is broken.
 *
 *   /root/.bun/bin/bun extensions/looksmax-store/e2e/browser.ts
 */
import { mkdirSync, writeFileSync } from "node:fs";

// The forum's configured base URL, not 127.0.0.1.
//
// Flarum builds `app.forum.attribute('apiUrl')` from the configured URL, so a
// browser that loaded the page from 127.0.0.1 sends every XHR to the public
// origin instead — cross-origin, and refused. That is not a store bug (the
// same failure hits /api/tags and every other core request), but a suite that
// loads the wrong origin measures nothing. Read the real one.
const BASE = process.env.FORUM_URL || (await (async () => {
  const p = Bun.spawn(["docker", "exec", "flarum-app", "php", "-r", 'echo (require "/flarum/app/config.php")["url"];'], { stdout: "pipe" });
  await p.exited;
  return (await new Response(p.stdout).text()).trim() || "http://127.0.0.1:8888";
})());
const CDP_PORT = Number(process.env.CDP_PORT || 21444);
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const SHOTS = process.env.SHOT_DIR || "/work/flarum/store-shots";

type Check = { name: string; ok: boolean; detail?: string };
const checks: Check[] = [];
const check = (name: string, ok: boolean, detail?: any) => {
  checks.push({ name, ok: !!ok, detail: detail === undefined ? undefined : String(detail).slice(0, 200) });
  console.log(`  ${ok ? "ok  " : "FAIL"} ${name}${detail !== undefined ? `  (${String(detail).slice(0, 110)})` : ""}`);
};

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
        if (p) { c.pending.delete(m.id); m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result); }
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
    if (r.exceptionDetails) throw new Error(`eval: ${r.exceptionDetails.text}`);
    return r.result?.value;
  }

  /**
   * Navigate, and retry if the page never booted.
   *
   * The forum is reachable only through a cloudflared quick tunnel, and the
   * suite has to load it from that origin because Flarum builds its own apiUrl
   * from the configured base URL — loading 127.0.0.1 makes every XHR
   * cross-origin and refused. The tunnel drops occasionally
   * (net::ERR_NETWORK_CHANGED mid-run), which is not a defect in the store, so
   * a navigation that produces no app is retried before anything is asserted
   * about it.
   */
  async goto(url: string, attempts = 3) {
    for (let i = 0; i < attempts; i++) {
      await this.send("Page.navigate", { url });
      const deadline = Date.now() + 25000;
      while (Date.now() < deadline) {
        await Bun.sleep(300);
        const ready = await this.eval(`!!(document.querySelector('#app') && window.flarum)`).catch(() => false);
        if (ready) { await Bun.sleep(700); return true; }
      }
      if (i < attempts - 1) { console.log(`  (retrying ${url})`); await Bun.sleep(2000); }
    }
    return false;
  }

  async width(px: number, height = 2400) {
    await this.send("Emulation.setDeviceMetricsOverride", {
      width: px, height, deviceScaleFactor: 1, mobile: px < 500,
    });
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
const DB_PASS = (await sh(["sh", "-c", "grep ^DB_PASS /work/flarum/.env | cut -d= -f2"])).trim();
const ADMIN_PASS = (await sh(["sh", "-c", "grep ^ADMIN_PASS /work/flarum/.env | cut -d= -f2"])).trim();
const sql = (q: string) => sh(["docker", "exec", "flarum-db", "mariadb", "-uflarum", "-p" + DB_PASS, "flarum", "-N", "-B", "-e", q]);
const num = async (q: string) => Number((await sql(q)).split("\n")[0]) || 0;

mkdirSync(SHOTS, { recursive: true });
await sh(["docker", "rm", "-f", "store-e2e-chrome"]);

const proc = Bun.spawn([
  CHROME, "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage",
  "--user-data-dir=/tmp/store-e2e-profile", `--remote-debugging-port=${CDP_PORT}`,
  "--remote-allow-origins=*", "--window-size=1440,2400",
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
  if (p.type === "error") consoleErrors.push((p.args || []).map((a: any) => a.value ?? a.description).join(" ").slice(0, 200));
});
browser.on("Runtime.exceptionThrown", (p) => {
  // The `text` is almost always the useless "Uncaught (in promise)"; the
  // message that says WHICH extension threw is on the exception object.
  const d = p.exceptionDetails || {};
  const detail = d.exception?.description || d.exception?.value || d.text || "exception";
  consoleErrors.push(String(detail).split("\n")[0].slice(0, 200));
});
browser.on("Network.loadingFailed", (p) => { if (p.type !== "Image") failedRequests.push(`${p.type} ${p.errorText}`); });

// ------------------------------------------------------------------ sign in
console.log("\n== signing in as a real session ==");
await browser.width(1440);
await browser.goto(BASE);
// Flarum's CSRF middleware rejects a session-less POST with 400 before the
// controller sees it, so the token the SPA already holds has to go with it.
const loggedIn = await browser.eval(`
  fetch('/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': (window.app && app.session && app.session.csrfToken) || ''
    },
    body: JSON.stringify({identification: 'admin', password: ${JSON.stringify(ADMIN_PASS)}})
  }).then(r => r.status)
`, true);
check("login endpoint accepts the session", loggedIn === 200, loggedIn);
await browser.goto(BASE);
const me = await browser.eval(`(window.app && app.session && app.session.user) ? app.session.user.username() : null`);
check("the SPA knows who we are", !!me, me);

// ------------------------------------------------- the bug this lane started
// The header credits chip links to /store. Before this extension existed that
// URL was a 404, which is what the operator hit: a balance you cannot spend.
// This is that exact path, clicked rather than typed.
console.log("\n== the credits chip actually goes somewhere ==");
{
  const chip = await browser.eval(`(() => {
    const c = document.querySelector('.lmx-points');
    return c ? c.getAttribute('href') : null;
  })()`);
  check("the header carries a credits chip pointing at the store", chip === "/store", chip);

  const status = await browser.eval(`fetch('/store', {redirect: 'follow'}).then(r => r.status)`, true);
  check("that URL answers 200 rather than 404", status === 200, status);

  await browser.eval(`document.querySelector('.lmx-points').click()`);
  await Bun.sleep(3500);
  const landed = await browser.eval(`location.pathname + ' | ' + document.querySelectorAll('.StorePage').length`);
  check("clicking the chip lands on the store", landed === "/store | 1", landed);
}

// -------------------------------------------------------------- store page
console.log("\n== /store renders ==");
await browser.goto(BASE + "/store");
const pathAfter = await browser.eval(`location.pathname`);
check("the URL is still /store (the router did not bounce us to the index)", pathAfter === "/store", pathAfter);

const routeRegistered = await browser.eval(`!!(window.app && app.routes && app.routes.store)`);
check("the client route is registered from the head script", routeRegistered);

const cards = await browser.eval(`document.querySelectorAll('.StoreCard').length`);
check("catalogue cards render", cards > 0, `${cards} cards in the first section`);

// The grid is filtered by section, so "how many cards" is only meaningful
// alongside which section is selected.
const switched = await browser.eval(`(() => {
  const tabs = [...document.querySelectorAll('.StoreCat')];
  const colours = tabs.find(t => /colours/i.test(t.textContent));
  if (!colours) return 'no colours tab';
  colours.click();
  return 'clicked';
})()`);
await Bun.sleep(600);
const colourCards = await browser.eval(`document.querySelectorAll('.StoreCard').length`);
check("switching section changes the grid", switched === "clicked" && colourCards > 10, `${switched}, ${colourCards} colour cards`);

const previews = await browser.eval(`document.querySelectorAll('.StorePreview-name').length`);
check("colours are previewed in the style they are sold in", previews > 10, `${previews} previews`);

const balanceText = await browser.eval(`(() => { const e = document.querySelector('.StoreBar-n'); return e ? e.textContent : null; })()`);
const dbBalance = await num(`select points from users where id=1`);
check("the balance on screen equals the balance in the database",
  balanceText && Number(balanceText.replace(/[^0-9]/g, "")) === dbBalance, `${balanceText} vs ${dbBalance}`);

const catTabs = await browser.eval(`[...document.querySelectorAll('.StoreCat')].map(e => e.textContent.trim())`);
check("category tabs render", Array.isArray(catTabs) && catTabs.length >= 5, JSON.stringify(catTabs).slice(0, 140));

const styled = await browser.eval(`(() => {
  const c = document.querySelector('.StoreCard');
  if (!c) return null;
  const s = getComputedStyle(c);
  return s.backgroundColor + ' | ' + s.borderTopWidth + ' | ' + s.borderRadius;
})()`);
check("the card CSS actually compiled and applied", /rgb/.test(styled || "") && !/rgba\(0, 0, 0, 0\)/.test((styled || "").split(" | ")[0]), styled);

await browser.shot("01-store-desktop");

// A card that cannot be afforded and one that is locked both have to say so.
const lockedText = await browser.eval(`(() => {
  const e = document.querySelector('.StoreCard-locked span:last-child');
  return e ? e.textContent.trim() : null;
})()`);
check("locked cards explain themselves in words", !!lockedText, lockedText);

// ----------------------------------------------------------- buying by hand
console.log("\n== buying through the UI ==");
await sql(`delete from identity_inventory where user_id=1 and item='vaporwave'`);
await sql(`update store_orders set state='refunded' where recipient_id=1 and sku='style-vaporwave' and state='granted'`);
await sql(`delete from store_entitlements where user_id=1 and sku='style-vaporwave'`);
await browser.goto(BASE + "/store");
await Bun.sleep(500);
await browser.eval(`(() => {
  const t = [...document.querySelectorAll('.StoreCat')].find(t => /colours/i.test(t.textContent));
  if (t) t.click();
})()`);
await Bun.sleep(600);

const clicked = await browser.eval(`(() => {
  const cards = [...document.querySelectorAll('.StoreCard')];
  const card = cards.find(c => (c.querySelector('h3')||{}).textContent === 'Vaporwave');
  if (!card) return 'no card';
  const b = card.querySelector('.StoreCard-buy');
  if (!b || b.disabled) return 'no button';
  b.click();
  return 'clicked';
})()`);
check("the buy button on a specific card is clickable", clicked === "clicked", clicked);
await Bun.sleep(600);

const modalTitle = await browser.eval(`(() => { const e = document.querySelector('.StoreModal-title'); return e ? e.textContent : null; })()`);
check("a confirm dialog opens naming the item", modalTitle === "Vaporwave", modalTitle);

const modalLines = await browser.eval(`[...document.querySelectorAll('.StoreModal-lines dd')].map(e => e.textContent.trim())`);
check("the dialog quotes the price and the balance after", (modalLines || []).length >= 2, JSON.stringify(modalLines));
await browser.shot("02-store-confirm");

const before = await num(`select points from users where id=1`);
await browser.eval(`(() => { const b = document.querySelector('.StoreModal-actions .Button--primary'); if (b) b.click(); })()`);
await Bun.sleep(2500);
const after = await num(`select points from users where id=1`);

check("the balance moved in the database", after < before, `${before} -> ${after}`);
check("the order is granted",
  await num(`select count(*) from store_orders where recipient_id=1 and sku='style-vaporwave' and state='granted'`) === 1);
check("the inventory row exists",
  await num(`select count(*) from identity_inventory where user_id=1 and item='vaporwave'`) === 1);

// Every alert on screen, not just the first: flarum/pusher is installed without
// an app key on this instance and puts its own failure alert up on boot, so
// "the first alert" is somebody else's message.
const alertText = await browser.eval(`[...document.querySelectorAll('.Alert')].map(e => e.textContent.trim()).join(' | ')`);
check("the buyer is told it worked", /yours|Vaporwave/i.test(alertText || ""), alertText);

const onScreen = await browser.eval(`(() => { const e = document.querySelector('.StoreBar-n'); return e ? Number(e.textContent.replace(/[^0-9]/g,'')) : null; })()`);
check("the balance on screen updated without a reload", onScreen === after, `${onScreen} vs ${after}`);
await browser.shot("03-store-after-purchase");

// ----------------------------------------------------------------- history
console.log("\n== the other screens ==");
await browser.goto(BASE + "/store/orders");
await Bun.sleep(1200);
const rows = await browser.eval(`document.querySelectorAll('.StoreTable tbody tr').length`);
check("the order history lists real orders", rows > 0, `${rows} rows`);
const stateChips = await browser.eval(`[...document.querySelectorAll('.StoreState')].map(e => e.textContent.trim()).slice(0,6)`);
check("each order shows its state", (stateChips || []).length > 0, JSON.stringify(stateChips));
await browser.shot("04-orders-desktop");

await browser.goto(BASE + "/store/inventory");
await Bun.sleep(1200);
const sections = await browser.eval(`[...document.querySelectorAll('.StoreSection-title')].map(e => e.textContent.trim())`);
check("the inventory page groups what you hold", (sections || []).length === 3, JSON.stringify(sections));
await browser.shot("05-inventory-desktop");

await browser.goto(BASE + "/store/admin");
await Bun.sleep(1500);
const stats = await browser.eval(`document.querySelectorAll('.StoreStat').length`);
check("the admin screen shows the store's numbers", stats >= 6, `${stats} stats`);
const adminRows = await browser.eval(`document.querySelectorAll('.StoreTable--admin tbody tr').length`);
check("the admin screen lists the catalogue", adminRows > 10, `${adminRows} items`);
await browser.shot("06-admin-desktop");

// -------------------------------------------------------------- phone width
console.log("\n== phone ==");
await browser.width(390, 1800);
for (const [path, name] of [["/store", "07-store-phone"], ["/store/orders", "08-orders-phone"], ["/store/inventory", "09-inventory-phone"]] as const) {
  await browser.goto(BASE + path);
  await Bun.sleep(1200);
  await browser.shot(name);
}

await browser.goto(BASE + "/store");
await Bun.sleep(900);

const overflow = await browser.eval(`(() => {
  const w = document.documentElement.clientWidth;
  const wide = [...document.querySelectorAll('.StorePage *')].filter(e => e.getBoundingClientRect().right > w + 2);
  return wide.slice(0, 3).map(e => e.className + ':' + Math.round(e.getBoundingClientRect().right));
})()`);
check("nothing overflows the phone viewport", (overflow || []).length === 0, JSON.stringify(overflow));

const cardWidth = await browser.eval(`(() => { const c = document.querySelector('.StoreCard'); return c ? Math.round(c.getBoundingClientRect().width) : null; })()`);
check("cards use the full phone width", cardWidth !== null && cardWidth > 300, cardWidth);

// ------------------------------------------------------------------- guests
console.log("\n== a signed-out visitor ==");
await browser.width(1440);
await browser.eval(`fetch('/logout?token=' + (app.session.csrfToken || ''), {method: 'GET'}).then(r => r.status)`, true).catch(() => null);
await browser.send("Network.clearBrowserCookies");
await browser.goto(BASE + "/store");
await Bun.sleep(800);
const guestCards = await browser.eval(`document.querySelectorAll('.StoreCard').length`);
check("a guest can still browse the catalogue", guestCards > 0, `${guestCards} cards`);
const guestPrompt = await browser.eval(`(() => { const e = document.querySelector('.StoreBar--guest'); return e ? e.textContent.trim() : null; })()`);
check("a guest is asked to sign in rather than shown a balance of zero", !!guestPrompt, guestPrompt);
await browser.shot("10-store-guest");

// -------------------------------------------------------------------- health
console.log("\n== page health ==");
// Console errors this extension is responsible for. The instance also throws
// two that predate the store and belong to other lanes — flarum/pusher is
// enabled with no app key, and core's Pusher module dynamic-imports a CDN URL
// that cannot resolve — so they are listed rather than silently swallowed.
const foreign = consoleErrors.filter((e) => /pusher/i.test(e));
const ours = consoleErrors.filter((e) => !/pusher/i.test(e));
check("no console errors from the store", ours.length === 0, ours.slice(0, 3).join(" | "));
if (foreign.length) console.log(`  note: ${foreign.length} console error(s) from flarum/pusher, not the store`);
// Tunnel drops are not store defects; anything else is.
const flaky = failedRequests.filter((r) => /ERR_NETWORK_CHANGED|ERR_CONNECTION_RESET/.test(r));
const realFailures = failedRequests.filter((r) => !/ERR_NETWORK_CHANGED|ERR_CONNECTION_RESET/.test(r));
check("no failed requests other than tunnel drops", realFailures.length === 0, realFailures.slice(0, 3).join(" | "));
if (flaky.length) console.log(`  note: ${flaky.length} request(s) lost to the cloudflared tunnel dropping`);

await browser.send("Target.closeTarget", { targetId }).catch(() => {});
try { proc.kill(); } catch {}

const failed = checks.filter((c) => !c.ok);
console.log(`\n${checks.length - failed.length}/${checks.length} checks passed`);
if (failed.length) for (const f of failed) console.log(`  - ${f.name}${f.detail ? ` (${f.detail})` : ""}`);
writeFileSync(`${SHOTS}/results.json`, JSON.stringify({ checks, consoleErrors, failedRequests }, null, 2));
console.log(`screenshots: ${SHOTS}`);
process.exit(failed.length ? 1 : 0);
