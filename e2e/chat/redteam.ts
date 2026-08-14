/**
 * Prove the assertions can fail.
 *
 * A green check is not evidence until it has gone red deliberately. Every
 * predicate the other three suites rely on is re-run here against a page that
 * has been sabotaged in exactly the way that predicate is supposed to catch,
 * and this file FAILS if the predicate still passes.
 *
 * Two of them did not need sabotage — they went red on real defects and are
 * recorded here for the record:
 *
 *   - the agreement check (every API id rendered exactly once) was red on the
 *     shipped build:
 *     baseline measured dom=2 vs api=1, every load, forever (the mount path
 *     fired two polls at cursor 0).
 *   - `a URL becomes a link` was red on the first build of this rewrite:
 *     emoji substitution ran before linkification, so `:/` inside `https://`
 *     became an emoji and the link was never recognised — "https😕/looksmax.lat".
 *     Fixed by cutting URLs out first; see js/chat.js renderText().
 */
import { BASE, ADMIN_USER, ADMIN_PASS, fresh, agree, agreeOk, cycles, Report, login } from "./lib.ts";

const R = new Report();
const b = await fresh(21961);
await login(b, ADMIN_USER, ADMIN_PASS);
await b.goto(BASE + "/");
await cycles(1);

/**
 * Evaluate with one retry.
 *
 * The forum is reached through a trycloudflare tunnel, which drops a request
 * now and then; a rejected fetch inside a predicate throws out of CDP and takes
 * the whole suite with it. One retry turns a tunnel blip back into what it is —
 * noise — without hiding a predicate that genuinely throws twice.
 */
async function evalRetry(expr: string, isAsync = false) {
  try {
    return await b.eval(expr, isAsync);
  } catch (e) {
    await Bun.sleep(2000);
    return b.eval(expr, isAsync);
  }
}

/** Run a predicate, expecting it to be FALSE because the page was sabotaged. */
async function red(name: string, sabotage: string, predicate: string, isAsync = false) {
  await b.eval(`(() => { ${sabotage} })()`);
  // an un-awaited promise is an object, and an object is truthy — an async
  // predicate evaluated without this always "passes" and proves nothing
  const still = await evalRetry(predicate, isAsync);
  R.check(`red: ${name}`, !still, still ? "predicate still passed — it proves nothing" : "went red as required");
  await b.goto(BASE + "/");     // reload undoes the sabotage
  await cycles(1);
}

/** The invariant the suites assert: every API id rendered exactly once. */
const AGREES = `(async () => {
  const j = await fetch('/api/chat?since=0', { headers: { Accept: 'application/json' } })
    .then(r => r.json()).catch(() => ({ messages: [] }));
  const dom = [...document.querySelectorAll('.LmxChat-msg[data-mid]')].map(n => +n.getAttribute('data-mid'));
  const counts = new Map();
  dom.forEach(id => counts.set(id, (counts.get(id) || 0) + 1));
  return j.messages.every(m => counts.get(m.id) === 1);
})()`;

// 1. the duplicate that shipped
await b.eval(`(() => { const n = document.querySelector('.LmxChat-msg');
  document.querySelector('.LmxChat-log').appendChild(n.cloneNode(true)); })()`);
const dupSurvives = await evalRetry(AGREES, true);
R.check("red: the agreement check catches one extra rendered row", !dupSurvives,
  dupSurvives ? "predicate still passed" : "went red as required");
const dupIds = await b.eval(
  `(() => { const ids = [...document.querySelectorAll('.LmxChat-msg[data-mid]')].map(n => n.getAttribute('data-mid'));
     return ids.length - new Set(ids).size; })()`,
);
R.check("red: the duplicate-id predicate catches it too", dupIds > 0, `duplicates=${dupIds}`);
await b.goto(BASE + "/");
await cycles(1);

// 2. a missing row is caught by the same predicate (the opposite failure)
await b.eval(`document.querySelector('.LmxChat-msg').remove()`);
const missing = await evalRetry(AGREES, true);
R.check("red: the agreement check catches a dropped row", !missing);
await b.goto(BASE + "/");
await cycles(1);

// 3. own-vs-others styling
await red(
  "own-message styling",
  `document.querySelectorAll('.LmxChat-msg.is-own').forEach(n => n.classList.remove('is-own'));`,
  `document.querySelectorAll('.LmxChat-msg.is-own').length > 0
   && document.querySelectorAll('.LmxChat-msg:not(.is-own)').length > 0`,
);

// 4. the offline state
await red(
  "offline state is visible",
  `const a = document.querySelector('.LmxChat-alert'); a.hidden = true;`,
  `(() => { const a = document.querySelector('.LmxChat-alert'); return !!a && !a.hidden; })()`,
);

// 5. the empty state
await red(
  "empty state has real size",
  `document.querySelector('.LmxChat-empty').style.display = 'none';`,
  `(() => { const e = document.querySelector('.LmxChat-empty');
     return !!e && e.getBoundingClientRect().height > 40; })()`,
);

// 6. overflow geometry
await red(
  "overflow detection",
  `const t = document.querySelector('.LmxChat-text');
   t.style.width = '900px'; t.style.overflow = 'visible'; t.style.whiteSpace = 'nowrap';`,
  `(() => { const card = document.querySelector('.LmxChat');
     const cr = card.getBoundingClientRect();
     const log = document.querySelector('.LmxChat-log');
     return log.scrollWidth <= log.clientWidth
       && [...card.querySelectorAll('*')]
            .filter(n => getComputedStyle(n).position !== 'absolute')
            .every(n => n.getBoundingClientRect().right <= cr.right - 1); })()`,
);

// 7. clipped-text detection
await red(
  "clipped-text detection",
  `const t = document.querySelector('.LmxChat-text');
   t.style.whiteSpace = 'nowrap'; t.style.overflow = 'hidden';
   t.textContent = 'x'.repeat(300);`,
  `[...document.querySelectorAll('.LmxChat-text')].every(n => n.scrollWidth <= n.clientWidth + 1)`,
);

// 8. the mobile font-size rule (iOS zooms any input under 16px)
await red(
  "mobile input font-size",
  `document.querySelector('.LmxChat-input').style.fontSize = '12px';`,
  `parseFloat(getComputedStyle(document.querySelector('.LmxChat-input')).fontSize) >= 12.5`,
);

// 9. the presence count must come from the payload, not from the markup
await red(
  "presence reads the live number",
  `document.querySelector('.LmxChat-online').textContent = '999';`,
  `(async () => {
     const j = await fetch('/api/chat?since=0', { headers: { Accept: 'application/json' } })
       .then(r => r.json()).catch(() => null);
     if (!j) return true;   // a dropped request must not read as "went red"
     return document.querySelector('.LmxChat-online').textContent === String(j.online);
   })()`,
  true,
);

// 10. the send-disabled rule
await red(
  "send button disabled state",
  `document.querySelector('.LmxChat-send').disabled = false;`,
  `document.querySelector('.LmxChat-send').disabled === true`,
);

// and the page must be healthy again afterwards, so a red run leaves nothing behind
const intact = await agree(b);
R.check("page is intact after the red run", agreeOk(intact), JSON.stringify(intact));

await b.close();
process.exit(R.summary() ? 0 : 1);
