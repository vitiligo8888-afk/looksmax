#!/usr/bin/env bun
/**
 * Stacking-context diagnostic for the search dropdown.
 *
 * The claim under test: Flarum gives `.Search-results` z-index 1030, yet it
 * paints BEHIND page content. If true, the cause is not the dropdown's own
 * z-index but an ancestor that establishes a stacking context, which clamps
 * every descendant z-index into that ancestor's own painting order.
 *
 * This does not assert; it reports the ancestor chain and the actual hit-test
 * result at a point inside the dropdown, which is the only measurement that
 * proves what a user sees.
 */
import { writeFileSync, mkdirSync } from "node:fs";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const CDP_PORT = Number(process.env.CDP_PORT || 21501);
const CHROME = process.env.CHROME_PATH || "/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome";
const OUT = process.env.OUT_DIR || "/work/flarum/search-diag";
mkdirSync(OUT, { recursive: true });

class CDP {
  ws!: WebSocket; id = 0; pending = new Map<number, any>(); sessionId?: string;
  static async attach(u: string) {
    const c = new CDP(); c.ws = new WebSocket(u);
    await new Promise<void>((r, j) => { c.ws.onopen = () => r(); c.ws.onerror = (e) => j(new Error(String(e))); });
    c.ws.onmessage = (ev) => { const m = JSON.parse(String(ev.data)); if (m.id != null) { const p = c.pending.get(m.id); if (p) { c.pending.delete(m.id); m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result); } } };
    return c;
  }
  send(method: string, params: any = {}): Promise<any> {
    const id = ++this.id;
    return new Promise((res, rej) => { this.pending.set(id, { res, rej }); this.ws.send(JSON.stringify({ id, method, params, sessionId: this.sessionId })); setTimeout(() => { if (this.pending.delete(id)) rej(new Error(`timeout ${method}`)); }, 30000); });
  }
  async eval(expr: string, awaitPromise = false) {
    const r = await this.send("Runtime.evaluate", { expression: expr, awaitPromise, returnByValue: true, timeout: 25000 });
    if (r.exceptionDetails) throw new Error(`eval: ${r.exceptionDetails.text}`);
    return r.result?.value;
  }
  async goto(url: string) {
    await this.send("Page.navigate", { url });
    const dl = Date.now() + 25000;
    while (Date.now() < dl) { await Bun.sleep(300); if (await this.eval(`!!(document.querySelector('#app') && window.flarum)`).catch(() => false)) { await Bun.sleep(1200); return true; } }
    return false;
  }
  async shot(name: string) {
    const r = await this.send("Page.captureScreenshot", { format: "png" });
    if (r?.data) writeFileSync(`${OUT}/${name}.png`, Buffer.from(r.data, "base64"));
  }
}

const proc = Bun.spawn([CHROME, "--headless=new", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage",
  "--user-data-dir=/tmp/zdiag-profile", `--remote-debugging-port=${CDP_PORT}`, "--remote-allow-origins=*", "--window-size=1440,1000"], { stdout: "pipe", stderr: "pipe" });

let version: any = null;
for (let i = 0; i < 40; i++) { try { const r = await fetch(`http://127.0.0.1:${CDP_PORT}/json/version`); if (r.ok) { version = await r.json(); break; } } catch {} await Bun.sleep(400); }
if (!version) { console.error("no CDP"); process.exit(1); }

const b = await CDP.attach(version.webSocketDebuggerUrl);
const { targetId } = await b.send("Target.createTarget", { url: "about:blank" });
const { sessionId } = await b.send("Target.attachToTarget", { targetId, flatten: true });
b.sessionId = sessionId;
await b.send("Page.enable"); await b.send("Runtime.enable");

const PAGES = (process.env.PAGES || "/,/all,/t/looksmaxing,/d/1").split(",");
const report: any[] = [];

// The generic detector: walk from an element to the root, and record every
// ancestor that establishes a stacking context and why. This is what makes the
// finding exhaustive rather than a single-page patch.
const STACK_PROBE = `
(() => {
  const creates = (el) => {
    const s = getComputedStyle(el);
    const why = [];
    if (el === document.documentElement) why.push('root');
    if (s.position === 'fixed' || s.position === 'sticky') why.push('position:' + s.position);
    if ((s.position === 'relative' || s.position === 'absolute') && s.zIndex !== 'auto') why.push('position:' + s.position + ' + z-index:' + s.zIndex);
    if (s.transform !== 'none') why.push('transform');
    if (s.filter !== 'none') why.push('filter');
    if (s.backdropFilter && s.backdropFilter !== 'none') why.push('backdrop-filter');
    if (s.perspective !== 'none') why.push('perspective');
    if (parseFloat(s.opacity) < 1) why.push('opacity:' + s.opacity);
    if (s.mixBlendMode !== 'normal') why.push('mix-blend-mode');
    if (s.isolation === 'isolate') why.push('isolation:isolate');
    if (/paint|layout|strict|content/.test(s.contain || '')) why.push('contain:' + s.contain);
    if (/transform|opacity|filter/.test(s.willChange || '')) why.push('will-change:' + s.willChange);
    if (s.containerType && s.containerType !== 'normal') why.push('container-type:' + s.containerType);
    return why;
  };
  const chainOf = (el) => {
    const out = [];
    for (let n = el; n && n !== document; n = n.parentElement) {
      const s = getComputedStyle(n);
      const why = creates(n);
      if (why.length) out.push({ sel: n.tagName.toLowerCase() + (n.id ? '#' + n.id : '') + (n.className && typeof n.className === 'string' ? '.' + n.className.trim().split(/\\s+/).join('.') : ''), z: s.zIndex, why, overflow: s.overflow });
    }
    return out;
  };
  return { chainOf: chainOf.toString(), creates: creates.toString() };
})()`;

for (const path of PAGES) {
  const url = new URL(path, BASE).href;
  const ok = await b.goto(url);
  if (!ok) { report.push({ path, error: "did not boot" }); continue; }

  // Type into the search box to open the dropdown, the same way a user does.
  const opened = await b.eval(`(async () => {
    const input = document.querySelector('.Search-input input, .Search input[type=search], #header input[type=search]');
    if (!input) return { ok: false, why: 'no search input' };
    input.focus();
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(input, 'looks');
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'k' }));
    await new Promise(r => setTimeout(r, 2500));
    const res = document.querySelector('.Search-results, ul.Dropdown-menu.Search-results');
    return { ok: !!res, visible: res ? getComputedStyle(res).display !== 'none' : false, rect: res ? res.getBoundingClientRect().toJSON() : null, count: res ? res.querySelectorAll('li').length : 0 };
  })()`, true);

  let chain: any = null, hit: any = null;
  if (opened.ok) {
    chain = await b.eval(`(() => {
      ${(await b.eval(STACK_PROBE)).creates.replace(/^/, 'const creates = ')};
      const el = document.querySelector('.Search-results');
      const out = [];
      for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
        const s = getComputedStyle(n);
        const why = creates(n);
        const name = n.tagName.toLowerCase() + (n.id ? '#'+n.id : '') + (typeof n.className === 'string' && n.className.trim() ? '.'+n.className.trim().split(/\\s+/).join('.') : '');
        if (why.length || s.zIndex !== 'auto' || /hidden|clip|auto|scroll/.test(s.overflow)) out.push({ el: name.slice(0,120), position: s.position, zIndex: s.zIndex, overflow: s.overflow, stackingContext: why });
      }
      return out;
    })()`);

    // The only measurement that proves what the user sees: hit-test a point
    // inside the dropdown and see which element actually receives it.
    hit = await b.eval(`(() => {
      const el = document.querySelector('.Search-results');
      const r = el.getBoundingClientRect();
      if (r.height < 4) return { skipped: 'zero-height dropdown', rect: r.toJSON() };
      const pts = [0.15, 0.5, 0.85].map(f => ({ x: Math.round(r.left + r.width/2), y: Math.round(r.top + r.height*f) }));
      return pts.map(p => {
        const top = document.elementFromPoint(p.x, p.y);
        if (!top) return { ...p, top: null };
        const inside = el.contains(top) || top === el;
        const name = top.tagName.toLowerCase() + (top.id ? '#'+top.id : '') + (typeof top.className === 'string' && top.className.trim() ? '.'+top.className.trim().split(/\\s+/).slice(0,3).join('.') : '');
        return { ...p, topElement: name.slice(0,120), occluded: !inside };
      });
    })()`);
    await b.shot(`dropdown${path.replace(/\W+/g, '_')}`);
  }
  report.push({ path, opened, chain, hit });
  console.log(`\n### ${path}`);
  console.log("  opened:", JSON.stringify(opened));
  if (chain) console.log("  ancestor chain:\n" + chain.map((c: any) => `    ${c.el}\n      position=${c.position} z=${c.zIndex} overflow=${c.overflow} stacking=[${c.stackingContext.join(', ')}]`).join("\n"));
  if (hit) console.log("  hit-test:", JSON.stringify(hit));
}

writeFileSync(`${OUT}/zdiag.json`, JSON.stringify(report, null, 2));
console.log(`\nwrote ${OUT}/zdiag.json + screenshots`);
await b.send("Target.closeTarget", { targetId }).catch(() => {});
try { proc.kill(); } catch {}
