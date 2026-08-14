#!/usr/bin/env bun
/**
 * Gate: no page may load with a blocked or failed subresource.
 *
 * This exists because of a bug that was invisible to every check we had. An
 * enforcing `img-src 'self'` CSP was correct on the canonical hostname and
 * silently wrong everywhere else, because Flarum builds absolute asset urls
 * from the configured base url. Reached on any other host, 31 requests were
 * blocked: the logo, the favicon, the webmanifest, EVERY avatar, and all the
 * icon fonts.
 *
 * The page still returned 200. The DOM still contained every element. Nothing
 * threw. The only symptom was a broken-image glyph and stretched icon
 * placeholders — and, worst of all, the screenshots used to verify the UI were
 * themselves missing the avatars and icons, so the verification could not see
 * what it was verifying.
 *
 * So: assert on the browser's network layer, not on status codes or the DOM.
 * Anything the page asks for must arrive.
 *
 *   bun e2e/assets-gate.ts                  # exit 1 on any failure
 *   bun e2e/assets-gate.ts --allow-fonts    # tolerate font failures only
 */
import { mkdirSync, writeFileSync } from 'node:fs';

const BASE = process.env.FORUM_URL || 'http://127.0.0.1:8888';
const CHROME = process.env.CHROME_PATH || '/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome';
const PORT = Number(process.env.CDP_PORT || 21779);
const OUT = process.env.REPORT_DIR || '/work/flarum/reports';

const argv = process.argv.slice(2);
const allowFonts = argv.includes('--allow-fonts');

/** Surfaces to sweep. A blocked avatar only shows up where avatars render. */
const PATHS = ['/', '/all', '/tags'];

const proc = Bun.spawn([
  CHROME, `--remote-debugging-port=${PORT}`, '--headless=new', '--no-sandbox',
  '--disable-gpu', '--window-size=1440,900', `--user-data-dir=/tmp/lmx-gate-${process.pid}`,
], { stdout: 'ignore', stderr: 'ignore' });

async function endpoint(): Promise<string> {
  for (let i = 0; i < 60; i++) {
    try {
      const list = (await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json()) as any[];
      const p = list.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
      if (p) return p.webSocketDebuggerUrl;
    } catch {}
    await Bun.sleep(200);
  }
  throw new Error('chromium never exposed a page target');
}

type Failure = { url: string; error: string; blockedReason?: string; type: string; page: string };

const ws = new WebSocket(await endpoint());
await new Promise((r) => (ws.onopen = r));

let id = 0;
const pending = new Map<number, (v: any) => void>();
const urlById = new Map<string, string>();
const typeById = new Map<string, string>();
const failures: Failure[] = [];
const consoleErrors: string[] = [];
let currentPage = '';

ws.onmessage = (e) => {
  const m = JSON.parse(String(e.data));
  if (m.id && pending.has(m.id)) { pending.get(m.id)!(m); pending.delete(m.id); return; }

  if (m.method === 'Network.requestWillBeSent') {
    urlById.set(m.params.requestId, m.params.request.url);
    typeById.set(m.params.requestId, m.params.type ?? '?');
  }
  if (m.method === 'Network.loadingFailed') {
    failures.push({
      url: urlById.get(m.params.requestId) ?? '?',
      error: m.params.errorText || '',
      blockedReason: m.params.blockedReason,
      type: m.params.type ?? typeById.get(m.params.requestId) ?? '?',
      page: currentPage,
    });
  }
  if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
    consoleErrors.push(`${currentPage}: ${(m.params.args ?? []).map((a: any) => a.value ?? a.description ?? '').join(' ')}`);
  }
};

const send = (method: string, params: any = {}) =>
  new Promise<any>((res) => { const n = ++id; pending.set(n, res); ws.send(JSON.stringify({ id: n, method, params })); });

await send('Network.enable');
await send('Page.enable');
await send('Runtime.enable');

for (const path of PATHS) {
  currentPage = path;
  await send('Page.navigate', { url: `${BASE}${path}` });
  await Bun.sleep(5000);
}

ws.close();
proc.kill();

// A font failure is the one thing that can legitimately be environmental, so it
// is separable — but it is NOT ignored by default, because a missing icon font
// is exactly how the "stretched icon" defects reached the operator.
const fatal = failures.filter((f) => !(allowFonts && f.type === 'Font'));

mkdirSync(OUT, { recursive: true });
writeFileSync(`${OUT}/assets-gate.json`, JSON.stringify({ failures, consoleErrors }, null, 2));

console.log(`swept ${PATHS.length} surfaces`);
console.log(`  failed subresources : ${failures.length}${allowFonts ? ` (${failures.length - fatal.length} font failures tolerated)` : ''}`);
console.log(`  console errors      : ${consoleErrors.length}`);

if (fatal.length) {
  console.log('\nFAILURES:');
  // Group, because one blocked avatar host produces hundreds of identical lines.
  const grouped = new Map<string, { n: number; sample: Failure }>();
  for (const f of fatal) {
    const key = `${f.blockedReason ? `blocked=${f.blockedReason}` : f.error} ${f.type}`;
    const g = grouped.get(key);
    if (g) g.n++;
    else grouped.set(key, { n: 1, sample: f });
  }
  for (const [key, g] of grouped) {
    console.log(`  ${key}  x${g.n}`);
    console.log(`    e.g. ${g.sample.sample ?? g.sample.url}  (on ${g.sample.page})`);
  }
}

if (consoleErrors.length) {
  console.log('\nCONSOLE ERRORS:');
  for (const c of consoleErrors.slice(0, 10)) console.log(`  ${c}`);
}

console.log(`\nreport: ${OUT}/assets-gate.json`);

if (fatal.length || consoleErrors.length) {
  console.log('\nGATE FAILED');
  process.exit(1);
}
console.log('\nGATE PASSED — every subresource loaded, no console errors');
