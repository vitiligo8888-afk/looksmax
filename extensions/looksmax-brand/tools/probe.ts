#!/usr/bin/env bun
/**
 * Ask the rendered page what it actually did.
 *
 * Every brand defect found on this lane so far was invisible in the source and
 * obvious in the computed style: a placeholder mark surviving because it lives
 * in a pseudo-element on another extension's selector, a webfont that resolved
 * to the fallback because a more specific rule won. This reports computed
 * values and loaded font faces, not "the CSS says".
 *
 *   bun tools/probe.ts <url>
 */
const URL_ = process.argv[2] || "http://127.0.0.1:8888";
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const PORT = Number(process.env.CDP_PORT || 21977);

const proc = Bun.spawn([CHROME, `--remote-debugging-port=${PORT}`, "--headless=new", "--no-sandbox",
  "--disable-gpu", "--hide-scrollbars", "--force-device-scale-factor=1",
  `--user-data-dir=/tmp/lmx-probe-${process.pid}`], { stdout: "ignore", stderr: "ignore" });
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
const failed: string[] = [];
const consoleErrors: string[] = [];
ws.onmessage = (e) => {
  const m = JSON.parse(String(e.data));
  if (m.id && pending.has(m.id)) { pending.get(m.id)!(m); pending.delete(m.id); return; }
  if (m.method === "Network.loadingFailed") failed.push(`${m.params.errorText} ${m.params.type}`);
  if (m.method === "Network.responseReceived" && m.params.response.status >= 400) {
    failed.push(`HTTP ${m.params.response.status} ${m.params.response.url}`);
  }
  if (m.method === "Runtime.exceptionThrown") {
    const d = m.params.exceptionDetails;
    consoleErrors.push(`${d?.text || ""} ${d?.exception?.description || d?.exception?.value || ""}`.trim()
      + (d?.url ? ` @ ${d.url}:${d.lineNumber}` : ""));
  }
  if (m.method === "Runtime.consoleAPICalled" && m.params.type === "error") {
    consoleErrors.push("console.error: " + m.params.args.map((a: any) => a.value ?? a.description).join(" "));
  }
};
const send = (m: string, p: any = {}) => new Promise<any>((res) => { const n = ++id; pending.set(n, res); ws.send(JSON.stringify({ id: n, method: m, params: p })); });
await send("Page.enable");
await send("Network.enable");
await send("Runtime.enable");
await send("Emulation.setDeviceMetricsOverride", { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
await send("Page.navigate", { url: URL_ });
await Bun.sleep(6000);

const js = `(() => {
  const cs = (sel, prop, pseudo) => {
    const el = document.querySelector(sel);
    if (!el) return "(no element " + sel + ")";
    return getComputedStyle(el, pseudo || null).getPropertyValue(prop);
  };
  const logo = document.querySelector('.Header-logo');
  return {
    faviconLinks: [...document.querySelectorAll('link[rel*="icon"], link[rel="manifest"], link[rel="mask-icon"]')]
      .map(l => l.rel + ' ' + (l.type||'') + ' ' + (l.sizes||'') + ' -> ' + l.href.split('/').pop()),
    themeColor: (document.querySelector('meta[name="theme-color"]')||{}).content,
    ogImage: (document.querySelector('meta[property="og:image"]')||{}).content,
    title: document.title,
    titleTrans: (() => { try {
      return window.app && app.translator
        ? app.translator.trans("core.lib.meta_titles.without_page_title", { forumName: "X", pageNumber: 1 })
        : "(no app.translator)";
    } catch (e) { return "threw: " + e.message; } })(),
    titleTransNoParams: (() => { try {
      return window.app && app.translator
        ? app.translator.trans("core.lib.meta_titles.without_page_title", { forumName: "X" })
        : "(no app.translator)";
    } catch (e) { return "threw: " + e.message; } })(),
    forumTitleAttr: (() => { try { return app.forum.attribute("title"); } catch (e) { return "err"; } })(),
    logoSrc: logo ? logo.getAttribute('src').split('/').pop() : null,
    logoBox: logo ? (r => ({w: Math.round(r.width), h: Math.round(r.height)}))(logo.getBoundingClientRect()) : null,
    logoAlt: logo ? logo.alt : null,
    headerTitleBefore: cs('.Header-title a, .Header-title #home-link', 'content', '::before'),
    headerTitleBeforeW: cs('.Header-title a, .Header-title #home-link', 'width', '::before'),
    heroTitleFont: cs('.WelcomeHero .Hero-title, .Hero.WelcomeHero h1', 'font-family'),
    heroTitleWeight: cs('.WelcomeHero .Hero-title, .Hero.WelcomeHero h1', 'font-weight'),
    heroBg: cs('.Hero.WelcomeHero', 'background-color'),
    brandBrass: getComputedStyle(document.documentElement).getPropertyValue('--brand-brass-500').trim(),
    brandFontDisplay: getComputedStyle(document.documentElement).getPropertyValue('--brand-font-display').trim(),
    archivoLoaded: document.fonts ? [...document.fonts].filter(f => f.family === 'Archivo').map(f => f.weight + ':' + f.status) : 'n/a',
    archivoCheck: document.fonts ? document.fonts.check('800 24px Archivo') : 'n/a',
    selectionBg: cs('body', 'background-color'),
  };
})()`;
const r = await send("Runtime.evaluate", { expression: js, returnByValue: true, awaitPromise: true });
console.log(JSON.stringify(r.result?.result?.value ?? r.result, null, 2));
if (failed.length) console.log("\nfailed requests:\n  " + [...new Set(failed)].join("\n  "));
else console.log("\nno failed requests");
if (consoleErrors.length) console.log("\nconsole exceptions:\n  " + consoleErrors.slice(0, 5).join("\n  "));
ws.close(); proc.kill();
