# looksmax.lat — technical SEO fixes (§4.8)

Written 2026-08-24. Everything below was verified directly against the live
origin and the public edge, not inferred. Where something is already fixed it
says so and gives the verification.

## Context that reframes everything here

The site went live 2026-08-14. In its first 10 days Googlebot made **48 requests
total** and reached **55 distinct `/d/` URLs** out of 2,763 visible discussions.
It asked for `/robots.txt` 9 times and `/sitemap.xml` twice, and got a 404 every
time.

Crawl budget is therefore **not** the binding constraint, and nothing in this
document should be justified by it. Discoverability is the constraint. Do not
let anyone optimise crawl budget on a site Google has barely crawled.

---

## SEV 1 — The Cloudflare tunnel bypasses Caddy (architecture drift)

**Verified:** `https://looksmax.lat/healthz` returns `lmx-app-ok`, which is
nginx answering from inside `flarum-app`. Caddy returns `caddy-edge-ok` and is
reachable only at `127.0.0.1:80`.

The Caddyfile header comments state that Cloudflare routes `looksmax.lat` to
`http://127.0.0.1:80` and that Caddy is "the LOCAL origin router". That is not
what is running. `cloudflared` connects straight to the app on `127.0.0.1:8888`.

**Consequences, all currently live:**

- Every `header` directive in the Caddyfile — HSTS, `X-Content-Type-Options`,
  `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` — never reaches a
  public visitor. The security headers documented in that file are not in effect.
- The Caddy JSON access log that the `caddy-status` fail2ban jail reads sees no
  public traffic, so that jail is watching an empty stream.
- The maintenance page, and its deliberate 200-not-502 behaviour, is unreachable.
- Any `X-Robots-Tag` set in Caddy is inert.

**Fix — pick one:**

1. Repoint the tunnel public-hostname ingress from `http://localhost:8888` to
   `http://localhost:80` in the Cloudflare dashboard (Zero Trust → Networks →
   Tunnels → this tunnel → Public Hostnames). Restores the documented design and
   activates the SEO handlers already added to the Caddyfile.
2. Or accept the app as the origin, move the headers into
   `/srv/looksmax/app/nginx.conf`, and rebuild the image. If you take this path,
   delete the misleading comments from the Caddyfile so the next person is not
   misled.

Option 1 is strongly preferred — a dashboard change, no rebuild, and it makes
six other things true again.

---

## SEV 1 — Cloudflare Managed robots.txt overrides the origin

**Verified:** the origin serves a 1,069-byte robots.txt. The edge serves a
different 1,836-byte file beginning `# BEGIN Cloudflare Managed content`, with
Content-Signal directives and AI-crawler blocks. **None** of the origin
directives survive — it replaces, it does not append.

The managed file contains `User-agent: *` / `Allow: /` and **no `Sitemap:`
line**. Every rule written at the origin is currently inert, including the
`Disallow` entries for `/api/`, `/u/` and `/search`.

**Fix:** in the Cloudflare dashboard either disable the managed robots.txt
(Security → Settings → "Manage robots.txt", or AI Crawl Control depending on
plan), or add the `Sitemap:` line and the Disallow rules through that UI.
Until then, submit the sitemap directly in GSC — do not rely on robots.txt
discovery.

---

## SEV 1 — Sitemap missing — FIXED

`/sitemap.xml` returned 404. Now live and verified through the public edge:
HTTP 200, 380,674 bytes, **2,781 `<loc>` entries**, well-formed XML.

Contents: homepage, the 17 tag pages that have at least one visible discussion,
and all 2,763 visible discussions, each with `<lastmod>`.
Deliberately excluded: the 64,792 hidden discussions, empty tag pages,
restricted/hidden tags, `/u/` profiles, `/search`.

Regenerate with:

    LMX_DB_PASS=... /srv/looksmax/tools/gen-sitemap.sh

It rebuilds from the live DB and publishes into `flarum-app:/flarum/app/public/`.
**Run it after every batch that hides, deletes, or restores discussions** — a
sitemap listing URLs that now 404 is worse than no sitemap at all.

Why the file lives in the app public dir: nginx has `root /flarum/app/public`
and `try_files $uri`, so a real file is served directly, and `/flarum/app` is
the persistent `looksmax_app-data` volume, so it survives container restarts.
This placement works regardless of the tunnel-bypass issue above.

---

## SEV 2 — Every discussion page ships the same meta description

**Verified:** thread 28629 emits the forum-wide default
("Calificaciones de cara y cuerpo, y las guias que hay detras…"). So do all
2,763. Duplicate meta descriptions across the entire indexable surface.

**Fix:** emit a per-discussion description from the first ~155 characters of the
OP with markup stripped. Belongs in a small Flarum extension — `looksmax-brand`
or `looksmax-index` are the natural homes. Falling back to the forum default for
very short OPs is fine; sending it for every page is not.

---

## SEV 2 — Open Graph tags point at the homepage from every thread

**Verified on a thread page:** `og:url` = `https://looksmax.lat`,
`og:title` = `Looksmax.lat`. `<link rel="canonical">` is correct and
per-thread, so this is not a canonicalisation bug — but every social share of
every thread resolves to the homepage.

**Fix:** set `og:url` from the canonical URL and `og:title` from the discussion
title, in the same extension as the meta description.

---

## SEV 2 — Two URL shapes for the same pagination

**Verified:** Googlebot crawled both `/d/30660-...?page=2` and `/d/30660-.../p2`
for the same thread.

**Fix:** standardise on the native `/p2` form, 301 the `?page=` form to it, and
ensure paginated pages carry a self-referencing canonical. Do **not** `noindex`
page 2+ — on a forum the replies are the content.

---

## SEV 2 — `?lang=en` duplicate variant

**Verified:** Googlebot crawled `/d/30660-...?lang=en`.

The canonical tag already points at the clean URL, so this is largely handled.
Leave it crawlable — blocking it in robots.txt would stop Google seeing the
canonical, which is worse. Revisit only if `?lang=` URLs appear in GSC as
indexed.

---

## SEV 3 — Empty tag pages are crawlable

From `tags.tsv`, these have `discussion_count = 0` and remain reachable:
`p-news`, `p-rage`, `p-story`, `p-bluepill`, `p-mogs`, `p-lifefuel`, `p-not-op`,
`p-discussion`, `p-op`, `p-redpill`, `p-nsfw`, `p-success`, `p-blackpill`,
`p-motivation`, `p-method`, `p-theory`, `p-serious`, `f-17`, `f-21`, `f-22`,
`f-25`, `f-26`, `f-7`.

Already excluded from the sitemap. **Fix:** `noindex, follow` on any tag page
with zero visible discussions, or delete the unused tags outright — several are
leftovers from the source forum prefix system.

---

## SEV 3 — `/u/` profiles and `/search` are indexable

30,451 users exist, nearly all imported with no content. `/search` is an
unbounded URL space. Googlebot has already crawled `/u/admin` and
`/search?q=mewing`.

Currently addressed by `Disallow` in the origin robots.txt — **which the edge
overrides** — so they are effectively still open. Once the tunnel points at
Caddy, the `X-Robots-Tag: noindex, follow` rule already added there takes over,
which is the better directive: Google crawls, sees noindex, and drops them. A
`Disallow` alone can leave a URL indexed from inbound links with no snippet.

---

## SEV 3 — 64,792 hidden discussions return 404, not 410

63,205 were hidden in a single batch on 2026-08-19.

**Do not bulk-convert these to 410.** 410 is right only for content you will
never restore. These are reversible (`hidden_at` is simply nulled), the hide was
a bulk language purge rather than a per-thread quality judgement, and Google has
never crawled the overwhelming majority of them — so there is nothing to correct
in the index and 410 buys nothing. 404 is correct and safe here.

Reserve 410 for the DELETE_410 set that comes out of the pruning audit, where
the decision is deliberate and per-URL.

---

## SEV 4 — `first_post_id` is NULL on all 2,763 visible discussions

Not directly an SEO issue, but a data-integrity bug from the import: Flarum
normally populates `discussions.first_post_id`. Anything relying on it — excerpt
generation, "go to first post", some extensions — is silently degraded. The
per-discussion meta description fix above needs the OP, so fix this first.

    UPDATE discussions d
      JOIN posts p ON p.discussion_id = d.id AND p.number = 1
      SET d.first_post_id = p.id
    WHERE d.first_post_id IS NULL;

Back up first and run it against `flarum_restore_test` before production.

---

## Order of work

1. Repoint the tunnel to Caddy (SEV 1) — unblocks headers, logging, and noindex.
2. Disable or edit Cloudflare Managed robots.txt (SEV 1) — makes the origin rules real.
3. Submit `https://looksmax.lat/sitemap.xml` in GSC. Do not wait for step 2.
4. Backfill `first_post_id` (SEV 4).
5. Per-discussion meta description and og:url/og:title (SEV 2).
6. Pagination canonicalisation (SEV 2).
7. Empty-tag noindex (SEV 3).
