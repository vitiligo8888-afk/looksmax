#!/usr/bin/env bun
/**
 * Screenshot the header lockup as it is actually served by THIS box.
 *
 * Needed because `config.php` sets `'url' => 'https://looksmax.lat'`, so every
 * asset Flarum prints — the logo among them — is an absolute URL on the public
 * hostname. That hostname is this same origin behind Cloudflare, and Cloudflare
 * was serving a 517-second-old cached copy of `lockup.svg` (7404B, the previous
 * mark) while the origin already had the new one (29618B). Measured:
 *
 *   lockup.svg            -> 7404B  cf-cache-status: HIT   age: 517
 *   lockup.svg?bust=1     -> 29618B cf-cache-status: MISS
 *
 * A plain screenshot of the local site therefore shows the *old* mark, fetched
 * from the edge, and would have been read as "the deploy did not take". This
 * rewrites every same-brand asset URL onto the local origin with a cache
 * buster before shooting, so what is captured is what this box serves.
 *
 *   bun design/shot-header.ts <out-dir> [width]
 */
const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21999);
const OUT = process.argv[2] || "/work/flarum/shots-brandmark";
const WIDTH = Number(process.argv[3] || 1440);
const HEIGHT = Number(process.argv[4] || 900);

import { mkdirSync, writeFileSync } from "node:fs";
mkdirSync(OUT, { recursive: true });

const proc = Bun.spawn([CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox",
  "--disable-gpu", "--hide-scrollbars", `--window-size=${WIDTH},${HEIGHT}`,
  `--user-data-dir=/tmp/lmx-hdr-${process.pid}`], { stdout: "ignore", stderr: "ignore" });

async function endpoint(): Promise<string> {
  for (let i = 0; i < 80; i++) {
    try {
      const list = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()) as any[];
      const p = list.find((t) => t.type === "page" && t.webSocketDebuggerUrl);
      if (p) return p.webSocketDebuggerUrl;
    } catch {}
    await Bun.sleep(200);
  }
  throw new Error("chromium never exposed a page target");
}
const ws = new WebSocket(await endpoint());
await new Promise((r) => (ws.onopen = r));
let id = 0;
const pending = new Map<number, (v: any) => void>();
ws.onmessage = (e) => { const m = JSON.parse(String(e.data)); if (m.id && pending.has(m.id)) { pending.get(m.id)!(m); pending.delete(m.id); } };
const send = (m: string, p: any = {}) => new Promise<any>((res) => { const n = ++id; pending.set(n, res); ws.send(JSON.stringify({ id: n, method: m, params: p })); });

await send("Page.enable");
await send("Runtime.enable");
await send("Emulation.setDeviceMetricsOverride", { width: WIDTH, height: HEIGHT, deviceScaleFactor: 2, mobile: WIDTH < 700 });
await send("Page.navigate", { url: BASE });
await Bun.sleep(4000);

const rewritten = await send("Runtime.evaluate", {
  returnByValue: true,
  expression: `(() => {
    const n = Date.now();
    let c = 0;
    for (const img of document.querySelectorAll('img')) {
      if (/looksmax-brand/.test(img.src)) {
        img.src = img.src.replace(/^https?:\\/\\/[^/]+/, ${JSON.stringify(BASE)}) + '?bust=' + n;
        c++;
      }
    }
    return c;
  })()`,
});
console.log("  rewritten imgs:", rewritten?.result?.result?.value);
await Bun.sleep(1500);

const box = await send("Runtime.evaluate", {
  returnByValue: true,
  expression: `(() => { const e = document.querySelector('.App-header'); if (!e) return null;
    const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height }; })()`,
});
const clip = box?.result?.result?.value;

for (const [name, c] of [["header", clip], ["page", null]] as const) {
  const shot = await send("Page.captureScreenshot", {
    format: "png",
    ...(c ? { clip: { x: c.x, y: c.y, width: c.width, height: c.height, scale: 2 } } : { captureBeyondViewport: false }),
  });
  const f = `${OUT}/${name}-${WIDTH}.png`;
  writeFileSync(f, Buffer.from(shot.result.data, "base64"));
  console.log("  shot", f);
}
ws.close();
proc.kill();
