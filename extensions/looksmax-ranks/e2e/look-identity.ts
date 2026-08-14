#!/usr/bin/env bun
/**
 * Signed-in screenshot sweep for the identity chrome.
 *
 * /work/flarum/e2e/look.ts shoots the logged-out forum, and the points chip,
 * the tier chips and the trophy case only exist for a signed-in reader — so the
 * one surface most likely to collide with the header furniture was the one
 * surface nothing was looking at. This logs in for real and shoots the header
 * cluster at several viewport widths AND several balance magnitudes, because a
 * chip that fits at "0" is a chip that overlaps at "1,284,930".
 *
 * Makes no assertions. The output is pixels, for a human to look at.
 *
 *   bun e2e/look-identity.ts
 *   bun e2e/look-identity.ts --width 420
 */
import { mkdirSync, writeFileSync } from "node:fs";

const BASE = process.env.FORUM_URL || "https://colleague-eligibility-workers-slides.trycloudflare.com";
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const OUT = process.env.SHOT_DIR || "/work/flarum/look-identity";
const PORT = Number(process.env.CDP_PORT || 21811);
const ADMIN_USER = process.env.ADMIN_USER || "admin";

const argv = process.argv.slice(2);
const flag = (n: string, d?: any) => { const i = argv.indexOf(`--${n}`); return i === -1 ? d : argv[i + 1]; };
const WIDTHS: number[] = flag("width") ? [Number(flag("width"))] : [1440, 1024, 768, 420];

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

mkdirSync(OUT, { recursive: true });

const proc = Bun.spawn([
  CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox",
  "--disable-gpu", "--hide-scrollbars", "--window-size=1440,1000",
  `--user-data-dir=/tmp/lmx-look-identity-${process.pid}`,
], { stdout: "ignore", stderr: "ignore" });

async function endpoint(): Promise<string> {
  for (let i = 0; i < 50; i++) {
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
const send = (method: string, params: any = {}) =>
  new Promise<any>((res) => { const i = ++id; pending.set(i, res); ws.send(JSON.stringify({ id: i, method, params })); });

const evaluate = async (expr: string, awaitPromise = false) => {
  const r = await send("Runtime.evaluate", { expression: expr, awaitPromise, returnByValue: true });
  return r.result?.result?.value;
};

await send("Page.enable");
await send("Runtime.enable");
await send("Network.setUserAgentOverride", {
  userAgent: "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36",
});

async function goto(url: string, wait = 3200) {
  await send("Page.navigate", { url });
  await Bun.sleep(wait);
}

async function shoot(name: string, clip?: string) {
  let params: any = { format: "png", captureBeyondViewport: false };
  if (clip) {
    const box = await evaluate(
      `(() => { const e = document.querySelector(${JSON.stringify(clip)}); if (!e) return null;
        const r = e.getBoundingClientRect();
        return { x: Math.max(0, r.x - 12), y: Math.max(0, r.y - 10), width: r.width + 24, height: r.height + 20 }; })()`
    );
    if (!box || box.width < 4) { console.log(`  skip ${name} (no ${clip})`); return; }
    params.clip = { ...box, scale: 3 };
  }
  const r = await send("Page.captureScreenshot", params);
  if (r?.result?.data) {
    writeFileSync(`${OUT}/${name}.png`, Buffer.from(r.result.data, "base64"));
    console.log(`  shot ${name}`);
  }
}

// ------------------------------------------------------------------- sign in
const adminPass = await sh(["sh", "-c", "grep ^ADMIN_PASS /work/flarum/.env | cut -d= -f2"]);
await goto(BASE, 3500);
const status = await evaluate(
  `(() => { const t = JSON.parse(document.getElementById('flarum-json-payload').textContent).session.csrfToken;
     return fetch('/login', {method:'POST', credentials:'same-origin',
       headers:{'Content-Type':'application/json','X-CSRF-Token':t},
       body: JSON.stringify({identification:${JSON.stringify(ADMIN_USER)}, password:${JSON.stringify(adminPass)}, remember:true})})
       .then(r => r.status); })()`, true);
console.log(`login -> ${status}`);

const adminId = await sql(`SELECT id FROM users WHERE username='${ADMIN_USER}';`);

// A chip that fits at "0" is a chip that overlaps at seven digits, and the
// abbreviation switches at 1k and 1M — every one of those is a different width.
const BALANCES = [0, 7, 940, 12400, 1284930];

for (const width of WIDTHS) {
  await send("Emulation.setDeviceMetricsOverride", { width, height: 1000, deviceScaleFactor: 1, mobile: width < 700 });

  for (const bal of BALANCES) {
    await sql(`UPDATE users SET points = ${bal} WHERE id = ${adminId};`);
    await goto(BASE, 3400);
    const tag = `header-w${width}-p${bal}`;
    await shoot(tag, ".App-header .Header-secondary");
    await shoot(`${tag}-full`);
  }

  // the post header cluster: rank chip, tier chip, badge chip, custom title
  const did = await sql(
    `SELECT d.id FROM discussions d JOIN users u ON u.id=d.user_id
     WHERE u.name_style IS NOT NULL AND u.custom_title IS NOT NULL ORDER BY d.comment_count DESC LIMIT 1;`);
  if (did) {
    await goto(`${BASE}/d/${did}`, 3800);
    await shoot(`post-header-w${width}`, ".Post-header");
    await shoot(`post-w${width}`, "article.Post");
  }
}

await send("Emulation.setDeviceMetricsOverride", { width: 1440, height: 1400, deviceScaleFactor: 1, mobile: false });
await goto(BASE, 3600);
await shoot("sidebar-chat", ".LmxChat");
await shoot("sidebar-leaders", ".LmxCard--leaders");

// and the logged-out header, where the username is absent entirely
await evaluate(`(() => { const t = JSON.parse(document.getElementById('flarum-json-payload').textContent).session.csrfToken;
  return fetch('/logout?token=' + t, {credentials:'same-origin'}).then(r=>r.status); })()`, true);
await goto(BASE, 3200);
await shoot("header-logged-out", ".App-header .Header-secondary");

await sql(`UPDATE users SET points = 200000 WHERE id = ${adminId};`);
try { proc.kill(); } catch {}
console.log(`\npngs in ${OUT}`);
