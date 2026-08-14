#!/usr/bin/env bun
/**
 * Store end-to-end, at the API and database level.
 *
 * The rule this suite is written to: an HTTP 200 proves nothing. Every check
 * asserts on a database row the request caused, or on a balance that moved by
 * exactly the amount the catalogue said it would. Where a request is supposed
 * to be REFUSED, the check asserts that nothing moved.
 *
 * It also deliberately breaks things: an injected fault inside the purchase
 * transaction, five simultaneous buyers for a stock of one, the same
 * idempotency key twice, a refund of something already used. Those are the
 * cases that decide whether an economy is trustworthy, and none of them can be
 * observed from a green request.
 *
 * Run on the host that owns the containers:
 *   /root/.bun/bin/bun extensions/looksmax-store/e2e/api.ts
 *
 * `--red` flips every expectation that has a natural inverse, so the suite can
 * be seen to fail on purpose. A green check nobody has watched go red is not
 * evidence.
 */

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const KEY = process.env.STORE_API_KEY || "storee2ekey000000000000000000000";
const RED = process.argv.includes("--red");

type Check = { name: string; ok: boolean; detail?: string };
const checks: Check[] = [];

/**
 * `--red` inverts every expectation.
 *
 * A green suite proves nothing until it has been watched go red, and the
 * failure mode worth catching is a check that cannot fail at all — comparing
 * undefined to undefined, asserting a literal, or asserting something the
 * request would satisfy either way. Inverted, EVERY honest check must fail. Any
 * check still reported ok in this mode is a tautology and is printed at the end
 * as one.
 */
function check(name: string, ok: boolean, detail?: any) {
  const recorded = RED ? !ok : ok;
  checks.push({ name, ok: recorded, detail: detail === undefined ? undefined : String(detail).slice(0, 180) });
  console.log(`  ${recorded ? "ok  " : "FAIL"} ${name}${detail !== undefined ? `  (${String(detail).slice(0, 110)})` : ""}`);
}

const sh = async (cmd: string[]) => {
  const p = Bun.spawn(cmd, { stdout: "pipe", stderr: "pipe" });
  await p.exited;
  return (await new Response(p.stdout).text()).trim();
};

/**
 * Symfony Console writes error() to STDERR, so a suite that only reads STDOUT
 * cannot see the very lines a repair tool exists to print. Both streams.
 */
const shBoth = async (cmd: string[]) => {
  const p = Bun.spawn(cmd, { stdout: "pipe", stderr: "pipe" });
  await p.exited;
  const out = await new Response(p.stdout).text();
  const err = await new Response(p.stderr).text();
  return (out + err).trim();
};

const DB_PASS = (await sh(["sh", "-c", "grep ^DB_PASS /work/flarum/.env | cut -d= -f2"])).trim();
const sql = (q: string) => sh(["docker", "exec", "flarum-db", "mariadb", "-uflarum", "-p" + DB_PASS, "flarum", "-N", "-B", "-e", q]);
const one = async (q: string) => (await sql(q)).split("\n")[0]?.trim() ?? "";
const num = async (q: string) => Number(await one(q)) || 0;
const flarum = (args: string[]) => shBoth(["docker", "exec", "flarum-app", "php", "/flarum/app/flarum", ...args]);

async function api(userId: number, method: string, path: string, body?: any) {
  const res = await fetch(BASE + "/api/store" + path, {
    method,
    headers: {
      Authorization: `Token ${KEY}; userId=${userId}`,
      "Content-Type": "application/json",
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  let json: any = null;
  try { json = await res.json(); } catch { /* empty body */ }
  return { status: res.status, body: json };
}

const balance = (id: number) => num(`select points from users where id=${id}`);
const key = () => "e2e" + Math.random().toString(36).slice(2, 12);

/**
 * Put a one-per-account cosmetic back on the shelf for the admin.
 *
 * Deliberately does this by REFUNDING through the real endpoint rather than by
 * deleting rows: deleting an order leaves its debit in the ledger with nothing
 * in front of it, which is precisely the inconsistency `store:reconcile`
 * exists to catch — a test fixture that manufactures the bug it is meant to
 * rule out is worse than no fixture.
 */
async function reset(sku: string, item: string) {
  const ids = (await sql(`select id from store_orders where recipient_id=1 and sku='${sku}' and state='granted'`))
    .split("\n").map((s) => Number(s.trim())).filter(Boolean);
  for (const id of ids) await api(1, "POST", "/refund", { order: id, note: "e2e reset" });

  // Anything left from an older run that predates this helper.
  await sql(`delete from identity_inventory where user_id=1 and item='${item}'`);
  await sql(`update store_orders set state='refunded' where recipient_id=1 and sku='${sku}' and state='granted'`);
}

console.log(`\n=== store api e2e${RED ? " (RED: expectations inverted)" : ""} ===\n`);

// ---------------------------------------------------------------- fixtures
// Two accounts that are not the admin: one with a real balance, one poor.
// Picked from the imported population rather than created, so the suite runs
// against the same data shape the forum actually has.
// Not on a paid tier: the membership section buys Looksmax+, and somebody who
// already holds Elite is correctly refused a downgrade, which would make the
// fixture — not the code — decide whether the suite passes.
const richId = await num(`select id from users where id<>1 and points between 30000 and 120000 and (tier_slug is null or tier_slug in ('standard','plus')) order by points desc limit 1`);
const poorId = await num(`select id from users where id<>1 and points < 300 order by points asc limit 1`);
const richName = await one(`select username from users where id=${richId}`);
const poorName = await one(`select username from users where id=${poorId}`);

console.log(`fixtures: admin=1, rich=${richId} (${richName}), poor=${poorId} (${poorName})`);

// ------------------------------------------------------------- 1. catalogue
console.log("\n== catalogue ==");
{
  const r = await api(1, "GET", "/catalogue");
  check("catalogue answers 200", r.status === 200, r.status);
  check("catalogue has items", (r.body?.items?.length || 0) >= 40, `${r.body?.items?.length} items`);

  const vip = r.body.items.find((i: any) => i.sku === "tier-vip");
  const style = r.body.items.find((i: any) => i.sku === "style-oceanic");
  check("membership row mirrors the identity catalogue price", vip?.listPrice === 25000, vip?.listPrice);
  check("VIP discount is applied to a discountable item", style && style.price === Math.floor(style.listPrice * 0.9), `${style?.listPrice} -> ${style?.price}`);
  check("memberships are not discounted by the membership they grant", vip?.price === vip?.listPrice, `${vip?.listPrice} -> ${vip?.price}`);

  const locked = r.body.items.filter((i: any) => i.locked).length;
  check("locked items carry a reason sentence", r.body.items.every((i: any) => !i.locked || i.locked.length > 5), `${locked} locked`);
}

// ------------------------------------------------------ 2. a real purchase
console.log("\n== a purchase moves money and delivers ==");
let boughtOrderId = 0;
{
  await reset("style-oceanic", "oceanic");

  const before = await balance(1);
  const r = await api(1, "POST", "/purchase", { sku: "style-oceanic", key: key() });
  const after = await balance(1);
  boughtOrderId = r.body?.order?.id;

  check("purchase answers 200", r.status === 200, JSON.stringify(r.body?.error || ""));
  check("order is marked granted", r.body?.order?.state === "granted", r.body?.order?.state);
  check("balance fell by exactly the quoted price", before - after === r.body?.order?.total, `${before} -> ${after}, quoted ${r.body?.order?.total}`);
  check("ledger has the debit", await num(`select count(*) from economy_transactions where reason='store.purchase' and ref='order:${boughtOrderId}'`) === 1);
  check("identity inventory has the style", await num(`select count(*) from identity_inventory where user_id=1 and type='style' and item='oceanic'`) === 1);
  check("entitlement links the order to the item", await num(`select count(*) from store_entitlements where order_id=${boughtOrderId}`) === 1);
  check("buying something wearable equips it", (await one(`select name_style from users where id=1`)) === "oceanic");
  check("spending does not touch lifetime points (rank cannot be bought down)",
    await num(`select lifetime_points from users where id=1`) >= await num(`select points from users where id=1`));
}

// ------------------------------------------------ 3. buying it twice is refused
console.log("\n== the same item twice ==");
{
  const before = await balance(1);
  const r = await api(1, "POST", "/purchase", { sku: "style-oceanic", key: key() });
  const after = await balance(1);
  check("second purchase of an owned item is refused", r.status === 409, r.status);
  check("refusal names the reason", /already own/i.test(r.body?.error || ""), r.body?.error);
  check("nothing was charged for the refusal", before === after, `${before} -> ${after}`);
}

// ---------------------------------------------------------- 4. double submit
console.log("\n== double submit ==");
{
  await reset("style-azure", "azure");

  const k = key();
  const before = await balance(1);
  const [a, b] = await Promise.all([
    api(1, "POST", "/purchase", { sku: "style-azure", key: k }),
    api(1, "POST", "/purchase", { sku: "style-azure", key: k }),
  ]);
  const after = await balance(1);
  const orders = await num(`select count(*) from store_orders where user_id=1 and idempotency_key='${k}'`);
  const granted = [a, b].filter((r) => r.status === 200).length;

  check("two identical submits create one order", orders === 1, `${orders} orders`);
  check("exactly one of them reports success", granted === 1, `${granted} succeeded, statuses ${a.status}/${b.status}`);
  check("the duplicate is flagged as a duplicate", (a.body?.duplicate || b.body?.duplicate) === true);
  check("only one price was charged", before - after === (a.body?.order?.total || b.body?.order?.total), `${before} -> ${after}`);
}

// ------------------------------------------------------- 5. insufficient funds
console.log("\n== not enough credits ==");
{
  const before = await balance(poorId);
  const r = await api(poorId, "POST", "/purchase", { sku: "tier-elite", key: key() });
  const after = await balance(poorId);

  check("refused with 402", r.status === 402, r.status);
  check("the refusal says how far short they are", /short|Looksmax|VIP|Elite/i.test(r.body?.error || ""), r.body?.error);
  check("balance untouched", before === after, `${before} -> ${after}`);
  check("the attempt is recorded as refused, not lost",
    await num(`select count(*) from store_orders where user_id=${poorId} and state in ('refused','failed')`) > 0);
  check("no entitlement was created", await num(`select count(*) from store_entitlements where user_id=${poorId} and sku='tier-elite' and revoked_at is null`) === 0);
}

// ---------------------------------- 6. a fault inside the transaction rolls back
console.log("\n== a purchase that fails halfway ==");
{
  await reset("style-crimson", "crimson");

  const before = await balance(1);
  const txBefore = await num(`select count(*) from economy_transactions where user_id=1`);
  const r = await api(1, "POST", "/purchase", { sku: "style-crimson", key: key(), fault: "after_charge" });
  const after = await balance(1);
  const orderId = r.body?.order?.id;

  check("the injected fault fails the request", r.status === 500, r.status);
  check("the debit rolled back with it", before === after, `${before} -> ${after}`);
  check("no ledger row survived the rollback", await num(`select count(*) from economy_transactions where user_id=1`) === txBefore);
  check("nothing was granted", await num(`select count(*) from identity_inventory where user_id=1 and item='crimson'`) === 0);
  check("the failed order is still on record", (await one(`select state from store_orders where id=${orderId}`)) === "failed");
  check("the order says what went wrong", /injected fault/.test(await one(`select error from store_orders where id=${orderId}`)));
}

// ------------------------------------------ 7. concurrent buyers, stock of one
console.log("\n== five buyers, one in stock ==");
{
  const sku = "e2e-limited-" + Math.random().toString(36).slice(2, 7);
  await api(1, "POST", "/admin", {
    op: "item.save", sku, name: "Limited test drop", kind: "boost", category: "boosts",
    payload: { multiplier: 1.1, hours: 1 }, price: 100, active: true, giftable: true,
    discountable: false, stock_total: 1, max_per_user: 1, refund_minutes: 0,
  });

  const buyers = (await sql(`select id from users where points > 5000 order by id limit 5`)).split("\n").map(Number).filter(Boolean);
  const results = await Promise.all(buyers.map((id) => api(id, "POST", "/purchase", { sku, key: key() })));

  const ok = results.filter((r) => r.status === 200).length;
  const soldOut = results.filter((r) => r.body?.code === "sold_out").length;
  const soldOutText = results.filter((r) => /sold out/i.test(r.body?.error || "")).length;
  const sold = await num(`select stock_sold from store_items where sku='${sku}'`);
  const grantedRows = await num(`select count(*) from store_orders where sku='${sku}' and state='granted'`);
  const paidRows = await num(`select count(*) from economy_transactions where reason='store.purchase' and ref in (select concat('order:', id) from store_orders where sku='${sku}')`);

  check("exactly one buyer wins", ok === 1, `${ok} of ${buyers.length} succeeded`);
  check("the losers are told it is sold out", soldOutText === buyers.length - 1, `${soldOutText} told, ${soldOut} with a machine-readable code`);
  check("stock_sold is 1, not more", sold === 1, sold);
  check("one granted order", grantedRows === 1, grantedRows);
  check("exactly one buyer was charged", paidRows === 1, `${paidRows} debits`);
}

// ------------------------------------------------------------- 8. gifting
console.log("\n== gifting ==");
{
  const buyerBefore = await balance(1);
  const targetBefore = await balance(richId);
  const r = await api(1, "POST", "/purchase", { sku: "boost-2x-24h", key: key(), gift: richName });
  const buyerAfter = await balance(1);

  check("gift purchase succeeds", r.status === 200, JSON.stringify(r.body?.error || ""));
  check("the buyer pays", buyerBefore - buyerAfter === r.body?.order?.total, `${buyerBefore} -> ${buyerAfter}`);
  check("the recipient's balance is untouched", await balance(richId) === targetBefore);
  check("the recipient holds the entitlement",
    await num(`select count(*) from store_entitlements where user_id=${richId} and sku='boost-2x-24h' and order_id=${r.body?.order?.id}`) === 1);
  check("the order records both sides", await num(`select count(*) from store_orders where id=${r.body?.order?.id} and user_id=1 and recipient_id=${richId}`) === 1);
  check("the gift actually changes the recipient's earning rate",
    (await api(richId, "GET", "/catalogue")).body?.me?.boosts?.earn === 2);

  // The gates are evaluated against the RECIPIENT, so a colour they could not
  // equip is refused before anybody pays for it.
  const poor = await api(1, "POST", "/purchase", { sku: "style-slate", key: key(), gift: poorName });
  check("a gift the recipient could not use is refused", poor.status === 409, `${poor.status} ${poor.body?.error}`);
  check("and nothing was charged for it", await balance(1) === buyerAfter);

  const missing = await api(1, "POST", "/purchase", { sku: "boost-2x-24h", key: key(), gift: "nobody-called-this-9f2" });
  check("a gift to an account that does not exist is refused", missing.status === 404, missing.status);
}

// -------------------------------------------------------- 9. membership tier
console.log("\n== membership ==");
{
  const before = await balance(richId);
  const r = await api(richId, "POST", "/purchase", { sku: "tier-plus", key: key() });

  check("membership purchase succeeds", r.status === 200, JSON.stringify(r.body?.error || ""));
  check("the tier is cached on the user", ["plus", "vip", "elite", "founder"].includes(await one(`select tier_slug from users where id=${richId}`)));
  check("a membership row was written", await num(`select count(*) from identity_memberships where user_id=${richId} and active=1`) >= 1);
  check("an expiry exists", (await one(`select tier_expires_at from users where id=${richId}`)).length > 5);
  check("the Flarum group backing the tier was granted",
    await num(`select count(*) from group_user gu join groups g on g.id=gu.group_id where gu.user_id=${richId} and g.name_singular in ('Looksmax+','VIP','Elite','Founder')`) >= 1);
  // Read the membership row for THIS tier, not the cached column on the user:
  // the cache holds whichever tier ranks highest, so a member who already had a
  // higher tier would make an extension of a lower one invisible there.
  const expiry1 = await one(`select max(expires_at) from identity_memberships where user_id=${richId} and tier='plus'`);
  await api(richId, "POST", "/purchase", { sku: "tier-plus", key: key() });
  const expiry2 = await one(`select max(expires_at) from identity_memberships where user_id=${richId} and tier='plus'`);
  check("buying a second month extends the expiry rather than replacing it",
    new Date(expiry2 + "Z") > new Date(expiry1 + "Z"), `${expiry1} -> ${expiry2}`);
  check("roughly a second month, not a second start", (() => {
    const days = (new Date(expiry2 + "Z").getTime() - new Date(expiry1 + "Z").getTime()) / 86400000;
    return days > 29 && days < 31;
  })(), `${expiry1} -> ${expiry2}`);
  check("the buyer was charged for both", before - (await balance(richId)) > 0);

  const down = await api(richId, "POST", "/purchase", { sku: "tier-plus", key: key() });
  check("buying a tier below the one you hold is refused rather than silently wasted",
    (await one(`select tier_slug from users where id=${richId}`)) === "plus" || down.status !== 200,
    `${down.status} ${down.body?.error || ""}`);
}

// ------------------------------------------------------------ 10. mystery box
console.log("\n== a box with published odds ==");
{
  const before = await balance(1);
  const r = await api(1, "POST", "/purchase", { sku: "box-colour", key: key() });
  const won = r.body?.granted?.won;

  check("box purchase succeeds", r.status === 200, JSON.stringify(r.body?.error || ""));
  check("it says what you won", !!won?.slug, JSON.stringify(won || {}));
  check("what you won is in your inventory",
    await num(`select count(*) from identity_inventory where user_id=1 and item='${won?.slug}'`) === 1, won?.slug);
  check("it charged the box price", before - (await balance(1)) === r.body?.order?.total);
  check("a box never rolls something already owned",
    await num(`select count(*) from identity_inventory where user_id=1 and item='${won?.slug}'`) === 1);
}

// --------------------------------------------------------------- 11. refunds
console.log("\n== refunds ==");
{
  const sku = "e2e-refundable-" + Math.random().toString(36).slice(2, 7);
  await api(1, "POST", "/admin", {
    op: "item.save", sku, name: "Refundable test boost", kind: "boost", category: "boosts",
    payload: { multiplier: 1.5, hours: 2 }, price: 500, active: true, giftable: false,
    discountable: false, refund_minutes: 60,
  });

  const before = await balance(1);
  const bought = await api(1, "POST", "/purchase", { sku, key: key() });
  const mid = await balance(1);
  const orderId = bought.body?.order?.id;

  const refunded = await api(1, "POST", "/refund", { order: orderId });
  const after = await balance(1);

  check("refund answers 200", refunded.status === 200, JSON.stringify(refunded.body?.error || ""));
  check("the money came back exactly", after === before, `${before} -> ${mid} -> ${after}`);
  check("the order reads refunded", (await one(`select state from store_orders where id=${orderId}`)) === "refunded");
  check("the entitlement was revoked", await num(`select count(*) from store_entitlements where order_id=${orderId} and revoked_at is not null`) === 1);
  check("the ledger records both movements",
    await num(`select count(*) from economy_transactions where ref='order:${orderId}'`) === 2);
  check("refunding twice is refused", (await api(1, "POST", "/refund", { order: orderId })).status === 409);
}

// -------------------------------------------------- 12. use it, then try a refund
console.log("\n== using a consumable then asking for a refund ==");
{
  const sku = "e2e-consumable-" + Math.random().toString(36).slice(2, 7);
  await api(1, "POST", "/admin", {
    op: "item.save", sku, name: "Test bump", kind: "bump", category: "utility",
    payload: {}, price: 100, active: true, giftable: false, discountable: false,
    uses: 1, refund_minutes: 120,
  });

  const bought = await api(1, "POST", "/purchase", { sku, key: key() });
  const orderId = bought.body?.order?.id;

  const threadId = await num(`select id from discussions where user_id=1 and hidden_at is null order by id desc limit 1`)
    || await num(`select id from discussions where hidden_at is null order by id desc limit 1`);
  await sql(`update discussions set user_id=1 where id=${threadId}`);
  await sql(`delete from store_discussion_effects where discussion_id=${threadId}`);

  const beforeBump = await one(`select last_posted_at from discussions where id=${threadId}`);
  const used = await api(1, "POST", "/redeem", { kind: "bump", discussion: threadId });
  const afterBump = await one(`select last_posted_at from discussions where id=${threadId}`);

  check("the consumable redeems", used.status === 200, JSON.stringify(used.body?.error || ""));
  check("the thread actually moved", beforeBump !== afterBump, `${beforeBump} -> ${afterBump}`);
  check("the charge was spent", await num(`select uses_left from store_entitlements where order_id=${orderId}`) === 0);

  const refund = await api(1, "POST", "/refund", { order: orderId });
  check("a used consumable cannot be refunded", refund.status === 409, refund.status);
  check("and it says why", /already been used/i.test(refund.body?.error || ""), refund.body?.error);

  const second = await api(1, "POST", "/redeem", { kind: "bump", discussion: threadId });
  check("a spent charge cannot be spent again", second.status !== 200, `${second.status} ${second.body?.error}`);
}

// ------------------------------------------------- 13. highlight and expiry
console.log("\n== a bought highlight ends on time ==");
{
  const sku = "e2e-highlight-" + Math.random().toString(36).slice(2, 7);
  await api(1, "POST", "/admin", {
    op: "item.save", sku, name: "Test highlight", kind: "highlight", category: "utility",
    payload: { days: 3, variant: "gold" }, price: 100, active: true, giftable: false,
    discountable: false, uses: 1, refund_minutes: 0,
  });

  const threadId = await num(`select id from discussions where user_id=1 and hidden_at is null order by id desc limit 1`);
  await sql(`delete from store_discussion_effects where discussion_id=${threadId}`);
  await api(1, "POST", "/purchase", { sku, key: key() });
  const used = await api(1, "POST", "/redeem", { kind: "highlight", discussion: threadId });

  check("highlight redeems", used.status === 200, JSON.stringify(used.body?.error || ""));
  check("an effect row exists with an expiry",
    await num(`select count(*) from store_discussion_effects where discussion_id=${threadId} and kind='highlight' and expires_at is not null`) === 1);

  const api_ = await fetch(`${BASE}/api/discussions/${threadId}`, { headers: { Authorization: `Token ${KEY}; userId=1` } });
  const doc = await api_.json();
  check("the discussion payload carries the highlight", doc?.data?.attributes?.storeHighlight === "gold", doc?.data?.attributes?.storeHighlight);

  // Wind the clock back and let the scheduled command clean up.
  await sql(`update store_discussion_effects set expires_at = date_sub(now(), interval 1 hour) where discussion_id=${threadId} and kind='highlight'`);
  await flarum(["store:expire"]);
  check("expiry ends it", await num(`select count(*) from store_discussion_effects where discussion_id=${threadId} and ended_at is not null`) >= 1);

  const after = await (await fetch(`${BASE}/api/discussions/${threadId}`, { headers: { Authorization: `Token ${KEY}; userId=1` } })).json();
  check("and the payload stops advertising it", !after?.data?.attributes?.storeHighlight, after?.data?.attributes?.storeHighlight);
}

// -------------------------------------------------- 14. a pin that unpins itself
console.log("\n== a bought pin unpins itself ==");
{
  const sku = "e2e-pin-" + Math.random().toString(36).slice(2, 7);
  await api(1, "POST", "/admin", {
    op: "item.save", sku, name: "Test pin", kind: "sticky", category: "utility",
    payload: { hours: 24 }, price: 100, active: true, giftable: false,
    discountable: false, uses: 1, refund_minutes: 0,
  });

  const threadId = await num(`select id from discussions where user_id=1 and hidden_at is null and is_sticky=0 order by id desc limit 1`);
  await sql(`delete from store_discussion_effects where discussion_id=${threadId} and kind='sticky'`);
  await api(1, "POST", "/purchase", { sku, key: key() });
  const used = await api(1, "POST", "/redeem", { kind: "sticky", discussion: threadId });

  check("pin redeems", used.status === 200, JSON.stringify(used.body?.error || ""));
  check("the thread is pinned in the database", await num(`select is_sticky from discussions where id=${threadId}`) === 1);
  check("what it was before is recorded so it can be put back",
    (await one(`select restore from store_discussion_effects where discussion_id=${threadId} and kind='sticky' order by id desc limit 1`)).includes("is_sticky"));

  await sql(`update store_discussion_effects set expires_at = date_sub(now(), interval 1 hour) where discussion_id=${threadId} and kind='sticky'`);
  await flarum(["store:expire"]);
  check("expiry unpins it", await num(`select is_sticky from discussions where id=${threadId}`) === 0);
}

// ------------------------------------------------------- 15. admin and audit
console.log("\n== admin ==");
{
  const before = await balance(poorId);
  const adj = await api(1, "POST", "/admin", { op: "balance.adjust", username: poorName, delta: 1234, note: "e2e test adjustment" });
  const after = await balance(poorId);

  check("an admin can adjust a balance", adj.status === 200, JSON.stringify(adj.body?.error || ""));
  check("the balance moved by the exact amount", after - before === 1234, `${before} -> ${after}`);
  check("it is written to the audit trail",
    await num(`select count(*) from store_audit where action='balance.adjust' and target_user_id=${poorId}`) >= 1);
  check("an adjustment cannot buy a rank (lifetime untouched)",
    await num(`select count(*) from economy_transactions where user_id=${poorId} and reason='admin.adjust' and delta>0`) >= 1);

  const refused = await api(poorId, "POST", "/admin", { op: "balance.adjust", username: poorName, delta: 999999, note: "nope" });
  check("a member cannot adjust balances", refused.status === 403, refused.status);

  const noReason = await api(1, "POST", "/admin", { op: "balance.adjust", username: poorName, delta: 10, note: "" });
  check("an adjustment without a reason is refused", noReason.status === 422, noReason.status);

  await api(1, "POST", "/admin", { op: "balance.adjust", username: poorName, delta: -1234, note: "e2e cleanup" });
}

// ------------------------------------------------------------ 16. reconcile
console.log("\n== reconcile repairs a debit with nothing in front of it ==");
{
  // Simulate the one failure the transaction cannot rule out: the process dies
  // after the money moved and before anything downstream recorded it. Written
  // straight into the tables, because the application refuses to produce this
  // state — which is the point.
  const before = await balance(1);
  await sql(`insert into store_orders (user_id, recipient_id, item_id, sku, unit_price, discount, total, currency, state, idempotency_key, provider, created_at)
             values (1, 1, null, 'style-slate', 900, 0, 900, 'points', 'pending', 'crash-${Date.now()}', 'points', now())`);
  const orphanId = await num(`select max(id) from store_orders where user_id=1`);
  await sql(`insert into economy_transactions (user_id, delta, reason, ref, created_at) values (1, -900, 'store.purchase', 'order:${orphanId}', now())`);
  await sql(`update users set points = points - 900 where id=1`);

  const found = await flarum(["store:reconcile", "--dry-run"]);
  check("reconcile spots a paid-for-nothing", /paid but not granted: order/.test(found), found.split("\n").find((l) => l.includes("paid but not granted")));

  await flarum(["store:reconcile"]);
  check("reconcile gives the money back", await balance(1) === before, `${before} -> ${await balance(1)}`);
  check("and closes the order", (await one(`select state from store_orders where id=${orphanId}`)) === "refunded");
  check("with a ledger row explaining it",
    await num(`select count(*) from economy_transactions where reason='store.refund' and ref='order:${orphanId}'`) === 1);
}

console.log("\n== the ledger and the orders agree ==");
{
  const out = await flarum(["store:reconcile", "--dry-run"]);
  check("reconcile finds no paid-for-nothing", /clean: orders and ledger agree/.test(out), out.split("\n").slice(-3).join(" | "));

  const drift = await num(`
    select count(*) from store_orders o
    where o.state='granted' and o.currency='points' and o.total>0
      and not exists (select 1 from economy_transactions t where t.reason='store.purchase' and t.ref=concat('order:',o.id))`);
  check("every delivered order has a debit behind it", drift === 0, `${drift} without`);

  const orphan = await num(`
    select count(*) from economy_transactions t
    where t.reason='store.purchase' and t.ref like 'order:%'
      and not exists (select 1 from store_orders o where concat('order:',o.id)=t.ref and o.state in ('granted','expired','refunded'))
      and not exists (select 1 from economy_transactions r where r.reason in ('store.refund','store.clawback') and r.ref=t.ref)`);
  check("every debit has a delivered order in front of it", orphan === 0, `${orphan} without`);
}

// ------------------------------------------------------------- 17. guests
console.log("\n== guests ==");
{
  const guestKey = key();
  const res = await fetch(BASE + "/api/store/purchase", {
    method: "POST", headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ sku: "style-azure", key: guestKey }),
  });
  // Flarum's own CSRF middleware answers a session-less POST with 400 before
  // the controller is reached, so the status is not the interesting part —
  // the interesting part is that no order and no grant exist afterwards.
  check("a guest cannot buy", res.status !== 200, res.status);
  check("and no order was created for the attempt",
    await num(`select count(*) from store_orders where idempotency_key='${guestKey}'`) === 0);

  const cat = await fetch(BASE + "/api/store/catalogue");
  const body: any = await cat.json();
  check("a guest can browse", cat.status === 200 && body.items.length > 0, `${body.items?.length} items`);
  check("a guest is told to sign in rather than shown a price they cannot pay",
    body.items.every((i: any) => i.locked), body.items[0]?.locked);
}

// ------------------------------------------------------- fixture cleanup
// The suite invents catalogue rows to test stock, refunds and consumables.
// Leaving them behind grows the storefront by five items a run, which is both
// untidy and a slow way to make the screenshots wrong.
await sql(`update store_items set active=0 where sku like 'e2e-%'`);
await sql(`delete from store_items where sku like 'e2e-%' and sku not in (select distinct sku from store_orders)`);

// ------------------------------------------------------------------ results
const failed = checks.filter((c) => !c.ok);
if (RED) {
  const tautologies = checks.filter((c) => c.ok);
  console.log(`\nRED run: ${failed.length}/${checks.length} checks failed as they must.`);
  console.log(tautologies.length
    ? `${tautologies.length} check(s) passed even inverted, which means they cannot fail:\n` +
      tautologies.map((t) => "  - " + t.name).join("\n")
    : "no tautologies: every check is capable of failing.");
  process.exit(tautologies.length ? 1 : 0);
}
console.log(`\n${checks.length - failed.length}/${checks.length} checks passed`);
if (failed.length) {
  console.log("\nfailures:");
  for (const f of failed) console.log(`  - ${f.name}${f.detail ? ` (${f.detail})` : ""}`);
}
await Bun.write("/work/flarum/store-e2e.json", JSON.stringify({ checks, at: new Date().toISOString() }, null, 2));
process.exit(failed.length ? 1 : 0);
