/**
 * Shared plumbing for the shoutbox checks.
 *
 * Wraps the visual lane's CDP client (read-only import — that file belongs to
 * another lane) with the things a chat check needs and a screenshot sweep does
 * not: element-clipped screenshots, a poll-cycle wait that is expressed in
 * cycles rather than milliseconds, and a second independent browser profile so
 * two sessions can talk to each other.
 */
import { Browser } from "../visual/cdp.ts";

export { Browser };

/** Run a command on the box (the e2e runs on osprey, next to the container). */
export async function sh(cmd: string[]): Promise<string> {
  const p = Bun.spawn(cmd, { stdout: "pipe", stderr: "pipe" });
  const out = await new Response(p.stdout).text();
  await p.exited;
  return out;
}

export const DB = [
  "docker", "exec", "flarum-db", "mariadb",
  "-uflarum", "-p0f5951d6671cf05a2b73c7782676773ca66e37ff", "flarum", "-e",
];

export const sql = (q: string) => sh([...DB, q]);

/**
 * The forum's own `url` setting, resolved at load, and nothing else.
 *
 * That setting moved from the trycloudflare hostname to looksmax.lat mid-run
 * and every login started failing with `status: 0` plus a 405 on a preflight to
 * `https://looksmax.lat/login`: the SPA builds its apiUrl from the setting, so a
 * base that disagrees with it makes every API call cross-origin and no session
 * can be established. Both names reach the same container (verified: identical
 * /api/chat payloads). Reading it beats hard-coding it — this is the second time
 * the hostname has changed underneath these suites.
 */
async function configuredUrl(): Promise<string> {
  try {
    const out = await sh([
      "docker", "exec", "flarum-app", "php", "-r",
      "$c = require '/flarum/app/config.php'; echo $c['url'];",
    ]);
    return out.trim();
  } catch {
    return "";
  }
}

export const BASE =
  process.env.FORUM_URL || (await configuredUrl()) || "https://looksmax.lat";

export const ADMIN_USER = process.env.ADMIN_USER || "admin";
export const ADMIN_PASS = process.env.ADMIN_PASS || "1IxmV2IMZ8cnulvIHBT0";

export const SHOTS = process.env.SHOTS || "/work/chat-e2e/shots";

/** The client polls on this cadence; see RenderChat::script(). */
export const POLL_MS = Number(process.env.POLL_MS || 3000);

export const sleep = (ms: number) => Bun.sleep(ms);

/** Wait n poll cycles plus a render margin. */
export const cycles = (n: number) => Bun.sleep(n * POLL_MS + 700);

/**
 * A browser with an empty cookie jar.
 *
 * Browser.launch reuses `/tmp/visual-<port>` as its profile, so a second run
 * starts logged in as whoever the first run was — which is exactly the sort of
 * shared state that makes a two-session test lie. Each session gets its own
 * port, and the profile is removed first.
 */
export async function fresh(port: number, window?: string) {
  await Bun.spawn(["rm", "-rf", `/tmp/visual-${port}`]).exited;
  return Browser.launch(port, window);
}

/**
 * Log in, tolerating the reload that a successful login triggers.
 *
 * cdp.ts's `login()` awaits `app.session.login(...)` through CDP. Flarum
 * reloads the page as soon as that resolves, and when the reload wins the race
 * the execution context is destroyed mid-await: CDP reports `exceptionDetails`
 * with the text "Uncaught" and no reason, and the suite dies at its first line.
 * It only started losing that race when the forum's url moved behind
 * Cloudflare and the round trip got faster. So the call is fired without being
 * awaited and the session is confirmed by polling for the user afterwards —
 * which is the thing being asserted anyway.
 *
 * cdp.ts belongs to another lane; this is a local replacement, not an edit.
 */
export async function login(b: Browser, identification: string, password: string) {
  const who = () =>
    b.eval(`(() => { try { const u = window.flarum.core.app.session.user;
       return u ? u.username() : null; } catch (e) { return null; } })()`).catch(() => null);

  // Wait for the SPA to exist before talking to it. Under load — this box runs
  // several lanes' browsers at once — goto() can return before window.flarum is
  // defined, and the login then failed with a bare "no-app" that reads like a
  // credential problem.
  let booted = false;
  for (let attempt = 0; attempt < 3 && !booted; attempt++) {
    await b.goto(BASE + "/");
    for (let i = 0; i < 20 && !booted; i++) {
      booted = !!(await b.eval(
        `!!(window.flarum && window.flarum.core && window.flarum.core.app && window.flarum.core.app.session)`,
      ).catch(() => false));
      if (!booted) await Bun.sleep(500);
    }
  }
  if (!booted) return "fail no-app (the SPA never booted)";

  const already = await who();
  if (already === identification) return `ok:${already}`;

  await b.eval(
    `(() => {
       window.__loginErr = null;
       const app = window.flarum && window.flarum.core && window.flarum.core.app;
       if (!app) { window.__loginErr = 'no-app'; return 'no-app'; }
       app.session.login({ identification: ${JSON.stringify(identification)},
                           password: ${JSON.stringify(password)} })
         .catch(e => { window.__loginErr = String((e && e.message) || e); });
       return 'fired';
     })()`,
  );

  for (let i = 0; i < 30; i++) {
    await Bun.sleep(700);
    const u = await who();
    if (u === identification) return `ok:${u}`;
    const err = await b.eval(`window.__loginErr || null`).catch(() => null);
    if (err) return `fail ${err}`;
  }

  await b.goto(BASE + "/");
  const u = await who();
  return u === identification ? `ok:${u}` : `no-session-after-login (saw ${u})`;
}

/**
 * Actually stop a browser.
 *
 * `Browser.close()` closes the CDP target and SIGTERMs the process, and Chrome
 * routinely survives both: four abandoned browsers from earlier runs were still
 * polling the forum, and still counted as four people, which is what made the
 * presence assertions unpredictable. A session that is supposed to be gone has
 * to actually be gone before the count is read.
 */
export async function killBrowser(port: number) {
  await Bun.spawn(["pkill", "-f", `user-data-dir=/tmp/visual-${port}`]).exited;
  await Bun.sleep(800);
}

/**
 * Console noise this lane does not own and cannot fix from inside its surface.
 *
 * flarum/pusher is enabled with no credentials, so every page boot rejects with
 * "You must pass your app key when you instantiate Pusher." Filtering it is a
 * deliberate, narrow exception — see HANDOFF-UI.md — and the chat still asserts
 * zero errors of every other kind, including anything mentioning its own
 * classes. Filtering the whole check away instead would hide a real regression.
 */
const FOREIGN = [/pass your app key when you instantiate Pusher/i];

/**
 * Collect console errors WITH their payload.
 *
 * cdp.ts records `exceptionDetails.text`, which for a rejected promise is the
 * string "Uncaught (in promise)" and nothing else — three identical useless
 * lines. The reason lives in `exception.value`/`description`, so this attaches
 * its own listener and keeps the whole thing; without it the Pusher rejection
 * and a real chat bug look identical.
 */
export function watchErrors(b: Browser): string[] {
  const errors: string[] = [];
  b.on("Runtime.exceptionThrown", (p: any) => {
    const d = p.exceptionDetails || {};
    const detail = d.exception?.description || d.exception?.value || "";
    errors.push(`${d.text || "exception"}: ${String(detail).slice(0, 300)}`);
  });
  b.on("Runtime.consoleAPICalled", (p: any) => {
    if (p.type !== "error") return;
    errors.push("console.error: " + (p.args || []).map((a: any) => a.value ?? a.description).join(" ").slice(0, 300));
  });
  return errors;
}

/** Errors this lane is responsible for. */
export function ownErrors(errors: string[]) {
  return errors.filter((e) => !FOREIGN.some((f) => f.test(e)));
}

export type Check = { name: string; ok: boolean; detail: string };

export class Report {
  checks: Check[] = [];
  shots: string[] = [];

  check(name: string, ok: boolean, detail = "") {
    this.checks.push({ name, ok, detail });
    console.log(`${ok ? "PASS" : "FAIL"}  ${name}${detail ? "  — " + detail : ""}`);
    return ok;
  }

  shot(path: string) {
    this.shots.push(path);
  }

  get failed() {
    return this.checks.filter((c) => !c.ok);
  }

  summary() {
    const bad = this.failed;
    console.log(`\n${this.checks.length - bad.length}/${this.checks.length} checks passed`);
    for (const b of bad) console.log(`  FAIL ${b.name} — ${b.detail}`);
    if (this.shots.length) console.log(`shots: ${this.shots.length}`);
    return bad.length === 0;
  }
}

/**
 * Screenshot one element, padded, instead of the viewport.
 *
 * A 300px card inside a 1600px page is 3% of the pixels of a full-page shot,
 * which is the difference between being able to see a 1px misalignment and
 * not. Returns false when the element is absent so a missing card fails the
 * check rather than silently writing the page.
 */
export async function shotEl(
  b: Browser,
  selector: string,
  path: string,
  pad = 12,
): Promise<boolean> {
  const box = await b.eval(
    `(() => { const e = document.querySelector(${JSON.stringify(selector)});
       if (!e) return null;
       const r = e.getBoundingClientRect();
       return { x: r.x + scrollX, y: r.y + scrollY, w: r.width, h: r.height }; })()`,
  );
  if (!box || box.w < 2 || box.h < 2) return false;
  return b.shot(path, {
    captureBeyondViewport: true,
    clip: {
      x: Math.max(0, box.x - pad),
      y: Math.max(0, box.y - pad),
      width: box.w + pad * 2,
      height: box.h + pad * 2,
      scale: 1,
    },
  });
}

/** Poll an in-page expression until it is truthy, or give up. */
export async function until(
  b: Browser,
  expr: string,
  timeoutMs = 20000,
  step = 300,
): Promise<any> {
  const end = Date.now() + timeoutMs;
  let last: any = null;
  while (Date.now() < end) {
    last = await b.eval(expr).catch(() => null);
    if (last) return last;
    await Bun.sleep(step);
  }
  return last;
}

/**
 * What the server thinks, from inside the page session.
 *
 * Carries the page's own client id. Without it this helper is a distinct
 * anonymous viewer as far as presence is concerned, and the harness inflates
 * the very number it is checking — it did: 3 browsers, 9 presence rows.
 */
export async function apiState(b: Browser) {
  return b.eval(
    `fetch('/api/chat?since=0&cid=' + encodeURIComponent(localStorage.getItem('lmx-chat-cid') || ''),
           { headers: { Accept: 'application/json' } })
       .then(r => r.json())
       .then(j => ({ n: (j.messages||[]).length, online: j.online, canPost: j.canPost,
                     canModerate: j.canModerate, ids: (j.messages||[]).map(m => m.id) }))`,
    true,
  );
}

/**
 * Compare what is rendered against what the server has, by id.
 *
 * `dom.length === api.length` is the wrong invariant and it lied twice: the API
 * hands a cold client the newest 40 rows while the log keeps up to 150, so once
 * a conversation passes 40 messages the DOM legitimately holds more. What must
 * always hold is:
 *
 *   - every id the API returned is rendered exactly once (nothing missed);
 *   - no id is rendered twice (the original defect);
 *   - anything extra in the DOM is OLDER than the oldest id the API returned,
 *     i.e. scrollback the page limit cut off, never an invented row.
 */
export async function agree(b: Browser) {
  return b.eval(
    `(async () => {
       const r = await fetch('/api/chat?since=0&cid=' + encodeURIComponent(localStorage.getItem('lmx-chat-cid') || ''),
                             { headers: { Accept: 'application/json' } });
       const j = await r.json().catch(() => null);
       // An error document has no messages array, and reading it as one threw
       // out of CDP and killed the suite mid-run. Report the payload instead.
       if (!j || !Array.isArray(j.messages)) {
         return { api: -1, dom: -1, missing: ['api-error'], duplicated: [], invented: [],
                  pending: 0, status: r.status, payload: JSON.stringify(j).slice(0, 200) };
       }
       const api = j.messages.map(m => m.id);
       const dom = [...document.querySelectorAll('.LmxChat-msg[data-mid]')].map(n => +n.getAttribute('data-mid'));
       const seen = new Map();
       dom.forEach(id => seen.set(id, (seen.get(id) || 0) + 1));
       const oldest = api.length ? Math.min(...api) : Infinity;
       return {
         api: api.length,
         dom: dom.length,
         missing: api.filter(id => !seen.has(id)),
         duplicated: [...seen.entries()].filter(([, n]) => n > 1).map(([id]) => id),
         invented: dom.filter(id => id > oldest && !api.includes(id)),
         pending: document.querySelectorAll('.LmxChat-msg.is-pending').length,
       };
     })()`,
    true,
  );
}

export function agreeOk(a: any) {
  return a && a.missing.length === 0 && a.duplicated.length === 0 && a.invented.length === 0;
}

/** Type into the chat input and submit, the way a person does. */
export async function say(b: Browser, text: string) {
  return b.eval(
    `(() => {
       const i = document.querySelector('.LmxChat-input');
       const f = document.querySelector('.LmxChat-form');
       if (!i || !f) return 'no-form';
       // the native setter for THIS element type — using the textarea
       // descriptor on an input throws "Illegal invocation"
       const proto = i.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype
                                              : window.HTMLInputElement.prototype;
       Object.getOwnPropertyDescriptor(proto, 'value').set.call(i, ${JSON.stringify(text)});
       i.dispatchEvent(new Event('input', { bubbles: true }));
       f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
       return 'sent';
     })()`,
  );
}

/** The rendered log, as text, for assertions that care about content. */
export async function logText(b: Browser) {
  return b.eval(
    `[...document.querySelectorAll('.LmxChat-msg')].map(n =>
       (n.getAttribute('data-mid')||'?') + '|' +
       (n.querySelector('.LmxChat-who')?.textContent||'') + '|' +
       (n.querySelector('.LmxChat-text')?.textContent||'')).join('\\n')`,
  );
}

export async function count(b: Browser, sel = ".LmxChat-msg") {
  return b.eval(`document.querySelectorAll(${JSON.stringify(sel)}).length`);
}
