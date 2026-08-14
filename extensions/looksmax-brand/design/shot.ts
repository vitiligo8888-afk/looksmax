#!/usr/bin/env bun
/**
 * Screenshot a local HTML file or URL at full content height.
 *
 * The one primitive every other look-at-it step in this lane needs: brand
 * sheets, the OG image, the e-mail header, the favicon previews. Kept separate
 * from e2e/look.ts, which owns the forum surfaces and belongs to a different
 * job.
 *
 *   bun design/shot.ts <file-or-url> <out.png> [width] [height] [scale]
 *
 * A fixed height crops instead of growing, which is what a fixed-size artwork
 * (1200x630 OG, 600px e-mail header) needs.
 */
import { writeFileSync } from "node:fs";
import { resolve } from "node:path";

const [target, out, wArg, hArg, sArg] = process.argv.slice(2);
if (!target || !out) throw new Error("usage: shot.ts <file-or-url> <out.png> [w] [h] [scale]");
const URL_ = /^https?:\/\//.test(target) ? target : `file://${resolve(target)}`;
const W = Number(wArg || 1200);
const H = hArg ? Number(hArg) : 0;
const SCALE = Number(sArg || 1);
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21955);

const proc = Bun.spawn([CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox",
  "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1", "--allow-file-access-from-files",
  `--user-data-dir=/tmp/lmx-shot-${process.pid}`], { stdout: "ignore", stderr: "ignore" });

async function endpoint(): Promise<string> {
  for (let i = 0; i < 80; i++) {
    try {
      const list = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()) as any[];
      const p = list.find((t) => t.type === "page" && t.webSocketDebuggerUrl);
      if (p) return p.webSocketDebuggerUrl;
    } catch {}
    await Bun.sleep(200);
  }
  throw new Error("no page target");
}
const ws = new WebSocket(await endpoint());
await new Promise((r) => (ws.onopen = r));
let id = 0;
const pending = new Map<number, (v: any) => void>();
ws.onmessage = (e) => { const m = JSON.parse(String(e.data)); if (m.id && pending.has(m.id)) { pending.get(m.id)!(m); pending.delete(m.id); } };
const send = (m: string, p: any = {}) => new Promise<any>((res) => { const n = ++id; pending.set(n, res); ws.send(JSON.stringify({ id: n, method: m, params: p })); });

await send("Page.enable");
await send("Emulation.setDeviceMetricsOverride", { width: W, height: H || 900, deviceScaleFactor: SCALE, mobile: false });
await send("Page.navigate", { url: URL_ });
await Bun.sleep(2200);
let width = W, height = H;
if (!H) {
  const cs = (await send("Page.getLayoutMetrics"))?.result?.cssContentSize;
  width = Math.ceil(cs?.width || W);
  height = Math.ceil(cs?.height || 900);
}
const r = await send("Page.captureScreenshot", {
  format: "png", captureBeyondViewport: true,
  clip: { x: 0, y: 0, width, height, scale: SCALE },
});
if (!r?.result?.data) throw new Error("no image returned");
writeFileSync(out, Buffer.from(r.result.data, "base64"));
console.log(`${out} ${width * SCALE}x${height * SCALE}`);
ws.close(); proc.kill();
