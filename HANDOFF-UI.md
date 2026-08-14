# HANDOFF — things found outside the UI lane's surface

Appended by the UI/visual lane (theme, userinfo, ranks, icons, index, chat, guides,
search, analytics, e2e). Each entry is `file:line` + what it would take. Nothing
here has been changed by this lane.

---

## 1. `looksmax-store` takes the whole forum down (P0, was live)

`extensions/looksmax-store/extend.php:66`

```php
->prepareDataForSerialization([Listeners\DiscussionEffects::class, 'preload']),
```

Flarum 1.8 `Extend\ApiController::prepareDataForSerialization` forwards its
argument to `AbstractSerializeController::addSerializationPreparationCallback`,
which is typed `callable` but is called with the argument in a position PHP does
not accept an array-callable for. Live error, every page and every console
command:

```
Fatal error: Uncaught TypeError:
  Flarum\Api\Controller\AbstractSerializeController::addSerializationPreparationCallback():
  Argument #2 ($callback) must be of type callable, array given,
  called in /flarum/app/vendor/flarum/core/src/Extend/ApiController.php on line 395
Next Flarum\Extension\Exception\ExtensionBootError:
  Experienced an error while booting extension: Looksmax Store.
```

Observed at 2026-08-13 10:47Z: `GET /` → **HTTP 500**, `php flarum cache:clear` →
fatal, `php flarum extension:disable` → fatal (the container cannot boot far
enough to run any command). The forum was completely down.

**What it would take:** pass a closure instead of an array —
`->prepareDataForSerialization(fn (...$a) => Listeners\DiscussionEffects::preload(...$a))`
— or make `preload` a real invokable. Verify with
`ssh osprey 'docker exec flarum-app php flarum cache:clear'` exiting 0 and
`curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8888/` returning 200.

**Note for whoever owns deploys:** an extension whose `extend.php` throws cannot
be disabled through `php flarum`, because disabling boots the app first. The way
out is to move the extension directory aside on the host and `cache:clear`.

---

## 2. `looksmax-import` throws on real imported content

`extensions/looksmax-import/src/HtmlToBbcode.php:1064`

```
TypeError: Local\Import\HtmlToBbcode::text(): Return value must be of type string,
null returned
```

Logged twice during a live import run (2026-08-13 10:47Z, 10:48Z). `text()` is
declared `: string` but returns `null` on some node shape — almost certainly a
`DOMNode` whose `nodeValue`/`textContent` is null (a comment, a doctype, or an
empty element). Also in the same log, earlier: `InvalidArgumentException: Invalid
UTF-8 input in s9e/text-formatter/src/Parser.php:216` for Cyrillic post bodies
coming through `ImportCommand.php:188`, which aborts that post's import.

**What it would take:** `?? ''` on the return, plus a UTF-8 validity pass
(`mb_convert_encoding($s, 'UTF-8', 'UTF-8')`) before handing a body to the
formatter. Both are one-liners; the second one is currently losing posts.

---

## 3. `flarum-pusher` is enabled but unconfigured — console error on every page

Not our extension (vendor), but it is a console error on 100% of surfaces and
therefore fails this lane's console gate:

```
Uncaught (in promise) TypeError: Failed to resolve module specifier
'//cdn.jsdelivr.net/npm/pusher-js@7.0.3/dist/web/pusher.min.js'.
The base URL is about:blank because import() is called from a CORS-cross-origin script.
```

It fires twice per page load. `php flarum info` lists `flarum-pusher v1.8.1` as
enabled; no Pusher key is set, so the extension does nothing except throw.

**What it would take:** `php flarum extension:disable flarum-pusher` (blocked at
the time of writing by entry 1 above), or set a real Pusher app key. If the chat
lane wants Pusher as a real-time transport, configuring it also removes the
error.

---

## 4. `looksmax-search` and `looksmax-format` are in the repo but not installed

`php flarum info` lists neither. The repo ships complete extensions
(`extensions/looksmax-search` — Meilisearch engine, facets, saved searches, a
`/search` route; `extensions/looksmax-format`). A `flarum-meili` container is up
on 127.0.0.1:7701. The forum currently runs on core search only, so `/search`
404s and none of the search UI in this lane's surface is reachable.

**What it would take:** `composer require` the local paths (or enable via the
admin panel) and run `php flarum lmx:search:index`. Not done here because
enabling an extension changes the boot path for every page and another lane was
mid-deploy; it needs a quiet window and a full sweep afterwards.

---

## `looksmax-ranks` LESS passes a CSS custom property to `darken()` — every page 500s

`extensions/looksmax-ranks/less/forum.less:227`

```less
background: linear-gradient(180deg, var(--surface-1, #161d28), darken(var(--surface-1, #161d28), 1.5%));
```

`wikimedia/less.php` evaluates `darken()` at compile time and `var(--x, #hex)` is
not a colour to it, so compilation aborts, `forum.css` is never written and the
whole document is replaced by the compiler stack trace. Observed by the chat lane
at 2026-08-13 10:52Z (the same box had rendered fine at 10:47Z):

```
Less_Exception_Compiler: error evaluating function `darken`
  The first argument to darken must be a color index: 7695
  in /flarum/app/vendor/wikimedia/less.php/lib/Less/Tree/Call.php on line 80
```
`GET http://127.0.0.1:8888/` → the trace above instead of HTML, on every route.

**What it would take:** LESS colour functions cannot take custom properties.
Either hard-code the darkened literal
(`darken(#161d28, 1.5%)` → `#151c26`), or drop the second stop and use an
overlay (`linear-gradient(180deg, transparent, rgba(0,0,0,.06))`) over
`var(--surface-1)`. Two other call sites in `looksmax-theme` pass real LESS
variables and are fine (`less/forum.less:42`, `less/tokens.less:271`).
Verify with `ssh osprey 'docker exec flarum-app php flarum cache:clear && docker exec flarum-app ls /flarum/app/public/assets | grep forum.css'`
and a 200 from `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8888/`.

---

## `flarum/pusher` is enabled with no credentials — a console error on every page

Not a file in this repo: the extension is enabled in the database
(`php flarum info` lists `flarum-pusher v1.8.1`) and no `pusher_*` rows exist in
`settings`, so its frontend constructs `new Pusher(undefined)` on boot. Measured
by the chat lane at 2026-08-13 11:0xZ over CDP, on `/` and on `/t/f-2`, logged in
and logged out:

```
Runtime.exceptionThrown  "Uncaught (in promise)"
  value: "You must pass your app key when you instantiate Pusher."
```

Six occurrences in one 40-second session. It is the only console error left on
the index, and it makes "zero console errors" unassertable for every other lane
as well — a real regression would be indistinguishable from this noise.

**What it would take:** either `docker exec flarum-app php flarum extension:disable flarum-pusher`
(nothing on the forum uses it — the shoutbox polls), or fill in
`pusher_app_key` / `pusher_app_id` / `pusher_app_secret` / `pusher_app_cluster`
in Settings. Verify with a CDP session that collects `Runtime.exceptionThrown`
on `/` and sees none.

---

## `looksmax-userinfo` LESS uses `@container` — wikimedia/less.php cannot parse it, every page 500s

`extensions/looksmax-userinfo/less/forum.less:400`

```less
@container lmxstats (max-width: 190px) {
```

Observed by the chat lane at 2026-08-13 11:20Z, `GET http://127.0.0.1:8888/`:

```
Less_Exception_Chunk: ParseError: Unexpected input in forum.less on line 400, column 12
  in /flarum/app/vendor/wikimedia/less.php/lib/Less/Parser.php on line 625
```

`wikimedia/less.php` implements the LESS 2.5 grammar; `@container` did not exist
then, so it is not parsed as an at-rule and the whole stylesheet aborts —
`forum.css` is never written and the compiler trace is served instead of HTML on
every route. (`container-type` in a declaration is fine; it is the at-rule that
is fatal.) This is the third LESS-compiler outage today, all of the same shape.

**What it would take:** escape the whole block so it is passed through verbatim
rather than parsed — LESS supports `@media` interpolation but not unknown
at-rules, so the reliable form is to keep the query in a plain CSS file included
verbatim, or replace it with a width media query. A cheap guard for the whole
stack: after any LESS change, `docker exec flarum-app php flarum cache:clear &&
curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8888/` must print 200 and
`docker exec flarum-app ls /flarum/app/public/assets | grep forum.css` must print
a file.

---

## 5. `looksmax-format` LESS passes CSS custom properties to `fade()` — every page 500s

`extensions/looksmax-format/less/forum.less:18-24`

```less
@lmx-surface-2: var(--surface-2, #171d26);
@lmx-surface-3: var(--surface-3, #212a36);
@lmx-ink:       var(--ink, #e8ecf2);
@lmx-accent:    var(--accent, #e6b169);
@lmx-accent-2:  var(--accent-2, #6ea8fe);
```

…and then `fade(@lmx-accent, 30%)` at lines 560, 565, 605, 610, 611, 655, 718.

`wikimedia/less.php` evaluates `fade()` at COMPILE time, and `var(--accent, #e6b169)`
is not a colour to it:

```
Less_Exception_Compiler: error evaluating function `fade`
  The first argument to fade must be a color index: 13254
  in /flarum/app/vendor/wikimedia/less.php/lib/Less/Tree/Call.php:80
```

Observed 2026-08-13 11:10Z: `GET /` → **HTTP 500** on every route, `forum.css`
never written. Assigning a `var()` to a LESS variable is fine on its own — it
only detonates when that variable reaches a colour function.

**What it would take:** keep the `@lmx-*` variables as they are for plain use,
and add literal-valued twins for the `fade()` call sites —

```less
@lmx-accent-lit: #e6b169;   // literal twin, ONLY for fade()/darken()/lighten()
… fade(@lmx-accent-lit, 30%)
```

— or replace those calls with `color-mix(in srgb, var(--accent) 30%, transparent)`,
which less.php passes through untouched and which keeps the token live at
runtime. Same rule applies to `darken()`, `lighten()`, `mix()`, `saturate()`,
and to `+` inside `calc()`.

**Verify with `e2e/deploy.sh`** (in this repo). It rsyncs, clears the cache,
asserts HTTP 200, asserts `forum.css` exists, and then loads the site in a real
browser to confirm the theme tokens resolve. It has now caught three separate
instances of this exact class of failure, including two of mine. Please run it
after every deploy — one broken LESS file takes down every other lane's ability
to verify anything.

---

## `.LmxCard` is defined twice — `looksmax-userinfo` pins every sidebar card to 300px

`extensions/looksmax-userinfo/less/forum.less:520`

```less
.LmxCard {
  width: 300px;      // intended for the hovercard in .LmxHoverHost
  ...
}
```

`looksmax-index` already owns `.LmxCard` as the index sidebar card
(`looksmax-index/less/forum.less:176`), so this unscoped `width` applies to
every card in the rail. Measured by the chat lane at 420px (mobile emulation,
CDP), on `/`:

```
sidebar column = 390px, alignItems = normal
LmxCard LmxChat            300px (computed width: 300px)
LmxCard (pinned/trending/latest)  300px each
LmxCard LmxCard--stats     300px
LmxCard LmxCard--leaders   300px
LmxCard LmxCard--ad        300px
```

So on any viewport under 980px — where the index correctly collapses to one
full-width column — the whole sidebar renders as a 300px strip with 90px of dead
space beside it. On desktop it is invisible because the grid track happens to be
300px too.

**What it would take:** scope the hovercard rule to its host —
`.LmxHoverHost .LmxCard { width: 300px; }` — or rename the hovercard's class
(`.LmxHoverCard`), which is the safer fix since two extensions now style
`.LmxCard`. Verify at 420px that `.LmxIndex-side > .LmxCard` measures the same
width as `.LmxIndex-side`.

---

## 6. Rail block registry — the contribution point other lanes asked for

Not built yet (see the report), but the shape is fixed so lanes can plan against
it. The operator asked for desktop rails that are "fully configurable and
dynamic": blocks an admin can add, remove, reorder and configure, not hardcoded
markup.

Measured on the live site, which is the case for building it at all:

```
viewport 1440 -> container 1100px at x=183   (340px unused)
viewport 1920 -> container 1100px at x=423   (820px unused, 43% of the width)
viewport 2560 -> container 1100px at x=743   (1486px unused)
```

**Intended contribution point** — `Local\Index\Rails::register()`, an ItemList
keyed by block id, each block declaring: `id`, `title`, `icon` (iconify name),
`side` (`left` | `right`), `defaultPosition`, `render(User $actor): ?string`,
and an optional `settings` schema rendered in the admin panel. Blocks returning
null are omitted entirely rather than rendering an empty card. Order and
enablement persist in Flarum settings under `looksmax-index.rails`.

Lanes with something that wants a slot — store (featured items, own balance),
ranks (leaderboard, already rendered as `.LmxCard--leaders` by DOM injection
into `.LmxIndex-side`, which this replaces), search (saved searches, recent
queries), guides (tier index) — should register a block rather than injecting
into the sidebar DOM. Until this exists, DOM injection is the only option and is
what ranks currently does.

**Ad slot:** one reserved block with fixed dimensions declared up front so
nothing reflows when a creative loads or fails, an explicit empty state, and no
network request at all unless a creative is configured.

---

## 7. Front page overhaul — the six sections, and how they map onto imported data

### The mapping question, answered with counts

The six curated sections **do not exist in the scraped board**. Measured on the
live database (6,489 discussions, 47 tags):

```
p-serious  2321   f-3  Offtopic              2040   f-2  Looksmaxing   1763
f-8        1063   f-7  Ratings                925   p-guide            821
p-success   659   p-op                       581   p-theory            546
```

There is no `Peptides` tag, no `Anabólicos`, no `Softmaxing`, no
`Peligrosomaxing`. The import produced forums (`f-*`: Looksmaxing, Offtopic,
Ratings, Moneymaking) and XenForo prefixes (`p-*`: Serious, Guide, Theory). So
the six sections are **not a rename** of anything that exists, and cannot be.

### Decision: sections are a CURATED VIEW, materialised additively

1. Six **new** tags are created for the sections. Nothing existing is renamed,
   re-parented or deleted — the operator's standing rule, and the 47-tag tree
   stays reachable at `/tags` so no imported content becomes unreachable.
2. A re-runnable classifier **adds** a section tag to a discussion and never
   removes an existing one. A discussion keeps `f-2 Looksmaxing` + `p-serious`
   AND gains `Softmaxing`. Idempotent, so it can be re-run as rules improve.
3. Confidence is recorded per assignment, so a wrong rule is findable and
   reversible rather than baked in.

Mapping confidence, from the data:

| Section | Source | Confidence |
|---|---|---|
| **Mejores Guías** | `p-guide` (821) + `f-9 Best of the Best` (137) | high — direct |
| **Looksmaxing** | `f-2` (1763) + `f-16` (142) minus what the other sections claim | high |
| **Peptides** | keyword over title/body (HGH, IGF, BPC, TB-500, ipamorelin…) | medium — needs a measured keyword pass, NOT guesses |
| **Anabólicos** | keyword (testosterona, trembolona, AAS, ciclo, SARM…) | medium — same |
| **Softmaxing** | keyword (skincare, dieta, gym, pelo, dermarolling…) + `f-28 Fitness & Health` | medium |
| **Peligrosomaxing** | keyword (cirugía, MARPE, bone smashing, sarms + riesgo…) + `f-27 Cosmetic Surgery` | medium |

**Whoever builds the classifier: derive the keyword lists from the corpus, not
from your own head.** Extract candidate terms from titles/bodies and check the
slice boundary; a hand-written keyword list is the known failure mode here.

### Titles and descriptions are translation keys from day one

The six names are the operator's own and are **not** to be renamed, translated
or "improved". Ship exactly:

```
Peptides · Anabólicos · Softmaxing · Looksmaxing · Peligrosomaxing · Mejores Guías
```

For the **i18n lane**: these need keys, with Spanish primary and English a
first-class second. Proposed namespace, please confirm or correct:

```
local-looksmax-index.forum.section.peptides.title        = "Peptides"
local-looksmax-index.forum.section.peptides.desc         = <you own this copy>
… .anabolicos / .softmaxing / .looksmaxing / .peligrosomaxing / .mejores_guias
```

Slugs stay ASCII (`anabolicos`, `mejores-guias`); only display strings are
translated. The UI lane will read these keys and will not hardcode any of the
six strings. **Descriptions are yours to write** — one short line each, aimed at
someone who has never used a forum.

### Section colours and icons

Drawn from the same OKLCH ramp as `Palette.php` (one lightness and chroma, hue
assigned semantically) so six sections read as one set, and every one clears
4.5:1 on `--bg`, `--surface-1` and `--surface-2`. Icons come from the
self-hosted bundle in `looksmax-icons` — any new icon must be added to
`js/dist/icons.json`, because nothing is fetched from a CDN any more.

---

## 8. CORRECTION to §7 — Spanish is the DEFAULT locale, not the "primary"

§7 said "Spanish primary, English a first-class second". That is now wrong in an
important way, and the difference matters for whoever writes the keys:

**Spanish is the SOURCE string. English is the translation.** Not the reverse.
No English fallback should be treated as the canonical value of a key.

Consequence for every lane shipping user-facing copy: do not write an English
string and hand it over to be translated. Ship the key, and flag it as needing
Spanish source text. The UI lane does not invent Spanish copy.

The six section names remain exactly as the operator gave them and are not to be
renamed, re-translated or "improved":

```
Peptides · Anabólicos · Softmaxing · Looksmaxing · Peligrosomaxing · Mejores Guías
```

## 9. News block on the front page — for the i18n lane

Operator: *"and news and shit on top too ofc"*. A news/announcements block opens
the front page above the six sections, built as one of the configurable rail
blocks (§6) rather than welded into the template, defaulting to full content
width — which is also the job for the 43% of horizontal space measured empty at
1920 wide.

Relevant to i18n: it is a block of prose, so it is the string-heaviest thing on
the front page. Keys land under:

```
local-looksmax-index.forum.news.*
```

covering at minimum the block title, the "new since your last visit" marker, the
dismiss/collapse control and its restore affordance, the relative-time strings,
and — the ones most likely to be forgotten — the **empty state** and the
**single-item state**. Those two are explicit deliverables, not fallbacks, so
they need real Spanish copy rather than a shrug.

Existing content it can render immediately: `f-11 News & Announcements`, 42
discussions in the live database, so a tag-backed implementation needs no new
authoring UI. The UI lane is deciding tag-backed vs admin surface on the merits
and will report which and why.

---

## 10. Findings from the cohesive-UI pass (2026-08-13, ~15:00–16:00Z)

Everything here was **measured on the running site**, and everything in this
section is **outside** the UI lane's surface. Nothing below has been changed by
this lane.

### 10.1 `discussions.first_post_id` is NULL on 99.99% of rows — `looksmax-import`

```sql
SELECT COUNT(*) total, SUM(first_post_id IS NULL) null_first FROM discussions;
-- 71473   71469
```

The bulk import inserts posts without the model events that maintain the column,
the same class of defect as `users.discussion_count` (which the userinfo lane's
`userinfo:backfill --counts-only` now repairs — measured 952 wrong
`discussion_count` and 3,184 wrong `comment_count` before, 0 after).

**What it costs, concretely.** Anything that wants a thread's opening post has to
find it the long way. `looksmax-index`'s feed originally joined on it and every
excerpt came back empty, which looked like a stripping bug in `Html::excerpt()`
and was not; the `reacted` tab joined `legacy_post_totals` through it, returned
zero rows, and silently removed its own tab from the strip. Both now go through
`(discussion_id, number = 1)` instead — see
`extensions/looksmax-index/src/Blocks/FeedBlock.php` `attachExcerpts()`. Core
reads this column too (`Discussion::firstPost`), so the workaround only covers
our own surfaces.

**What it would take:** one UPDATE at the end of the import, the same shape as the
counter recount already in `looksmax-userinfo/src/Console/BackfillCommand.php`:

```sql
UPDATE discussions d SET first_post_id =
  (SELECT p.id FROM posts p WHERE p.discussion_id = d.id AND p.number = 1 LIMIT 1);
```

Verify with the query at the top of this section returning `null_first = 0`.

### 10.2 A running import overwrites `tags.color`, so any colour system is transient

`php flarum lmx:tag-colours` (new, `looksmax-index`) assigns all 47 non-section
tags a measured OKLCH colour — every one clears 4.5:1 against all four dark
surfaces (worst 5.02, best 5.55) and its darkened light-scheme form clears 4.5:1
on white (worst 5.15). It writes, and the write was verified in the same second:

```
f-2       #7aa2f7 -> #de816c
p-serious #7aa2f7 -> #f17074
```

**Two minutes later both rows read `#7aa2f7` again.** `ps aux` on the box shows
27 live `lmx:import` processes; the import writes tags and resets their colour to
the source board's palette. So the command is correct and the result is not
durable while an import is running.

**What it would take:** either have the import stop writing `color` on a tag that
already exists (it is our value, not the source board's), or re-run
`php flarum lmx:tag-colours` after the import completes. The command is
idempotent and reversible (`--restore`, backed up to the
`looksmax-index.tag_colours_backup` setting before it writes).

### 10.3 `looksmax-ranks` ships two name colours that cannot be read

From `looksmax-brand/tools/palette.py`, which parses `looksmax-ranks/src/Catalog.php`
and measures every colour it finds:

```
style ns-kraken    #123a4d on --surface-1 : 1.51:1   (needs 4.5)
style ns-obsidian  #15181f on --surface-1 : 1.03:1   (needs 4.5)
```

These are purchasable username colours (`store_items`, `category='colours'`), so a
member can spend 16,000 credits on a name nobody can see. `ns-obsidian` at 1.03:1
is invisible, not merely low.

**What it would take:** lighten both in `Catalog.php` until `palette.py` stops
reporting them — it prints the achieved ratio for every candidate, so this is a
two-minute loop. Also note two catalogue colours now sit inside the reserved
house-hue band 278–308° (`rank master #a78bfa`, `tier elite #bb9af7`) and are
within 0.04 and 0.09 OKLab of the new violet accent, i.e. a bought status colour
that matches the furniture.

### 10.4 A Flarum console fatal produces EXIT 255 and completely empty output

Not a defect in anyone's extension — a property of the stack, recorded because it
cost this lane 20 minutes. A fatal inside a command's `fire()` prints **nothing**
on stdout or stderr; `php -l` passes, `php flarum list` works, and the only
symptom is `rc=255`.

```
php -d display_errors=1 -d error_reporting=E_ALL flarum <cmd>
docker exec flarum-app sh -c 'tail -40 /flarum/app/storage/logs/flarum-$(date +%Y-%m-%d).log'
```

Either of those shows the real error immediately.

### 10.5 Probe `https://looksmax.lat`, never `http://127.0.0.1:8888`

Flarum emits **absolute** asset URLs against its configured base url. Probing the
loopback therefore makes every webfont cross-origin and reports failures a real
visitor never sees. Measured, same page, same browser build, one minute apart:

```
http://127.0.0.1:8888/   5 x "Font net::ERR_FAILED"  (fa-solid-900.woff2/.woff/.ttf, archivo-800.woff2)
https://looksmax.lat/    0 failed requests
```

`curl -sI https://looksmax.lat/assets/fonts/fa-solid-900.woff2` returns
`access-control-allow-origin: *`. The nginx CORS fix works; the loopback probe was
the bug. `e2e/visual/sweep.ts` already auto-detects and switches, but any ad-hoc
probe needs the same treatment.
