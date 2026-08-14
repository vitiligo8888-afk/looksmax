#!/usr/bin/env bun
/**
 * Earning, end to end against the live forum.
 *
 * The ledger has always had rates for reactions and streaks; until now nothing
 * fired them, so every `reaction.received` row on this install came from the
 * import and the only live way to earn was to post. This suite exercises the
 * paths that changed and, more importantly, the limits on them — a cap nobody
 * has watched refuse anything is not a cap.
 *
 *   /root/.bun/bin/bun extensions/looksmax-economy/e2e/earning.ts
 */

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const KEY = process.env.STORE_API_KEY || "storee2ekey000000000000000000000";

type Check = { name: string; ok: boolean; detail?: string };
const checks: Check[] = [];
const check = (name: string, ok: boolean, detail?: any) => {
  checks.push({ name, ok: !!ok, detail: detail === undefined ? undefined : String(detail).slice(0, 180) });
  console.log(`  ${ok ? "ok  " : "FAIL"} ${name}${detail !== undefined ? `  (${String(detail).slice(0, 110)})` : ""}`);
};

const sh = async (cmd: string[]) => {
  const p = Bun.spawn(cmd, { stdout: "pipe", stderr: "pipe" });
  await p.exited;
  return (await new Response(p.stdout).text()).trim();
};
const DB_PASS = (await sh(["sh", "-c", "grep ^DB_PASS /work/flarum/.env | cut -d= -f2"])).trim();
const sql = (q: string) => sh(["docker", "exec", "flarum-db", "mariadb", "-uflarum", "-p" + DB_PASS, "flarum", "-N", "-B", "-e", q]);
const one = async (q: string) => (await sql(q)).split("\n")[0]?.trim() ?? "";
const num = async (q: string) => Number(await one(q)) || 0;

async function api(userId: number, method: string, path: string, body?: any) {
  const res = await fetch(BASE + path, {
    method,
    headers: {
      Authorization: `Token ${KEY}; userId=${userId}`,
      "Content-Type": "application/json",
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  let json: any = null;
  try { json = await res.json(); } catch {}
  return { status: res.status, body: json };
}

const balance = (id: number) => num(`select points from users where id=${id}`);

console.log("\n=== economy earning e2e ===\n");

const authorId = 1;
// Confirmed accounts only. Flarum puts unconfirmed users outside the Member
// group, so they hold no `discussion.likePosts` permission and every like from
// one answers 403 — which on this install is all 1,400 imported accounts. See
// HANDOFF-STORE.md; it is the import lane's call, not the economy's.
const likerId = await num(`select id from users where id<>1 and is_email_confirmed=1 order by id limit 1`);
const liker2 = await num(`select id from users where id<>1 and is_email_confirmed=1 order by id limit 1 offset 1`);
const tagId = await num(`select id from tags order by id limit 1`);

console.log(`author=${authorId}, likers=${likerId},${liker2}, tag=${tagId}`);

// ------------------------------------------------------- a fresh discussion
console.log("\n== posting earns, and is weighted by effort ==");
let discussionId = 0;
let postId = 0;
{
  const before = await balance(authorId);
  const r = await api(authorId, "POST", "/api/discussions", {
    data: {
      type: "discussions",
      attributes: {
        title: "Economy harness thread " + Date.now(),
        content: "This thread exists so the earning rules can be tested against a real post rather than an imported one. ".repeat(3),
      },
      relationships: { tags: { data: [{ type: "tags", id: String(tagId) }] } },
    },
  });

  discussionId = Number(r.body?.data?.id || 0);
  postId = Number(r.body?.data?.relationships?.firstPost?.data?.id || 0);

  check("a discussion can be created", r.status === 201 && discussionId > 0, `${r.status} ${JSON.stringify(r.body?.errors || "").slice(0, 90)}`);
  check("starting a discussion pays", (await balance(authorId)) > before, `${before} -> ${await balance(authorId)}`);
  check("with a ledger row naming the reason",
    await num(`select count(*) from economy_transactions where user_id=${authorId} and ref='discussion:${discussionId}'`) === 1);
}

// -------------------------------------------------------------- reactions
console.log("\n== reactions pay the author, not the clicker ==");
{
  const authorBefore = await balance(authorId);
  const likerBefore = await balance(likerId);

  const r = await api(likerId, "PATCH", `/api/posts/${postId}`, {
    data: { type: "posts", id: String(postId), attributes: { isLiked: true } },
  });

  check("the like registers", r.status === 200, r.status);
  check("the author is paid", (await balance(authorId)) > authorBefore, `${authorBefore} -> ${await balance(authorId)}`);
  check("the ledger records who caused it",
    await num(`select count(*) from economy_transactions where user_id=${authorId} and reason='reaction.received' and actor_id=${likerId}`) >= 1);
  check("the person clicking earns something, but less",
    (await balance(likerId)) - likerBefore > 0 && (await balance(likerId)) - likerBefore < (await balance(authorId)) - authorBefore,
    `liker +${(await balance(likerId)) - likerBefore}, author +${(await balance(authorId)) - authorBefore}`);

  // Removing it takes both back off.
  const authorPaid = await balance(authorId);
  await api(likerId, "PATCH", `/api/posts/${postId}`, {
    data: { type: "posts", id: String(postId), attributes: { isLiked: false } },
  });
  check("unliking reverses the payment", (await balance(authorId)) < authorPaid, `${authorPaid} -> ${await balance(authorId)}`);
  check("and leaves no orphan row",
    await num(`select count(*) from economy_transactions where reason='reaction.received' and ref='like:${postId}:${likerId}'`) === 0);
}

// ------------------------------------------------------------- self-likes
console.log("\n== the first thing anybody tries ==");
{
  const before = await balance(authorId);
  await api(authorId, "PATCH", `/api/posts/${postId}`, {
    data: { type: "posts", id: String(postId), attributes: { isLiked: true } },
  });
  const after = await balance(authorId);

  check("a self-like pays no reaction.received",
    await num(`select count(*) from economy_transactions where user_id=${authorId} and reason='reaction.received' and actor_id=${authorId}`) === 0);
  check("a self-like pays nothing at all", after === before, `${before} -> ${after}`);

  // And the loop that made a token award farmable: like, unlike, like again.
  for (let i = 0; i < 3; i++) {
    await api(authorId, "PATCH", `/api/posts/${postId}`, {
      data: { type: "posts", id: String(postId), attributes: { isLiked: false } },
    });
    await api(authorId, "PATCH", `/api/posts/${postId}`, {
      data: { type: "posts", id: String(postId), attributes: { isLiked: true } },
    });
  }
  check("and three like/unlike cycles on your own post still pay nothing",
    (await balance(authorId)) === before, `${before} -> ${await balance(authorId)}`);

  await api(authorId, "PATCH", `/api/posts/${postId}`, {
    data: { type: "posts", id: String(postId), attributes: { isLiked: false } },
  });
}

// -------------------------------------------------------- the pair ceiling
console.log("\n== one account cannot pay another all day ==");
{
  // Seven posts from the same author, all liked by the same account. The pair
  // cap is six per 24 hours, so the seventh must pay nothing — a global daily
  // cap would never catch this, which is the whole point of it existing.
  await sql(`delete from economy_transactions where user_id=${authorId} and reason='reaction.received' and actor_id=${liker2}`);

  const posts: number[] = [];
  for (let i = 0; i < 7; i++) {
    const r = await api(authorId, "POST", "/api/posts", {
      data: {
        type: "posts",
        attributes: { content: "Reply number " + i + " written so the pair cap has something to refuse. It has to be long enough to earn." },
        relationships: { discussion: { data: { type: "discussions", id: String(discussionId) } } },
      },
    });
    const id = Number(r.body?.data?.id || 0);
    if (id) posts.push(id);
  }

  check("seven replies were created", posts.length === 7, `${posts.length}`);

  for (const id of posts) {
    await api(liker2, "PATCH", `/api/posts/${id}`, {
      data: { type: "posts", id: String(id), attributes: { isLiked: true } },
    });
  }

  const paid = await num(`select count(*) from economy_transactions where user_id=${authorId} and reason='reaction.received' and actor_id=${liker2}`);
  check("only six of the seven likes paid", paid === 6, `${paid} paid`);
}

// -------------------------------------------------------------- old posts
console.log("\n== necro-liking pays nothing ==");
{
  const oldPost = await num(`select id from posts where created_at < date_sub(now(), interval 200 day) and user_id is not null and user_id <> ${liker2} order by id desc limit 1`);
  const oldAuthor = await num(`select user_id from posts where id=${oldPost}`);
  const before = await balance(oldAuthor);

  await api(liker2, "PATCH", `/api/posts/${oldPost}`, {
    data: { type: "posts", id: String(oldPost), attributes: { isLiked: true } },
  });

  check("a like on a post older than 90 days pays the author nothing",
    (await balance(oldAuthor)) === before, `post ${oldPost}, author ${oldAuthor}: ${before} -> ${await balance(oldAuthor)}`);

  await api(liker2, "PATCH", `/api/posts/${oldPost}`, {
    data: { type: "posts", id: String(oldPost), attributes: { isLiked: false } },
  });
}

// --------------------------------------------------------------- streaks
console.log("\n== streaks ==");
{
  const row = await one(`select concat(current, '|', best, '|', last_day) from economy_streaks where user_id=${authorId}`);
  check("posting started a streak", row.startsWith("1|") || Number(row.split("|")[0]) >= 1, row);
  check("the day award is on the ledger",
    await num(`select count(*) from economy_transactions where user_id=${authorId} and reason='streak.day'`) >= 1);

  // Wind yesterday back so the next post extends rather than resets.
  await sql(`update economy_streaks set current=4, best=9, last_day=date_sub(utc_date(), interval 1 day) where user_id=${authorId}`);
  await api(authorId, "POST", "/api/posts", {
    data: {
      type: "posts",
      attributes: { content: "A second day of posting, long enough to hold the streak that the harness just wound back a day." },
      relationships: { discussion: { data: { type: "discussions", id: String(discussionId) } } },
    },
  });
  check("a post the next day extends the streak", await num(`select current from economy_streaks where user_id=${authorId}`) === 5,
    await one(`select current from economy_streaks where user_id=${authorId}`));

  // Two days missed with no freeze resets to 1.
  await sql(`update economy_streaks set current=12, best=12, last_day=date_sub(utc_date(), interval 3 day) where user_id=${authorId}`);
  await sql(`update store_entitlements set revoked_at=now() where user_id=${authorId} and kind='streakfreeze' and revoked_at is null`);
  await api(authorId, "POST", "/api/posts", {
    data: {
      type: "posts",
      attributes: { content: "Coming back after three days away, which is more than a single freeze can cover for anybody." },
      relationships: { discussion: { data: { type: "discussions", id: String(discussionId) } } },
    },
  });
  check("three days away resets the streak", await num(`select current from economy_streaks where user_id=${authorId}`) === 1);
  check("but the best is remembered", await num(`select best from economy_streaks where user_id=${authorId}`) >= 12);

  // One day missed WITH a freeze from the store survives.
  const held = await api(authorId, "POST", "/api/store/purchase", { sku: "streak-freeze", key: "streak" + Date.now() });
  check("a streak freeze can be bought", held.status === 200, JSON.stringify(held.body?.error || ""));

  await sql(`update economy_streaks set current=6, last_day=date_sub(utc_date(), interval 2 day) where user_id=${authorId}`);
  await api(authorId, "POST", "/api/posts", {
    data: {
      type: "posts",
      attributes: { content: "Missed exactly one day, and the freeze bought in the store should quietly cover it without any prompt." },
      relationships: { discussion: { data: { type: "discussions", id: String(discussionId) } } },
    },
  });
  check("a bought freeze covers a single missed day", await num(`select current from economy_streaks where user_id=${authorId}`) === 7,
    await one(`select concat(current,' freezes used ',freezes_used) from economy_streaks where user_id=${authorId}`));
  check("and the freeze was spent, not merely counted",
    await num(`select count(*) from store_entitlements where user_id=${authorId} and kind='streakfreeze' and uses_left=0`) >= 1);
}

// ---------------------------------------------------------------- boosts
console.log("\n== a bought boost multiplies real earnings ==");
{
  await sql(`update store_entitlements set revoked_at=now() where user_id=${authorId} and kind='boost' and revoked_at is null`);

  const plain = await api(authorId, "POST", "/api/posts", {
    data: {
      type: "posts",
      attributes: { content: "A control post of a very specific length, written so the award for it can be compared with the same post made under a boost." },
      relationships: { discussion: { data: { type: "discussions", id: String(discussionId) } } },
    },
  });
  const plainId = Number(plain.body?.data?.id || 0);
  const plainAward = await num(`select delta from economy_transactions where ref='post:${plainId}' and reason='post.created'`);

  const bought = await api(authorId, "POST", "/api/store/purchase", { sku: "boost-2x-24h", key: "boost" + Date.now() });
  check("the boost can be bought", bought.status === 200, JSON.stringify(bought.body?.error || ""));

  const boosted = await api(authorId, "POST", "/api/posts", {
    data: {
      type: "posts",
      attributes: { content: "A control post of a very specific length, written so the award for it can be compared with the same post made under a boost." },
      relationships: { discussion: { data: { type: "discussions", id: String(discussionId) } } },
    },
  });
  const boostedId = Number(boosted.body?.data?.id || 0);
  const boostedAward = await num(`select delta from economy_transactions where ref='post:${boostedId}' and reason='post.created'`);

  check("the same post earns about twice as much under the boost",
    plainAward > 0 && boostedAward >= plainAward * 1.8, `${plainAward} -> ${boostedAward}`);
}

// ----------------------------------------------------------------- tidy up
await sql(`update discussions set hidden_at = now(), hidden_user_id = 1 where id = ${discussionId}`);

const failed = checks.filter((c) => !c.ok);
console.log(`\n${checks.length - failed.length}/${checks.length} checks passed`);
if (failed.length) for (const f of failed) console.log(`  - ${f.name}${f.detail ? ` (${f.detail})` : ""}`);
process.exit(failed.length ? 1 : 0);
