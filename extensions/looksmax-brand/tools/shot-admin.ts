#!/usr/bin/env bun
/**
 * Screenshot the admin panel, logged in.
 *
 * The admin is half the product's surface and none of it is reachable without
 * a session, so it is the half that never gets looked at. Logs in through the
 * real /login endpoint, installs the returned session cookie into the browser
 * and shoots the pages an admin actually opens.
 *
 *   bun tools/shot-admin.ts <base-url> <user> <pass> <out-dir>
 */
import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const [BASE, USER, PASS, OUT = "/tmp/admin-shots"] = process.argv.slice(2);
if (!BASE || !USER || !PASS) throw new Error("usage: shot-admin.ts <base-url> <user> <pass> [out]");
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21999);
mkdirSync(OUT, { recursive: true });

// ------------------------------------------------------------------- log in
// Flarum's /login is CSRF-protected, so a bare POST answers 400
// TokenMismatchException. The token is minted per session and embedded in the
// forum payload of any page, so the sequence is: GET a page to be issued a
// flarum_session cookie, read the csrfToken out of the payload, then POST with
// both. This is the same handshake the SPA performs.
const parseCookies = (r: Response) => r.headers.getSetCookie().map((c) => {
  const [pair] = c.split(";");
  const i = pair.indexOf("=");
  return { name: pair.slice(0, i).trim(), value: pair.slice(i + 1).trim() };
});
const jar = new Map<string, string>();
const cookieHeader = () => [...jar].map(([k, v]) => `${k}=${v}`).join("; ");

const boot = await fetch(`${BASE}/`, { redirect: "follow" });
for (const c of parseCookies(boot)) jar.set(c.name, c.value);
const html = await boot.text();
const csrf = html.match(/"csrfToken":"([^"]+)"/)?.[1];
if (!csrf) throw new Error("no csrfToken in the forum payload");

const res = await fetch(`${BASE}/login`, {
  method: "POST",
  headers: {
    "content-type": "application/json",
    "x-csrf-token": csrf,
    cookie: cookieHeader(),
  },
  body: JSON.stringify({ identification: USER, password: PASS, remember: true }),
});
if (!res.ok) throw new Error(`login -> HTTP ${res.status} ${(await res.text()).slice(0, 300)}`);
for (const c of parseCookies(res)) jar.set(c.name, c.value);
const cookies = [...jar].map(([name, value]) => ({ name, value }));
console.log(`logged in: ${cookies.map((c) => c.name).join(", ")}`);

const host = new URL(BASE).hostname;

const proc = Bun.spawn([CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox",
  "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1",
  `--user-data-dir=/tmp/lmx-admin-${process.pid}`], { stdout: "ignore", stderr: "ignore" });
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
await send("Network.enable");
for (const c of cookies) {
  await send("Network.setCookie", { name: c.name, value: c.value, domain: host, path: "/", secure: true });
}
await send("Emulation.setDeviceMetricsOverride", { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

for (const [name, path] of [["admin-dashboard", "/admin"], ["admin-appearance", "/admin#/appearance"],
                            ["admin-mail", "/admin#/mail"]] as const) {
  await send("Page.navigate", { url: `${BASE}${path}` });
  await Bun.sleep(6000);
  const r = await send("Page.captureScreenshot", { format: "png", clip: { x: 0, y: 0, width: 1440, height: 900, scale: 1 } });
  if (!r?.result?.data) { console.log(`  ! ${name}: no image`); continue; }
  writeFileSync(join(OUT, `${name}.png`), Buffer.from(r.result.data, "base64"));
  console.log(`  ${join(OUT, name)}.png`);
}
ws.close(); proc.kill();
