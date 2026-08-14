/**
 * The paths that only exist when something is broken.
 *
 * A poller is easy to make look right on a good network. What decides whether
 * it is shippable is what it does when the endpoint stops answering: whether it
 * spins, whether it tells the user, whether it double-renders on recovery, and
 * what it does to the server when a tab that has been backgrounded for a minute
 * comes back.
 *
 * Every phase here breaks the network from outside the page (CDP), never by
 * poking the client's own state, so the client has no way to know it is a test.
 */
import {
  Browser, BASE, ADMIN_USER, ADMIN_PASS, SHOTS,
  fresh, agree, agreeOk, shotEl, count, say, until, Report, sleep, cycles, watchErrors, ownErrors,
  login,
} from "./lib.ts";

const out = `${SHOTS}/failure`;
const R = new Report();

const A = await fresh(21931);
const errorsA = watchErrors(A);
const la = await login(A, ADMIN_USER, ADMIN_PASS);
R.check("session A logged in", la.startsWith("ok:"), la);
await A.goto(BASE + "/");

const B = await fresh(21932);
const lb = await login(B, "chattester", "Chat!Tester2026");
R.check("session B logged in", lb.startsWith("ok:"), lb);
await B.goto(BASE + "/");

const pollsOf = (b: Browser) => b.eval(`window.__lmxChat.polls.length`);
const stateOf = (b: Browser) => b.eval(`window.__lmxChat.state()`);

// =============================================================== 1. blocked
console.log("\n-- phase 1: endpoint blocked --");
const before1 = await pollsOf(A);
await A.send("Network.setBlockedURLs", { urls: ["*/api/chat*"] });
await sleep(22000);

const blocked = await stateOf(A);
const attempts1 = (await pollsOf(A)) - before1;
R.check("a blocked endpoint shows a visible offline state",
  (await A.eval(`(() => { const a = document.querySelector('.LmxChat-alert');
     return !!a && !a.hidden && a.className.includes('is-error') &&
            document.querySelector('.LmxChat').classList.contains('is-offline'); })()`)),
  `failures=${blocked.failures} backoff=${blocked.backoff}`);
R.check("it backs off instead of spinning", attempts1 <= 8 && blocked.backoff >= 12000,
  `${attempts1} attempts in 22s, backoff=${blocked.backoff}ms`);
R.check("a retry control is offered", (await A.eval(`!!document.querySelector('.LmxChat-alert .LmxChat-retry')`)));
await shotEl(A, ".LmxChat", `${out}/offline.png`);

// The other party keeps talking while A is deaf.
//
// B's sends are checked here, not assumed: a run where two of these three never
// reached the database reported itself as "A did not receive them", which is a
// different bug in a different component. A fixture that can fail silently
// makes every assertion downstream of it a lie.
const missed: string[] = [];
for (let i = 0; i < 3; i++) {
  const t = `missed ${i} ${Date.now()}`;
  missed.push(t);
  const sent = await say(B, t);
  if (sent !== "sent") R.check(`fixture: B could compose "${t.slice(0, 9)}"`, false, String(sent));
  await sleep(3200);
}
await sleep(1500);
const bSends = await B.eval(`window.__lmxChat.sends`);
R.check("fixture: every message B sent was accepted",
  Array.isArray(bSends) && bSends.length >= 3 && bSends.slice(-3).every((s: any) => s.status === 200),
  JSON.stringify(bSends));

// --- sending while offline -------------------------------------------------
const orphan = "sent while offline " + Date.now();
await say(A, orphan);
await sleep(4000);
R.check("a send that cannot reach the server is marked failed, not lost",
  (await A.eval(`(() => { const n = [...document.querySelectorAll('.LmxChat-msg.is-failed .LmxChat-text')]
      .find(x => x.textContent.includes(${JSON.stringify(orphan)}));
     return !!n && !!document.querySelector('.LmxChat-msg.is-failed .LmxChat-failnote .LmxChat-retry'); })()`)));
await shotEl(A, ".LmxChat", `${out}/send-failed.png`);

// =============================================================== 2. recovery
console.log("\n-- phase 2: recovery --");
await A.send("Network.setBlockedURLs", { urls: [] });
// NOT "the alert went away": a failed SEND also raises the alert and clears it
// on a 6s timer, so `alert.hidden` goes true without a single successful poll.
// That predicate passed while the client was still 24s into its backoff and
// three messages behind — the check has to be a poll that actually succeeded.
const recovered = await until(
  A,
  `(() => { const p = window.__lmxChat.polls; const last = p[p.length - 1];
     return last && last.status === 'ok' ? 1 : 0; })()`,
  45000,
);
R.check("recovers on its own once the endpoint answers again", !!recovered,
  `backoff was ${(await stateOf(A)).backoff}ms`);

await cycles(2);
const agR = await agree(A);
R.check("no duplication and nothing lost on recovery", agreeOk(agR), JSON.stringify(agR));
for (const t of missed) {
  const c = await A.eval(
    `[...document.querySelectorAll('.LmxChat-text')].filter(n => n.textContent.includes(${JSON.stringify(t)})).length`,
  );
  R.check(`missed message delivered exactly once: "${t.slice(0, 12)}…"`, c === 1, `copies=${c}`);
}

// --- retrying the failed send ----------------------------------------------
await A.eval(`(() => { const b = document.querySelector('.LmxChat-msg.is-failed .LmxChat-failnote .LmxChat-retry');
  if (b) b.click(); return !!b; })()`);
const retried = await until(
  A,
  `[...document.querySelectorAll('.LmxChat-msg:not(.is-failed) .LmxChat-text')]
     .filter(n => n.textContent.includes(${JSON.stringify(orphan)})).length`,
  15000,
);
R.check("retry sends the failed message exactly once", retried === 1, `copies=${retried}`);
await cycles(2);
R.check("retry did not leave a second copy",
  (await A.eval(`[...document.querySelectorAll('.LmxChat-text')].filter(n => n.textContent.includes(${JSON.stringify(orphan)})).length`)) === 1);

// ============================================================ 3. offline flag
console.log("\n-- phase 3: emulated offline --");
const before3 = await pollsOf(A);
await A.send("Network.emulateNetworkConditions", {
  offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1,
});
await sleep(16000);
const off = await stateOf(A);
R.check("emulated offline is handled the same way", off.failures >= 1 && off.backoff > 0,
  `failures=${off.failures} backoff=${off.backoff} attempts=${(await pollsOf(A)) - before3}`);
await A.send("Network.emulateNetworkConditions", {
  offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1,
});
const back = await until(
  A,
  `(() => { const p = window.__lmxChat.polls; const last = p[p.length - 1];
     return last && last.status === 'ok' ? 1 : 0; })()`,
  40000,
);
R.check("recovers from an offline device", !!back);
const ag3 = await agree(A);
R.check("still one row per message after two outages", agreeOk(ag3), JSON.stringify(ag3));

// ========================================================= 4. backgrounded
// Two different things get called "backgrounded" and they need different
// evidence. `Page.setWebLifecycleState` takes 'frozen'|'active' only — the
// earlier run passed it 'hidden', CDP rejected the command, the catch swallowed
// it and the tab was never backgrounded at all (document.hidden stayed false
// and the "slow cadence" check was measuring the active cadence).
console.log("\n-- phase 4a: frozen tab (Chrome's real background behaviour) --");
const before4 = await pollsOf(A);
let froze = true;
await A.send("Page.setWebLifecycleState", { state: "frozen" }).catch((e) => { froze = false; });

const frozenAway: string[] = [];
for (let i = 0; i < 3; i++) {
  const t = `while frozen ${i} ${Date.now()}`;
  frozenAway.push(t);
  await say(B, t);
  await sleep(2600);
}
await sleep(25000);
const duringFrozen = (await pollsOf(A)) - before4;
R.check("a frozen tab stops polling entirely", froze && duringFrozen === 0,
  `froze=${froze} ${duringFrozen} polls in ~33s frozen`);

const beforeThaw = await pollsOf(A);
await A.send("Page.setWebLifecycleState", { state: "active" }).catch(() => {});
await sleep(5000);
R.check("thawing costs one catch-up request, not a storm",
  (await pollsOf(A)) - beforeThaw <= 2, `${(await pollsOf(A)) - beforeThaw} requests in 5s`);
await cycles(2);
const agFrozen = await agree(A);
R.check("everything said while frozen arrives, once", agreeOk(agFrozen), JSON.stringify(agFrozen));
for (const t of frozenAway) {
  const c = await A.eval(
    `[...document.querySelectorAll('.LmxChat-text')].filter(n => n.textContent.includes(${JSON.stringify("PLACEHOLDER")})).length`.replace('"PLACEHOLDER"', JSON.stringify(t)),
  );
  R.check(`message from the frozen window delivered once: "${t.slice(0, 16)}…"`, c === 1, `copies=${c}`);
}

console.log("\n-- phase 4b: hidden tab cadence --");
// document.hidden cannot be driven from outside in headless Chrome, so the
// property is overridden and the real visibilitychange event dispatched: the
// listener, the cadence branch and the wake-up path are the production ones,
// only the trigger is synthetic. Labelled as such rather than dressed up.
const before4b = await pollsOf(A);
await A.eval(`(() => {
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
  document.dispatchEvent(new Event('visibilitychange'));
})()`);
const awayHidden: string[] = [];
for (let i = 0; i < 3; i++) {
  const t = `while hidden ${i} ${Date.now()}`;
  awayHidden.push(t);
  await say(B, t);
  await sleep(2600);
}
await sleep(32000);
const duringHidden = (await pollsOf(A)) - before4b;
R.check("a hidden tab polls on the slow cadence", duringHidden <= 4,
  `${duringHidden} polls in ~40s hidden (the 3s cadence would be ~13)`);

const beforeWake = await pollsOf(A);
await A.eval(`(() => {
  Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
  document.dispatchEvent(new Event('visibilitychange'));
})()`);
await sleep(4000);
const wakeBurst = (await pollsOf(A)) - beforeWake;
R.check("waking up costs one catch-up request, not a storm", wakeBurst <= 2,
  `${wakeBurst} requests in the 4s after waking`);

await cycles(2);
const ag4 = await agree(A);
R.check("everything missed while away arrives, once", agreeOk(ag4), JSON.stringify(ag4));
for (const t of awayHidden) {
  const c = await A.eval(
    `[...document.querySelectorAll('.LmxChat-text')].filter(n => n.textContent.includes(${JSON.stringify("PLACEHOLDER")})).length`.replace('"PLACEHOLDER"', JSON.stringify(t)),
  );
  R.check(`message from while away delivered once: "${t.slice(0, 16)}…"`, c === 1, `copies=${c}`);
}

const dup = await A.eval(
  `(() => { const ids = [...document.querySelectorAll('.LmxChat-msg[data-mid]')].map(n => n.getAttribute('data-mid'));
     return ids.length - new Set(ids).size; })()`,
);
R.check("no duplicate ids after four failure phases", dup === 0, `duplicates=${dup}`);
const ownA = ownErrors(errorsA);
R.check("no console errors owned by the chat through every failure path", ownA.length === 0,
  JSON.stringify(ownA.slice(0, 5)));

await shotEl(A, ".LmxChat", `${out}/recovered.png`);
await A.close();
await B.close();
process.exit(R.summary() ? 0 : 1);
