#!/usr/bin/env bun
/**
 * SVG -> true-size PNG -> magnified contact sheet.
 *
 * Two passes, and the second one is the point. A logo judged from a 512px
 * render is not judged at all; the sizes that decide whether a mark works are
 * 16px (browser tab) and 32px (retina tab, avatar). Pass 1 rasterises each mark
 * at its real pixel size through the same renderer a browser will use. Pass 2
 * blows those exact pixels up with nearest-neighbour so the aliasing is
 * visible instead of being re-rendered away by a second vector pass.
 *
 *   bun design/render.ts <svg-dir> <out-dir> [--fg '#e8c07d'] [--sizes 16,32,64,512]
 */
import { mkdirSync, readdirSync, writeFileSync, readFileSync } from "node:fs";
import { basename, join, resolve } from "node:path";

const argv = process.argv.slice(2);
const flag = (n: string, d?: string) => {
  const i = argv.indexOf(`--${n}`);
  return i === -1 ? d : argv[i + 1];
};
const positional = argv.filter((a, i) => !a.startsWith("--") && !(i > 0 && argv[i - 1].startsWith("--")));
const SRC = resolve(positional[0] || "design/explore");
const OUT = resolve(positional[1] || "design/out");
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21931);
const SIZES = (flag("sizes", "16,32,64,512") as string).split(",").map(Number);

/** Surfaces this forum actually paints on, plus plain white and plain black. */
const BACKGROUNDS: Record<string, string> = {
  dark: flag("bg", "#0e1116") as string,
  card: "#1a2029",
  white: "#ffffff",
};
const FOREGROUNDS: Record<string, string> = {
  dark: flag("fg", "#e8c07d") as string,
  card: flag("fg", "#e8c07d") as string,
  white: flag("fgLight", "#12161c") as string,
};

mkdirSync(OUT, { recursive: true });
const marks = readdirSync(SRC).filter((f) => f.endsWith(".svg")).sort();
if (!marks.length) throw new Error(`no svgs in ${SRC}`);

// ------------------------------------------------------------------ browser
const proc = Bun.spawn(
  [CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox", "--disable-gpu",
   "--hide-scrollbars", "--force-device-scale-factor=1", "--window-size=1600,1200",
   "--allow-file-access-from-files", `--user-data-dir=/tmp/lmx-render-${process.pid}`],
  { stdout: "ignore", stderr: "ignore" },
);

async function endpoint(): Promise<string> {
  for (let i = 0; i < 60; i++) {
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
ws.onmessage = (e) => {
  const m = JSON.parse(String(e.data));
  if (m.id && pending.has(m.id)) { pending.get(m.id)!(m); pending.delete(m.id); }
};
const send = (method: string, params: any = {}): Promise<any> =>
  new Promise((res) => { const n = ++id; pending.set(n, res); ws.send(JSON.stringify({ id: n, method, params })); });
await send("Page.enable");
await send("Runtime.enable");

const nav = async (file: string) => {
  await send("Page.navigate", { url: `file://${file}` });
  await Bun.sleep(700);
};
const shoot = async (clip: any, out: string) => {
  const r = await send("Page.captureScreenshot", {
    format: "png", captureBeyondViewport: true,
    clip: { ...clip, scale: 1 },
  });
  const data = r?.result?.data;
  if (!data) throw new Error(`no image for ${out}`);
  writeFileSync(out, Buffer.from(data, "base64"));
};

/**
 * The SVGs use currentColor throughout, so colour is applied by inlining them
 * into the page and setting `color` on the wrapper. An <img src="*.svg"> would
 * cut currentColor off from the document and render everything black.
 */
const svgSource = new Map(marks.map((m) => [m, readFileSync(join(SRC, m), "utf8")]));

// -------------------------------------------------------------- pass 1
type Cell = { mark: string; scheme: string; size: number; x: number; y: number; file: string };
const cells: Cell[] = [];
let html = `<!doctype html><meta charset=utf-8><style>
  html,body{margin:0;padding:0;background:#ff00ff}
  .c{position:absolute;display:block;line-height:0}
  .c svg{display:block}
</style><body>`;
let y = 0;
for (const scheme of Object.keys(BACKGROUNDS)) {
  for (const mark of marks) {
    let x = 0;
    for (const size of SIZES) {
      const file = join(OUT, `${basename(mark, ".svg")}-${scheme}-${size}.png`);
      cells.push({ mark, scheme, size, x, y, file });
      const src = svgSource.get(mark)!
        .replace(/width="\d+"/, `width="${size}"`)
        .replace(/height="\d+"/, `height="${size}"`);
      html += `<div class="c" style="left:${x}px;top:${y}px;width:${size}px;height:${size}px;`
        + `background:${BACKGROUNDS[scheme]};color:${FOREGROUNDS[scheme]}">${src}</div>`;
      x += size + 8;
    }
    y += Math.max(...SIZES) + 8;
  }
}
html += "</body>";
const page1 = join(OUT, "_pass1.html");
writeFileSync(page1, html);
await send("Emulation.setDeviceMetricsOverride", {
  width: 1400, height: 900, deviceScaleFactor: 1, mobile: false,
});
await nav(page1);
for (const c of cells) await shoot({ x: c.x, y: c.y, width: c.size, height: c.size }, c.file);
console.log(`pass 1: ${cells.length} true-size renders`);

// -------------------------------------------------------------- pass 2
// Magnified sheet. Nearest-neighbour on the pass-1 bitmaps, so what is on
// screen is the actual tab-sized pixel grid, not a fresh vector render.
const MAG = 220; // px each cell is blown up to
let sheet = `<!doctype html><meta charset=utf-8><style>
  body{margin:0;background:#0a0d12;color:#c8d2e0;font:12px/1.4 "DejaVu Sans",sans-serif;padding:20px}
  table{border-collapse:collapse} td,th{padding:6px 8px;text-align:center;vertical-align:bottom}
  th{color:#8f9bad;font-weight:600;text-transform:uppercase;letter-spacing:.08em;font-size:11px}
  .name{text-align:right;color:#e8c07d;font-weight:700;font-size:14px;padding-right:14px}
  img{image-rendering:pixelated;display:block;margin:0 auto;outline:1px solid #2f3b4c}
  .true{image-rendering:auto}
  h2{color:#eef2f7;font:600 15px/1 "DejaVu Sans",sans-serif;margin:26px 0 10px}
</style><body>`;
for (const scheme of Object.keys(BACKGROUNDS)) {
  sheet += `<h2>${scheme} &nbsp;<span style="color:#8f9bad;font-weight:400">${BACKGROUNDS[scheme]} / ${FOREGROUNDS[scheme]}</span></h2><table><tr><th></th>`;
  for (const s of SIZES) sheet += `<th>${s}px${s < 100 ? ` &times;${Math.round(MAG / s)}` : ""}</th>`;
  sheet += `<th>16px true</th></tr>`;
  for (const mark of marks) {
    const n = basename(mark, ".svg");
    sheet += `<tr><td class="name">${n}</td>`;
    for (const s of SIZES) {
      const px = s < 100 ? Math.round(MAG / s) * s : MAG;
      sheet += `<td><img src="${n}-${scheme}-${s}.png" style="width:${px}px;height:${px}px"></td>`;
    }
    sheet += `<td><img class="true" src="${n}-${scheme}-16.png" style="width:16px;height:16px"></td></tr>`;
  }
  sheet += `</table>`;
}
sheet += "</body>";
const page2 = join(OUT, "_sheet.html");
writeFileSync(page2, sheet);
await send("Emulation.setDeviceMetricsOverride", { width: 1500, height: 800, deviceScaleFactor: 1, mobile: false });
await nav(page2);
await Bun.sleep(600);
const metrics = await send("Page.getLayoutMetrics");
const cs = metrics?.result?.cssContentSize || { width: 1500, height: 2000 };
await shoot({ x: 0, y: 0, width: Math.ceil(cs.width), height: Math.ceil(cs.height) }, join(OUT, "SHEET.png"));
console.log(`pass 2: ${join(OUT, "SHEET.png")}  ${Math.ceil(cs.width)}x${Math.ceil(cs.height)}`);

ws.close();
proc.kill();
