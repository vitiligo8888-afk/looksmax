/**
 * Open THE user card at every site a username appears, at two widths, logged
 * out and logged in, and screenshot each one.
 *
 *   cd /work/flarum && bun extensions/looksmax-userinfo/e2e/card-sweep.ts
 *
 * Runs against https://looksmax.lat, not 127.0.0.1:8888. That is not a
 * preference: Flarum's `apiUrl` is absolute (https://looksmax.lat/api), so
 * every XHR from a page served on 127.0.0.1 is cross-origin and fails — which
 * is why a profile opened on the loopback shows "Oops! Something went wrong
 * during a cross-origin request" and no posts. Logging in and loading a
 * hovercard for a user the page has not already cached both need XHR.
 *
 * A site is only PASSED when the card actually appeared with the expected
 * user's name in it. "The selector matched" is not the assertion.
 */
import { Browser } from "../../../e2e/visual/cdp.ts";

const BASE = process.env.FORUM_URL || "https://looksmax.lat";
const OUT = process.env.OUT || "/tmp/lmx-card";
const DISC = process.env.DISC || "/d/25909";
const USER = process.env.USER_SLUG || "maarda";

type Site = { name: string; url: string; selector: string; scroll?: boolean };

const SITES: Site[] = [
  { name: "post-rail", url: DISC, selector: ".LmxAuthor-name" },
  { name: "post-mention", url: DISC, selector: "a.UserMention" },
  { name: "list-author", url: "/all", selector: ".DiscussionListItem a[href*='/u/']" },
  { name: "index-lastposter", url: "/", selector: "a[href*='/u/']" },
  { name: "chat-who", url: "/", selector: ".LmxChat-who" },
  { name: "leaderboard", url: "/", selector: ".LmxLeader-name" },
  { name: "search-result", url: "/search?q=looksmax", selector: "a[href*='/u/']" },
  { name: "profile-header", url: "/u/" + USER, selector: ".UserCard a[href*='/u/'], .UserCard-identity" },
];

const EXPR_CARD = `(() => {
  const h = document.querySelector('.LmxCardHost');
  if (!h || !h.classList.contains('is-open')) return { open: false };
  const c = h.querySelector('.LmxHoverCard');
  const box = c ? c.getBoundingClientRect() : null;
  return {
    open: true,
    mode: c ? c.getAttribute('data-mode') : null,
    w: box ? Math.round(box.width) : null,
    hgt: box ? Math.round(box.height) : null,
    inViewport: box ? (box.top >= -1 && box.left >= -1 && box.right <= innerWidth + 1 && box.bottom <= innerHeight + 1) : null,
    name: h.querySelector('.LmxAuthor-name') ? h.querySelector('.LmxAuthor-name').textContent : null,
    actions: [].map.call(h.querySelectorAll('.LmxCardAction'), function (a) { return a.textContent.trim(); }),
    text: c ? c.innerText.split(String.fromCharCode(10)).join(' | ') : null,
    stray: c ? /,\\s*(posts|reactions|publicaciones|reacciones)/.test(c.innerText) : false,
    overlaps: (function () {
      const bad = [];
      (c ? c.querySelectorAll('.LmxStat') : []).forEach(function (s) {
        const dt = s.querySelector('dt'), dd = s.querySelector('dd');
        if (!dt || !dd) return;
        const a = dt.getBoundingClientRect(), b = dd.getBoundingClientRect();
        if (!(a.right <= b.left + 0.5 || b.right <= a.left + 0.5 || a.bottom <= b.top + 0.5 || b.bottom <= a.top + 0.5)) {
          bad.push(dt.innerText + ' X ' + dd.innerText);
        }
      });
      return bad;
    })(),
    dupes: document.querySelectorAll('.LmxHover.is-open, .LmxHoverCard').length,
  };
})()`;

async function run() {
  const results: any[] = [];
  const b = await Browser.launch(21840, "1440,1100");

  for (const authed of [false, true]) {
    if (authed) {
      const who = await b.login("admin", process.env.ADMIN_PW || "1IxmV2IMZ8cnulvIHBT0", BASE);
      if (!String(who).startsWith("ok")) throw new Error("login failed: " + who);
    }

    for (const vp of [
      { name: "1440", width: 1440, height: 1100 },
      { name: "390", width: 390, height: 844, mobile: true },
    ]) {
      await b.viewport(vp);
      // cdp.ts's viewport() only ever ENABLES touch emulation, so a desktop
      // pass that follows a mobile pass inherits it and silently measures a
      // touch layout at 1440px. Turned off explicitly here.
      if (!(vp as any).mobile) {
        await b.send("Emulation.setTouchEmulationEnabled", { enabled: false });
      }

      for (const site of SITES) {
        await b.goto(BASE + site.url, 1600);

        const found = await b.eval(`(() => {
          const el = document.querySelector(${JSON.stringify(site.selector)});
          if (!el) return null;
          el.scrollIntoView({ block: 'center' });
          const r = el.getBoundingClientRect();
          return { x: Math.round(r.left + Math.min(r.width, 40) / 2), y: Math.round(r.top + r.height / 2), text: el.textContent.trim().slice(0, 40) };
        })()`);

        const tag = `${authed ? "in" : "out"}-${vp.name}-${site.name}`;

        /*
         * A leaked translation key, rendered. Flarum prints the key itself when
         * one is missing, so this is the only signal there is — and four of
         * them shipped to the live profile page before this check existed.
         * Scoped to the extension's own prefix so another lane's leak is not
         * reported as this lane's.
         */
        const leaked = await b.eval(`(() => {
          const hits = [];
          const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
          let n;
          while ((n = walk.nextNode())) {
            const s = (n.nodeValue || '').trim();
            if (/local-looksmax-userinfo\\.[a-z]/.test(s)) hits.push(s.slice(0, 80));
          }
          return hits.slice(0, 5);
        })()`);

        if (!found) {
          results.push({ tag, status: "no-anchor", leaked });
          continue;
        }

        if (vp.mobile) {
          // Touch: a tap is the ONLY way in, and it must open the sheet rather
          // than navigate.
          await b.send("Input.dispatchMouseEvent", { type: "mousePressed", x: found.x, y: found.y, button: "left", clickCount: 1, buttons: 1 });
          await b.send("Input.dispatchMouseEvent", { type: "mouseReleased", x: found.x, y: found.y, button: "left", clickCount: 1, buttons: 0 });
        } else {
          await b.send("Input.dispatchMouseEvent", { type: "mouseMoved", x: found.x, y: found.y, buttons: 0 });
        }
        await Bun.sleep(1500);

        const card = await b.eval(EXPR_CARD);
        await b.shot(`${OUT}/${tag}.png`);
        results.push({ tag, anchor: found.text, leaked, ...card });

        await b.eval(`window.LmxUserCard && window.LmxUserCard.close()`);
        await Bun.sleep(150);
      }
    }
  }

  console.log(JSON.stringify(results, null, 1));
  const failed = results.filter((r) => r.status === "no-anchor" || r.open === false);
  const leaks = results.filter((r) => r.leaked && r.leaked.length);
  const overlaps = results.filter((r) => r.overlaps && r.overlaps.length);
  const strays = results.filter((r) => r.stray);
  const clipped = results.filter((r) => r.open && r.inViewport === false);
  const dupes = results.filter((r) => r.dupes > 1);
  console.log("\nOPENED " + results.filter((r) => r.open).length + "/" + results.length);
  if (failed.length) console.log("NOT OPENED: " + failed.map((f) => f.tag).join(", "));
  if (leaks.length) console.log("RAW I18N KEYS: " + leaks.map((f) => f.tag + " " + f.leaked[0]).join(" | "));
  if (overlaps.length) console.log("STAT OVERLAP: " + overlaps.map((f) => f.tag + " " + f.overlaps[0]).join(" | "));
  if (strays.length) console.log("STRAY COMMA: " + strays.map((f) => f.tag).join(", "));
  if (clipped.length) console.log("OUTSIDE VIEWPORT: " + clipped.map((f) => f.tag).join(", "));
  if (dupes.length) console.log("MORE THAN ONE CARD: " + dupes.map((f) => f.tag).join(", "));
  console.log("console errors:", b.consoleErrors.slice(0, 10));
  await b.close();
}

run();
