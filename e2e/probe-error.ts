#!/usr/bin/env bun
/**
 * Capture the FULL uncaught exception and every failed request, verbatim.
 * The harness truncates for readability; this exists to answer "what actually
 * broke" with the real text and stack rather than a first token.
 */
const BASE = process.env.FORUM_URL!;
const PORT = Number(process.env.CDP_PORT || 21600);
const CHROME = "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";

const proc = Bun.spawn([CHROME, "--headless=new", "--no-sandbox", "--disable-gpu",
  "--disable-dev-shm-usage", "--user-data-dir=/tmp/probe-err",
  `--remote-debugging-port=${PORT}`, "--remote-allow-origins=*"], { stdout: "pipe", stderr: "pipe" });

let v: any = null;
for (let i = 0; i < 40; i++) {
  try { const r = await fetch(`http://127.0.0.1:${PORT}/json/version`); if (r.ok) { v = await r.json(); break; } } catch {}
  await Bun.sleep(400);
}
const ws = new WebSocket(v.webSocketDebuggerUrl);
await new Promise<void>((res) => { ws.onopen = () => res(); });

let id = 0;
const pending = new Map<number, any>();
const send = (method: string, params: any = {}, sessionId?: string) =>
  new Promise<any>((res, rej) => {
    const i = ++id;
    pending.set(i, { res, rej });
    ws.send(JSON.stringify({ id: i, method, params, sessionId }));
  });

const exceptions: any[] = [];
const consoleMsgs: any[] = [];
const netFail: any[] = [];
let sessionId: string | undefined;

ws.onmessage = (ev) => {
  const m = JSON.parse(String(ev.data));
  if (m.id != null) { const p = pending.get(m.id); if (p) { pending.delete(m.id); p.res(m.result); } return; }
  if (m.method === "Runtime.exceptionThrown") exceptions.push(m.params.exceptionDetails);
  if (m.method === "Runtime.consoleAPICalled" && m.params.type === "error")
    consoleMsgs.push((m.params.args || []).map((a: any) => a.value ?? a.description ?? a.preview?.description).join(" "));
  if (m.method === "Network.loadingFailed") netFail.push(m.params);
};

const t = await send("Target.createTarget", { url: "about:blank" });
const a = await send("Target.attachToTarget", { targetId: t.targetId, flatten: true });
sessionId = a.sessionId;
await send("Page.enable", {}, sessionId);
await send("Runtime.enable", {}, sessionId);
await send("Network.enable", {}, sessionId);
await send("Page.navigate", { url: BASE }, sessionId);
await Bun.sleep(9000);

console.log("=== UNCAUGHT EXCEPTIONS ===");
for (const e of exceptions) {
  console.log("text:", e.text);
  console.log("desc:", e.exception?.description?.slice(0, 700));
  console.log("at  :", e.url, `${e.lineNumber}:${e.columnNumber}`);
  console.log("---");
}
console.log("=== CONSOLE ERRORS ===");
for (const c of consoleMsgs) console.log(" ", String(c).slice(0, 400));
console.log("=== FAILED REQUESTS ===");
for (const f of netFail) console.log(` ${f.type} ${f.errorText} ${f.blockedReason ?? ""}`);

const state = await send("Runtime.evaluate", {
  expression: `JSON.stringify({
    hasApp: !!document.querySelector('#app'),
    appContent: (document.querySelector('#app')||{}).innerHTML ? document.querySelector('#app').innerHTML.length : 0,
    bodyText: document.body.innerText.slice(0,200),
    preload: !!document.querySelector('#flarum-json-payload'),
    payloadLen: (document.querySelector('#flarum-json-payload')||{}).textContent?.length || 0
  })`, returnByValue: true,
}, sessionId);
console.log("=== PAGE STATE ===");
console.log(state.result?.value);

try { proc.kill(); } catch {}
