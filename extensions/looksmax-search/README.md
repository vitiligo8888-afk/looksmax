# looksmax-search

Search and discovery for Looksmax.lat. Meilisearch engine, own API + UI.

## Rebuilding from nothing

osprey is ephemeral. Everything below is a command, not a snapshot — there is no
index state that cannot be regenerated from the database.

```bash
# 1. engine (container + volume). Key lives in /work/flarum/.env as MEILI_MASTER_KEY
docker start flarum-meili

# 2. tell the extension where it is (once, survives in the settings table)
#    looksmax-search.host  = http://flarum-meili:7700
#    looksmax-search.key   = <MEILI_MASTER_KEY>

# 3. create indexes + push settings, index nothing
docker exec -w /flarum/app flarum-app php flarum search:index --settings-only

# 4. full build (resumable: --from=<id>, scoped: --only=discussions,posts)
docker exec -w /flarum/app flarum-app php flarum search:index --fresh

# 5. prove it
docker exec -w /flarum/app flarum-app php flarum search:status --diff
```

`search:status` exits non-zero when the engine is unreachable, an index is below
`--min-coverage` percent of its table, the outbox is not draining, an engine task
failed, or the live index settings have drifted from `Meili\IndexSettings`. It is
safe to call from a health check.

Incremental updates need no manual step: model events write to
`search_index_queue` and `search:sync` drains it every minute (see `extend.php`).

## Settings are code

`src/Meili/IndexSettings.php` is the single source of truth. Eight of these
settings force a FULL reindex when changed (`searchableAttributes`,
`filterableAttributes`, `sortableAttributes`, `proximityPrecision`,
`dictionary`, `separatorTokens`, `stopWords`, embedders), so at 28M documents
they are decided once, before the backfill. `search:status --diff` is what
catches them silently drifting afterwards.

## Tools

| path | what it does |
|---|---|
| `tools/scale-index.py` | bulk-indexes the acquisition SQLite corpus straight into Meilisearch, for scale measurement. Read-only on the crawler's DB. |
| `tools/multilingual-probe.py` | derives queries from real indexed documents per language and checks they retrieve the document they came from |
| `tools/query-bench.py` | latency percentiles over a weighted realistic query mix, engine time vs full HTTP round trip |
| `e2e/shoot.mjs` | real Chromium over CDP; screenshots every search surface and fails on console errors / failed requests |
| `e2e/diag.mjs` | one-page browser diagnostic (what the DOM and URL actually are) |

## Measured, on this corpus

- **Multilingual:** Meilisearch does NOT fold `ё`→`е` or `ß`→`ss`. Both confirmed
  at corpus scale with zero overlap between spellings. `Text::fold` fixes both,
  applied to the searchable copy at index time and to the query — the two must
  stay in sync or the fix is worse than nothing. German compound splitting needs
  a single-locale field (`*_de`); it does not fire under a six-locale rule.
- **Deep pagination is the one cliff.** offset 0 ≈ 50 ms, offset 500 ≈ 350 ms.
- **Index size** extrapolates to ~150 GB at 28.4M posts from a measured
  5.34 KB/document on 408k real posts.
