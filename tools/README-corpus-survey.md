# corpus-survey.mjs

Whole-corpus census of the scraped looksmax.org (XenForo) post HTML, and the
work order it implies for the Flarum side. Emits
`reports/corpus-survey.json` (raw counts) and `reports/CORPUS-SURVEY.md`
(the readable report). Re-run it after converter changes to show movement — the
JSON keeps the same shape, so the numbers are directly comparable.

## Running it

Node 22 only, no dependencies (`node:sqlite` is built in). The database lives on
the dedi, so run it there:

```sh
ssh osprey
node --no-warnings tools/corpus-survey.mjs \
  --db /work/lmx/scraper/looksmax.db \
  --out reports/ --workers 24
```

The tool needs the two converter files to resolve its coverage column, at
`<repo>/extensions/looksmax-import/src/HtmlToBbcode.php` and
`<repo>/extensions/looksmax-format/src/Configure.php`, or `--ext <dir>`.

| flag | meaning |
|---|---|
| `--db PATH` | sqlite file (required) |
| `--out DIR` | output directory (default `<repo>/reports`) |
| `--workers N` | forked shards (default `min(16, cpus)`) |
| `--limit N` | stop after N posts **per shard** — smoke run; the report is stamped PARTIAL |
| `--sample N` | target size of the §9 lossless sample (default 50,000) |
| `--ext DIR` | extensions directory to read the converter from |
| `--json-only` | skip the markdown render |

Smoke run, a few seconds:

```sh
node tools/corpus-survey.mjs --db /work/lmx/scraper/looksmax.db --out /tmp/smoke --limit 2000
```

The checked-in report is a full pass — 417,463 posts, 7.0s wall clock on 24
shards of a 32-core box.

## What it does

- **Read-only, always.** `file:…?mode=ro` + `PRAGMA query_only=1`. The scraper is
  still writing to this database; nothing here writes, VACUUMs or takes a lock.
- **Whole corpus, streamed.** Rows are iterated, never loaded. The only sampled
  figure in the output is §9, and it is labelled with its sample size and
  sampling rule everywhere it appears.
- **Prior-free extraction.** Every element name, class token, `data-*` attribute
  and literal `[bbcode]` is discovered from the corpus, not from a list of tags
  someone expected. The whole vocabulary turns out to be ~200 tokens, and §8.1
  classifies every single one, so there is no unexamined tail.
- **Distinct threads next to every count.** 13k blocks in 200 threads and 13k in
  13k threads are different problems.
- **Parallel by thread range, not post-id range.** A thread must not straddle
  two shards or the distinct-thread counts double-count at the seam. The first
  shard also takes the NULL-`thread_id` orphans and the last shard's upper bound
  is open, so rows the live scraper inserts mid-run are still scanned.

## Sections it emits

1. HTML construct census — elements, class tokens, `data-*`, `element[attr]` pairs
2. Attribute forms for quotes / spoilers / images / links, plus link + image host tables
3. Nesting depth for quotes and spoilers (including mixed) and quote body length percentiles
4. Literal BBCode surviving in the stored html, with real examples
5. Malformed cases with post ids
6. XenForo constructs with no Flarum equivalent, incl. dangling quote backlinks, reactions, prefixes, sprite indices
7. Language mix (heuristic — the method and its limits are stated in the report)
8. Converter coverage, resolved against the PHP source at run time; §8.1 classifies every token
9. Lossless-conversion measurement over a sample spread across the whole id range

## The coverage column is a static read

Section 8 resolves each entry's marker against the real PHP file when it runs,
so a cited `file:line` cannot go stale and a deleted branch shows up as
`marker NOT FOUND` rather than staying green. It proves a branch **exists**. It
is not a test of the live site and makes no claim that anything renders
correctly — the report says so in its own header.
