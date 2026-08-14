# HANDOFF — reactions lane

Owner: `extensions/looksmax-reactions/**`, `assets/chrigger/**`, the `Chris` account.
Nothing outside that was edited. Everything below is either something another
lane needs to know, or something this lane wants and cannot do itself.

---

## 1. `flarum-likes` is now DISABLED

`php flarum extension:disable flarum-likes` was run on osprey. Reason: a binary
like button and a 31-reaction strip in the same post footer are two competing
controls for the same gesture, and the brief for this lane is explicit that the
end state must be a real multi-reaction system rather than likes with a
different icon.

**Nothing was destroyed.**

* `post_likes` still exists and still has its rows. It was NOT dropped and NOT
  emptied, because `looksmax-userinfo`'s reception metric reads it —
  `extensions/looksmax-userinfo/src/Console/BackfillCommand.php` and its
  `reception()` path add `post_likes` on top of the derived profile counters.
* The 21 rows that existed were **copied** into `post_reactions` as the `plus1`
  reaction (`Local\Reactions\Console\BackfillCommand::absorbLikes()`,
  `extensions/looksmax-reactions/src/Console/BackfillCommand.php`). Idempotent —
  the `post_reactions_once` unique key means re-running inserts nothing.

**Action for the userinfo lane:** `userinfo_profiles.reactions_here` and
`reaction_mix` are now computable from a much better source. See §3.

---

## 2. What is on the box now

Extension `local-looksmax-reactions`, enabled, `php flarum info` lists it.

| table | rows (2026-08-13) | what it is |
|---|---|---|
| `reactions` | 31, all enabled | the catalogue. Table name + core columns are deliberately fof/reactions-compatible. |
| `post_reactions` | native reactions, one row per (post, user, type) | `UNIQUE (post_id, user_id, reaction_id)` |
| `legacy_post_reactions` | 126,296 | imported XenForo types per post |
| `legacy_post_totals` | 85,633 posts / 505,560 score | imported per-post totals + the original byline |

Catalogue groups: `chrigger` (13 custom faces), `classic` (the 9 XenForo types,
each carrying its XenForo id in `reactions.xf_id`), `extra` (9 new).

Console:

```
php flarum lmx:reactions:status      # counts + invariant checks, exit 1 on violation
php flarum lmx:reactions:backfill    # re-runnable; the import is still running
php flarum lmx:chris                 # create/repair the Chris account
```

The backfill reads a **slice** of the scrape DB, not the live file:
`extensions/looksmax-reactions/bin/extract-legacy.py` copies `post_reactions`
plus two columns of `posts` out of `/work/lmx/scraper/looksmax.db` (opened
`mode=ro` + `PRAGMA query_only`) into a 32 MB SQLite file, which is then
`docker cp`'d to `/data/looksmax-import.db` in `flarum-app`. Nothing under
`/work/lmx` is written or locked.

**Re-run the backfill after the import finishes** — it only sees posts that had
already landed in `posts.imported_id`.

---

## 3. For the **userinfo** lane

`userinfo_profiles.reactions_here` / `reaction_mix` are currently derived from
`extensions/looksmax-userinfo/bin/extract-profiles.py`, which joins the scrape
by `(thread_id, position)` because it predates a reliable `posts.imported_id`.

There is now a better source that needs no scrape access at all:

```sql
-- reactions this user's posts have received, natively
SELECT p.user_id, COUNT(*) FROM post_reactions pr
  JOIN posts p ON p.id = pr.post_id GROUP BY p.user_id;

-- imported reception, per user, with the caveat in §5
SELECT p.user_id, SUM(t.score) FROM legacy_post_totals t
  JOIN posts p ON p.id = t.post_id GROUP BY p.user_id;

-- the mix, by slug
SELECT p.user_id, r.slug, COUNT(*) FROM post_reactions pr
  JOIN posts p ON p.id = pr.post_id
  JOIN reactions r ON r.id = pr.reaction_id
  GROUP BY p.user_id, r.slug;
```

`reception()` should add `post_reactions` where it currently adds `post_likes`;
`post_likes` is now a frozen subset of it (every row was copied), so counting
both double-counts those 21.

**Not editing that file — it is yours.** File to change:
`extensions/looksmax-userinfo/src/Console/BackfillCommand.php:310-312`
(`reactions_here` / `reaction_mix` write) and the `reception()` helper.

---

## 4. For the **economy** lane

Two integration points, both already published so you need no dependency on
this extension's internals:

1. **`Local\Reactions\Events\PostWasReacted`** —
   `extensions/looksmax-reactions/src/Events/PostWasReacted.php`. Fired only on
   ADD, never on remove. Carries `->post`, `->actor`, `->reaction`. Listen with
   `(new Extend\Event())->listen(...)` and it costs this lane nothing.

2. **`reactions.points`** — every catalogue row has an integer worth
   (`+1` for positive reactions, `0` for neutral, `-1` for `angry` / `error` /
   `ugh` / `clown`). It is on the API in the forum payload
   (`app.forum.attribute('lmxReactions')[i].points`) and in the leaderboard
   response, so points policy lives in your lane, not mine.

**Requested from the economy lane, not built here:** the brief mentions custom
reactions as a purchasable item. The catalogue supports it already — a
`reactions` row with `enabled=0` plus a per-user entitlement check is all that
is missing, and entitlements are `store_entitlements`, which is yours. If you
want it, the hook to add is a filter on `ForumAttributes::__invoke`
(`extensions/looksmax-reactions/src/ForumAttributes.php:33`) — tell me the
entitlement key and I will gate the catalogue on it.

---

## 5. What the imported history can and cannot say

This constrains anything anyone builds on the legacy tables, so it is written
down rather than left to be rediscovered.

The scrape's `post_reactions` is `PRIMARY KEY (post_id, reaction_id)` with **no
user column and no timestamp**. It records *that* a post drew a type. Separately
`posts.reaction_score` is the total across all types and `posts.reaction_summary`
is XenForo's byline (at most three display names, then "and N others", and most
of those names are "Deleted member 6401").

So:

* **survives** — which types a post drew, the total, the byline verbatim
* **lost** — who reacted, when, and the per-type split

The per-type split is derivable in exactly one case and it is not a guess: a
post that drew exactly **one** type has its whole `reaction_score` on that type.
Those rows carry `count` and `exact = 1` (53,785 of 126,296). Everything else is
`count = NULL, exact = 0`, meaning *present, quantity not recorded*.

**Do not apportion a total across present types by global frequency.** It
produces numbers that look authoritative and were invented. `lmx:reactions:status`
asserts three invariants against this and exits non-zero if any is violated.

Consequently the all-time "top reactors" leaderboard counts **native reactions
only**, and says so in the UI. The per-post board is the one place legacy
participates, because a post-level score is exactly what was recorded.

---

## 6. For the **theme / UI** lane

New classes, all under the `LmxRx` prefix, styled in
`extensions/looksmax-reactions/less/forum.less` against your tokens only
(`--surface-1/2/3`, `--line`, `--ink*`, `--accent*`, `--r-*`, `--e-*`, `--t-*`).
No literal colours except inside `less/chris.less`.

`.LmxRx` `.LmxRx-chip` `.LmxRx-chip--legacy` `.LmxRx-legacyTotal` `.LmxRx-add`
`.LmxRx-picker` `.LmxRx-grid` `.LmxRx-option` `.LmxRx-preview` `.LmxRx-fly`
`.LmxRxModal-*` `.LmxRx-filterBar`

Two things you may want to know:

* The strip is added to **`CommentPost.prototype.footerItems`**, not
  `Post.prototype`. Flarum 1.8's `CommentPost` declares its own `footerItems`
  and does not call super, so an extender on `Post.prototype` is silently
  shadowed and you get `<footer class="Post-footer"></footer>` with no error
  anywhere. Verified live. If your lane ever adds footer items, use CommentPost.
* Flarum's `Post` holds a `SubtreeRetainer`; `onbeforeupdate` returns
  `this.subtree.needsRebuild()`. A plain `m.redraw()` after a late bind does
  **not** re-render posts. `post.freshness = new Date()` is the handle that
  invalidates it.

---

## 7. For the **brand** lane

`extensions/looksmax-reactions/assets/emoji/*.svg` are Twemoji, **CC-BY 4.0**
(jdecked/twemoji 15.1.0). That licence requires attribution somewhere in the
product. This lane has no page to put it on; if there is a credits or about
surface, the line is:

> Emoji artwork by Twemoji, © Twitter/X and contributors, CC-BY 4.0.

The 13 chrigger faces are the operator's own artwork; no attribution needed.

---

## 8. Open / not done

* **Admin ordering UI** exists as an API (`POST /api/lmx/reactions/order`) and a
  read-only admin list; drag-to-reorder is not built. `reactions.position` is a
  real column so reordering never destroys history (unlike fof/reactions, where
  delete-and-recreate is the only mechanism and the FK is `ON DELETE CASCADE`).
* **Smilie sprite sheet.** `/work/lmx/survey/out/CORPUS-SURVEY.md:51` records
  that the source board's ~115-class smilie sprite was never scraped, so inline
  emotes in imported posts are text chips with the emote name. That is the
  format lane's surface, not this one, but the images would have to be re-fetched
  from looksmax.org if anyone wants them.
* **`flarum/pusher` is installed and enabled.** Realtime reaction updates are
  possible and not wired.
