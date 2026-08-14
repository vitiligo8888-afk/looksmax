#!/usr/bin/env bun
/** Throwaway diagnostic: what does the runtime module registry actually expose? */
const CDP_PORT = Number(process.env.CDP_PORT || 21477);
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const URL_ = process.env.URL || "http://127.0.0.1:8888/d/193";

const proc = Bun.spawn([CHROME, "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage",
  "--user-data-dir=/tmp/probe-guides", `--remote-debugging-port=${CDP_PORT}`, "--remote-allow-origins=*"],
  { stdout: "pipe", stderr: "pipe" });

let version: any = null;
for (let i = 0; i < 50; i++) {
  try { const r = await fetch(`http://127.0.0.1:${CDP_PORT}/json/version`); if (r.ok) { version = await r.json(); break; } } catch {}
  await Bun.sleep(400);
}
const ws = new WebSocket(version.webSocketDebuggerUrl);
await new Promise<void>((res) => { ws.onopen = () => res(); });
let id = 0; const pending = new Map<number, any>();
let sessionId: string | undefined;
ws.onmessage = (ev) => { const m = JSON.parse(String(ev.data)); if (m.id != null) { const p = pending.get(m.id); if (p) { pending.delete(m.id); p(m); } } };
const send = (method: string, params: any = {}) => new Promise<any>((res) => {
  const i = ++id; pending.set(i, (m: any) => res(m.result ?? m)); ws.send(JSON.stringify({ id: i, method, params, sessionId }));
});
const t = await send("Target.createTarget", { url: "about:blank" });
const a = await send("Target.attachToTarget", { targetId: t.targetId, flatten: true });
sessionId = a.sessionId;
await send("Page.enable"); await send("Runtime.enable");

const errs: string[] = [];
ws.addEventListener("message", (ev) => {
  const m = JSON.parse(String(ev.data));
  if (m.method === "Runtime.exceptionThrown") errs.push(JSON.stringify(m.params?.exceptionDetails?.exception?.description || m.params).slice(0, 400));
});

await send("Page.navigate", { url: URL_ });
await Bun.sleep(7000);

const r = await send("Runtime.evaluate", {
  expression: `JSON.stringify((function(){
    var c = (window.flarum && window.flarum.core && window.flarum.core.compat) || {};
    var keys = Object.keys(c);
    var pick = function(k){ var v = c[k]; return v ? (typeof v) + (v && v.prototype ? '+proto' : '') : 'MISSING'; };
    return {
      compatKeys: keys.length,
      m: typeof window.m,
      app: pick('forum/app'),
      extend: pick('common/extend'),
      extendFn: c['common/extend'] ? typeof c['common/extend'].extend : 'n/a',
      DLI: pick('forum/components/DiscussionListItem'),
      CP: pick('forum/components/CommentPost'),
      DP: pick('forum/components/DiscussionPage'),
      guideMarkerInBundle: !!document.querySelector('script[src*="forum.js"]'),
      guidePost: !!document.querySelector('.GuidePost'),
      guideHeader: !!document.querySelector('.GuideHeader'),
      iconifyEls: document.querySelectorAll('iconify-icon').length,
      postBody: !!document.querySelector('.Post-body'),
      claims: document.querySelectorAll('.GuideClaim').length,
      lmxProbe: JSON.stringify(window.__lmxGuides || null),
      bodyHasFlag: !!(document.querySelector(".Post-body") && document.querySelector(".Post-body").getAttribute("data-guide-done")),
      exts: Object.keys((window.flarum && window.flarum.extensions) || {}).map(function(k){ var v=window.flarum.extensions[k]; return k + "=" + (v === undefined ? "UNDEFINED" : (v && v.extend ? "ok" : typeof v)); }),
      appCurrent: (function(){ try { var a=(window.flarum.core.compat["forum/app"]); return { hasCurrent: !!a.current, route: a.current && a.current.get && !!a.current.get("discussion"), booted: !!a.initializers }; } catch(e){ return String(e); } })()
    };
  })())`,
  returnByValue: true,
});
console.log(r?.result?.value ?? JSON.stringify(r));
console.log("EXCEPTIONS:", errs.slice(0, 5));
try { proc.kill(); } catch {}
process.exit(0);
