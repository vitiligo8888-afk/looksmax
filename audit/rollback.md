# Rollback procedures (per action class)

Phase 1 made **no writes**, so nothing needs rolling back yet. This is the reversal plan for Phase 2. Every action class is reversible; the full-DB backup is the backstop of last resort.

## 0. Backstop — full restore
- Dump: `/root/seo-backups/flarum-2026-08-24T21-51-42Z.sql.gz` (md5 `dd9d5e537dd9a29402307969a0f98b76`), restore-verified.
- Restore: `gunzip -c <dump> | docker exec -i flarum-db sh -lc 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" flarum'`
- Use only for a catastrophic mistake; the per-class reversals below are surgical and preferred.

## 1. REMOVE_URGENT (soft-hide + 301)
- **How it's applied:** set `discussions.hidden_at` to a per-batch sentinel timestamp; the 301 lives in the redirect layer (see §4), not the DB.
- **Reverse:** `UPDATE discussions SET hidden_at=NULL, hidden_user_id=NULL WHERE hidden_at='<batch-sentinel>';` then drop the redirect rows for those URLs.
- Each batch uses a **unique sentinel timestamp** recorded in `changelog.csv`, so any single batch reverses independently. (Pattern already proven on this site: the prior purge is reversible via its own `2026-08-19 …` sentinels.)
- The content is only hidden, never deleted, so no post/attachment data is lost.

## 2. CONSOLIDATE (merge + 301)
- **How it's applied:** losing thread soft-hidden (sentinel); 301 loser→canonical in the redirect map; any content merged into the canonical is an *append* to the canonical's first post, tagged with an HTML comment `<!-- merged-from:/d/<id> -->`.
- **Reverse:** un-hide the loser (as §1), remove the redirect row, and delete the `merged-from` block from the canonical (locate by the comment marker).
- `redirect-map.csv` is the source of truth for what merged where.

## 3. NOINDEX
- **How it's applied:** per-discussion flag consumed by the Head/meta layer to emit `<meta name="robots" content="noindex,follow">` and to drop the URL from the sitemap. No content change.
- **Reverse:** clear the flag; the page re-enters the sitemap on next regeneration. Fully reversible, zero data risk.

## 4. REDIRECT / redirect layer
- Redirects are data rows (source→dest, 301), not code. Reverse = delete the row. No chains permitted (validated in `redirect-map.csv`); destinations must return 200 at apply time.
- **Never** 301 to homepage (soft-404); all destinations are section hubs or canonical twins.

## 5. REWRITE
- Not destructive to the URL. The original translated body is preserved in `lmx_translation_backup` (already exists) **and** re-snapshotted to `seo_rewrite_backup(discussion_id, old_content, ts)` before each rewrite.
- **Reverse:** restore `old_content` from the snapshot table.

## 6. Sitemap / robots
- Sitemap is regenerated from visible+indexable state each batch; reverting the underlying flags (above) and regenerating restores the previous sitemap. Keep the prior `sitemap.xml` copied to `backups/<ts>/` before each regeneration.

## Logging
- Every write appends to `audit/changelog.csv`: `url, old_status, action, destination, timestamp(=batch sentinel where applicable), reason_code`. That file + this document are sufficient to reverse any batch without the full restore.
