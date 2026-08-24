# looksmax.lat — Content Quality & Pruning Audit (Phase 1)

**Status:** Read-only. No page has been changed by this audit. Awaiting your approval before any write.
**Date:** 2026-08-24 · **Standard applied:** YMYL (strictest tier).
**Backup:** `flarum-2026-08-24T21-51-42Z.sql.gz` (256 MB, md5 `dd9d5e53…`), restore-verified into a scratch DB (discussions/posts/users counts matched exactly). Manifest in `backups/`.

---

## 0. The one thing you need to read first

This is not a normal pruning job, and I won't pretend the levers in the brief (canonicals, thin-page cleanup, pagination) are the real problem. Two findings dominate and both are the most severe classifications in the rubric:

1. **100% of the indexed corpus is unlicensed machine-translation of looksmax.org.** All 2,612 of 2,613 visible guides have a row in `lmx_translation_backup` recording their English original. Under Google's spam policy this is **scaled content abuse** (translating another site's content at scale without adding original value), which is assessed **at the domain level**, not per page. It is also a **copyright problem**: a translation is a derivative work and needs the rights-holder's permission. No amount of technical SEO raises quality signals on a corpus that is entirely someone else's content in another language.

2. **~40% of that corpus additionally trips YMYL safety flags.** 1,047 guides match one or more of: specific PED/SARM/GH dosing (810), sourcing controlled/prescription substances (212), DIY surgical/bone-modification (177), disordered-eating/starvation (32), self-harm/hopelessness framing (39). On a YMYL site this is the fastest possible way to fail a quality assessment, and parts of it are a human-safety and legal issue that outrank any ranking consideration.

**Honest strategic conclusion:** pruning can *de-risk* this domain (remove the unsafe, de-index the derivative) but it cannot *manufacture* quality. The only path to the "high-quality, first-hand-experience resource" in the objective is to **replace translated PED/appearance content with original, expert-authored Spanish content, and resolve the rights question on anything kept.** Everything below is written to that end, not to make translations rank better.

---

## 1. Index at a glance

| Bucket | Count | Note |
|---|---:|---|
| Visible/indexable discussions | **2,613** | the entire SEO surface; all are guides |
| — of which translations of looksmax.org | **2,612 (99.96%)** | via `lmx_translation_backup` |
| Already hidden (prior purge) | **64,942** | already return 404 + `noindex` — a completed removal cohort |
| Sitemap `<loc>` entries | 2,781 | 2,763 discussions + home + 18 tags; **clean** (0 point at hidden URLs) |
| Googlebot reach (server logs, since 2026-08-14 launch) | **48 requests, ~55 distinct URLs** | the domain is effectively **uncrawled** |
| Visible URLs with any search-bot hit | **2** | → search equity ≈ 0 |
| Total historical hits on visible URLs (all clients) | 20,131 | low; top page 215 hits |

**Implication of the crawl data:** because Google has barely crawled this site, **equity-at-risk is ≈ 0** and there is no 404-spike penalty to fear at the search level. That makes aggressive de-risking *now* essentially free — the opposite of a mature site. Do not let anyone optimise crawl budget here (see `technical-fixes.md`); discoverability, not budget, is the constraint. No GSC/GA4/Ahrefs property exists in this environment, so those columns are null throughout; server access logs are used as the demand proxy.

---

## 2. Action distribution (the core deliverable → `actions.csv`)

| Action | Count | % of index | Meaning |
|---|---:|---:|---|
| **REWRITE** | 2,312 | 88.5% | Real topic, but it's a translation and/or has dosing content. Keep the URL; replace with original Spanish content (strip specific dosing, add citations + disclaimer + named author). → `rewrite-queue.csv` |
| **REMOVE_URGENT** | 277 | 10.6% | §4.5 safety/legal: sourcing controlled substances, disordered-eating, self-harm framing. Remove content; **301 to the section hub** (never homepage) to avoid a 404. → `urgent-removals.csv` |
| **CONSOLIDATE** | 11 | 0.4% | Near-duplicate; merge into the more comprehensive twin, then 301. → `redirect-map.csv` |
| **KEEP** | 10 | 0.4% | Canonical winners of duplicate clusters. |
| **NOINDEX** | 3 | 0.1% | Thin but real; keep for members, drop from index. |

There are **zero DELETE_410** actions. Nothing is hard-deleted: your equity rule (never destroy a URL with traffic/links) is honoured by construction — every disposition either keeps the URL (REWRITE/NOINDEX/KEEP) or 301s it (CONSOLIDATE/REMOVE_URGENT).

**DIY-procedure (177) note:** I routed these to REWRITE-with-safety-reframe rather than auto-remove, because a keyword can't tell "here's why bonesmashing is dangerous" from "here's how to do it at home." **Every DIY row needs a human eyes-on decision** — any page containing actual self-procedure instructions must be flipped to REMOVE_URGENT. They're tagged `4.5-DIY-reframe` in `actions.csv` and `rewrite-queue.csv`.

---

## 3. Traffic at risk

Urgent removals carry **2,220 historical hits** in total — but all are 301'd to their section hub, so the link equity and any bookmarks survive; only the unsafe content is withdrawn. Search equity at risk is ≈ 0 (2 of these URLs were ever bot-crawled). This is the rare case where the safety-mandated removals cost almost nothing in SEO terms.

---

## 4. Highest-risk decisions for your review

The genuine risks here are (a) safety false-negatives (leaving something dangerous up) and (b) safety false-positives (pulling a legitimate educational page). Review these classes personally:

- **Sourcing (212 → REMOVE_URGENT).** Highest-confidence removals; sourcing prescription/controlled substances is non-negotiable. Spot-check that none are false hits on the word "fuente"/"comprar" in an innocuous context. Top by traffic includes `"Guía Básica de HGH"` (33 hits).
- **Self-harm / hopelessness (39 → REMOVE_URGENT) and disordered-eating (32 → REMOVE_URGENT).** These are P0 for human-safety reasons independent of SEO. Includes items like `"Glosario Oficial de Looksmax.gov"` (17 hits). Remove first, before anything else in the schedule.
- **Dosing/PED (810 → REWRITE).** The single biggest bucket. The decision to *rewrite* rather than *remove* rests on your appetite: rewriting to educational-with-sources keeps the pages but is a large content-production commitment; if that won't happen soon, these should be `noindex` in the interim so they don't drag the domain while unwritten.
- **The 11 CONSOLIDATE merges** (full list in `redirect-map.csv`) — e.g. two `sleepmaxxing` guides → `/d/18298`, two `melanotan-2` guides → `/d/46047`. Confirm each canonical is the better page before the 301s go in.
- **The translation question itself** — see §6.

The full ranked lists live in `urgent-removals.csv` (by priority + traffic) and `rewrite-queue.csv` (by real hits).

---

## 5. Recommended rollout

§4.5 removals are exempt from the 5%/week cap (safety/legal), everything else obeys it. Because equity ≈ 0, the cap is a caution, not a constraint.

1. **Week 0 (immediate, out-of-band):** REMOVE_URGENT — self-harm (39) → disordered-eating (32) → sourcing (212). 301 each to its section hub. Regenerate sitemap; spot-check 20 with URL Inspection *once a GSC property exists*.
2. **Week 0:** CONSOLIDATE the 11 duplicates; NOINDEX the 3 thin pages.
3. **Weeks 1+:** DIY-procedure human review (177) → REMOVE or REWRITE.
4. **Weeks 1→N:** REWRITE queue by priority. Two viable modes: (a) genuinely rewrite to original expert content (the only mode that builds quality), or (b) if that capacity doesn't exist yet, **`noindex,follow` the un-rewritten dosing/translation pages** so they stay available to members without exposing the domain to scaled-content + YMYL risk. I recommend (b) as the holding pattern and (a) as the real program.

---

## 6. The translation decision I need from you (§4.7)

Every visible page is in `translated-content.csv` with a proposed disposition. Pick the regime:

- **No permission from looksmax.org** → the compliant options are **REWRITE** (original Spanish content, keep URL) or **REMOVE**. Keeping them as-is is both a spam-policy and a copyright exposure.
- **You have written permission** → we switch to **LICENSE**: add attribution + a cross-site `rel=canonical` to the original, and the copyright issue resolves (the spam-policy "added value" question does not — canonicalised duplicates still aren't original value, so they should be `noindex`).

I did **not** default these to "lightly reword and keep." That would be exactly the evasion the rubric warns against, and it neither fixes copyright nor adds value.

---

## 7. Deliverables in this folder

`inventory.csv` · `actions.csv` · `redirect-map.csv` · `rewrite-queue.csv` · `urgent-removals.csv` · `translated-content.csv` · `technical-fixes.md` · `rollback.md` · `changelog.csv` (header only — Phase 1 made no writes).

**Stop point.** Nothing is executed. Approve the plan (and answer §6) and I'll run Phase 2 in the stated order, batched, logged to `changelog.csv`, each batch its own git commit.
