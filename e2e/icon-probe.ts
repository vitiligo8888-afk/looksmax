#!/usr/bin/env bun
/**
 * Icon rendering probe.
 *
 * <iconify-icon> is a web component that fetches its geometry at runtime. Until
 * that resolves — or forever, if the script or the API is blocked — the element
 * has NO intrinsic size, so any flex or grid parent stretches it to whatever
 * space is going. The symptom the operator sees is "a weird stretched rotating
 * big icon"; the cause is an unsized replaced element, not a wrong glyph.
 *
 * This reports, per surface: whether the custom element is defined at all, how
 * many icons resolved to real SVG geometry, and every icon whose rendered box is
 * absurd (non-square, or far off its font-size), which is what stretching looks
 * like when you measure it instead of squinting at it.
 */
const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21993);
const PATHS = (process.env.PROBE_PATHS || "/,/all").split(",").filter(Boolean);


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
   "--hide-scrollbars", "--window-size=1440,1000", `--user-data-dir=/tmp/lmx-icon-${process.pid}`],
  { stdout: "ignore", stderr: "ignore", detached: true },
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
  throw new Error("no page target");
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
await send("Network.enable");

const failed: string[] = [];
ws.addEventListener("message", (e) => {
  const m = JSON.parse(String(e.data));
  if (m.method === "Network.loadingFailed") failed.push(String(m.params?.errorText));
  if (m.method === "Network.responseReceived") {
    const u = m.params?.response?.url || "";
    if (/iconify/i.test(u)) failed.push(`iconify-response ${m.params.response.status} ${u.slice(0, 90)}`);
  }
});

const evalJs = async (expr: string) => {
  const r = await send("Runtime.evaluate", { expression: expr, awaitPromise: true, returnByValue: true });
  return r?.result?.result?.value;
};

const PROBE = `(() => {
  const px = (n) => Math.round(n * 10) / 10;
  const out = { defined: !!customElements.get('iconify-icon'), total: 0, resolved: 0, empty: 0, bad: [] };
  document.querySelectorAll('iconify-icon').forEach((el) => {
    out.total++;
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    const fs = parseFloat(cs.fontSize) || 0;
    // A resolved icon has an <svg> in its shadow root.
    const hasSvg = !!(el.shadowRoot && el.shadowRoot.querySelector('svg'));
    if (hasSvg) out.resolved++;
    if (r.width === 0 || r.height === 0) out.empty++;
    const ratio = r.height ? r.width / r.height : 0;
    const oversize = fs > 0 && (r.width > fs * 2.2 || r.height > fs * 2.2);
    if ((ratio && (ratio > 1.35 || ratio < 0.74)) || oversize) {
      out.bad.push({
        icon: el.getAttribute('icon'), w: px(r.width), h: px(r.height), fontSize: fs,
        ratio: px(ratio), resolved: hasSvg,
        parent: el.parentElement ? el.parentElement.tagName.toLowerCase() +
          '.' + String(el.parentElement.className || '').split(/\\s+/).slice(0,2).join('.') : null,
        parentDisplay: el.parentElement ? getComputedStyle(el.parentElement).display : null,
      });
    }
  });
  // Font Awesome fallbacks that never got mapped render as a blank box too.
  out.faLeft = document.querySelectorAll('i.fas, i.far, i.fab').length;
  return out;
})()`;

for (const path of PATHS) {
  await send("Page.navigate", { url: `${BASE}${path}` });
  await Bun.sleep(5000);
  const r = await evalJs(PROBE);
  console.log(`\n=== ${path}`);
  console.log(`  custom element defined: ${r.defined}`);
  console.log(`  iconify-icon elements : ${r.total}  resolved: ${r.resolved}  zero-size: ${r.empty}`);
  console.log(`  leftover <i class=fa*>: ${r.faLeft}`);
  console.log(`  misshapen             : ${r.bad.length}`);
  for (const b of r.bad.slice(0, 12)) {
    console.log(`    ${String(b.icon).padEnd(34)} ${b.w}x${b.h} (fs ${b.fontSize}, ratio ${b.ratio}, resolved=${b.resolved}) in ${b.parent} [${b.parentDisplay}]`);
  }
}
if (failed.length) {
  console.log("\nnetwork notes:");
  for (const f of [...new Set(failed)].slice(0, 10)) console.log("  " + f);
}
ws.close();
cleanup(proc, `/tmp/lmx-icon-probe-${process.pid}`);
