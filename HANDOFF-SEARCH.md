# HANDOFF — from the search lane

Owner of this file: the search/discovery agent (`extensions/looksmax-search`).
Everything below is something I observed from outside my lane and did not touch.

---

## 1. `looksmax-store` takes the ENTIRE forum down with a 500 (economy lane)

**Status: RESOLVED by that lane at ~11:00Z — `/` returned 200 again without any
change from me. Left here because the defect is still in the file as written and
will recur if that `extend.php` line is reinstated.**
Observed 2026-08-13 10:50Z at `http://127.0.0.1:8888/`, anonymous: every page and
every API route returned HTTP 500.

```
Flarum encountered a boot error (Flarum\Extension\Exception\ExtensionBootError)
Experienced an error while booting extension: Looksmax Store.
Error occurred while applying an extender of type: Flarum\Extend\ApiController.

TypeError: Flarum\Api\Controller\AbstractSerializeController::addSerializationPreparationCallback():
  Argument #2 ($callback) must be of type callable, array given,
  called in /flarum/app/vendor/flarum/core/src/Extend/ApiController.php on line 395
```

**Exact cause — `extensions/looksmax-store/extend.php:65-66`:**

```php
(new Extend\ApiController(ListDiscussionsController::class))
    ->prepareDataForSerialization([Listeners\DiscussionEffects::class, 'preload']),
```

`prepareDataForSerialization()` reaches `AbstractSerializeController::addSerializationPreparationCallback(string $controllerClass, callable $callback)`
(`vendor/flarum/core/src/Api/Controller/AbstractSerializeController.php:466`).
The array `[Listeners\DiscussionEffects::class, 'preload']` only satisfies PHP's
`callable` type if `preload` is declared `static`. It is not, so PHP rejects the
argument at extender-application time, which is inside the boot path — hence the
whole forum, not just that one controller.

**What it would take (any one of these):**

1. Declare `public static function preload(...)` on `Local\Store\Listeners\DiscussionEffects`. Smallest change.
2. Pass an invokable class name instead — `->prepareDataForSerialization(Listeners\DiscussionEffects::class)`
   with an `__invoke()` on that class. This is the shape core's own extenders use.
3. Pass a closure: `->prepareDataForSerialization(fn (...$a) => resolve(Listeners\DiscussionEffects::class)->preload(...$a))`.

I did not edit the file. `extend.php` mtime was `2026-08-13 10:48:48Z`, ~2 minutes
before I hit it, so this looks like an in-flight edit rather than a landed state.

**Why it matters to me specifically:** search cannot be verified in a browser,
screenshotted, or exercised over HTTP while any extension fails to boot. Flarum
boot errors are global. My extension's own boot is clean (verified: disabling
nothing else, `php flarum search:status` runs and the API routes are registered).

---

## 2. For the import / content-fidelity lane

Nothing blocking. Two things that will matter when the bulk backfill runs:

- **The search outbox drains on a schedule, not synchronously.** Rows land in
  `search_index_queue` (`extensions/looksmax-search/src/Listeners/QueueIndexChanges.php`)
  and are applied by `php flarum search:sync`, scheduled `everyMinute()` in
  `extensions/looksmax-search/extend.php:90-92`. A bulk import that writes
  millions of rows through Eloquent will enqueue millions of outbox rows.
  That is fine — it is byte-batched — but it means **search is eventually
  consistent during an import, not immediately consistent**, and
  `php flarum search:status` will report coverage below 100% until it drains.
  It reports the age of the oldest queued row, which is the number to watch.

- **If the import writes with raw SQL / `insert()` rather than Eloquent model
  saves, no model events fire and nothing is enqueued at all.** In that case
  search will silently stay at whatever it was. The fix is one command after the
  import, not a code change:
  `docker exec -w /flarum/app flarum-app php flarum search:index --only=discussions,posts`
  (it is resumable — `--from=<id>`).

---

## 3. For the UI lane

`looksmax-search` injects its forum JS as its own `<script data-lmx-search>`
element with its own try/catch boundary (`src/Listeners/InjectSearch.php:45`),
and its CSS via `Extend\Frontend->css()`. It does **not** override
`IndexPage`, `HeaderPrimary`, or any Mithril component prototype, so it should
not collide with theme work. It attaches behaviourally to the core search input
via the selector `.Search-input input` and adds elements under `#lmx-*` ids and
`.lmx-*` classes only.

If the theme changes the header search markup away from Flarum's stock
`.Search-input input`, tell me and I will re-point the selector.

---

## 4. For the icons lane — the header magnifying glass is visibly deformed

Visible on every page. Cropped from `extensions/looksmax-search/shots/02-search-page.png`
at 1440×1000: the header search glyph renders as a **tall, narrow ellipse with a
stubby handle** rather than a circle.

Source: `extensions/looksmax-icons/js/dist/forum.js:29`

```js
'fa-search': 'ph:magnifying-glass-bold', 'fa-plus': 'ph:plus-bold',
```

The substitution emits an `<iconify-icon>` element. Two things follow, and I
could only verify the first from my side:

1. `<iconify-icon>` fetches its geometry from the Iconify CDN at runtime. This
   box has no route to it — my screenshot run recorded a stream of
   `net::ERR_FAILED (Font)` and CDN failures on every page load. An
   `<iconify-icon>` that has not resolved has no intrinsic size, so whatever box
   the header gives it is the aspect ratio it gets.
2. The header's `.Search-input` places it in a flex row, so a non-square box
   stretches it on one axis.

**What it would take:** give the element an explicit square box
(`width:1em;height:1em;aspect-ratio:1/1;flex:0 0 auto` on `iconify-icon`), and
either bundle the icon set locally or inline the handful of icons that appear
above the fold. Inline SVG with `preserveAspectRatio` is what
`looksmax-search/js/dist/forum.js` (`ICONS`, ~line 178) now does for its own
icons, for exactly this reason — those render correctly in the same screenshots
where the header one does not, which isolates the cause to the iconify path
rather than to the theme's CSS.

I did not touch `looksmax-icons`.

## 5. For whoever owns the Flarum base URL — a cross-origin error banner on every page

Visible at the bottom of every screenshot: *"Oops! Something went wrong during a
cross-origin request. Please reload the page and try again."*

Captured over CDP:

```
HTTP 405 https://colleague-eligibility-workers-slides.trycloudflare.com/api/tags?include=children%2ClastPostedDiscussion%2Cparent
```

The page is being served from `127.0.0.1:8888`, but `FLARUM_BASE_URL` in
`/work/flarum/.env` points at the cloudflared quick-tunnel hostname, so the SPA
issues its XHRs cross-origin to the tunnel and they fail. It is cosmetic for a
logged-out reader but it fires on every page load and it makes the console
useless as a defect signal for every other lane.

**What it would take:** either serve the forum through the tunnel hostname when
testing, or make `FLARUM_BASE_URL` relative/origin-derived. Not my file; flagged
because it is currently the loudest console error in the stack and it is not
coming from search.

## 6. `/work/flarum/.env` lost its `MEILI_MASTER_KEY` line

At 11:30Z `grep MEILI_MASTER_KEY /work/flarum/.env` returns nothing. It was
present at 10:41Z (I read the key from it to configure the extension). The
`flarum-meili` container still has the original value in its own environment,
and the two agreed when I checked:

```
container : b29f7f6e9dba…  (64 chars)
settings  : b29f7f6e9dba…  (64 chars)   -> match, search still works
.env      : (absent)
```

Search survived only because the key is also stored in the `settings` table
under `looksmax-search.key`, which is what the extension actually reads.
Anything else on this box that reads the key from `.env` is now broken, and a
`docker compose up -d` that recreates `flarum-meili` from that `.env` would
start the engine with a DIFFERENT (or empty) master key and orphan the existing
index volume.

**What it would take:** restore the line from the container's environment —
`docker inspect flarum-meili --format '{{range .Config.Env}}{{println .}}{{end}}' | grep MEILI_MASTER_KEY`
— before anything recreates that container. I did not edit `.env`; it is shared.

## 7. Search index sync now runs as a service

`lmx-search-sync.service` (unit committed at
`extensions/looksmax-search/deploy/lmx-search-sync.service`, installed and
enabled on osprey) runs `php flarum search:sync --daemon`.

It exists because Flarum runs no scheduler daemon: `Extend\Console->schedule()`
only registers work for `php flarum schedule:run`, and nothing on this box
invokes that. The outbox had gone 33 minutes without draining before I noticed —
`search:status` is what reported it.

It deliberately runs ONLY the search sync rather than the forum-wide
`schedule:run`, so this lane does not become responsible for every other
extension's scheduled jobs. **If another lane wants a general scheduler, that is
still missing and any `->schedule()` call in any extension is currently dead
code.**

Measured drain rate: **308 outbox rows/s**.
