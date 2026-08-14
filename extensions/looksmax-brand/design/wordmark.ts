#!/usr/bin/env bun
/**
 * Wordmark comparison.
 *
 * Renders "Looksmax.lat" in each candidate face at the size it will actually
 * be used (a 26px header lockup and a 64px large lockup), next to the mark, on
 * the forum's own header surface. Judging a typeface from a specimen page at
 * 120px is how you end up with a header that looks nothing like the specimen.
 *
 *   bun design/wordmark.ts design/fonts design/out-word
 */
import { mkdirSync, readdirSync, writeFileSync, readFileSync } from "node:fs";
import { basename, join, resolve } from "node:path";

const FONTS = resolve(process.argv[2] || "design/fonts");
const OUT = resolve(process.argv[3] || "design/out-word");
const MARK = resolve(process.argv[4] || "design/explore5/m1.svg");
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21947);
mkdirSync(OUT, { recursive: true });

const faces = readdirSync(FONTS).filter((f) => f.endsWith(".ttf")).sort();
const mark = readFileSync(MARK, "utf8");

const rows = faces.map((f) => {
  const name = basename(f, ".ttf");
  const face = `@font-face{font-family:"${name}";src:url("file://${join(FONTS, f)}")}`;
  return { name, face };
});

// Two tracking values per face: logos are almost always tracked tighter than
// the same face set as UI text, and the difference at 26px is large.
const html = `<!doctype html><meta charset=utf-8><style>
${rows.map((r) => r.face).join("\n")}
body{margin:0;background:#0e1116;color:#eef2f7;font:13px/1.4 "DejaVu Sans",sans-serif;padding:24px}
table{border-collapse:collapse;width:100%}
td{padding:14px 16px;border-bottom:1px solid #232c39;vertical-align:middle}
.n{color:#8f9bad;font-size:12px;width:150px}
.lk{display:flex;align-items:center;gap:10px}
.lk svg{display:block;color:#e8c07d;flex:none}
.wm{color:#eef2f7;white-space:nowrap}
.dot{color:#e8c07d}
</style><body><table>
${rows.map((r) => `<tr>
  <td class="n">${r.name}</td>
  <td><div class="lk"><span style="width:26px;height:26px">${mark.replace(/width="\d+" height="\d+"/, 'width="26" height="26"')}</span>
      <span class="wm" style="font-family:'${r.name}';font-size:23px;letter-spacing:-0.02em">Looksmax<span class="dot">.</span>lat</span></div></td>
  <td><div class="lk"><span style="width:26px;height:26px">${mark.replace(/width="\d+" height="\d+"/, 'width="26" height="26"')}</span>
      <span class="wm" style="font-family:'${r.name}';font-size:23px;letter-spacing:-0.045em">Looksmax<span class="dot">.</span>lat</span></div></td>
  <td><div class="lk"><span style="width:56px;height:56px">${mark.replace(/width="\d+" height="\d+"/, 'width="56" height="56"')}</span>
      <span class="wm" style="font-family:'${r.name}';font-size:48px;letter-spacing:-0.035em">Looksmax<span class="dot">.</span>lat</span></div></td>
</tr>`).join("")}
</table></body>`;

const page = join(OUT, "_word.html");
writeFileSync(page, html);

const proc = Bun.spawn([CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox",
  "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1", "--allow-file-access-from-files",
  `--user-data-dir=/tmp/lmx-word-${process.pid}`], { stdout: "ignore", stderr: "ignore" });
async function endpoint(): Promise<string> {
  for (let i = 0; i < 60; i++) {
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
await send("Emulation.setDeviceMetricsOverride", { width: 1200, height: 900, deviceScaleFactor: 1, mobile: false });
await send("Page.navigate", { url: `file://${page}` });
await Bun.sleep(2500);
const cs = (await send("Page.getLayoutMetrics"))?.result?.cssContentSize || { width: 1200, height: 2000 };
const shot = await send("Page.captureScreenshot", { format: "png", captureBeyondViewport: true, clip: { x: 0, y: 0, width: Math.ceil(cs.width), height: Math.ceil(cs.height), scale: 1 } });
writeFileSync(join(OUT, "WORDMARK.png"), Buffer.from(shot.result.data, "base64"));
console.log(`${join(OUT, "WORDMARK.png")} ${Math.ceil(cs.width)}x${Math.ceil(cs.height)}`);
ws.close(); proc.kill();
