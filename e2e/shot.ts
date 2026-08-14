#!/usr/bin/env bun
/**
 * Screenshot an arbitrary list of paths, optionally logged in.
 *
 * look.ts has a fixed surface list; this one takes paths on the command line,
 * which is what you want when chasing a specific defect an operator reported on
 * a specific page. Also supports hovering a selector and clipping to an element
 * so a card can be inspected at its real size instead of as a speck in a
 * full-page shot.
 *
 *   bun e2e/shot.ts /u/somebody /d/329 --out /work/flarum/shots-adhoc
 *   bun e2e/shot.ts /u/somebody --clip .LmxProfile --login admin:PASS
 */
import { mkdirSync, writeFileSync } from "node:fs";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21988);

const argv = process.argv.slice(2);
const flag = (n: string, d?: string) => { const i = argv.indexOf(`--${n}`); return i === -1 ? d : argv[i + 1]; };
const paths = argv.filter((a, i) => !a.startsWith("--") && !(i > 0 && argv[i - 1].startsWith("--")));
const OUT = flag("out", "/work/flarum/shots-adhoc")!;
const WIDTH = Number(flag("width", "1440"));
const HEIGHT = Number(flag("height", "1200"));
const CLIP = flag("clip");
const HOVER = flag("hover");
const LOGIN = flag("login"); // user:pass
const SCROLL = Number(flag("scroll", "0"));

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

const proc = Bun.spawn(
  [CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox", "--disable-gpu",
   "--hide-scrollbars", `--window-size=${WIDTH},${HEIGHT}`, `--user-data-dir=/tmp/lmx-shot-${process.pid}`],
  { stdout: "ignore", stderr: "ignore", detached: true },
);

async function endpoint(): Promise<string> {
  for (let i = 0; i < 60; i++) {
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

if (LOGIN) {
  const [u, p] = LOGIN.split(":");
  await send("Page.navigate", { url: BASE });
  await Bun.sleep(3500);
  // The API sets the session cookie; driving the modal is slower and flakier.
  // The CSRF token is REQUIRED — without it /login answers 400
  // `csrf_token_mismatch` and every "logged in" screenshot is silently a
  // logged-out one, which is how a members-only fatal error survived a
  // screenshot pass. Flarum publishes the token on `app.session`, and also in
  // the boot payload for the pre-boot case.
  // Retried, because the token is only present once the boot payload has been
  // parsed. A cache:clear makes the first render slow enough that a fixed wait
  // loses the race, the token comes back empty, /login answers 400, and the
  // screenshot is silently logged out — which is exactly how a members-only
  // fatal error survived an earlier pass.
  const ok = await evalJs(`(async () => {
    const token = () => (window.app && app.session && app.session.csrfToken)
      || (document.querySelector('meta[name="csrf-token"]') || {}).content
      || (String(document.documentElement.outerHTML).match(/"csrfToken":"([^"]+)"/) || [])[1];
    let tok = null;
    for (let i = 0; i < 30 && !tok; i++) {
      tok = token();
      if (!tok) await new Promise((r) => setTimeout(r, 500));
    }
    if (!tok) return 'no-csrf-after-15s';
    const r = await fetch('${BASE}/login', {
      method: 'POST', credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
      body: JSON.stringify({ identification: ${JSON.stringify(u)}, password: ${JSON.stringify(p)} }),
    });
    return String(r.status);
  })()`);
  if (String(ok) !== "200") { console.log(`  ! login did not succeed (${ok}) — the shots below are LOGGED OUT`); }
  console.log(`  login ${u}: HTTP ${ok}`);
  await send("Page.navigate", { url: BASE });
  await Bun.sleep(3000);
}

for (const path of paths) {
  await send("Page.navigate", { url: `${BASE}${path}` });
  await Bun.sleep(4200);
  if (HOVER) {
    const box = await evalJs(
      `(() => { const e = document.querySelector(${JSON.stringify(HOVER)}); if (!e) return null;
        const r = e.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; })()`,
    );
    if (box) {
      await send("Input.dispatchMouseEvent", { type: "mouseMoved", x: box.x, y: box.y });
      await Bun.sleep(1200);
    } else console.log(`  ! hover target ${HOVER} not present on ${path}`);
  }
  if (SCROLL) { await evalJs(`window.scrollTo(0, ${SCROLL})`); await Bun.sleep(1000); }

  let params: any = { format: "png" };
  if (CLIP) {
    const r = await evalJs(
      `(() => { const e = document.querySelector(${JSON.stringify(CLIP)}); if (!e) return null;
        const b = e.getBoundingClientRect();
        return { x: b.x, y: b.y + window.scrollY, width: b.width, height: b.height, scale: 2 }; })()`,
    );
    if (r && r.width) params.clip = r;
    else console.log(`  ! clip target ${CLIP} not found on ${path}`);
  }
  const shot = await send("Page.captureScreenshot", params);
  const data = shot?.result?.data;
  if (!data) { console.log(`  ! ${path}: no image returned`); continue; }
  const name = (path.replace(/[^a-z0-9]+/gi, "-").replace(/^-|-$/g, "") || "root") + `-${WIDTH}.png`;
  writeFileSync(`${OUT}/${name}`, Buffer.from(data, "base64"));
  console.log(`  shot ${OUT}/${name}`);
}

ws.close();
cleanup(proc, `/tmp/lmx-shot-${process.pid}`);
