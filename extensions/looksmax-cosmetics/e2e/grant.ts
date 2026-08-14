/**
 * Create REAL frame entitlements, through the store's own supported path.
 *
 * This exists because of a measured fact: `store_entitlements` held ZERO rows
 * of kind `frame` on 2026-08-13, so the seven frames the catalogue sells had no
 * owner anywhere and the renderer had nothing to render. Proving a renderer
 * against invented rows proves nothing, so nothing here writes to a table
 * directly. Every row it creates is the output of:
 *
 *   POST /api/store/admin     { op: 'balance.adjust' }  -> Ledger credit + store_audit row
 *   POST /api/store/purchase  { sku, gift }           -> Purchase::buy(), which is the
 *                                                     single transaction that writes
 *                                                     store_orders, the ledger debit,
 *                                                     store_entitlements and
 *                                                     identity_inventory together
 *
 * The gift form is used for other accounts because that is the only supported
 * way to deliver an item to somebody whose session we do not have, and the
 * store evaluates every gate against the RECIPIENT, so a gift can only land on
 * an account that could legitimately have bought it.
 *
 *   bun e2e/grant.ts
 */
const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const ADMIN = { identification: "admin", password: "1IxmV2IMZ8cnulvIHBT0" };

let cookies = "";
let csrf = "";

function merge(res: Response) {
  const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  const jar = new Map<string, string>();
  for (const c of cookies.split("; ").filter(Boolean)) {
    const i = c.indexOf("=");
    jar.set(c.slice(0, i), c.slice(i + 1));
  }
  for (const line of raw) {
    const pair = line.split(";")[0];
    const i = pair.indexOf("=");
    jar.set(pair.slice(0, i), pair.slice(i + 1));
  }
  cookies = [...jar].map(([k, v]) => `${k}=${v}`).join("; ");
}

async function boot() {
  const r = await fetch(BASE + "/", { headers: { cookie: cookies } });
  merge(r);
  const html = await r.text();
  const m = html.match(/"csrfToken":"([^"]+)"/);
  if (m) csrf = m[1];
}

/**
 * POST /login, not POST /api/token.
 *
 * Measured: /api/token answers 200 with a bearer token and sets NO session
 * cookie, so every subsequent store call came back 401 "Inicia sesión primero."
 * The forum's own login route is what establishes the session the store
 * controllers read out of the request.
 */
async function login() {
  await boot();
  const r = await fetch(BASE + "/login", {
    method: "POST",
    headers: { "content-type": "application/json", cookie: cookies, "x-csrf-token": csrf },
    body: JSON.stringify({ ...ADMIN, remember: true }),
  });
  merge(r);
  const text = await r.text();
  if (r.status !== 200) throw new Error(`login ${r.status}: ${text.slice(0, 300)}`);

  // Re-read the document so the CSRF token belongs to the NEW session; Flarum
  // rotates it on login and a stale one is a 400 on every write.
  await boot();

  const me = await (await fetch(BASE + "/api/users/1", { headers: { cookie: cookies } })).json();
  const doc = await (await fetch(BASE + "/", { headers: { cookie: cookies } })).text();
  const session = doc.match(/"userId":(\d+)/);
  return `${me?.data?.attributes?.username} (session userId=${session ? session[1] : "?"})`;
}

async function post(path: string, body: any) {
  const r = await fetch(BASE + path, {
    method: "POST",
    headers: { "content-type": "application/json", cookie: cookies, "x-csrf-token": csrf },
    body: JSON.stringify(body),
  });
  merge(r);
  const text = await r.text();
  let json: any = null;
  try { json = JSON.parse(text); } catch {}
  return { status: r.status, json, text: text.slice(0, 400) };
}

const PLAN: { sku: string; gift?: string; why: string }[] = [
  // admin is already VIP and can hold the vip-gated frames.
  { sku: "frame-gold-laurel", why: "admin, static gradient ring" },
  { sku: "tier-elite", why: "admin needs Elite to be allowed an epic frame" },
  { sku: "frame-ember", why: "admin, rotating conic" },
  // Real Elite accounts from the imported population. Every gate below is
  // evaluated by the store against the recipient, not against admin.
  { sku: "frame-glacier", gift: "maarda", why: "elite, rotating conic" },
  { sku: "frame-circuit", gift: "FatJattMofo", why: "elite, rotating dashed" },
  { sku: "frame-neon", gift: "Tony", why: "vip-gated, pulsing ring" },
  { sku: "frame-silver-laurel", gift: "eduardkoopman", why: "vip-gated, flat ring" },
  { sku: "frame-bronze-laurel", gift: "aids", why: "vip-gated, flat ring" },
  // A second wave, chosen from the accounts that are actually VISIBLE on the
  // front page and on /all right now (recent last-posters with avatars), so
  // those two call sites can be proven with a screenshot rather than argued
  // about. All of them are real VIP/Elite accounts and the store gates each
  // purchase against them.
  { sku: "frame-gold-laurel", gift: "lowltn222", why: "vip, recent last-poster, gradient ring" },
  { sku: "frame-neon", gift: "FunnyVALENTINE", why: "vip, recent last-poster, pulsing ring" },
  { sku: "frame-silver-laurel", gift: "pinterest", why: "vip, recent last-poster, flat ring" },
  { sku: "frame-ember", gift: "DrRodger", why: "elite, recent last-poster, rotating conic" },
];

const out: any[] = [];

const who = await login();
console.log("logged in as", who);

const top = await post("/api/store/admin", {
  op: "balance.adjust",
  username: "admin",
  delta: 400000,
  note: "cosmetics lane: funding real frame purchases so the renderer can be proven against real entitlements",
});
console.log("adjust", top.status, top.json?.after ?? top.text);

for (const step of PLAN) {
  const body: any = { sku: step.sku, key: `cos-${step.sku}-${step.gift || "self"}-${Date.now()}` };
  if (step.gift) body.gift = step.gift;
  const r = await post("/api/store/purchase", body);
  const line = {
    sku: step.sku,
    to: step.gift || "admin",
    status: r.status,
    ok: r.json?.ok ?? false,
    order: r.json?.order?.id ?? null,
    error: r.json?.error ?? null,
    code: r.json?.code ?? null,
    why: step.why,
  };
  out.push(line);
  console.log(
    `  ${line.status} ${line.sku} -> ${line.to}` +
      (line.order ? ` order#${line.order}` : "") +
      (line.error ? `  REFUSED: ${line.error}` : ""),
  );
}

console.log("\n" + JSON.stringify(out, null, 2));
