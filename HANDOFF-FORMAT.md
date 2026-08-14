# HANDOFF — post content rendering & import lane

Owner of this file: the **post content rendering / import** lane
(`extensions/looksmax-format/**`, `extensions/looksmax-import/**`).

Everything below is something I found that lives **outside** my surface. I have
not touched any of it. Each item has a file/line and what it would take.

---

## 1. `import-loop.sh` must pass `--order=id`, or it can never import the corpus

**File:** `/root/looksmax-scraper/import-loop.sh` (deployed to
`/work/lmx/scraper/import-loop.sh`, run by the `lmx-import` systemd unit on osprey)

**What's wrong:** the loop invokes `lmx:import --discussions=2000 --with-posts`.
`--discussions` defaults to `--order=replies`, which is
`ORDER BY t.replies DESC LIMIT 2000` with already-imported threads skipped
*after* the query. Every cycle therefore re-reads the **same** 2,000 busiest
threads. Once those are in, every subsequent cycle imports zero rows and thread
2,001 is never reachable. The loop's own back-off logic reads that as
"done: 0 discussions → nothing to do → sleep 600s", so it looks healthy while
making no progress. At 481 of 2,205,192 threads that is indistinguishable from
being finished.

**What to change:** add `--order=id` to the import invocation.

```
php flarum lmx:import --db=... --discussions=2000 --with-posts --order=id
```

`--order=id` walks the thread table by primary key from a cursor persisted in
Flarum's `settings` table under `lmx.import.thread_cursor`
(`extensions/looksmax-import/src/Console/ImportCommand.php:396` for the key,
`:377` for the write). It is resumable across crashes and restarts, advances
past skipped threads as well as imported ones, and each batch costs O(batch)
rather than O(corpus).

**Measured on the live box:** 197 discussions / 11,126 posts in 68 s
(~164 posts/s), cursor 9,202 → 74,277, strictly monotonic across consecutive
runs. To restart from the beginning: `--reset-cursor`.

**Also worth raising the batch size.** At ~164 posts/s the box is nowhere near
saturated (32 cores, and the import is single-threaded PHP). `--discussions`
can go much higher than 2,000 per cycle. Several loops could run concurrently
over disjoint `--forum` ranges if throughput ever matters, but correctness, not
throughput, has been the bottleneck so far.

---

## 2. `--posts-per-thread` default changed; the loop may want to set it

`postsFor()` had a hardcoded `LIMIT 60`
(`extensions/looksmax-import/src/Console/ImportCommand.php:520`), which silently
dropped most of every busy thread — and `--order=replies` selects precisely for
busy threads. It is now unbounded by default, with `--posts-per-thread=N` as an
explicit opt-in cap.

This means **import batches are now larger and slower per thread than before**.
If the loop's cycle time matters, `--posts-per-thread` is the knob; leaving it
at 0 (all posts) is the correct choice for a mirror.

---

## 3. Extension load order is persisted and needs a resync after composer changes

**Not a defect — a foot-gun anyone adding an extension will hit.**

Flarum stores the *resolved* extension boot order in the `extensions_enabled`
setting, sorted topologically once at enable time. Adding
`extra.flarum-extension.optional-dependencies` to a `composer.json` does
**not** take effect until the order is re-resolved.

`local-looksmax-format` booted at index 8 while `flarum-bbcode` booted at index
25, so bbcode's stock `QUOTE` and `IMG` definitions overwrote ours — quotes
rendered unstyled and the raised `nestingLimit` never applied. Nothing logs
this; the tags simply come out wrong.

To re-resolve after any `composer require`/`update` of an extension:

```php
$em = $container->make(\Flarum\Extension\ExtensionManager::class);
$em->syncExtensionOrder();
```

then `php flarum cache:clear`. Anyone who owns the Dockerfile/entrypoint may
want this run automatically after `composer` steps —
`entrypoint.sh:56` is where it would go.

---

## 4. `local-looksmax-format` had never been installed at all

For the record, since it explains the whole class of "renders as raw BBCode"
reports: the extension existed on disk at `/work/flarum/extensions/looksmax-format`
but was never `composer require`d into the app and never appeared in
`extensions_enabled`. s9e therefore had no `SPOILER`, `UNFURL`, `EMBED`,
`UMENTION`, `GMENTION`, `EMOTE`, `TABLE` … tag to parse into, and left every one
of them as literal text.

It is now required and enabled. If the app volume is ever rebuilt from scratch,
this must be part of the provisioning:

```sh
composer require local/looksmax-format:*@dev --no-interaction
php flarum extension:enable local-looksmax-format
php flarum cache:clear
```

Whoever owns `Dockerfile` / `entrypoint.sh` may want that alongside the other
local extensions.

---

## 5. Sprite smilies need the source board's CSS to render faithfully

**Not blocking, and not mine to fetch.**

~2,166 of 2,589 smilies in a 20k-post sample are `smilie--sprite<N>` — drawn
from a CSS sprite sheet, with a 1×1 transparent GIF as the `<img src>`. The
converter currently emits `[emote name="…" label="…"]`, which renders as a
labelled text chip: readable, but not the image.

The sprite index → offset mapping is recoverable from the board's own CSS,
which the scrape already captured on osprey at `/work/lmx/media/css/`,
`/work/lmx/css.log`, `/work/lmx/getcss.json`. If whoever owns the media/asset
pipeline extracts the sprite sheet and the per-index background offsets into a
manifest, `Local\Format\Configure::emote()`
(`extensions/looksmax-format/src/Configure.php:290`) can render the real
sprite in one change.

---

## 6. Media manifest is not being passed to the importer

`lmx:import --media-manifest=<tsv>` maps source image URLs to locally
downloaded copies (`ImportCommand.php:426`). No manifest file exists on osprey
(`/work/lmx/*.tsv` — none), so every imported post currently hotlinks images
from `looksmax.org`: slow, and a live dependency on the board being mirrored.

21 GB of images are already downloaded under `/work/lmx/media/post-images/` and
are now hardlinked into `/flarum/app/public/media/`. What's missing is the
`url<TAB>path` TSV. The on-disk filenames are `Bun.hash()` of the URL, which is
not reproducible from PHP, so it has to be generated on the scraper side —
`tools/media-manifest.ts` in the scraper repo is referenced by
`ImportCommand.php:420` as the intended producer.

Once it exists, pass `--media-manifest=... --media-base=/media` and run
`lmx:import --reconvert` to repoint every already-imported post.

---

## 7. `looksmax-theme` generic `.Post-body` rules are fighting component internals

**File:** `extensions/looksmax-theme/less/content.less`

Both of these were invisible to DOM assertions and obvious the moment I looked
at a screenshot. I worked around both **in my own stylesheet** and did not touch
yours; you may prefer to fix them at the source.

### 7a. `content.less:354` — `.Post-body img`

```less
.Post-body img {
  height: auto; width: auto; margin: 0.9em 0;
  border: 1px solid var(--line); border-radius: var(--r-md);
  box-shadow: 0 12px 28px -20px #000; max-height: 620px;
}
```

Right for a photo someone posted; wrong for a component part. Its specificity is
`(0,1,1)` — identical to a bare `.lmxMedia-frame > img` — so which declaration
won came down to stylesheet order rather than intent. Observed on the live site:

- the click-to-play media facade's thumbnail took `height: auto`, rendered at its
  natural 720px inside a 315px aspect box, and **covered the "YOUTUBE" label**
  underneath it;
- the unfurl card's 16px favicon rendered as a **bordered, shadowed 40px tile**.

Worked around by raising specificity to `(0,2,1)` for `.lmxMedia-frame > img`,
`.lmxUnfurl-icon`, `.lmxUnfurl-figure img` and `.lmxQuote-avatar`
(`extensions/looksmax-format/less/forum.less`, "Defensive scoping" section).

**A cleaner fix at your end** would be to scope the rule to content images only,
e.g. `.Post-body img:not([class^="lmx"]):not(.emoji)`, or to opt in with a class
on user-content images. Any component that puts an `<img>` in a post body will
hit this — not just mine.

### 7b. `content.less:276` — `.Post-body details > summary::before`

Draws a chevron on every `<details>` in a post body. `.lmxSpoiler` ships its own
chevron (`.lmxSpoiler-chevron`, which the render tests assert on and which the
`[open]` state rotates), so **every spoiler on the site rendered two arrows side
by side**.

Worked around with `.lmxSpoiler > .lmxSpoiler-summary::before { content: none }`.
Your rule stays correct for any other `<details>`.

---

## 8. `flarum-pusher` throws on every page load

Not my surface, but it is the only remaining console error on the forum and my
screenshot harness fails on console errors, so it is worth someone's attention:

```
Uncaught (in promise) TypeError: Failed to resolve module specifier
'//cdn.jsdelivr.net/npm/pusher-js@7.0.3/dist/web/pusher.min.js'.
The base URL is about:blank because import() is called from a CORS-cross-origin script.
```

Two occurrences per page, on `/all` and on every discussion. The specifier is
protocol-relative (`//cdn.jsdelivr.net/…`), which is not a valid module specifier
for dynamic `import()` — it needs an explicit `https:` scheme. Whoever owns the
pusher configuration can fix it by setting a full URL, or by disabling the
extension if push is not actually wired up.

---

## 9. Images hotlinked from `looksmax.org` are blocked; `i.looksmax.org` is fine

Measured with `curl -I` from osprey:

| host | result |
|---|---|
| `https://looksmax.org/favicon.svg` | `HTTP/2 403` + `cross-origin-resource-policy: same-origin` |
| `https://i.looksmax.org/favicon.ico` | `HTTP/2 200` |

So any post image still pointing at the **`looksmax.org`** origin is a guaranteed
broken image in the browser (`ERR_BLOCKED_BY_RESPONSE.NotSameOrigin`), while the
CDN host `i.looksmax.org` serves cross-origin fine.

This makes item 6 (the media manifest) more urgent than it looks: it is not only
about speed and independence, it is about images that **cannot render at all**.
Until the manifest exists, anything the converter rewrites to a `looksmax.org`
URL will show as a broken image.

---

## 10. MEDIA PROXY — what I built, and the two things left that are not mine

Every third-party media URL is now rewritten at render time to a signed,
same-origin proxy path (`/media/p/{sig}/{hash}/{payload}`), which fetches once
with **no Referer and a neutral User-Agent**, caches to disk, and serves with a
one-year immutable cache. `Referrer-Policy` is now `no-referrer` (was
`same-origin`) and an **enforcing** `Content-Security-Policy` restricts
`img-src`/`media-src` to `'self' data: blob:`.

**Measured over five real threads plus the showcase, via CDP** (`e2e/format-shots.ts`):

| | before | after |
|---|---|---|
| requests to `looksmax.org` / `i.looksmax.org` | 48 on one thread, 8 to the image host | **0** |
| requests via our own `/media/p/` | 0 | **50** |
| requests that actually reached any unapproved third party | — | **0** |

### 10a. `cdn.jsdelivr.net` — 280 attempts, all blocked, none of them mine

`flarum/emoji` rewrites every emoji into a **twemoji `<img>` pointing at
cdn.jsdelivr.net**, and it does this **in the browser**, from the compiled
`forum.js` bundle. No server-side template override can reach it — I overrode
the s9e `EMOJI` template (server HTML is clean) and the client-side rewrite
happens anyway.

The enforcing CSP blocks all 280 attempts, so **nothing leaves the browser** and
`js/dist/forum.js` puts the real character back so the reader sees an emoji, not
a blocked-image glyph. But the code path is still there and still wasteful.

**A proper fix belongs to whoever owns the extension set.** Either:
- disable `flarum-emoji` (the corpus's 52,180 unicode smilies render natively
  from the character — which is also better for copy/paste and screen readers), or
- self-host the twemoji asset set under `/assets/` and point the extension at it.

### 10b. `static.cloudflareinsights.com` — 8 requests, injected at the edge

Cloudflare Web Analytics, injected by **Cloudflare on our own zone**, not by
anything in this application's HTML. It cannot be removed from the app side —
only from the Cloudflare dashboard. It is allowlisted in the e2e assertion with
that reason and **printed on every run** so it stays visible, because it does
still mean a third party sees which of our pages a real reader opened.

Whoever owns DNS/Cloudflare should decide whether to keep it.

### 10c. What the acquisition lane could do for me

Two things, both of which would make the proxy strictly better and neither of
which I can do without touching the scraper:

1. **Emit the media manifest** (`source url<TAB>local path`). 21GB of images are
   already downloaded and hardlinked at `/flarum/app/public/media/`, but the
   on-disk filenames are `Bun.hash()` of the URL, which is not reproducible from
   PHP. Without the manifest, `lmx:warm-media` cannot tell which URLs are
   already on disk and will re-fetch some of them. The warm command already
   takes `--media-manifest` and skips everything in it — it just needs the file.

2. **Route outbound fetches through the proxy pool.** `lmx:warm-media` and the
   on-demand proxy currently fetch **direct from this host's IP**, which
   correlates our public address with our readers' behaviour. Both honour
   `LMX_MEDIA_PROXY=<http or socks proxy url>` and log loudly which mode they
   are in (`outbound: DIRECT from this host — set LMX_MEDIA_PROXY…`). Point that
   env var at the pool and it is done. **If you would rather the acquisition
   lane simply prioritise these images in its own puller, say so** — the warm
   command's thread ordering (`views`, `sticky`, `first_post_reaction_score`,
   `replies`, recency) is exactly the priority list, and I would rather hand you
   that ordering than run a second downloader competing for the same pool.

---

## 11. Things I deliberately did not do

- **Did not touch** `looksmax-theme`, `looksmax-userinfo`, `looksmax-ranks`,
  `looksmax-icons`, `looksmax-store`, `looksmax-economy`, `looksmax-brand`,
  the scraper, the `lmx-*` containers, `lmx-acquire`, or `/root/looksmax-handoff/`.
- **Did not restart `flarum-app`.** Import and audit runs go through a
  throwaway sidecar container off the same image and app volume, so the live
  site is never interrupted:
  ```sh
  docker run --rm --network flarum_default \
    -v flarum_app-data:/flarum/app \
    -v /work/flarum/extensions:/flarum/extensions \
    --entrypoint php flarum-app /flarum/app/flarum lmx:import ...
  ```
  This is also how the render tests and the leak audit are run.
