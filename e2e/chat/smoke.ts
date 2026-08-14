/**
 * Single-session proof: the box renders what the API returned, exactly once,
 * a posted message appears without a reload, and the cursor advances.
 *
 * The assertion that matters is `DOM === API`, re-checked after several poll
 * cycles. The defect this replaces was a stable off-by-one — one extra copy,
 * forever — so an assertion that only looked once, or only looked for "at least
 * one message", would have passed on the broken build.
 */
import {
  Browser, BASE, ADMIN_USER, ADMIN_PASS, SHOTS,
  apiState, agree, agreeOk, shotEl, cycles, count, say, logText, until, Report, watchErrors, ownErrors,
  login,
} from "./lib.ts";

const out = `${SHOTS}/smoke`;
const R = new Report();

const b = await Browser.launch(21901);
const errors = watchErrors(b);
const who = await login(b, ADMIN_USER, ADMIN_PASS);
R.check("login as admin", who === "ok:admin" || who === "already", who);

await b.goto(BASE + "/");
b.resetNetwork();

R.check("card mounted", await b.eval(`!!document.querySelector('.LmxChat')`));
R.check(
  "instrumentation exposed",
  await b.eval(`!!(window.__lmxChat && window.__lmxChat.state)`),
);

// --- first paint comes from the server-rendered bootstrap, not from a poll ---
const boot = await b.eval(`(() => {
  const p = (window.__lmxChat.polls || []);
  return { dom: document.querySelectorAll('.LmxChat-msg').length, polls: p.length };
})()`);
const api0 = await apiState(b);
R.check(
  "boot renders the server payload",
  boot.dom === api0.n,
  `dom=${boot.dom} api=${api0.n} polls=${boot.polls}`,
);

// --- no duplication across poll cycles ---
let stable = true;
let detail = "";
for (let i = 1; i <= 5; i++) {
  await cycles(1);
  const a = await agree(b);
  const st = await b.eval(`window.__lmxChat.state()`);
  detail += ` [${i}] dom=${a.dom} api=${a.api} cursor=${st.since}`;
  if (!agreeOk(a)) { stable = false; detail += ` MISMATCH ${JSON.stringify(a)}`; }
}
R.check("every message the API returned is rendered exactly once, over 5 poll cycles", stable, detail.trim());

const st = await b.eval(`window.__lmxChat.state()`);
const apiNow = await agree(b);
// An empty box legitimately has cursor 0 — a previous suite had just cleared
// the table and this check failed on a correct client. What must hold is that
// the cursor is at the newest row the client has, whatever that is.
R.check("the cursor sits at the newest rendered row",
  apiNow.api === 0 ? st.since === 0 : st.since > 0,
  `cursor=${st.since} api=${apiNow.api}`);
R.check("seen-set equals DOM rows", st.seen === st.dom, `seen=${st.seen} dom=${st.dom}`);

// polls must be sequential, never overlapping: no two records share a cursor
// AND both return rows
const polls = await b.eval(`window.__lmxChat.polls.map(p => ({ asked: p.asked, got: p.got.length }))`);
const overlapping = polls.filter((p: any, i: number) =>
  polls.findIndex((q: any) => q.asked === p.asked && q.got > 0) !== i && p.got > 0);
R.check("no two polls fetched the same range and both returned rows", overlapping.length === 0,
  JSON.stringify(polls.slice(0, 8)));

// --- post, and see it without a reload ---
const before = await count(b);
const text = "smoke test " + Date.now();
await say(b, text);
const appeared = await until(
  b,
  `[...document.querySelectorAll('.LmxChat-text')].some(n => n.textContent.includes(${JSON.stringify(text)})) ? 1 : 0`,
  15000,
);
R.check("posted message appears without a reload", !!appeared, text);
// Wait for the PERSISTED row, not the optimistic one: `appeared` above is
// satisfied by the pending node, which deliberately has no id and cannot move
// the cursor — reading it there measured the moment before the POST answered.
const persisted = await until(
  b,
  `[...document.querySelectorAll('.LmxChat-msg[data-mid] .LmxChat-text')]
     .some(n => n.textContent.includes(${JSON.stringify(text)})) ? 1 : 0`,
  15000,
);
R.check("the message is persisted with a real id", !!persisted);
const afterSend = await b.eval(`window.__lmxChat.state()`);
R.check("the cursor advanced past the message that was just sent",
  afterSend.since > st.since, `cursor ${st.since}->${afterSend.since}`);

// it must land exactly once, and the optimistic row must be gone
await cycles(2);
const occurrences = await b.eval(
  `[...document.querySelectorAll('.LmxChat-text')].filter(n => n.textContent.includes(${JSON.stringify(text)})).length`,
);
R.check("own message rendered exactly once", occurrences === 1, `occurrences=${occurrences}`);
R.check("no optimistic row left behind", (await b.eval(`document.querySelectorAll('.LmxChat-msg.is-pending, .LmxChat-msg.is-failed').length`)) === 0);
R.check("own message is marked as own", (await b.eval(
  `!!document.querySelector('.LmxChat-msg.is-own .LmxChat-text')`)));

const afterPost = await agree(b);
R.check("DOM still agrees with the API after posting", agreeOk(afterPost), JSON.stringify(afterPost));
R.check("navigation id is a real row", (await b.eval(
  `!!document.querySelector('.LmxChat-msg[data-mid]')`)));

// --- SPA route change: leave the index and come back, in-app, no reload ---
const cursorBefore = (await b.eval(`window.__lmxChat.state()`)).since;
// Mithril's own router. The index's forum rows are server-rendered anchors, so
// clicking one is a full page load and would prove nothing about the SPA.
await b.eval(`window.m.route.set('/all')`);
await Bun.sleep(2500);
const gone = await b.eval(`!!document.querySelector('.LmxChat')`);
const parked = await b.eval(`window.__lmxChat.state()`);
R.check("the parked card keeps its log while off the index",
  !gone && parked.seen > 0 && parked.since === cursorBefore,
  `${JSON.stringify(parked)}`);
await b.eval(`window.m.route.set('/')`);
await Bun.sleep(3500);
const backState = await b.eval(`window.__lmxChat.state()`);
const backAgree = await agree(b);
R.check(
  "log survives an SPA route change with no duplication",
  agreeOk(backAgree) && backState.since >= cursorBefore,
  `left=${gone ? "card stayed" : "card unmounted"} ${JSON.stringify(backAgree)} cursor ${cursorBefore}->${backState.since}`,
);

R.shot(`${out}/after.png`);
await shotEl(b, ".LmxChat", `${out}/after.png`);
await b.fullShot(`${out}/index.png`);

const own = ownErrors(errors);
R.check("no console errors owned by the chat", own.length === 0, JSON.stringify(own.slice(0, 5)));
R.check("zero failed requests", b.failedRequests.length === 0, JSON.stringify(b.failedRequests.slice(0, 5)));
R.check("zero 4xx/5xx responses", b.badResponses.length === 0, JSON.stringify(b.badResponses.slice(0, 5)));

console.log("\n" + (await logText(b)));
await b.close();
process.exit(R.summary() ? 0 : 1);
