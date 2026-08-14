#!/usr/bin/env node
/*
 * Screenshot the search surfaces with a real Chromium over CDP, and collect the
 * console and network errors while doing it.
 *
 * Separate from e2e/look.ts because that one is a bun script and this box's app
 * container has node, not bun. Same posture though: it drives a real browser,
 * writes PNGs and expects a human to look at them. Every layout defect on this
 * project so far was invisible to DOM assertions and obvious in a screenshot.
 *
 * It also fails loudly on console errors and failed requests, because a search
 * box that throws in the console while still rendering results is broken in a
 * way no screenshot shows.
 *
 *   node shoot.mjs --out /work/flarum/lmx-shots --width 1440
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { spawn } from 'node:child_process';

const arg = (n, d) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const BASE = arg('base', 'http://127.0.0.1:8888');
const OUT = arg('out', '/work/flarum/lmx-shots');
const WIDTH = Number(arg('width', 1440));
const HEIGHT = Number(arg('height', 1000));
const PORT = Number(arg('port', 21999));
const CHROME = arg('chrome', '/usr/lib/chromium/chromium');

mkdirSync(OUT, { recursive: true });

const proc = spawn(CHROME, [
  `--remote-debugging-port=${PORT}`, '--headless=new', '--no-sandbox', '--disable-gpu',
  '--hide-scrollbars', '--disable-dev-shm-usage', `--window-size=${WIDTH},${HEIGHT}`,
  `--user-data-dir=/tmp/lmx-shoot-${process.pid}`, 'about:blank',
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/*
 * /json/version returns the BROWSER target, which does not implement the Page
 * domain — captureScreenshot against it returns nothing at all, silently. The
 * page target from /json/list is the one that can actually be driven.
 */
async function pageTarget() {
  for (let i = 0; i < 80; i++) {
    try {
      const list = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
      const p = list.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
      if (p) return p.webSocketDebuggerUrl;
    } catch {}
    await sleep(250);
  }
  throw new Error('no CDP page target appeared');
}

const ws = new WebSocket(await pageTarget());
await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });

let id = 0;
const pending = new Map();
const events = [];
ws.onmessage = (m) => {
  const msg = JSON.parse(m.data);
  if (msg.id && pending.has(msg.id)) {
    const { res, rej } = pending.get(msg.id);
    pending.delete(msg.id);
    msg.error ? rej(new Error(JSON.stringify(msg.error))) : res(msg.result);
  } else if (msg.method) {
    events.push(msg);
  }
};
const send = (method, params = {}) =>
  new Promise((res, rej) => {
    const n = ++id;
    pending.set(n, { res, rej });
    ws.send(JSON.stringify({ id: n, method, params }));
    setTimeout(() => pending.has(n) && (pending.delete(n), rej(new Error(`${method} timed out`))), 45000);
  });

await send('Page.enable');
await send('Runtime.enable');
await send('Network.enable');
await send('Emulation.setDeviceMetricsOverride', {
  width: WIDTH, height: HEIGHT, deviceScaleFactor: 1, mobile: false,
});

const problems = [];
function drain(label) {
  for (const e of events.splice(0)) {
    if (e.method === 'Runtime.consoleAPICalled' && e.params.type === 'error') {
      problems.push(`${label} console.error: ` +
        e.params.args.map((a) => a.value ?? a.description ?? a.type).join(' '));
    }
    if (e.method === 'Runtime.exceptionThrown') {
      problems.push(`${label} uncaught: ` +
        (e.params.exceptionDetails?.exception?.description || e.params.exceptionDetails?.text));
    }
    if (e.method === 'Network.loadingFailed' && !e.params.canceled) {
      problems.push(`${label} request failed: ${e.params.errorText} (${e.params.type})`);
    }
    if (e.method === 'Network.responseReceived' && e.params.response.status >= 400) {
      problems.push(`${label} HTTP ${e.params.response.status} ${e.params.response.url}`);
    }
  }
}

const evaluate = async (expr) => {
  const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.text + ' :: ' + expr.slice(0, 120));
  return r.result.value;
};

async function shot(name, { path, before, wait = 1400, full = false } = {}) {
  if (path) {
    await send('Page.navigate', { url: BASE + path });
    await sleep(wait);
  }
  if (before) { await evaluate(before); await sleep(900); }
  drain(name);
  const { data } = await send('Page.captureScreenshot', {
    format: 'png', captureBeyondViewport: full,
  });
  const file = `${OUT}/${name}.png`;
  writeFileSync(file, Buffer.from(data, 'base64'));
  const info = await evaluate(`(function(){
    var r = document.querySelectorAll('.lmx-r').length;
    var p = document.querySelector('.lmx-p-backdrop.lmx-open') ? 'open' : 'closed';
    return JSON.stringify({ results: r, palette: p, title: document.title,
      facets: document.querySelectorAll('.lmx-facet').length,
      marks: document.querySelectorAll('.lmx-r mark').length });
  })()`);
  console.log(`${name.padEnd(28)} ${info}`);
  return file;
}

await shot('01-forum-index', { path: '/' });
await shot('02-search-page', { path: '/search?q=jaw' });
await shot('03-search-multiword', { path: '/search?q=jaw%20surgery' });
await shot('04-search-filtered', { path: '/search?q=jaw%20sort:top' });
await shot('05-search-posts-tab', { path: '/search?q=skin&type=posts' });
await shot('06-search-zero', { path: '/search?q=zzzqqqxxnotathing' });
await shot('07-palette', {
  path: '/',
  before: `window.lmxSearch.open('jaw'); new Promise(r=>setTimeout(r,1200))`,
});
await shot('08-palette-empty', {
  path: '/',
  before: `window.lmxSearch.open(''); new Promise(r=>setTimeout(r,500))`,
});
await shot('09-search-russian', { path: '/search?q=%D0%BB%D0%B8%D1%86%D0%BE' });

// Deliberate red: assert the harness can actually SEE a failure. This navigates
// to a route that does not exist, and the run is only trustworthy if this one
// produces problems while the others do not.
if (process.argv.includes('--prove-red')) {
  await shot('99-deliberate-404', { path: '/api/looksmax/search/definitely-not-a-route' });
}

drain('final');
console.log('\n--- problems ---');
if (!problems.length) console.log('none');
else problems.forEach((p) => console.log(' * ' + p));
writeFileSync(`${OUT}/problems.json`, JSON.stringify(problems, null, 2));

ws.close();
proc.kill();
process.exit(0);
