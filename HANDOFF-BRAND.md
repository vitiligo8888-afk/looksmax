# Brand handoff

Written by the brand lane (`extensions/looksmax-brand/`). Everything below is
outside that lane's surface: it lives in another extension's files, in stack
config, or in an extension nobody owns yet. Each item says what was measured,
where it is, and what it would take. Nothing here was edited by this lane.

The brand itself is documented in `extensions/looksmax-brand/BRAND.md`.

---

## P0 — must be fixed before this forum is public

### 1. `debug` is on, and a 404 returns a PHP stack trace

`config.php` on the app container has `'debug' => true` (from `FLARUM_DEBUG` in
`.env`). Any unrouted URL returns 4KB of `Flarum\Http\Exception\...` with
absolute server paths and the full middleware stack:

```
$ curl -s https://<forum>/this-page-does-not-exist | head -3
Flarum\Http\Exception\RouteNotFoundException: /this-page-does-not-exist in file
/flarum/app/vendor/flarum/core/src/Http/Middleware/ResolveRoute.php on line 60
Stack trace: ...
```

That is a path disclosure and it is what a crawler indexes for every dead link.

**What it takes:** `FLARUM_DEBUG=false` in `/work/flarum/.env`, recreate the app
container (or edit `config.php` and wait ~3s for opcache to revalidate — it is
`opcache.validate_timestamps=1`, so a 1s sleep is not enough; that was measured).

The branded error pages this lane shipped only render with debug **off**;
verified by flipping the flag for one 5-second window and back:
`extensions/looksmax-brand/design/proof/404-1440.png`.

### 2. Pusher throws on every page load

Console, on every route, from `flarum/pusher`:

```
Uncaught (in promise) You must pass your app key when you instantiate Pusher.
```

The extension is enabled with no app key configured. It is an unhandled
rejection in the boot path. Either configure it in the admin panel or disable
the extension. Not brand-owned; reported because it is one of two exceptions on
the page and the other is fixed.

---

## P1 — placeholder identity still in another lane's file

### 3. Remove the placeholder mark from `looksmax-theme`

`extensions/looksmax-theme/less/chrome.less:75-86` draws a CSS-only mark — a
rotated square with a conic brass sweep — as `::before` on the header link, with
a hover rotation at lines 88-91 and a mobile size override at lines 371-372.

Setting a logo does **not** remove it: core swaps the *text* title for
`<img class="Header-logo">`, and the pseudo-element is attached to the `<a>`, so
the forum rendered two marks side by side for the ten minutes between the logo
being set and the suppression landing. The placeholder on its own, before this
lane touched anything, is in `look-audit/index-1440.png` — the gold diamond to
the left of the header text.

It is currently suppressed from
`extensions/looksmax-brand/less/forum.less:58-62` with a one-class-more-specific
selector (0,2,2 vs 0,1,2) so the result does not depend on stylesheet order.
That is a workaround, not a fix.

**What it takes:** delete `chrome.less:75-91` and `chrome.less:371-372`, then
delete the suppression block in `looksmax-brand/less/forum.less:58-62`. Both, or
neither — deleting only the suppression brings the diamond back.

### 4. Point the theme's tokens at the brand tokens

`extensions/looksmax-theme/less/tokens.less` defines its own literals, and three
of them have already drifted from what the site renders and from what the rest
of the stack uses:

| theme token | tokens.less value | measured on the live page / used elsewhere |
|---|---|---|
| `@c-accent` (line 38) | `#e6b169` | `#e8c07d` — the value in the `settings` table, in the Gold rank and in the VIP tier |
| `@c-bg` (line 28) | `#070910` | `#0e1116` sampled from `look-audit/index-1440.png` |
| `@c-surface-1` (line 30) | `#161d28` | `#12161c` sampled from the same render |

`extensions/looksmax-brand/less/brand.less` is generated and exposes the whole
system as `--brand-*` custom properties, including full OKLab ramps, the
semantic set and mirrors of every rank and tier colour. Consuming it removes
the duplication:

```less
// extensions/looksmax-theme/less/tokens.less
--bg:        var(--brand-bg);
--surface-1: var(--brand-surface);
--surface-2: var(--brand-raised);
--accent:    var(--brand-primary);
--accent-2:  var(--brand-secondary);
--line-strong: var(--brand-line-strong);
```

Note `--line-strong`: the theme's `@c-line-strong: #66748f` (tokens.less:34) is
fine at 4.0:1, but the brand's first attempt at the same token, `#3d4a5e`,
measured 2.11:1 and would have failed WCAG 1.4.11. If that token is ever
darkened, re-run `python3 extensions/looksmax-brand/tools/palette.py`.

The compile-time `fade()` calls cannot take a custom property, so the `@c-*`
LESS variables have to stay — but they should be set from the same hexes, which
is what the table above is for.

### 5. Adopt the display face for headings

`--brand-font-display` (Archivo 700/800, self-hosted, latin subset, ~11KB per
weight, OFL) is defined in `brand.less` and the `@font-face` rules are already
loaded on both frontends. The brand lane applies it only to surfaces it owns:
the welcome banner title and the admin headings.

Discussion titles, post headers and section headings still set in the system
stack. `looksmax-theme/less/tokens.less:166` (`--font-ui`) is where a
`--font-display` sibling would go, applied at `content.less` heading rules.

---

## P2 — operational, affects everyone working on this stack

### 6. `cache:clear` serves a broken site until something warms it

Measured at 11:00 and again at 11:05: immediately after
`php flarum cache:clear`, `public/assets/forum-en.js` is a **154-byte stub**,
and every string on the page renders as a raw translation key —
`core.forum.header.sign_up_link`, `core.admin.appearance.title`. It stays that
way until the first request regenerates it (28,075 bytes). `admin-en.js` is not
generated at all until an authenticated admin request.

A screenshot taken in that window shows a forum with no English on it. If a real
visitor lands in it, so do they.

**What it takes:** follow every `cache:clear` with a warming request, and treat
a small locale bundle as a failure. `tools/verify.sh` now asserts
`forum-en.js > 20000` bytes and greps the document for `core.forum.` — reuse it
or copy the two checks.

### 7. Concurrent LESS edits take the whole site down

At 11:10 the forum returned HTTP 500 for about a minute:

```
Less_Exception_Compiler: error evaluating function `fade`
The first argument to fade must be a color index: 13254
```

The byte offset is into the concatenation of every extension's LESS, so it names
nothing. `extensions/looksmax-theme/less/components.less` had been written
one minute earlier; recompiling while a file is mid-write produces this.

`extensions/looksmax-brand/tools/lesscheck.php` compiles a given set of `.less`
files in isolation with the same compiler Flarum uses and reports per file —
that is how this lane established in one command that all three of its
stylesheets compiled clean and the failure was elsewhere:

```
docker exec flarum-app php /flarum/extensions/looksmax-brand/tools/lesscheck.php
docker exec flarum-app sh -lc 'php /flarum/extensions/looksmax-brand/tools/lesscheck.php $(ls /flarum/extensions/*/less/*.less)'
```

### 8. The `flarum/announcements` extension polls discuss.flarum.org

`php flarum list` shows `announcements:refresh` — "Fetch and cache the latest
announcements from discuss.flarum.org." It is admin-only, so no member sees it,
and no `discuss.flarum.org` string appears in the rendered forum document
(asserted in `tools/verify.sh`). Left alone because it is not user-visible, but
it is an outbound call to a third party from the admin panel.

---

## P3 — smaller things noticed while working

### 9. `mail_from_name` does not exist in this Flarum

`mail_from` is now `noreply@looksmax.lat`. Flarum 1.8 has no separate from-name
setting: it uses `forum_title`, which is `Looksmax.lat`. Nothing to do unless
the mail driver moves off `mail` — the driver is currently `mail`, i.e. PHP
`mail()`, which will not deliver from a container with no MTA. Transactional
e-mail is untested end to end for that reason; the branded header image
(`assets/email-header.png`) and the branded reset-password page are in place and
were verified by rendering, not by receiving a message.

### 10. Greycel and Standard have no contrast headroom

`#7c8695` measures 4.93:1 on `--brand-surface` `#12161c` — a pass, with 0.43 to
spare. Any surface darker than `#12161c` behind a Greycel username fails AA.
Full table: `extensions/looksmax-brand/design/contrast.json`.

### 11. The mobile header had no branding at all

Core moves the entire header, logo included, into the off-canvas drawer on
phones — measured at `x=-276, visibility:hidden` — and the visible bar is three
fixed controls. The brand lane put the bare mark back into that bar by anchoring
a pseudo-element to `.App-titleControl`
(`looksmax-brand/less/forum.less:89-106`). If that element is restyled or
replaced by another lane, the mark goes with it.

---

## P0 — added by the mark swap (2026-08-13)

### 12. Cloudflare serves a stale logo and favicon, and always will

`config.php` has `'url' => 'https://looksmax.lat'`, so Flarum prints every
asset — the header logo among them — as an **absolute** URL on the public
hostname, which is this same origin behind Cloudflare. Flarum does not
cache-bust `logo_path` / `favicon_path`: the URL is a plain
`/assets/extensions/local-looksmax-brand/lockup.svg` with no `?v=`. Measured
immediately after this deploy, from the box itself:

```
lockup.svg          -> 7404B   cf-cache-status: HIT    age: 517   (the OLD mark)
lockup.svg?bust=1   -> 29618B  cf-cache-status: MISS              (the new one)
favicon-16.png?bust=1 -> 499B  cf-cache-status: MISS              (the new one)
```

The origin is correct — `tools/verify.sh` runs against `127.0.0.1:8888` and
passes 43/43. A browser on the public hostname gets the previous mark until the
edge entry expires. The first screenshot taken of this deploy showed the old
machinist's square and looked exactly like a failed deploy; it was not.

**What it takes:** purge the Cloudflare cache for
`/assets/extensions/local-looksmax-brand/*` (needs an API token this lane does
not have), or set a shorter edge TTL for that prefix. The durable fix is that
any lane changing a brand asset must purge, because the filenames are stable by
design — a versioned filename would break `logo_path`, which stores a path and
not a URL.

`extensions/looksmax-brand/design/shot-header.ts` works around it for
screenshots by rewriting the brand `<img>` URLs onto the local origin with a
buster before capturing. It is a measurement tool, not a fix.

### 13. `dev.looksmax.lat` returned 502 while this ran

`curl https://dev.looksmax.lat/` returned **502** at 11:47 and again at 12:05,
while `http://127.0.0.1:8888/` returned 200 throughout and the quick tunnel
(`colleague-eligibility-workers-slides.trycloudflare.com`) was serving. The
named tunnel's ingress is not reaching the origin. Not touched — the tunnel is
not brand-owned.

## P3 — added by the mark swap

### 14. The mark is `devil`; Chris's avatar is `rich`

Recorded here because two lanes are picking from the same thirteen files.
`looksmax-reactions/src/Console/ChrisCommand.php:96` defaults `--face` to
`rich`, so the Chris admin account wears the aviators and money bags. The brand
mark is `devil` — different expression, different silhouette, and the only face
in the set with a silhouette break that survives 16px. If the reactions lane
moves Chris onto `devil`, the forum will render the same head as the logo and
as an admin avatar in every thread; say so rather than doing it silently.

The reduction is documented in `extensions/looksmax-brand/BRAND.md` §1 and the
renders that drove it are in `extensions/looksmax-brand/design/chrigger/`.

### 15. The reactions ladder is a separate cut of the same illustrations

`looksmax-reactions/assets/chrigger/{24,48,96,256}/` already holds processed
copies, cut by `looksmax-reactions/bin/normalise-face.py`. The brand lane cut
its own from the 992×1056 originals rather than resampling those, because the
mark needs the full-resolution ink outline to separate hair from features and
the 256px ladder has already lost it. Two cuts of one source is duplication; if
anyone consolidates, `extensions/looksmax-brand/design/chrigger.py` is the one
that handles the non-flat background and the generator's dark border frame.

### 16. The mobile bar's mark cannot be verified from 127.0.0.1

`less/forum.less:97` masks `.App-titleControl::before` with
`url('extensions/local-looksmax-brand/mark.svg')`. Flarum's LESS build rewrites
that relative URL against the configured base, so the *computed* value on the
running page is:

```
mask-image: url("https://looksmax.lat/assets/extensions/local-looksmax-brand/mark.svg")
```

Probed live at 390px: the pseudo-element exists, is `position: fixed`,
`26x26`, `content: ""`, laid out at x=37..63 — and renders nothing
(`design/proof/chrigger/header-390.png`). The element is right; the mask image
is cross-origin to the render origin (`127.0.0.1:8888`), which Chromium does
not paint. On the public hostname it is same-origin and should paint, but that
is an inference, not a measurement: a screenshot run of `https://looksmax.lat/`
from the box itself did not return within 120s and is not evidence either way.

**What it takes:** shoot the public hostname from somewhere that can reach it,
or set `url` in `config.php` to the host being tested. Same root cause as §12 —
absolute asset URLs off an origin that is not the one under test. The desktop
lockup is unaffected because `design/shot-header.ts` rewrites `<img>` sources,
which a CSS mask has no equivalent of.
