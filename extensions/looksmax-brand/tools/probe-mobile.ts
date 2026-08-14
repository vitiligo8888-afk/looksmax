#!/usr/bin/env bun
/**
 * What the mobile header actually contains.
 *
 * On phones Flarum moves the whole header — logo included — into the slide-out
 * drawer, and the visible bar is a different set of elements. Reading the
 * desktop DOM tells you nothing about it, and a mobile screenshot tells you
 * the brand is missing without telling you where to put it back.
 *
 *   bun tools/probe-mobile.ts <url> [width]
 */
const URL_ = process.argv[2] || "http://127.0.0.1:8888";
const W = Number(process.argv[3] || 420);
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21988);

const proc = Bun.spawn([CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox",
  "--disable-gpu", "--hide-scrollbars", `--user-data-dir=/tmp/lmx-mob-${process.pid}`],
  { stdout: "ignore", stderr: "ignore" });
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
await send("Emulation.setDeviceMetricsOverride", { width: W, height: 800, deviceScaleFactor: 2, mobile: true });
await send("Page.navigate", { url: URL_ });
await Bun.sleep(6500);

const js = `(() => {
  const sels = ['#header', '.App-header', '.Header-title', '.Header-logo', '.App-titleControl',
                '.App-backControl', '.App-primaryControl', '.Drawer', '.Header-controls',
                '.Header-primary', '.Header-secondary', '.App-header-drawer'];
  const rows = [];
  for (const s of sels) {
    document.querySelectorAll(s).forEach((e) => {
      const r = e.getBoundingClientRect();
      const cs = getComputedStyle(e);
      rows.push([s, Math.round(r.x) + ',' + Math.round(r.y), Math.round(r.width) + 'x' + Math.round(r.height),
                 cs.display, cs.visibility, cs.position].join('  '));
    });
  }
  const bar = document.querySelector('.App-header');
  return { rows, barHtml: bar ? bar.outerHTML.slice(0, 900) : 'no .App-header' };
})()`;
const r = await send("Runtime.evaluate", { expression: js, returnByValue: true });
const v = r.result?.result?.value;
console.log((v?.rows || []).join("\n"));
console.log("\n--- .App-header ---\n" + (v?.barHtml || ""));
ws.close(); proc.kill();
