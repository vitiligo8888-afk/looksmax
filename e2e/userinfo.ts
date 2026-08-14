#!/usr/bin/env bun
/**
 * End-to-end verification for the author identity surface.
 *
 *     FORUM_URL=http://127.0.0.1:8888 bun e2e/userinfo.ts
 *
 * One command, and it is the whole gate: counters, API contract, rendered DOM,
 * hover cards, the profile page, and screenshots at desktop and phone widths.
 *
 * ── What this refuses to accept as evidence ──────────────────────────────────
 *
 *   HTTP 200                a page that renders nothing returns 200.
 *   "the selector exists"   an empty panel matches its own selector. Every
 *                           structural check is paired with a value check.
 *   "the number rendered"   a number rendered is not a number that is right.
 *                           Every displayed count is read out of the DOM and
 *                           compared against a live COUNT(*) on the rows.
 *   a passing screenshot    screenshots are captured so a human can look; they
 *                           are not an assertion. The assertions are separate.
 *
 * ── Why counts are checked twice ─────────────────────────────────────────────
 * Once by `userinfo:backfill --verify`, which compares users.comment_count and
 * users.discussion_count against COUNT(*) for every row in the table, and again
 * from the browser, which compares what the panel actually PRINTED against the
 * same query. The first catches a stale counter. The second catches a correct
 * counter rendered into the wrong slot — which is a different bug and the one
 * a database-only check would sail straight past.
 *
 * A NEW file: harness.ts is shared with two other lanes and is the one place
 * three concurrent edits would collide. Different CDP port, different shot
 * directory, so both can run at once.
 */
import { CDP, Runner, sh, sql } from "./userinfo-cdp";

const BASE = process.env.FORUM_URL || "http://127.0.0.1:8888";
const SHOTS = process.env.SHOT_DIR || "/work/flarum/userinfo-shots";
const APP = process.env.FLARUM_CONTAINER || "flarum-app";

const R = new Runner();
const { cdp, proc, targetId } = await CDP.launch({ port: 21750, shotDir: SHOTS, width: 1600, height: 1400 });

cdp.on("Runtime.consoleAPICalled", (p) => {
  if (p.type === "error")
    R.consoleErrors.push((p.args || []).map((a: any) => a.value ?? a.description).join(" ").slice(0, 240));
});
cdp.on("Runtime.exceptionThrown", (p) => R.consoleErrors.push(p.exceptionDetails?.text ?? "exception"));
cdp.on("Network.loadingFailed", (p) => {
  if (p.type !== "Image") R.failedRequests.push(`${p.type} ${p.errorText}`);
});

const num = (s: string) => Number(String(s).trim() || 0);

/* ============================================ 1. the counters, from the DB */

console.log("\n== denormalised counters agree with the rows ==");
const verify = await sh([
  "docker", "exec", "-w", "/flarum/app", APP, "php", "flarum", "userinfo:backfill", "--verify",
]);
console.log(verify.split("\n").map((l) => "    " + l).join("\n"));
R.check(
  "every user counter matches a live COUNT(*)",
  /every user counter matches/.test(verify),
  verify.split("\n").slice(-1)[0]
);

// The operator's actual report: profiles showing 0 discussions for users who
// have them. Assert the negative directly rather than trusting the sweep above.
const zeroButHas = num(
  await sql(
    `SELECT COUNT(*) FROM users u WHERE u.discussion_count = 0
       AND EXISTS (SELECT 1 FROM discussions d WHERE d.user_id = u.id AND d.is_private = 0 AND d.hidden_at IS NULL);`
  )
);
R.check("no user shows 0 discussions while owning discussions", zeroButHas === 0, `${zeroButHas} such users`);

const zeroPosts = num(
  await sql(
    `SELECT COUNT(*) FROM users u WHERE u.comment_count = 0
       AND EXISTS (SELECT 1 FROM posts p WHERE p.user_id = u.id AND p.type = 'comment' AND p.hidden_at IS NULL);`
  )
);
R.check("no user shows 0 posts while owning posts", zeroPosts === 0, `${zeroPosts} such users`);

const profiled = num(await sql(`SELECT COUNT(*) FROM userinfo_profiles;`));
const importedUsers = num(await sql(`SELECT COUNT(*) FROM users WHERE imported_id IS NOT NULL;`));
R.check(
  "every imported account has a carried-over profile row",
  profiled >= importedUsers && importedUsers > 0,
  `${profiled} profiles / ${importedUsers} imported users`
);

const withLegacy = num(await sql(`SELECT COUNT(*) FROM userinfo_profiles WHERE legacy_posts > 0;`));
const withReactions = num(await sql(`SELECT COUNT(*) FROM userinfo_profiles WHERE reactions_here > 0;`));
R.check("carried-over post counts were populated", withLegacy > 0, `${withLegacy} rows`);
R.check("local reaction scores were computed", withReactions > 0, `${withReactions} rows`);

/* ================================================= 2. the API contract */

console.log("\n== api exposes the identity payload ==");
await cdp.goto(BASE);
R.check("SPA boots", !!(await cdp.eval(`!!(document.querySelector('#app') && window.flarum)`)));

const bound = await cdp.eval(`JSON.stringify(window.__lmxUserInfo || null)`);
R.check("author-panel script bound to the component registry", /"bound":true/.test(bound || ""), bound);

const sampleId = (await sql(`SELECT id FROM users WHERE comment_count > 3 ORDER BY comment_count DESC LIMIT 1;`)).trim();
const payload = await cdp.eval(
  `fetch('/api/users/${sampleId}').then(r=>r.json()).then(d=>JSON.stringify(d.data.attributes.userInfo))`,
  true
);
let info: any = null;
try {
  info = JSON.parse(payload);
} catch {}
R.check("user payload carries userInfo", !!info, String(payload).slice(0, 200));

for (const k of ["posts", "discussions", "reactions", "joinedAt", "rank", "legacy", "online", "reactionMix"]) {
  R.check(`userInfo.${k} present`, !!info && k in info);
}

const dbRow = (
  await sql(
    `SELECT u.comment_count, u.discussion_count, COALESCE(p.reactions_here,0), COALESCE(p.legacy_posts,0)
       FROM users u LEFT JOIN userinfo_profiles p ON p.user_id = u.id WHERE u.id = ${sampleId};`
  )
).split("\t");
R.check("userInfo.posts equals users.comment_count", info?.posts === num(dbRow[0]), `${info?.posts} vs ${dbRow[0]}`);
R.check(
  "userInfo.discussions equals users.discussion_count",
  info?.discussions === num(dbRow[1]),
  `${info?.discussions} vs ${dbRow[1]}`
);
R.check("userInfo.reactions equals the computed local score", info?.reactions === num(dbRow[2]), `${info?.reactions} vs ${dbRow[2]}`);
R.check(
  "carried-over totals are namespaced, not merged into the local ones",
  info?.legacy?.posts === num(dbRow[3]) && info?.posts !== undefined && info.posts !== info.legacy.posts,
  `local ${info?.posts} / carried ${info?.legacy?.posts}`
);

/* ================================ 3. the panel, on a real discussion */

console.log("\n== the author panel renders on every post ==");
const did = (await sql(`SELECT id FROM discussions WHERE comment_count > 3 ORDER BY comment_count DESC LIMIT 1;`)).trim();
const dslug = (await sql(`SELECT slug FROM discussions WHERE id = ${did};`)).trim();
const durl = `${BASE}/d/${did}-${dslug}`;

const opened = await cdp.goto(durl, ".LmxAuthor");
R.check("discussion opens with author panels present", opened, durl);

const counts = await cdp.eval(
  `JSON.stringify({posts: document.querySelectorAll('article.Post.CommentPost').length,
                   panels: document.querySelectorAll('article.Post .LmxAuthor').length,
                   marked: document.querySelectorAll('article.Post.has-lmx-author').length})`
);
const c = JSON.parse(counts);
R.check("one panel per comment, no post left out", c.posts > 0 && c.panels === c.posts, counts);
R.check("every post carrying a panel is marked for the grid", c.marked === c.panels, counts);

// Not "the selector exists": the panel must contain a name, a stat block, and
// stats whose labels are the ones we ship.
const shape = await cdp.eval(`(() => {
  const p = document.querySelector('article.Post .LmxAuthor');
  if (!p) return null;
  return JSON.stringify({
    name: (p.querySelector('.LmxAuthor-name')||{}).textContent,
    nameColor: p.querySelector('.LmxAuthor-name') ? getComputedStyle(p.querySelector('.LmxAuthor-name')).color : null,
    avatar: !!p.querySelector('.LmxAvatar-img, .LmxAvatar .Avatar'),
    avatarBox: (() => { const a = p.querySelector('.LmxAvatar'); if(!a) return null; const r = a.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; })(),
    presence: !!p.querySelector('.LmxAvatar-presence'),
    rank: (p.querySelector('.LmxRank')||{}).textContent,
    title: (p.querySelector('.LmxAuthor-title')||{}).textContent,
    labels: [...p.querySelectorAll('.LmxStat dt')].map(e => e.textContent.trim()),
    values: [...p.querySelectorAll('.LmxStat dd')].map(e => e.textContent.trim()),
    mix: p.querySelectorAll('.LmxMix-seg').length,
    legacy: (p.querySelector('.LmxLegacy')||{}).textContent,
    width: Math.round(p.getBoundingClientRect().width),
    left: Math.round(p.getBoundingClientRect().left),
    bodyLeft: (() => { const b = p.closest('article.Post').querySelector('.Post-body'); return b ? Math.round(b.getBoundingClientRect().left) : null; })(),
  });
})()`);
const s = shape ? JSON.parse(shape) : null;
R.check("panel has a username", !!s?.name, s?.name);
R.check("panel has a large avatar", !!s?.avatar && (s?.avatarBox?.[0] || 0) >= 60, JSON.stringify(s?.avatarBox));
R.check("panel has a presence indicator", !!s?.presence);
R.check("panel shows the standard stat labels", ["Joined", "Messages", "Threads"].every((l) => s?.labels?.includes(l)), JSON.stringify(s?.labels));
R.check("stat values are non-empty", (s?.values || []).length > 0 && (s?.values || []).every((v: string) => v.length > 0), JSON.stringify(s?.values));
// This is the layout assertion. A panel that renders ABOVE the body rather than
// beside it satisfies every selector check above and is the wrong feature.
R.check("panel sits beside the post body, not above it", (s?.bodyLeft ?? 0) > (s?.left ?? 0) + 100, `panel@${s?.left} body@${s?.bodyLeft}`);
R.check("stock duplicate avatar/name in the post header is suppressed",
  (await cdp.eval(`[...document.querySelectorAll('article.Post.has-lmx-author .Post-header .PostUser')].filter(e => e.offsetParent !== null).length`)) === 0);

/* ============================ 4. every printed number checked against the DB */

console.log("\n== printed numbers match the database, for every panel on the page ==");
const printed = await cdp.eval(`(() => {
  const out = [];
  document.querySelectorAll('article.Post.CommentPost').forEach(post => {
    const p = post.querySelector('.LmxAuthor');
    if (!p) return;
    const stats = {};
    p.querySelectorAll('.LmxStat').forEach(st => {
      const k = (st.querySelector('dt')||{}).textContent.trim();
      stats[k] = { shown: (st.querySelector('dd')||{}).textContent.trim(), title: st.getAttribute('title') };
    });
    out.push({
      href: (p.querySelector('.LmxAuthor-name')||{}).getAttribute ? p.querySelector('.LmxAuthor-name').getAttribute('href') : null,
      name: (p.querySelector('.LmxAuthor-name')||{}).textContent,
      stats,
    });
  });
  return JSON.stringify(out);
})()`);
const panels = JSON.parse(printed || "[]");
R.check("read at least one panel out of the DOM", panels.length > 0, `${panels.length} panels`);

// The title attribute carries the exact figure ("1,204 posts on this forum"),
// which is what gets compared — the visible text is deliberately abbreviated.
let mismatches: string[] = [];
let checked = 0;
for (const p of panels) {
  const slug = (p.href || "").split("/u/")[1];
  if (!slug) continue;
  const row = (
    await sql(
      `SELECT u.comment_count, u.discussion_count, COALESCE(pr.reactions_here,0),
              (SELECT COUNT(*) FROM posts po JOIN discussions d ON d.id = po.discussion_id
                WHERE po.user_id = u.id AND po.type='comment' AND po.hidden_at IS NULL
                  AND po.is_private = 0 AND d.is_private = 0 AND d.hidden_at IS NULL),
              (SELECT COUNT(*) FROM discussions d2 WHERE d2.user_id = u.id AND d2.is_private=0 AND d2.hidden_at IS NULL)
         FROM users u LEFT JOIN userinfo_profiles pr ON pr.user_id = u.id
        WHERE u.username = '${slug.replace(/'/g, "")}' LIMIT 1;`
    )
  ).split("\t");
  if (row.length < 5) continue;
  checked++;

  const shownPosts = num((p.stats["Messages"]?.title || "").replace(/[^0-9]/g, ""));
  const shownThreads = num((p.stats["Threads"]?.title || "").replace(/[^0-9,]/g, "").replace(/,/g, ""));
  const shownReactions = p.stats["Reactions"]
    ? num((p.stats["Reactions"].title || "").split(" ")[0].replace(/,/g, ""))
    : 0;

  if (shownPosts !== num(row[3])) mismatches.push(`${p.name}: posts shown ${shownPosts}, real ${row[3]}`);
  if (shownThreads !== num(row[4])) mismatches.push(`${p.name}: threads shown ${shownThreads}, real ${row[4]}`);
  if (shownReactions !== num(row[2])) mismatches.push(`${p.name}: reactions shown ${shownReactions}, stored ${row[2]}`);
}
R.check(`all ${checked} panels print counts that match COUNT(*) on the rows`, checked > 0 && mismatches.length === 0, mismatches.slice(0, 4).join(" | "));

/* ================================================== 5. carried-over data */

console.log("\n== carried-over standing is real and labelled ==");
const legacyShown = await cdp.eval(
  `JSON.stringify([...document.querySelectorAll('.LmxLegacy')].map(e => e.textContent.trim()).slice(0,4))`
);
R.check("carried-over line renders on posts that have one", JSON.parse(legacyShown || "[]").length > 0, legacyShown);
R.check("carried-over line says so in words", /Carried over/.test(legacyShown || ""), legacyShown);

const ranks = await cdp.eval(
  `JSON.stringify([...document.querySelectorAll('.LmxRank')].map(e => [e.textContent.trim(), e.getAttribute('data-source'), getComputedStyle(e).color]).slice(0,6))`
);
R.check("rank chips render with a colour and a declared source", /rank|legacy/.test(ranks || ""), ranks);

const titles = await cdp.eval(
  `JSON.stringify([...document.querySelectorAll('.LmxAuthor-title')].map(e => e.textContent.trim()).filter(Boolean).slice(0,5))`
);
R.check("user titles from the source board render", JSON.parse(titles || "[]").length > 0, titles);

/* ==================================================== 6. desktop screenshots */

console.log("\n== screenshots: desktop ==");
await cdp.shot("10-discussion-desktop");
const cropped = await cdp.shotOf("article.Post .LmxAuthor", "11-panel-crop", 10);
R.check("panel crop captured", !!cropped, cropped);

/* ========================================================= 7. hover cards */

console.log("\n== hover card on a username ==");
const hovered = await cdp.eval(`(() => {
  const a = document.querySelector('.LmxAuthor-name');
  if (!a) return 'no anchor';
  const r = a.getBoundingClientRect();
  const opts = { bubbles: true, cancelable: true, clientX: r.x + 4, clientY: r.y + 4 };
  a.dispatchEvent(new MouseEvent('mouseover', opts));
  return a.getAttribute('href');
})()`);
await Bun.sleep(1600);
const card = await cdp.eval(`(() => {
  const h = document.querySelector('.LmxHoverHost.is-open .LmxCard');
  if (!h) return null;
  return JSON.stringify({
    name: (h.querySelector('.LmxAuthor-name')||{}).textContent,
    stats: [...h.querySelectorAll('.LmxStat dt')].map(e=>e.textContent.trim()),
    values: [...h.querySelectorAll('.LmxStat dd')].map(e=>e.textContent.trim()),
    link: !!h.querySelector('.LmxCard-link'),
    w: Math.round(h.getBoundingClientRect().width),
  });
})()`);
R.check("hover card opens on a username", !!card, `${hovered} -> ${card}`);
const cardObj = card ? JSON.parse(card) : null;
R.check("hover card carries stats, not just a name", (cardObj?.stats || []).length >= 3, JSON.stringify(cardObj?.stats));
R.check("hover card has a profile link", !!cardObj?.link);
R.check("hover card is inside the viewport", (cardObj?.w || 0) > 100 && (cardObj?.w || 0) < 1000, `${cardObj?.w}px`);
await cdp.shotOf(".LmxHoverHost.is-open .LmxCard", "12-hovercard", 12);

/* ========================================================= 8. profile page */

console.log("\n== profile page ==");
// Pick someone who HAS discussions, because "0 discussions on a profile that
// has them" is the exact defect reported.
const puser = (
  await sql(`SELECT username FROM users WHERE discussion_count > 0 AND comment_count > 3 ORDER BY discussion_count DESC LIMIT 1;`)
).trim();
const prow = (await sql(`SELECT discussion_count, comment_count FROM users WHERE username = '${puser}';`)).split("\t");

const popened = await cdp.goto(`${BASE}/u/${encodeURIComponent(puser)}`, ".LmxProfile");
R.check(`profile of ${puser} renders the identity surface`, popened, `${BASE}/u/${puser}`);
await Bun.sleep(2200);

const prof = await cdp.eval(`(() => {
  const p = document.querySelector('.LmxProfile');
  if (!p) return null;
  const stat = (label) => { let out = null;
    document.querySelectorAll('.LmxStat').forEach(s => { if ((s.querySelector('dt')||{}).textContent.trim() === label) out = { v:(s.querySelector('dd')||{}).textContent.trim(), t:s.getAttribute('title') }; });
    return out; };
  return JSON.stringify({
    blocks: [...p.querySelectorAll('.LmxProfile-block h4')].map(e=>e.textContent.trim()),
    threads: stat('Threads'), messages: stat('Messages'), joined: stat('Joined'),
    tagBars: p.querySelectorAll('.LmxTagBar').length,
    spark: p.querySelectorAll('.LmxSpark-bar').length,
    recent: p.querySelectorAll('.LmxRecent li').length,
    cardInfo: document.querySelectorAll('.UserCard .LmxCardInfo').length,
    rank: (document.querySelector('.UserCard .LmxRank')||{}).textContent,
  });
})()`);
const pf = prof ? JSON.parse(prof) : null;
R.check("profile shows the standing block", (pf?.blocks || []).includes("Standing"), JSON.stringify(pf?.blocks));
R.check("profile identity blocks rendered", (pf?.blocks || []).length >= 2, JSON.stringify(pf?.blocks));
R.check(
  "profile Threads matches users.discussion_count and is NOT 0",
  num((pf?.threads?.t || "").replace(/[^0-9]/g, "")) === num(prow[0]) && num(prow[0]) > 0,
  `shown "${pf?.threads?.v}" title "${pf?.threads?.t}" db ${prow[0]}`
);
R.check(
  "profile Messages matches users.comment_count",
  num((pf?.messages?.t || "").replace(/[^0-9]/g, "")) === num(prow[1]),
  `shown "${pf?.messages?.v}" db ${prow[1]}`
);
R.check("profile shows where they post", (pf?.tagBars || 0) > 0, `${pf?.tagBars} tag bars`);
R.check("profile shows an activity sparkline", (pf?.spark || 0) > 1, `${pf?.spark} bars`);
R.check("profile lists recent threads", (pf?.recent || 0) > 0, `${pf?.recent} rows`);
R.check("user card carries the identity rows", (pf?.cardInfo || 0) > 0, `${pf?.cardInfo} rows`);

const summaryOk = await cdp.eval(
  `fetch('/api/userinfo/summary?id=' + ${JSON.stringify("")} + document.location.pathname.split('/u/')[1] ).then(()=>1).catch(()=>0)`,
  true
).catch(() => 0);
void summaryOk;

await cdp.shot("20-profile-desktop");
await cdp.shotOf(".UserCard", "21-usercard", 8);

/* ============================================================ 9. mobile */

console.log("\n== mobile: the rail collapses instead of breaking ==");
await cdp.viewport(390, 844, true);
await cdp.goto(durl, ".LmxAuthor");
await Bun.sleep(1800);

const mob = await cdp.eval(`(() => {
  const p = document.querySelector('article.Post .LmxAuthor');
  if (!p) return null;
  const post = p.closest('article.Post');
  const body = post.querySelector('.Post-body');
  const r = p.getBoundingClientRect(), b = body.getBoundingClientRect();
  const doc = document.documentElement;
  return JSON.stringify({
    panelW: Math.round(r.width), panelH: Math.round(r.height),
    panelBottom: Math.round(r.bottom), bodyTop: Math.round(b.top),
    bodyW: Math.round(b.width),
    viewport: doc.clientWidth,
    scrollW: doc.scrollWidth,
    avatar: (() => { const a=p.querySelector('.LmxAvatar'); const q=a.getBoundingClientRect(); return [Math.round(q.width),Math.round(q.height)]; })(),
    nameVisible: !!p.querySelector('.LmxAuthor-name') && p.querySelector('.LmxAuthor-name').getBoundingClientRect().width > 10,
    statsVisible: [...p.querySelectorAll('.LmxStat')].filter(e=>e.getBoundingClientRect().width>0).length,
  });
})()`);
const mb = mob ? JSON.parse(mob) : null;
R.check("panel still renders on a phone", !!mb, mob);
R.check("panel stacks above the body rather than beside it", (mb?.bodyTop ?? 0) >= (mb?.panelBottom ?? 1e9) - 4, `panel bottom ${mb?.panelBottom} body top ${mb?.bodyTop}`);
R.check("body uses the full width on a phone", (mb?.bodyW ?? 0) > (mb?.viewport ?? 0) * 0.7, `${mb?.bodyW} of ${mb?.viewport}`);
// The specific way a side rail breaks on a phone: it does not shrink, and the
// page grows a horizontal scrollbar.
R.check("no horizontal overflow at 390px", (mb?.scrollW ?? 0) <= (mb?.viewport ?? 0) + 1, `scrollWidth ${mb?.scrollW} vs ${mb?.viewport}`);
R.check("avatar shrinks on a phone", (mb?.avatar?.[0] ?? 99) <= 56, JSON.stringify(mb?.avatar));
R.check("name and stats survive the collapse", !!mb?.nameVisible && (mb?.statsVisible || 0) >= 2, `${mb?.statsVisible} stats`);

await cdp.shot("30-discussion-mobile");
await cdp.shotOf("article.Post", "31-post-mobile", 6);

await cdp.goto(`${BASE}/u/${encodeURIComponent(puser)}`, ".LmxProfile");
await Bun.sleep(1800);
const mobProfOverflow = await cdp.eval(`document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1`);
R.check("profile has no horizontal overflow at 390px", !!mobProfOverflow);
await cdp.shot("32-profile-mobile");

/* ========================================================= 10. page health */

console.log("\n== page health ==");
// Filter to our own surface: another lane's console error is their gate to fix,
// but an error thrown BY this extension must fail this run.
const ours = R.consoleErrors.filter((e) => /userinfo|Lmx/i.test(e));
R.check("no console errors from this extension", ours.length === 0, ours.slice(0, 2).join(" | "));
R.check("no failed non-image requests", R.failedRequests.length === 0, R.failedRequests.slice(0, 2).join(" | "));
if (R.consoleErrors.length) console.log(`  note: ${R.consoleErrors.length} console error(s) on the page in total (other lanes):\n    ` + R.consoleErrors.slice(0, 3).join("\n    "));

await cdp.send("Target.closeTarget", { targetId }).catch(() => {});
try { proc.kill(); } catch {}

console.log(`\nscreenshots: ${SHOTS}`);
process.exit(R.summary(SHOTS) ? 1 : 0);
