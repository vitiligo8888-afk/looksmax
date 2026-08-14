#!/usr/bin/env bun
/**
 * Screenshot sweep for eyeballing, not asserting.
 *
 * Separate from harness.ts and from e2e/visual/ (another lane owns those) on
 * purpose: this one makes no claims and fails nothing. It drives a real
 * Chromium over CDP, visits a list of surfaces, optionally hovers an element or
 * scrolls, and writes PNGs. The point is that somebody looks at the pixels —
 * every layout defect found on this project so far was invisible to DOM
 * assertions and visible immediately in a screenshot.
 *
 *   bun e2e/look.ts                       # all surfaces, desktop
 *   bun e2e/look.ts --width 420           # mobile widths
 */
import { mkdirSync, writeFileSync } from "node:fs";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const OUT = process.env.SHOT_DIR || "/work/flarum/look";
const PORT = Number(process.env.CDP_PORT || 21777);

const argv = process.argv.slice(2);
const flag = (n: string, d?: any) => { const i = argv.indexOf(`--${n}`); return i === -1 ? d : argv[i + 1]; };
const WIDTH = Number(flag("width", 1440));
const HEIGHT = Number(flag("height", 1000));

type Shot = {
  name: string;
  path: string;
  /** css selector to hover before shooting, to capture hover states */
  hover?: string;
  /** scroll this many px before shooting, to catch sticky-header layering */
  scroll?: number;
  wait?: number;
};

const SHOTS: Shot[] = [
  { name: "index", path: "/" },
  { name: "index-hover-start", path: "/", hover: ".IndexPage-newDiscussion, .Button--primary" },
  { name: "index-hover-forum", path: "/", hover: ".LmxForum" },
  { name: "all", path: "/all" },
  { name: "discussion", path: "__FIRST_DISCUSSION__" },
  { name: "discussion-scrolled", path: "__FIRST_DISCUSSION__", scroll: 900 },
  { name: "discussion-deep", path: "__FIRST_DISCUSSION__", scroll: 2600 },
];

mkdirSync(OUT, { recursive: true });


// Chromium's children (gpu-process, renderers, zygote) are NOT killed by killing
// the parent: they reparent to init and keep burning CPU forever. Measured on
// this box — 20 orphaned gpu-processes, five of them at 56-97% CPU, ~4 cores
// gone. Kill the process GROUP and remove the profile dir.
function cleanup(proc: any, profileDir: string) {
  try { process.kill(-proc.pid, "SIGKILL"); } catch {}
  try { proc.kill(); } catch {}
  try { Bun.spawnSync(["rm", "-rf", profileDir]); } catch {}
}

const proc = Bun.spawn([
  CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox",
  "--disable-gpu", "--hide-scrollbars", `--window-size=${WIDTH},${HEIGHT}`,
  // a fresh profile per run: a killed Chromium leaves a lock behind and the
  // next launch then fails instantly instead of rendering
  `--user-data-dir=/tmp/lmx-look-${process.pid}`,
], { stdout: "ignore", stderr: "ignore", detached: true });

/**
 * The endpoint from /json/version is the BROWSER target, which does not expose
 * the Page domain — captureScreenshot there silently returns nothing. The page
 * target from /json/list is the one to drive.
 */
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
const send = (method: string, params: any = {}): Promise<any> =>
  new Promise((res) => { const n = ++id; pending.set(n, res); ws.send(JSON.stringify({ id: n, method, params })); });

await send("Page.enable");
await send("Runtime.enable");
await send("Emulation.setDeviceMetricsOverride", { width: WIDTH, height: HEIGHT, deviceScaleFactor: 1, mobile: WIDTH < 700 });

const evalJs = async (expr: string) => {
  const r = await send("Runtime.evaluate", { expression: expr, awaitPromise: true, returnByValue: true });
  return r?.result?.result?.value;
};

// resolve the first real discussion so the post surfaces are real content
await send("Page.navigate", { url: `${BASE}/all` });
await Bun.sleep(4000);
const firstHref: string = (await evalJs(
  `(document.querySelector('.DiscussionListItem-main, a.DiscussionListItem-content') || {}).getAttribute
     ? (document.querySelector('.DiscussionListItem-main, a.DiscussionListItem-content')).getAttribute('href')
     : (document.querySelector('a[href^="/d/"]') || {getAttribute:()=>null}).getAttribute('href')`,
)) || "/all";

const console_errors: string[] = [];

for (const s of SHOTS) {
  const path = s.path === "__FIRST_DISCUSSION__" ? firstHref : s.path;
  await send("Page.navigate", { url: `${BASE}${path}` });
  await Bun.sleep(s.wait ?? 4200);

  if (s.hover) {
    // CDP hover needs real coordinates; read them from the element box
    const box = await evalJs(
      `(() => { const e = document.querySelector(${JSON.stringify(s.hover)}); if (!e) return null;
         const r = e.getBoundingClientRect(); return {x: r.x + r.width/2, y: r.y + r.height/2}; })()`,
    );
    if (box) {
      await send("Input.dispatchMouseEvent", { type: "mouseMoved", x: box.x, y: box.y });
      await Bun.sleep(900);
    } else {
      console.log(`  ! ${s.name}: hover target ${s.hover} not present`);
    }
  }

  if (s.scroll) {
    await evalJs(`window.scrollTo(0, ${s.scroll})`);
    await Bun.sleep(1200);
  }

  const shot = await send("Page.captureScreenshot", { format: "png" });
  const data = shot?.result?.data;
  if (!data) { console.log(`  ! ${s.name}: no image returned`); continue; }
  const file = `${OUT}/${s.name}-${WIDTH}.png`;
  writeFileSync(file, Buffer.from(data, "base64"));
  console.log(`  shot ${file}  (${path})`);
}

console.log(`\nfirst discussion used: ${firstHref}`);
ws.close();
cleanup(proc, `/tmp/lmx-look-${process.pid}`);
