/**
 * Read-only measurement of the identity surface as it renders RIGHT NOW.
 * Run on osprey:  cd /work/flarum && bun extensions/looksmax-userinfo/e2e/probe.ts
 */
import { Browser } from "../../../e2e/visual/cdp.ts";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const OUT = process.env.OUT || "/tmp/ui-probe";

const b = await Browser.launch(21840, "1440,1000");
await b.viewport({ name: "desktop", width: 1440, height: 1000 });
const d = process.env.DISC || "/d/25909-mumbai-man-goes-er-after-getting-bullied-on-looksmaxorg";
await b.goto(BASE + d);

const r = await b.eval(`(() => {
  const out = {};
  const rail = document.querySelector('.LmxAuthor');
  if (rail) {
    const rb = rail.getBoundingClientRect();
    out.rail = { w: Math.round(rb.width), h: Math.round(rb.height) };
    out.railText = rail.innerText.replace(/\\n/g, ' | ');
    // dead space: post column height vs rail height
    const post = rail.closest('article.Post');
    if (post) {
      const pb = post.getBoundingClientRect();
      out.post = { w: Math.round(pb.width), h: Math.round(pb.height) };
      const body = post.querySelector('.Post-body');
      if (body) { const bb = body.getBoundingClientRect(); out.body = { h: Math.round(bb.height), top: Math.round(bb.top - pb.top) }; }
    }
    // overlap detection inside stats
    out.overlaps = [];
    rail.querySelectorAll('.LmxStat').forEach(s => {
      const dt = s.querySelector('dt'), dd = s.querySelector('dd');
      if (!dt || !dd) return;
      const a = dt.getBoundingClientRect(), c = dd.getBoundingClientRect();
      const over = !(a.right <= c.left + 0.5 || c.right <= a.left + 0.5 || a.bottom <= c.top + 0.5 || c.bottom <= a.top + 0.5);
      if (over) out.overlaps.push(dt.innerText + ' / ' + dd.innerText);
      if (dt.scrollWidth > dt.clientWidth + 1) out.overlaps.push('clipped:' + dt.innerText);
    });
    const leg = rail.querySelector('.LmxLegacy');
    out.legacy = leg ? leg.innerText : null;
    out.legacyTitle = leg ? leg.getAttribute('title') : null;
  }
  out.locale = document.documentElement.lang;
  out.bound = window.__lmxUserInfo || null;
  // how many distinct hover implementations are wired
  out.hoverImpls = {
    userinfoHost: !!document.querySelector('.LmxHoverHost'),
    ranksHover: !!document.querySelector('.LmxHover'),
    coreUserCard: !!document.querySelector('.UserCard'),
  };
  // the .LmxCard collision
  out.lmxCardUsers = [...document.querySelectorAll('.LmxCard')].map(e => e.className + ' w=' + Math.round(e.getBoundingClientRect().width));
  return out;
})()`);
console.log(JSON.stringify(r, null, 2));
await b.shot(OUT + "/post-1440.png");
await b.fullShot(OUT + "/post-1440-full.png");

// hover a username in the post stream
const hov = await b.eval(`(() => {
  const a = document.querySelector('.LmxAuthor-name');
  if (!a) return 'no-name-link';
  const r = a.getBoundingClientRect();
  return { x: Math.round(r.left + r.width/2), y: Math.round(r.top + r.height/2), name: a.textContent };
})()`);
console.log("hover target", JSON.stringify(hov));
if (hov && hov.x) {
  await b.send("Input.dispatchMouseEvent", { type: "mouseMoved", x: hov.x, y: hov.y, buttons: 0 });
  await Bun.sleep(1400);
  await b.shot(OUT + "/hover-1440.png");
  const card = await b.eval(`(() => {
    const h = document.querySelector('.LmxHoverHost');
    const rk = document.querySelector('.LmxHover.is-open');
    return { host: h ? { open: h.classList.contains('is-open'), text: h.innerText, w: Math.round(h.getBoundingClientRect().width) } : null,
             ranks: rk ? { text: rk.innerText, w: Math.round(rk.getBoundingClientRect().width) } : null };
  })()`);
  console.log("cards", JSON.stringify(card, null, 2));
}

// mobile
await b.viewport({ name: "m", width: 390, height: 844, mobile: true });
await b.goto(BASE + d);
await b.shot(OUT + "/post-390.png");
console.log("console errors:", b.consoleErrors.slice(0, 10));
await b.close();
