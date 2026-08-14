# Forum design, and seeding it with real data

This is our own forum. The scraped looksmax.org corpus is seed and test data:
it gives realistic volume, shape and edge cases to design against. It is not a
fidelity target and nothing here should be justified by matching that board.

Source of truth for the numbers below: the live crawl DB (`/work/lmx/scraper/looksmax.db`)
and the board's own counters. Board totals: **2,223,941 threads / 29,753,141 posts /
200,103 members**, 18 forums in 3 categories, 25 thread prefixes, 9 reaction types.

## 1. Concept mapping

XenForo's node tree and Flarum's tag system are not the same shape, and the mismatch is
mostly in our favour.

| XenForo | Flarum | Notes |
|---|---|---|
| Category (3) | **parent tag** | `Looksmax`, `Information`, `Looksmax International` |
| Forum (18) | **child tag** (primary) | Flarum supports exactly one level of nesting — matches the board, which is 2 deep |
| Sub-forum (9) | child tag | e.g. Cosmetic Surgery under Looksmaxing. Flat in Flarum, so encode parentage in tag order/colour |
| **Prefix (25)** | **secondary tag** | The upgrade: XenForo allows ONE prefix per thread, Flarum allows many secondary tags. `Guide` + `Blackpill` + `NSFW` becomes expressible |
| Thread | `discussions` | title, slug, comment_count, participant_count, first/last_post_id |
| Post | `posts` (type `comment`) | **content is s9e/TextFormatter XML, not HTML** — see §4 |
| Sticky / Locked | `flarum/sticky`, `flarum/lock` | already enabled |
| Reactions (9) | `fof/reactions` 1.4.1 | create the 9 types: +1, JFL, Love it, Hmm…, So Sad, Woah, Ugh.., WTF, Nerd |
| Views count | — | no core equivalent; custom column + display (§3) |
| Usergroup styling (style2/7/42…) | `groups` + group colour | 15,878 users on style7, 12,469 on style2, 4,917 on style42 — these are the rank tiers |
| Verification badges (ozzmodz) | custom, or `fof/user-badges` | 
| Attachments / inline images | `fof/upload` 1.9.0 | rehost from `i.looksmax.org` or hotlink (§5) |
| Member profile fields | `fof/masquerade` | |
| Private Ratings subforum | `fof/byobu` | private discussions with recipients |

## 2. Existing extensions — verified on packagist, stable 1.x lines

Parity set (all confirmed to have releases requiring `flarum/core ^1.x`):

- `fof/reactions` 1.4.1 — the 9 reaction types
- `fof/upload` 1.9.0 — attachments/images
- `fof/best-answer` — Guide/Method threads benefit
- `fof/polls` — Mog Battle / rating threads
- `fof/gamification` 1.6.12 — up/downvotes + ranking, maps to the reaction economy
- `fof/byobu` — private discussions (Private Ratings)
- `fof/user-directory` — member list (note: looksmax's own `/members/` is 403 to guests)
- `fof/masquerade` — profile fields
- `fof/merge-discussions`, `fof/split` — moderation parity
- `fof/follow-tags` — subscribe to a forum, which XenForo has natively
- `fof/prevent-necrobumping` — the board runs this addon too
- `v17development/flarum-seo` — the board runs `nulumia/seotools`

Evidence these are the right picks: discuss.flarum.org's own JSON:API exposes
`bestAnswerPost`, `canDownloadFiles`, `isPrivateDiscussion`, `recipientUsers`,
`recipientGroups`, `canMerge`, `isFirstMoved`, `seeVotes`, `canVote`,
`fof-prevent-necrobumping`, `seoMeta` — i.e. the flagship Flarum instance runs
almost exactly this set in production.

**Not found on packagist:** `fof/nsfw`. The NSFW prefix (6,531 threads) needs either a
spoiler-style extension or the custom blur described below.

## 3. Custom extensions — the gaps worth building

1. **Hidden content (react-to-reveal).** The board runs ThemeHouse ReactPlus, whose
   `hiddenContent.min.js` gates post bodies behind a reaction. No Flarum equivalent.
   Build: a BBCode-style tag + post-serializer that strips the body server-side unless
   the viewer has reacted. Must be server-side — a CSS blur is trivially bypassed.
2. **Views counter.** 835,000 max views, 668 avg — the board treats views as a first
   class signal. Add `discussions.view_count`, a throttled increment middleware, and a
   sort option. Cheap and high-visibility.
3. **Prefix-as-tag styling.** Render secondary tags with the board's label colours so
   `Blackpill` / `LifeFuel` / `JFL` read the same as on XenForo.
4. **Rating/Mog battle.** Ratings (183.7k threads) and Mog Battle (75) are structured
   interactions, not free text: a numeric rating aggregate per discussion, or a
   two-option image poll with a tally.
5. **Import provenance.** `imported_from_id` on discussions/posts/users so the forum
   is re-runnable and diffable against the source.

## 4. Seeding — the part that actually decides feasibility

**The trap:** `posts.content` in Flarum is **s9e/TextFormatter XML**, not HTML or
markdown. A naive `INSERT` of XenForo's HTML renders as escaped literal text. Anything
that skips the formatter produces a forum full of visible markup.

**The other trap:** counters. `discussions.comment_count`, `participant_count`,
`first_post_id`, `last_post_id`, `posts.number` are denormalised. Flarum maintains them
via events; a bulk import must either fire those (far too slow at 29.7M) or compute them
explicitly afterwards.

Architecture:

```
looksmax.db (SQLite)
  └─ export JSONL per entity (users, tags, discussions, posts, reactions)
       └─ php flarum lmx:import   ← custom console command in our extension
            ├─ users   : model layer (few hundred k, correctness matters)
            ├─ tags    : model layer (trivial volume)
            ├─ posts   : TextFormatter parse in N parallel workers → raw bulk INSERT
            └─ counters: single-pass SQL recompute, then search index rebuild
```

Why a console command inside an extension rather than the REST API: the API is
rate-limited, transactional per request, and would take days. The console command runs
in-process with Flarum's container, so `TextFormatter` and the models are available, but
we control batching and can bypass event dispatch.

Conversion chain per post: XenForo HTML → sanitise → markdown/BBCode → TextFormatter
`parse()` → XML → insert. We already extract both `html` and `text` per post, plus
`quotes`, `images`, `embeds`, `spoilers` separately, so the converter has structured
input rather than having to re-parse soup.

MariaDB during bulk load: `innodb_flush_log_at_trx_commit=2`, `unique_checks=0`,
`foreign_key_checks=0`, batched multi-row inserts of ~5k, indexes added *after* the
posts table is populated. Restore the settings afterwards.

Ordering: tags → users → discussions → posts → reactions → counters → search index.
Every stage keyed on the source id so the whole thing is idempotent and resumable, the
same discipline as the crawler's `fetch_log`.

## 5. Open decisions

- **Images.** 29.7M posts with an unknown image density (431 of our first 6,250 posts
  carry images). Rehosting via `fof/upload` means downloading from `i.looksmax.org` at
  scale; hotlinking is free but leaves the forum dependent on the source and leaks
  referrers. Recommend: hotlink first, rehost selectively for high-value content.
- **Search at 29.7M posts.** MySQL fulltext will struggle. Evaluate an external engine
  before the import, because retrofitting means a full reindex.
- **Users.** 200k accounts with no real emails. Synthesize unique addresses, mark
  unactivated, put them in an `Imported` group with no login — the content is the point,
  the accounts are attribution.
- **Scope.** Offtopic is 1.6M of the 2.2M threads (20.2M of 29.7M posts) and is the
  lowest-signal section. Seeding Looksmaxing + Ratings + Guides first gives most of
  the value for ~15% of the volume.
