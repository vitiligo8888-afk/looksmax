#!/usr/bin/env node
/* One-page diagnostic: what does /search actually look like in a real browser? */
import { spawn } from 'node:child_process';

const PORT = 22111;
const CHROME = '/root/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome';
const URL_ = process.argv[2] || 'http://127.0.0.1:8888/search?q=jaw';
const proc = spawn(CHROME, [
  `--remote-debugging-port=${PORT}`, '--headless=new', '--no-sandbox', '--disable-gpu',
  '--disable-dev-shm-usage', '--window-size=1440,1000',
  `--user-data-dir=/tmp/lmx-diag-${process.pid}`, 'about:blank',
], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let wsUrl;
for (let i = 0; i < 80 && !wsUrl; i++) {
  try {
    const l = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
    wsUrl = l.find((t) => t.type === 'page' && t.webSocketDebuggerUrl)?.webSocketDebuggerUrl;
  } catch {}
  if (!wsUrl) await sleep(250);
}
const ws = new WebSocket(wsUrl);
await new Promise((r) => { ws.onopen = r; });
let id = 0; const pend = new Map(); const logs = [];
ws.onmessage = (m) => {
  const x = JSON.parse(m.data);
  if (x.id && pend.has(x.id)) { const p = pend.get(x.id); pend.delete(x.id); x.error ? p.rej(new Error(JSON.stringify(x.error))) : p.res(x.result); }
  else if (x.method === 'Runtime.consoleAPICalled') logs.push(x.params.type + ': ' + x.params.args.map(a => a.value ?? a.description).join(' '));
  else if (x.method === 'Runtime.exceptionThrown') logs.push('THROW: ' + (x.params.exceptionDetails?.exception?.description || x.params.exceptionDetails?.text));
};
const send = (method, params = {}) => new Promise((res, rej) => { const n = ++id; pend.set(n, { res, rej }); ws.send(JSON.stringify({ id: n, method, params })); });
await send('Runtime.enable'); await send('Page.enable');
await send('Page.navigate', { url: URL_ });
await sleep(5000);

const ev = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true })).result?.value;

console.log('URL           ', await ev('location.pathname + location.search'));
console.log('lmxSearch     ', await ev('typeof window.lmxSearch'));
console.log('preload tag   ', await ev('!!document.getElementById("lmx-search-preload")'));
console.log('preload bytes ', await ev('(document.getElementById("lmx-search-preload")||{textContent:""}).textContent.length'));
console.log('preload keys  ', await ev('(function(){try{var d=JSON.parse(document.getElementById("lmx-search-preload").textContent);return Object.keys(d).join(",")+" | results="+(d.results||[]).length}catch(e){return "ERR "+e.message}})()'));
console.log('html classes  ', await ev('document.documentElement.className'));
console.log('#lmx-search-page', await ev('!!document.getElementById("lmx-search-page")'));
console.log('.App-content  ', await ev('!!document.querySelector(".App-content")'));
console.log('#content kids ', await ev('(document.getElementById("content")||{children:[]}).children.length'));
console.log('body last 3   ', await ev('Array.from(document.body.children).slice(-3).map(function(n){return n.tagName+"#"+n.id+"."+String(n.className).slice(0,40)}).join(" | ")'));
console.log('manual mount  ', await ev(`(function(){
  try {
    var host = document.querySelector('.App-content') || document.querySelector('.App') || document.getElementById('app') || document.body;
    return 'host=' + host.tagName + '.' + String(host.className).slice(0,40);
  } catch(e){ return 'ERR ' + e.message; }
})()`));
console.log('api direct    ', await ev(`fetch('/api/looksmax/search?q=jaw&limit=2',{credentials:'same-origin'}).then(r=>r.status+' ok').catch(e=>'ERR '+e.message)`));
console.log('\n--- console ---');
logs.slice(0, 25).forEach((l) => console.log(' ', l));
ws.close(); proc.kill(); process.exit(0);
