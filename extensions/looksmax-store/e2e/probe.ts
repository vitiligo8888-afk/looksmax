#!/usr/bin/env bun
/**
 * A single-purpose probe for the one thing that cannot be checked from the
 * server: whether the head script managed to register the client route before
 * the app booted, and if not, why.
 */
// The forum's configured base URL, not 127.0.0.1.
//
// Flarum builds `app.forum.attribute('apiUrl')` from the configured URL, so a
// browser that loaded the page from 127.0.0.1 sends every XHR to the public
// origin instead — cross-origin, and refused. That is not a store bug (the
// same failure hits /api/tags and every other core request), but a suite that
// loads the wrong origin measures nothing. Read the real one.
const BASE = process.env.FORUM_URL || (await (async () => {
  const p = Bun.spawn(["docker", "exec", "flarum-app", "php", "-r", 'echo (require "/flarum/app/config.php")["url"];'], { stdout: "pipe" });
  await p.exited;
  return (await new Response(p.stdout).text()).trim() || "http://127.0.0.1:8888";
})());
const CDP_PORT = 21455;
const CHROME = "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";

const proc = Bun.spawn([
  CHROME, "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage",
  "--user-data-dir=/tmp/store-probe", `--remote-debugging-port=${CDP_PORT}`, "--remote-allow-origins=*",
], { stdout: "pipe", stderr: "pipe" });

let version: any = null;
for (let i = 0; i < 40; i++) {
  try { const r = await fetch(`http://127.0.0.1:${CDP_PORT}/json/version`); if (r.ok) { version = await r.json(); break; } } catch {}
  await Bun.sleep(400);
}

const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise<void>((res) => { ws.onopen = () => res(); });
let id = 0;
const pending = new Map<number, any>();
const logs: string[] = [];
let sessionId: string | undefined;
ws.onmessage = (ev) => {
  const m = JSON.parse(String(ev.data));
  if (m.id != null) { const p = pending.get(m.id); if (p) { pending.delete(m.id); m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result); } }
  else if (m.method === "Runtime.consoleAPICalled") logs.push(m.params.type + ": " + (m.params.args || []).map((a: any) => a.value ?? a.description).join(" "));
  else if (m.method === "Runtime.exceptionThrown") logs.push("throw: " + (m.params.exceptionDetails?.text || "") + " :: " + String(m.params.exceptionDetails?.exception?.description || m.params.exceptionDetails?.exception?.value || "").slice(0, 400));
};
const send = (method: string, params: any = {}) => new Promise<any>((res, rej) => {
  const i = ++id; pending.set(i, { res, rej });
  ws.send(JSON.stringify({ id: i, method, params, sessionId }));
  setTimeout(() => { if (pending.delete(i)) rej(new Error("timeout " + method)); }, 30000);
});

const t = await send("Target.createTarget", { url: "about:blank" });
const a = await send("Target.attachToTarget", { targetId: t.targetId, flatten: true });
sessionId = a.sessionId;
await send("Page.enable"); await send("Runtime.enable");

await send("Page.navigate", { url: BASE + (process.env.PROBE_PATH || "/store") });
await Bun.sleep(6000);

const evaluate = async (expr: string) => {
  const r = await send("Runtime.evaluate", { expression: expr, returnByValue: true });
  return r.exceptionDetails ? "EXC: " + r.exceptionDetails.text : r.result?.value;
};

console.log("path:            ", await evaluate("location.pathname"));
console.log("head script tag: ", await evaluate("!!document.querySelector('script[data-lmx-store]')"));
console.log("window.flarum:   ", await evaluate("typeof window.flarum"));
console.log("flarum.core:     ", await evaluate("!!(window.flarum && window.flarum.core)"));
console.log("compat keys:     ", await evaluate("Object.keys((window.flarum&&window.flarum.core&&window.flarum.core.compat)||{}).length"));
console.log("window.app:      ", await evaluate("typeof window.app"));
console.log("app.routes.store:", await evaluate("!!(window.app && app.routes && app.routes.store)"));
console.log("route names:     ", await evaluate("Object.keys((window.app&&app.routes)||{}).join(',')"));
console.log("Page component:  ", await evaluate("!!(window.flarum&&flarum.core.compat['common/components/Page'])"));
console.log("mithril:         ", await evaluate("typeof window.m"));
console.log("StorePage nodes: ", await evaluate("document.querySelectorAll('.StorePage').length"));
console.log("trace:            " + JSON.stringify(await evaluate("window.__lmxStore || null")));
console.log("head scripts:     " + JSON.stringify(await evaluate("[...document.querySelectorAll('head script')].map(s => s.getAttribute('data-lmx-store')||s.getAttribute('data-lmx-identity')||s.getAttribute('data-src')||(s.src?('src:'+s.src.slice(-30)):'inline')).slice(0,12)")));
console.log("StorePage text:  ", JSON.stringify(String(await evaluate("(document.querySelector('.StorePage')||{}).innerText || null")).slice(0, 300)));
console.log("cards:           ", await evaluate("document.querySelectorAll('.StoreCard').length"));
console.log("catalogue fetch: ", await evaluate("typeof window.__lmxStoreData"));
console.log("\nconsole:");
for (const l of logs.slice(0, 25)) console.log("  " + l);

try { proc.kill(); } catch {}
process.exit(0);
