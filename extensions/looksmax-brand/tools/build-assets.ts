#!/usr/bin/env bun
/**
 * Rasterise the brand.
 *
 * Every PNG and the .ico come from the SVG masters in assets/, through the
 * same renderer a browser uses, so what ships is what a browser would have
 * drawn. Two things this gets right that a naive `magick convert icon.svg`
 * does not:
 *
 *  - ImageMagick rasterises SVG through its own internal renderer (or through
 *    a delegate that may not be installed), which ignores fill-rule="evenodd"
 *    in some builds and silently fills the cut graduations back in. Chromium
 *    is the renderer that matters; it is also the one we can verify against.
 *  - the 16 and 32px favicons use icon-small.svg, not a downscale of the 512px
 *    artwork. A logo drawn once and resampled to 16px is mush; this is the
 *    optical-size problem, and the fix is a second drawing, not a better
 *    filter.
 *
 * Alpha is preserved: Chromium's default white page background is overridden
 * to transparent, otherwise every "transparent" PNG ships with a white box
 * behind it, which is invisible on a white page and obvious on a dark one.
 *
 *   bun tools/build-assets.ts [assets-dir] [design-dir]
 */
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";

const ASSETS = resolve(process.argv[2] || "assets");
const DESIGN = resolve(process.argv[3] || "design");
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21966);
mkdirSync(ASSETS, { recursive: true });

type Job = { svg: string; size: number; out: string; note: string };
const JOBS: Job[] = [
  // favicons — the small ones from the simplified drawing
  { svg: "icon-small.svg", size: 16, out: "favicon-16.png", note: "tab" },
  { svg: "icon-small.svg", size: 32, out: "favicon-32.png", note: "retina tab, bookmarks" },
  { svg: "icon.svg", size: 48, out: "favicon-48.png", note: "windows taskbar" },
  { svg: "icon.svg", size: 64, out: "favicon-64.png", note: "ico top size" },
  // PWA
  { svg: "icon.svg", size: 192, out: "icon-192.png", note: "manifest any" },
  { svg: "icon.svg", size: 512, out: "icon-512.png", note: "manifest any, splash" },
  { svg: "icon-maskable.svg", size: 192, out: "icon-maskable-192.png", note: "manifest maskable" },
  { svg: "icon-maskable.svg", size: 512, out: "icon-maskable-512.png", note: "manifest maskable" },
  // iOS: no alpha, no rounding of our own
  { svg: "icon-apple.svg", size: 180, out: "apple-touch-icon.png", note: "ios home screen" },
  { svg: "icon-apple.svg", size: 167, out: "apple-touch-icon-167.png", note: "ipad pro" },
  { svg: "icon-apple.svg", size: 152, out: "apple-touch-icon-152.png", note: "ipad" },
  // the mark alone, for anything that wants a bitmap logo
  { svg: "mark-brass.svg", size: 512, out: "mark-512.png", note: "bitmap mark" },
];

/** Pages rendered at a fixed frame size rather than scaled from a square SVG. */
const PAGES = [
  { html: "og.html", out: "og.png", w: 1200, h: 630, scale: 1, note: "open graph / twitter summary_large_image" },
  { html: "email-header.html", out: "email-header.png", w: 600, h: 104, scale: 2, note: "transactional mail band, 2x" },
];

// ------------------------------------------------------------------- browser
const proc = Bun.spawn([CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox",
  "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1", "--allow-file-access-from-files",
  `--user-data-dir=/tmp/lmx-build-${process.pid}`], { stdout: "ignore", stderr: "ignore" });

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

async function capture(url: string, w: number, h: number, scale: number, out: string, transparent: boolean) {
  await send("Emulation.setDeviceMetricsOverride", { width: w, height: h, deviceScaleFactor: scale, mobile: false });
  await send("Emulation.setDefaultBackgroundColorOverride",
    transparent ? { color: { r: 0, g: 0, b: 0, a: 0 } } : {});
  await send("Page.navigate", { url });
  await Bun.sleep(transparent ? 500 : 1800);
  const r = await send("Page.captureScreenshot", {
    format: "png", captureBeyondViewport: true,
    clip: { x: 0, y: 0, width: w, height: h, scale },
  });
  if (!r?.result?.data) throw new Error(`no image for ${out}`);
  writeFileSync(out, Buffer.from(r.result.data, "base64"));
}

// ------------------------------------------------------------------- squares
// One page per size: the SVG is inlined so fill-rule and currentColor resolve
// against the document, which they do not when an SVG is loaded through <img>
// from a file:// URL in some Chromium builds.
const tmp = join(DESIGN, "_raster.html");
for (const j of JOBS) {
  const src = readFileSync(join(ASSETS, j.svg), "utf8")
    .replace(/width="[\d.]+"/, `width="${j.size}"`)
    .replace(/height="[\d.]+"/, `height="${j.size}"`);
  writeFileSync(tmp, `<!doctype html><meta charset=utf-8>`
    + `<style>html,body{margin:0;padding:0;background:transparent}svg{display:block}</style>${src}`);
  await capture(`file://${tmp}`, j.size, j.size, 1, join(ASSETS, j.out), true);
  console.log(`  ${j.out.padEnd(28)} ${String(j.size).padStart(4)}px  ${j.note}`);
}

for (const p of PAGES) {
  await capture(`file://${join(DESIGN, p.html)}`, p.w, p.h, p.scale, join(ASSETS, p.out), false);
  console.log(`  ${p.out.padEnd(28)} ${p.w * p.scale}x${p.h * p.scale}  ${p.note}`);
}
ws.close();
proc.kill();

// ----------------------------------------------------------------------- ico
// favicon.ico still matters: it is what a crawler, an RSS reader and every
// Windows shortcut asks for, and several of them never look at <link> tags.
// Multi-resolution so the OS picks the drawing made for the size it wants.
const ico = join(ASSETS, "favicon.ico");
const parts = ["favicon-16.png", "favicon-32.png", "favicon-48.png", "favicon-64.png"].map((f) => join(ASSETS, f));
const conv = Bun.spawnSync(["magick", ...parts, "-colors", "256", ico]);
if (conv.exitCode !== 0) {
  console.error("magick failed:", new TextDecoder().decode(conv.stderr));
  process.exit(1);
}
const idn = Bun.spawnSync(["magick", "identify", ico]);
console.log(`  favicon.ico`);
for (const line of new TextDecoder().decode(idn.stdout).trim().split("\n")) console.log(`      ${line}`);

// -------------------------------------------------------------- webmanifest
// Written here rather than by hand so the icon list cannot drift from the
// files that were actually produced.
const manifest = {
  name: "Looksmax.lat",
  short_name: "Looksmax",
  description: "Face and body ratings, and the guides behind them.",
  start_url: "/",
  scope: "/",
  display: "standalone",
  orientation: "portrait-primary",
  background_color: "#0e1116",
  theme_color: "#0b0e14",
  icons: [
    { src: "icon-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
    { src: "icon-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
    { src: "icon-maskable-192.png", sizes: "192x192", type: "image/png", purpose: "maskable" },
    { src: "icon-maskable-512.png", sizes: "512x512", type: "image/png", purpose: "maskable" },
    { src: "icon.svg", sizes: "any", type: "image/svg+xml", purpose: "any" },
  ],
};
writeFileSync(join(ASSETS, "site.webmanifest"), JSON.stringify(manifest, null, 2) + "\n");
console.log("  site.webmanifest");

for (const f of ["favicon.ico", "og.png", "site.webmanifest", "apple-touch-icon.png"]) {
  if (!existsSync(join(ASSETS, f))) throw new Error(`missing ${f}`);
}
console.log("build-assets: ok");
