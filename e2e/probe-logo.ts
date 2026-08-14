#!/usr/bin/env bun
/**
 * Why is the header logo a broken image?
 *
 * The file is well-formed SVG and the url answers 200 with the right
 * content-type when curled from the host, yet Chromium paints the broken-image
 * glyph. So the answer is in the BROWSER's network layer, not in the file:
 * this records every failed request with its error text and then reads the
 * <img> element's own load state.
 */
const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21778);

const proc = Bun.spawn([
  CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox",
  "--disable-gpu", "--window-size=1440,900", `--user-data-dir=/tmp/lmx-probe-${process.pid}`,
], { stdout: "ignore", stderr: "ignore" });

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
const failures: string[] = [];
ws.onmessage = (e) => {
  const m = JSON.parse(String(e.data));
  if (m.id && pending.has(m.id)) { pending.get(m.id)!(m); pending.delete(m.id); return; }
  if (m.method === "Network.loadingFailed") {
    failures.push(`${m.params.errorText}${m.params.blockedReason ? ` blocked=${m.params.blockedReason}` : ""} type=${m.params.type} id=${m.params.requestId}`);
  }
  if (m.method === "Network.requestWillBeSent") {
    (globalThis as any).__urls ??= new Map();
    (globalThis as any).__urls.set(m.params.requestId, m.params.request.url);
  }
};
const send = (method: string, params: any = {}) =>
  new Promise<any>((res) => { const n = ++id; pending.set(n, res); ws.send(JSON.stringify({ id: n, method, params })); });

await send("Network.enable");
await send("Page.enable");
await send("Runtime.enable");
await send("Page.navigate", { url: BASE });
await Bun.sleep(6000);

const r = await send("Runtime.evaluate", {
  expression: `(() => {
    const i = document.querySelector('.Header-logo, img[class*=logo]');
    if (!i) return { found: false };
    return { found: true, src: i.currentSrc || i.src, complete: i.complete,
             naturalWidth: i.naturalWidth, naturalHeight: i.naturalHeight,
             cssWidth: getComputedStyle(i).width, cssHeight: getComputedStyle(i).height,
             display: getComputedStyle(i).display };
  })()`,
  returnByValue: true,
});
console.log("logo element:", JSON.stringify(r?.result?.result?.value, null, 2));

const urls = (globalThis as any).__urls ?? new Map();
console.log(`\nfailed requests (${failures.length}):`);
for (const f of failures) {
  const rid = f.match(/id=(\S+)$/)?.[1];
  console.log(`  ${f}\n    url=${urls.get(rid) ?? "?"}`);
}

ws.close();
proc.kill();
