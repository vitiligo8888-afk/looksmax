/**
 * Two authenticated sessions, in two browser profiles, talking to each other.
 *
 * One session can only prove that a client agrees with itself. Everything the
 * shoutbox claims — that a message propagates, that a deletion propagates, that
 * "3 here" counts people rather than a constant — needs a second party that the
 * first one cannot see the state of.
 *
 * The presence table is truncated first so leftover rows from an earlier run do
 * not linger for 90 seconds, but the assertions are still on deltas and on
 * names — see the block above the first one for why an absolute count cannot be
 * asserted on a box other lanes are also driving browsers at.
 */
import { createHash } from "node:crypto";
import {
  Browser, BASE, ADMIN_USER, ADMIN_PASS, SHOTS,
  fresh, sql, agree, agreeOk, shotEl, cycles, count, say, until, Report, sleep, watchErrors, ownErrors, killBrowser,
  login,
} from "./lib.ts";

const out = `${SHOTS}/two-party`;
const R = new Report();
const U2 = { user: "chattester", pass: "Chat!Tester2026" };
const U3 = { user: "chattester2", pass: "Chat!Tester2026" };

/**
 * Presence is asserted on DELTAS and on names, never on an absolute count.
 *
 * The box is shared: other lanes drive their own headless browsers against the
 * same forum continuously, and each of those is a real anonymous viewer that
 * the count is right to include. Measured while writing this — three unexplained
 * guest rows, from a second source address that none of these browsers use.
 * Asserting `online === 1` tests the neighbours, not the feature.
 *
 * So: the named-member list must change exactly by the member who arrived, and
 * the specific guest row this test creates is looked up by the key the server
 * derives from that browser's own client id.
 */
await sql("TRUNCATE TABLE chat_presence");

/** The presence key the server computes for a guest with this client id. */
const guestKey = (cid: string) =>
  "g" + createHash("sha256").update("cid|" + cid).digest("hex").slice(0, 30);

const A = await fresh(21911);
const errorsA = watchErrors(A);
const a = await login(A, ADMIN_USER, ADMIN_PASS);
R.check("session A logged in", a.startsWith("ok:"), a);
await A.goto(BASE + "/");

// --- one member ------------------------------------------------------------
await A.eval(`window.__lmxChat.poll()`);
await sleep(1500);
const one = await A.eval(`(() => { const s = window.__lmxChat.state(); return s; })()`);
const members1 = await A.eval(
  `fetch('/api/chat?since=0&cid=' + localStorage.getItem('lmx-chat-cid'))
     .then(r => r.json()).then(j => j.members)`, true);
R.check("the member who is here is named", members1.includes("admin") && !members1.includes("chattester"),
  `members=${JSON.stringify(members1)} online=${one.online}`);
R.check("logging in does not leave a ghost guest behind",
  (await sql(`SELECT COUNT(*) FROM chat_presence WHERE \`key\`="${guestKey(await A.eval(`localStorage.getItem('lmx-chat-cid')`))}"`)).includes("0"),
  "the guest row from before the login must be gone");

// --- second member ---------------------------------------------------------
const B = await fresh(21912);
const errorsB = watchErrors(B);
const bLogin = await login(B, U2.user, U2.pass);
R.check("session B logged in", bLogin.startsWith("ok:"), bLogin);
await B.goto(BASE + "/");

await A.eval(`window.__lmxChat.poll()`);
await sleep(1800);
const two = await A.eval(`window.__lmxChat.state()`);
const members2 = await A.eval(
  `fetch('/api/chat?since=0&cid=' + localStorage.getItem('lmx-chat-cid'))
     .then(r => r.json()).then(j => j.members)`, true);
// ">=", not "== +1": strangers arrive. "Chris" — another lane's logged-in
// browser — joined between these two reads and made it +2. The part that is
// this test's to assert is that the member who arrived is named.
R.check("a second member appears in the count and by name",
  two.online > one.online && members2.includes("admin") && members2.includes(U2.user),
  `online ${one.online}->${two.online} members=${JSON.stringify(members2)}`);

// --- an anonymous viewer ---------------------------------------------------
const C = await fresh(21913);
await C.goto(BASE + "/");
await sleep(1500);
const cCid = await C.eval(`localStorage.getItem('lmx-chat-cid')`);
await A.eval(`window.__lmxChat.poll()`);
await sleep(1800);
const three = await A.eval(`window.__lmxChat.state()`);
R.check("a logged-out viewer counts too", three.online > two.online,
  `online ${two.online}->${three.online}`);
R.check("that viewer has its own presence row, keyed on its own client id",
  (await sql(`SELECT COUNT(*) FROM chat_presence WHERE \`key\`="${guestKey(cCid)}"`)).trim().endsWith("1"),
  `cid=${cCid}`);
R.check("it is counted as a guest, not as a member",
  three.guests > two.guests && !three.members.includes("(guest)"),
  `guests ${two.guests}->${three.guests} members=${JSON.stringify(three.members)}`);
R.check("guest cannot post", (await C.eval(`window.__lmxChat.state().canPost`)) === false);
R.check("guest sees the log-in prompt",
  (await C.eval(`(() => { const g = document.querySelector('.LmxChat-guest'); return !!g && !g.hidden; })()`)));
R.check("guest sees no compose form",
  (await C.eval(`(() => { const f = document.querySelector('.LmxChat-form'); return !f || f.hidden; })()`)));
await shotEl(C, ".LmxChat", `${out}/guest.png`);

// --- A -> B ------------------------------------------------------------------
const m1 = "from admin " + Date.now();
await say(A, m1);
const sawA = await until(
  B,
  `[...document.querySelectorAll('.LmxChat-text')].filter(n => n.textContent.includes(${JSON.stringify(m1)})).length`,
  15000,
);
R.check("B receives A's message", sawA === 1, `copies=${sawA}`);

// --- B -> A ------------------------------------------------------------------
const m2 = "from chattester " + Date.now();
await say(B, m2);
const sawB = await until(
  A,
  `[...document.querySelectorAll('.LmxChat-text')].filter(n => n.textContent.includes(${JSON.stringify(m2)})).length`,
  15000,
);
R.check("A receives B's message", sawB === 1, `copies=${sawB}`);

// --- a burst both ways, then compare both DOMs with the API -----------------
for (let i = 0; i < 3; i++) {
  await say(A, `ping ${i} ${Date.now()}`);
  await sleep(3200);              // the server rate-limits one author to 1/2s
  await say(B, `pong ${i} ${Date.now()}`);
  await sleep(3200);
}
await cycles(2);

const agA = await agree(A);
const agB = await agree(B);
const agC = await agree(C);
R.check("A's log agrees with the API after a burst", agreeOk(agA), JSON.stringify(agA));
R.check("B's log agrees with the API after a burst", agreeOk(agB), JSON.stringify(agB));
R.check("the logged-out viewer sees the same log", agreeOk(agC), JSON.stringify(agC));
R.check("no duplicate message ids in either session",
  agA.duplicated.length === 0 && agB.duplicated.length === 0,
  `A=${JSON.stringify(agA.duplicated)} B=${JSON.stringify(agB.duplicated)}`);

R.check("own and others' messages are distinguishable",
  (await A.eval(`document.querySelectorAll('.LmxChat-msg.is-own').length > 0
                 && document.querySelectorAll('.LmxChat-msg:not(.is-own)').length > 0`)));

await shotEl(A, ".LmxChat", `${out}/session-a.png`);
await shotEl(B, ".LmxChat", `${out}/session-b.png`);

// --- moderation propagates ---------------------------------------------------
const delId = await A.eval(
  `(() => { const n = [...document.querySelectorAll('.LmxChat-msg[data-mid]')].pop();
     return n ? n.getAttribute('data-mid') : null; })()`,
);
R.check("admin sees a delete control", (await A.eval(
  `!!document.querySelector('.LmxChat-msg.can-delete .LmxChat-del')`)));
await A.eval(`(() => { const n = document.querySelector('.LmxChat-msg[data-mid="${delId}"] .LmxChat-del');
  if (n) n.click(); return !!n; })()`);
const goneA = await until(A, `document.querySelector('.LmxChat-msg[data-mid="${delId}"]') ? 0 : 1`, 10000);
R.check("deleted message disappears for the moderator", !!goneA, `id=${delId}`);
const goneB = await until(B, `document.querySelector('.LmxChat-msg[data-mid="${delId}"]') ? 0 : 1`, 20000);
R.check("deletion propagates to the other session", !!goneB, `id=${delId}`);

// a non-moderator must not be able to delete someone else's line
const otherId = await B.eval(
  `(() => { const n = [...document.querySelectorAll('.LmxChat-msg[data-mid]')]
       .find(x => !x.classList.contains('is-own'));
     return n ? n.getAttribute('data-mid') : null; })()`,
);
if (otherId) {
  const status = await B.eval(
    `fetch('/api/chat/${otherId}', { method: 'DELETE', credentials: 'same-origin',
       headers: { 'X-CSRF-Token': window.flarum.core.app.session.csrfToken } }).then(r => r.status)`,
    true,
  );
  R.check("a normal member cannot delete someone else's message", status === 403, `status=${status}`);
  R.check("no delete control on other people's messages for a normal member",
    (await B.eval(`document.querySelectorAll('.LmxChat-msg:not(.is-own).can-delete').length`)) === 0);
}

// --- presence falls when a session leaves ------------------------------------
// The real 90s window, not a simulated one: the count has to fall because a
// browser stopped polling, which is the only thing that makes it a signal.
await C.close();
await killBrowser(21913);   // close() is not enough; see killBrowser()
console.log("waiting out the 90s presence window…");
await sleep(100000);
await A.eval(`window.__lmxChat.poll()`);
await sleep(2000);
const after = await A.eval(`window.__lmxChat.state()`);
// The guest count is the part this test owns; `online` is a sum that also
// contains strangers, and one of them logged in during the 100s wait (members
// 2 -> 3 while guests went 3 -> 2, so the total did not move). So: the guest
// this test created is gone, and the total is still exactly the sum of its
// parts — which is also what proves the number is computed and not decorative.
// The specific row, not the total: six unrelated guests arrived during the
// 100s wait (other lanes' browsers — the box is shared), so `guests` went
// 3 -> 9 while the viewer this test created did leave. A neighbour arriving
// must not be able to fail this.
const stillThere = (await sql(
  `SELECT COUNT(*) FROM chat_presence WHERE \`key\`="${guestKey(cCid)}" AND seen_at > NOW() - INTERVAL 90 SECOND`,
)).trim();
R.check("the departed viewer's own presence row has aged out", stillThere.endsWith("0"),
  `rows still counted for cid=${cCid}: ${JSON.stringify(stillThere)} (guests overall ${three.guests}->${after.guests})`);
R.check("presence is the sum of the members and guests actually here",
  after.online === after.members.length + after.guests,
  `online=${after.online} members=${after.members.length} guests=${after.guests}`);
R.check("the members still here are still named",
  after.members.includes("admin") && after.members.includes(U2.user),
  JSON.stringify(after.members));

R.check("session A: no console errors owned by the chat", ownErrors(errorsA).length === 0,
  JSON.stringify(ownErrors(errorsA).slice(0, 4)));
R.check("session B: no console errors owned by the chat", ownErrors(errorsB).length === 0,
  JSON.stringify(ownErrors(errorsB).slice(0, 4)));
// 502/503/504/530 come from the trycloudflare edge, not from the app: the
// request never reached PHP. They are counted and reported rather than
// asserted on, because the client's job is to survive them (it does — they are
// a failed poll, a backoff and a recovery) and this lane cannot fix a tunnel.
const edge = A.badResponses.filter((r) => [502, 503, 504, 520, 521, 522, 530].includes(r.status));
// 429 is the rate limiter answering correctly, and a burst test is exactly the
// thing that trips it; it is reported rather than treated as a defect. 403s on
// /api/chat/{id} are the deliberate permission probe above.
const rateLimited = A.badResponses.filter((r) => r.status === 429);
const appErrors = A.badResponses.filter(
  (r) => !edge.includes(r) && r.status !== 429 && !/\/api\/chat\/\d+$/.test(r.url),
);
R.check("no unexpected 4xx/5xx from the application", appErrors.length === 0, JSON.stringify(appErrors.slice(0, 4)));
console.log(`rate-limited sends during the burst: ${rateLimited.length}`);
console.log(`tunnel-level failures tolerated: ${edge.length} ${JSON.stringify(edge.slice(0, 3))}`);

await A.close();
await B.close();
process.exit(R.summary() ? 0 : 1);
