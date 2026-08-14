/**
 * Every state the box can be in, captured as pixels.
 *
 * Every layout defect on this project so far was invisible to a DOM assertion
 * and obvious in a screenshot: an element can be present, correctly classed and
 * the right size while overflowing its column, clipping its own text or sitting
 * behind something. So each state here is both asserted and photographed, and
 * the geometry checks below (nothing wider than the card, nothing clipped, the
 * log actually scrollable) are the ones a class-name assertion cannot make.
 *
 * The message fixtures are written into the database directly rather than typed
 * in, because the server rate-limits one author to a message every 2 seconds and
 * a 12-message fixture would otherwise take half a minute and test the rate
 * limiter instead of the layout.
 */
import {
  Browser, BASE, ADMIN_USER, ADMIN_PASS, SHOTS,
  fresh, sql, shotEl, count, say, until, Report, sleep, cycles, watchErrors, ownErrors,
  login,
} from "./lib.ts";

const out = `${SHOTS}/states`;
const R = new Report();

const esc = (s: string) => s.replace(/\\/g, "\\\\").replace(/"/g, '\\"');

async function seed(rows: [string, number, string][]) {
  const values = rows
    .map(([user, id, body], i) =>
      `(${id}, "${esc(user)}", "${esc(body)}", DATE_SUB(NOW(), INTERVAL ${(rows.length - i) * 37} SECOND))`)
    .join(",");
  await sql(`INSERT INTO chat_messages (user_id, username, body, created_at) VALUES ${values}`);
}

const ADMIN_ID = 1;
const T_ID = Number((await sql(`SELECT id FROM users WHERE username="chattester"`)).trim().split("\n").pop());
const T2_ID = Number((await sql(`SELECT id FROM users WHERE username="chattester2"`)).trim().split("\n").pop());
R.check("fixture users resolved", !!T_ID && !!T2_ID, `chattester=${T_ID} chattester2=${T2_ID}`);

// ---------------------------------------------------------------- 1. empty
await sql("DELETE FROM chat_messages");

const b = await fresh(21941);
const errors = watchErrors(b);
const l = await login(b, ADMIN_USER, ADMIN_PASS);
R.check("logged in", l.startsWith("ok:"), l);
await b.goto(BASE + "/");

R.check("empty log shows an empty state, not a blank box",
  (await b.eval(`(() => { const e = document.querySelector('.LmxChat-empty');
     return !!e && !e.hidden && e.getBoundingClientRect().height > 40; })()`)));
R.check("empty state: no message rows", (await count(b)) === 0);
await shotEl(b, ".LmxChat", `${out}/01-empty.png`);

// ------------------------------------------------------------ 2. one message
await say(b, "First message in the box.");
await until(b, `document.querySelectorAll('.LmxChat-msg').length ? 1 : 0`, 12000);
R.check("one message hides the empty state",
  (await b.eval(`document.querySelector('.LmxChat-empty').hidden`)) === true);
await shotEl(b, ".LmxChat", `${out}/02-one-message.png`);

// ----------------------------------------------------- 3. a conversation
await seed([
  ["chattester", T_ID, "morning"],
  ["chattester", T_ID, "anyone tried the mewing routine from the guide"],
  ["chattester2", T2_ID, "yeah for about 3 months"],
  ["chattester2", T2_ID, "jaw is noticeably sharper, photos are in my thread"],
  ["admin", ADMIN_ID, "post them in /d/12 so people can find them later"],
  ["chattester", T_ID, "link? @chattester2"],
  ["chattester2", T2_ID, "https://looksmax.lat/d/12-my-3-month-mewing-progress-with-photos"],
  ["chattester", T_ID, "🔥"],
  ["admin", ADMIN_ID, "that is a big improvement :fire: :100:"],
  ["chattester2", T2_ID, "cheers. next up is the marpe consult, will report back"],
]);
await cycles(2);

const domN = await count(b);
R.check("the seeded conversation renders", domN >= 11, `rows=${domN}`);
R.check("consecutive messages from one author are grouped",
  (await b.eval(`document.querySelectorAll('.LmxChat-msg.is-cont').length`)) >= 2);
R.check("a URL becomes a link", (await b.eval(
  `(() => { const a = document.querySelector('.LmxChat-link');
     return !!a && a.href.includes('/d/12-my-3-month') && a.target === '_blank'
            && a.rel.includes('noopener'); })()`)));
R.check("a @name becomes a profile link", (await b.eval(
  `(() => { const a = document.querySelector('.LmxChat-mention');
     return !!a && a.getAttribute('href') === '/u/chattester2'; })()`)));
R.check("a shortcode becomes an emoji", (await b.eval(
  `[...document.querySelectorAll('.LmxChat-text')].some(n => n.textContent.includes('🔥')
     && !n.textContent.includes(':fire:'))`)));
R.check("an all-emoji line renders large", (await b.eval(
  `document.querySelectorAll('.LmxChat-text.is-jumbo').length >= 1`)));
R.check("avatars render", (await b.eval(
  `document.querySelectorAll('.LmxChat-avatar .Avatar').length >= 5`)));
// settled rows only: a row still in flight reads "sending…" and a failed one
// reads "failed", both of which are correct and neither of which is a time
R.check("timestamps render", (await b.eval(
  `(() => { const ts = [...document.querySelectorAll(
       '.LmxChat-msg:not(.is-pending):not(.is-failed) .LmxChat-time')];
     return ts.length > 0 && ts.every(t => /^(now|\\d+[smhd])$/.test(t.textContent.trim())); })()`)));
await shotEl(b, ".LmxChat", `${out}/03-conversation.png`);

// --- geometry: what a DOM assertion cannot see ------------------------------
const geo = await b.eval(`(() => {
  const card = document.querySelector('.LmxChat');
  const cr = card.getBoundingClientRect();
  const log = document.querySelector('.LmxChat-log');
  // every descendant, not just the rows: a child with a fixed width overflows
  // its parent's content while the parent's own border box stays put, so a
  // row-level measurement cannot see it (proved in redteam.ts)
  const rows = [...card.querySelectorAll('*')].filter(n => getComputedStyle(n).position !== 'absolute');
  const over = rows.filter(n => n.getBoundingClientRect().right > cr.right - 1).length;
  const texts = [...document.querySelectorAll('.LmxChat-text')];
  const clipped = texts.filter(n => n.scrollWidth > n.clientWidth + 1).length;
  return {
    cardW: Math.round(cr.width),
    logScrollable: log.scrollHeight > log.clientHeight,
    pinnedToBottom: log.scrollHeight - log.scrollTop - log.clientHeight < 4,
    overflowing: over, clipped,
    logScrollX: log.scrollWidth > log.clientWidth,
    formBelowLog: document.querySelector('.LmxChat-form').getBoundingClientRect().top >= log.getBoundingClientRect().bottom - 1,
  };
})()`);
R.check("nothing inside the card overflows it", geo.overflowing === 0 && !geo.logScrollX, JSON.stringify(geo));
R.check("no message text is clipped horizontally", geo.clipped === 0, JSON.stringify(geo));
R.check("a full log scrolls and stays pinned to the newest message",
  geo.logScrollable && geo.pinnedToBottom, JSON.stringify(geo));
R.check("the composer sits below the log", geo.formBelowLog, JSON.stringify(geo));

// ------------------------------------------------------- 4. long message
await sql(`DELETE FROM chat_messages`);
await seed([
  ["chattester", T_ID,
    "this is the long one: a single message with no line breaks that keeps going well past the width of a 300 pixel sidebar column, because somebody will paste a paragraph into a shoutbox on day one and the box has to survive it without pushing the card wider than the column or hiding the send button"],
  ["admin", ADMIN_ID, "supercalifragilisticexpialidociousandthenaverylongunbrokentokenthatcannotwrapnormally"],
]);
await cycles(2);
const long = await b.eval(`(() => {
  const card = document.querySelector('.LmxChat').getBoundingClientRect();
  const side = document.querySelector('.LmxIndex-side').getBoundingClientRect();
  const texts = [...document.querySelectorAll('.LmxChat-text')];
  return { cardW: Math.round(card.width), sideW: Math.round(side.width),
           spill: texts.filter(n => n.getBoundingClientRect().right > card.right - 2).length,
           unbroken: texts.some(n => n.scrollWidth > n.clientWidth + 1) };
})()`);
R.check("a long message does not widen the card", long.cardW <= long.sideW + 1, JSON.stringify(long));
R.check("an unbreakable token wraps instead of spilling", !long.unbroken && long.spill === 0, JSON.stringify(long));
await shotEl(b, ".LmxChat", `${out}/04-long-message.png`);

// ---------------------------------------------------- 5. many + unread pill
await sql(`DELETE FROM chat_messages`);
await seed(Array.from({ length: 24 }, (_, i) =>
  [i % 2 ? "chattester" : "chattester2", i % 2 ? T_ID : T2_ID, `scrollback line ${i + 1}`] as [string, number, string]));
await cycles(2);
await b.eval(`document.querySelector('.LmxChat-log').scrollTop = 0`);
await sleep(600);
// confirm the precondition rather than assuming it: if the client still thinks
// it is pinned to the bottom, the pill is correct NOT to appear and the check
// below would be testing nothing
R.check("scrolling up unpins the log",
  (await b.eval(`window.__lmxChat.state().pinned`)) === false);
await seed([["chattester", T_ID, "a new one while you were reading back"]]);
R.check("scrolled up + a new message shows a jump-to-latest pill",
  !!(await until(b, `(() => { const j = document.querySelector('.LmxChat-jump');
     return (!!j && !j.hidden && /new/.test(j.textContent)) ? 1 : 0; })()`, 14000)));
R.check("the view did not jump while scrolled up",
  (await b.eval(`document.querySelector('.LmxChat-log').scrollTop < 40`)));
await shotEl(b, ".LmxChat", `${out}/05-unread-pill.png`);
await b.eval(`document.querySelector('.LmxChat-jump').click()`);
await sleep(900);
R.check("the pill scrolls to the newest message and clears",
  (await b.eval(`(() => { const l = document.querySelector('.LmxChat-log');
     return document.querySelector('.LmxChat-jump').hidden
            && l.scrollHeight - l.scrollTop - l.clientHeight < 6; })()`)));
await shotEl(b, ".LmxChat", `${out}/06-many-messages.png`);

// -------------------------------------------------------- 6. compose states
await b.eval(`(() => { const i = document.querySelector('.LmxChat-input');
  i.focus();
  Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(i, 'x'.repeat(360));
  i.dispatchEvent(new Event('input', { bubbles: true })); })()`);
await sleep(300);
R.check("the character counter appears near the limit",
  (await b.eval(`(() => { const r = document.querySelector('.LmxChat-remain');
     return !!r && !r.hidden && r.textContent === '40'; })()`)));
await shotEl(b, ".LmxChat-form", `${out}/07-compose-counter.png`, 6);
await b.eval(`(() => { const i = document.querySelector('.LmxChat-input');
  Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(i, '');
  i.dispatchEvent(new Event('input', { bubbles: true })); })()`);
R.check("send is disabled with an empty input",
  (await b.eval(`document.querySelector('.LmxChat-send').disabled`)) === true);

// ------------------------------------------------------------- 7. rate limit
await say(b, "rate limit probe one");
await sleep(600);
await say(b, "rate limit probe two");
const rated = await until(b, `(() => { const a = document.querySelector('.LmxChat-alert');
  return a && !a.hidden && /slow down/i.test(a.textContent) ? 1 : 0; })()`, 8000);
R.check("the rate limiter surfaces as a message, not a silent drop", !!rated);
await shotEl(b, ".LmxChat", `${out}/08-rate-limited.png`);

// -------------------------------------------------------------- 8. logged out
const g = await fresh(21942);
await g.goto(BASE + "/");
await until(g, `document.querySelector('.LmxChat') ? 1 : 0`, 15000);
await cycles(1);
const guestCard = await g.eval(`!!document.querySelector('.LmxChat')`);
R.check("logged out: the card mounts at all", guestCard,
  guestCard ? "" : "page=" + (await g.eval(`document.title + ' :: ' + document.body.innerText.slice(0,180)`)));
R.check("logged out: prompt instead of a composer",
  (await g.eval(`(() => { const f = document.querySelector('.LmxChat-form');
     const gu = document.querySelector('.LmxChat-guest');
     return (!f || f.hidden) && !!gu && !gu.hidden; })()`)));
R.check("logged out: messages are still readable", (await count(g)) > 0);
await shotEl(g, ".LmxChat", `${out}/09-logged-out.png`);
await g.eval(`(() => { const b = document.querySelector('.LmxChat-login'); if (b) b.click(); return !!b; })()`);
await sleep(1500);
R.check("the log-in prompt opens Flarum's login modal",
  (await g.eval(`!!document.querySelector('.Modal .LogInModal, .Modal-content form')`)));
await g.shot(`${out}/10-login-modal.png`);
await g.close();

// --------------------------------------------------- 8b. the other surfaces
// The card mounts wherever looksmax-index puts its sidebar, which is `/` and
// `/tags`. "Zero console errors on any surface the chat renders on" has to
// include the second one, and the routes it does NOT render on have to cost
// nothing at all.
await b.goto(BASE + "/tags");
await cycles(1);
R.check("the card mounts on /tags too", (await b.eval(`!!document.querySelector('.LmxIndex-side .LmxChat')`)));
R.check("/tags: messages render there as well", (await count(b)) > 0);
await shotEl(b, ".LmxChat", `${out}/13-tags.png`);

// A full navigation to a route with no sidebar: a fresh JS context, so the
// question is not "was the cursor kept" (it cannot be — that is what an SPA
// route change is for, asserted in smoke.ts) but "does the client cost
// anything at all on a page it does not render on".
await b.goto(BASE + "/all");
await sleep(7000);
const offIndex = await b.eval(`({ card: !!document.querySelector('.LmxChat'),
  polls: window.__lmxChat.polls.length, state: window.__lmxChat.state() })`);
R.check("off the index the card is gone and nothing is fetched",
  !offIndex.card && offIndex.polls === 0,
  `polls=${offIndex.polls} in 7s off-index, card=${offIndex.card}`);

// …and the SPA version of the same journey, where state IS kept.
await b.goto(BASE + "/");
await cycles(1);
const beforeSpa = await b.eval(`window.__lmxChat.state()`);
// Through Mithril's router, NOT by clicking a forum row: looksmax-index renders
// its rows as plain server-side anchors, so clicking one is a full page load
// (measured — the whole client state reset to zero, which is correct behaviour
// for a navigation and proves nothing about an SPA route change).
await b.eval(`window.m.route.set('/all')`);
await sleep(3000);
const during = await b.eval(`({ card: !!document.querySelector('.LmxChat'), state: window.__lmxChat.state() })`);
R.check("an in-app route change parks the card and keeps its log",
  !during.card && during.state.since === beforeSpa.since && during.state.seen === beforeSpa.seen,
  `${JSON.stringify(beforeSpa)} -> ${JSON.stringify(during.state)}`);
await b.eval(`window.m.route.set('/')`);
await sleep(3500);
const backOn = await b.eval(`({ card: !!document.querySelector('.LmxIndex-side .LmxChat'),
  dom: document.querySelectorAll('.LmxChat-msg').length, state: window.__lmxChat.state() })`);
R.check("coming back re-mounts the same log, with the same cursor",
  backOn.card && backOn.dom > 0 && backOn.state.since >= beforeSpa.since, JSON.stringify(backOn));

// ------------------------------------------------------------- 9. mobile
await b.viewport({ name: "mobile", width: 420, height: 844, mobile: true });
await b.goto(BASE + "/");
await cycles(1);
const mob = await b.eval(`(() => {
  const c = document.querySelector('.LmxChat');
  if (!c) return null;
  const r = c.getBoundingClientRect();
  const send = document.querySelector('.LmxChat-send').getBoundingClientRect();
  const input = document.querySelector('.LmxChat-input');
  const side = document.querySelector('.LmxIndex-side').getBoundingClientRect();
  const idx = document.querySelector('.LmxIndex');
  const siblings = [...document.querySelectorAll('.LmxIndex-side > .LmxCard')]
    .filter(n => !n.classList.contains('LmxChat'))
    .map(n => Math.round(n.getBoundingClientRect().width));
  return { w: Math.round(r.width), right: Math.round(r.right), inner: window.innerWidth,
           sideW: Math.round(side.width), siblings,
           // whose column is it: if the sidebar is narrower than the viewport on a
           // phone that is the index lane's grid, not the card
           indexCols: getComputedStyle(idx).gridTemplateColumns,
           mq980: matchMedia('(max-width: 980px)').matches,
           sendVisible: send.width > 10 && send.right <= window.innerWidth,
           fontPx: parseFloat(getComputedStyle(input).fontSize),
           logH: Math.round(document.querySelector('.LmxChat-log').getBoundingClientRect().height) };
})()`);
R.check("mobile: the card fits the viewport", !!mob && mob.right <= mob.inner + 1, JSON.stringify(mob));
// NOT "fills its column": `looksmax-userinfo` defines an unscoped
// `.LmxCard { width: 300px }` for its hovercard, and `looksmax-index` uses the
// same class for the rail, so every card in the sidebar — not just this one —
// is 300px inside a 390px column on a phone. That is logged in HANDOFF-UI.md
// and is not this lane's to change; what IS this lane's is that the chat card
// behaves exactly like its siblings rather than being the odd one out.
R.check("mobile: the card is exactly as wide as the other sidebar cards",
  !!mob && mob.siblings.length > 0 && mob.siblings.every((w: number) => Math.abs(w - mob.w) <= 1),
  JSON.stringify(mob));
R.check("mobile: the send button is on screen", !!mob && mob.sendVisible, JSON.stringify(mob));
R.check("mobile: the input does not trigger iOS zoom (>=16px)", !!mob && mob.fontPx >= 16, JSON.stringify(mob));
await shotEl(b, ".LmxChat", `${out}/11-mobile.png`);
await b.shot(`${out}/12-mobile-page.png`);

// Leave the box holding a plausible conversation rather than the fixtures — the
// index is the first thing every other lane screenshots.
await sql("DELETE FROM chat_messages");
await seed([
  ["chattester", T_ID, "morning"],
  ["chattester2", T2_ID, "anyone got the before/after thread from last week"],
  ["admin", ADMIN_ID, "pinned it — https://looksmax.lat/d/12-my-3-month-mewing-progress-with-photos"],
  ["chattester2", T2_ID, "cheers @admin"],
  ["chattester", T_ID, "🔥"],
  ["admin", ADMIN_ID, "keep ratings in the rate-me forum please, this box scrolls too fast for them"],
]);

const own = ownErrors(errors);
R.check("no console errors owned by the chat", own.length === 0, JSON.stringify(own.slice(0, 5)));
R.check("no 4xx/5xx other than the deliberate rate-limit probe",
  b.badResponses.filter((r) => r.status !== 429).length === 0,
  JSON.stringify(b.badResponses.slice(0, 5)));

await b.close();
process.exit(R.summary() ? 0 : 1);
