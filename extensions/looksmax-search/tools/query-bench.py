#!/usr/bin/env python3
"""
Query latency, measured two ways at once.

The engine's own `processingTimeMs` is the number people quote, and it is not
the number a reader experiences. The reader waits for: PHP boot, the query
parse, the engine round trip, the authoritative `whereVisibleTo` SQL gate, and
JSON serialisation. So this measures BOTH, side by side, against the real HTTP
endpoint — and reports percentiles rather than an average, because an average
hides exactly the tail that makes a search box feel broken.

The query mix is deliberately not a list of words that work. It is weighted the
way real forum demand is: mostly short and common, with a long tail of rare
terms, plus the cases that are known to be expensive (deep pagination, faceted
browse, multi-word, non-Latin script, typos, filtered).
"""

import argparse
import json
import statistics
import time
import urllib.error
import urllib.parse
import urllib.request

# (label, query, extra params) — weights come from repetition in MIX below.
QUERIES = [
    ("common",      "jaw",                      {}),
    ("common",      "skin",                     {}),
    ("common",      "hair",                     {}),
    ("mid",         "jawline",                  {}),
    ("mid",         "mewing",                   {}),
    ("mid",         "rhinoplasty",              {}),
    ("rare",        "genioplasty",              {}),
    ("rare",        "zygomatic implants",       {}),
    ("multiword",   "hair transplant results",  {}),
    ("multiword",   "how to fix recessed chin", {}),
    ("phrase",      '"bone smashing"',          {}),
    ("typo",        "jawlnie",                  {}),
    ("typo",        "minoxidal",                {}),
    ("cyrillic",    "лицо",                     {}),
    ("cyrillic",    "внешность",                {}),
    ("turkish",     "burun",                    {}),
    ("turkish",     "ilişki",                   {}),
    ("spanish",     "nariz",                    {}),
    ("german",      "gesicht",                  {}),
    ("operator",    "jaw tag:looksmaxing",      {}),
    ("operator",    "surgery by:admin",         {}),
    ("operator",    "jaw sort:top",             {}),
    ("posts-tab",   "skin",                     {"type": "posts"}),
    ("users-tab",   "admin",                    {"type": "users"}),
    ("deep-page",   "jaw",                      {"offset": 200}),
    ("deep-page",   "skin",                     {"offset": 500}),
    ("zero",        "qzxwvnothinghere",         {}),
]

# Repetition = weight. Short common queries dominate real traffic.
MIX = ["common"] * 5 + ["mid"] * 3 + ["multiword"] * 2 + ["rare", "phrase", "typo",
       "cyrillic", "turkish", "spanish", "german", "operator", "posts-tab",
       "users-tab", "deep-page", "zero"]


def pct(xs, p):
    if not xs:
        return None
    xs = sorted(xs)
    # Nearest-rank: with 30 samples an interpolated p99 is a fiction, and a
    # latency percentile that reports a value nobody actually observed is worse
    # than a coarse one that did happen.
    k = max(0, min(len(xs) - 1, int(round(p / 100 * len(xs) + 0.5)) - 1))
    return round(xs[k], 1)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8888")
    ap.add_argument("--runs", type=int, default=12)
    ap.add_argument("--warmup", type=int, default=2)
    ap.add_argument("--out", default="/work/flarum/lmx-query-bench.json")
    a = ap.parse_args()

    per_query = []
    for label, q, extra in QUERIES:
        params = {"q": q, "limit": 20}
        params.update(extra)
        url = a.base + "/api/looksmax/search?" + urllib.parse.urlencode(params)

        total_ms, engine_ms, hits, err = [], [], None, None
        for i in range(a.runs + a.warmup):
            t0 = time.perf_counter()
            try:
                with urllib.request.urlopen(url, timeout=60) as r:
                    body = json.loads(r.read())
            except urllib.error.HTTPError as e:
                err = f"HTTP {e.code}: {e.read()[:200].decode(errors='replace')}"
                break
            except Exception as e:
                err = str(e)
                break
            wall = (time.perf_counter() - t0) * 1000
            # Discard warmups: the first request pays for opcache, the DB
            # connection and Meilisearch's page cache, none of which a real user
            # pays on a warm forum.
            if i >= a.warmup:
                total_ms.append(wall)
                if body.get("engineMs") is not None:
                    engine_ms.append(float(body["engineMs"]))
            hits = body.get("estimatedTotalHits")
            got = len(body.get("results") or [])

        row = {
            "class": label, "q": q, "extra": extra, "hits": hits,
            "returned": got if not err else None, "error": err,
            "http_p50": pct(total_ms, 50), "http_p90": pct(total_ms, 90),
            "http_p95": pct(total_ms, 95), "http_p99": pct(total_ms, 99),
            "http_max": round(max(total_ms), 1) if total_ms else None,
            "engine_p50": pct(engine_ms, 50), "engine_p95": pct(engine_ms, 95),
        }
        per_query.append(row)
        print(f"{label:10s} {q[:26]:28s} hits={str(hits):>8s} "
              f"http p50={row['http_p50']} p95={row['http_p95']} max={row['http_max']} "
              f"engine p50={row['engine_p50']}"
              + (f"  ERROR {err}" if err else ""), flush=True)

    # Weighted aggregate over the mix, so the headline number reflects the
    # traffic shape rather than treating a deep-pagination query as being as
    # common as `jaw`.
    by_class = {}
    for r in per_query:
        by_class.setdefault(r["class"], []).extend(
            [r["http_p50"]] if r["http_p50"] is not None else []
        )
    weighted = []
    for c in MIX:
        weighted.extend(by_class.get(c, []))

    summary = {
        "queries": len(per_query),
        "runs_each": a.runs,
        "errors": [r for r in per_query if r["error"]],
        "weighted_http_p50": pct(weighted, 50),
        "weighted_http_p90": pct(weighted, 90),
        "weighted_http_p95": pct(weighted, 95),
        "weighted_http_p99": pct(weighted, 99),
        "engine_only_p50": pct([r["engine_p50"] for r in per_query if r["engine_p50"] is not None], 50),
        "engine_only_p95": pct([r["engine_p95"] for r in per_query if r["engine_p95"] is not None], 95),
        "worst": sorted(
            [r for r in per_query if r["http_p95"] is not None],
            key=lambda r: -r["http_p95"],
        )[:5],
    }
    print("\n=== weighted over a realistic mix (full HTTP round trip) ===")
    for k in ("weighted_http_p50", "weighted_http_p90", "weighted_http_p95", "weighted_http_p99"):
        print(f"  {k:22s} {summary[k]} ms")
    print(f"  engine only p50/p95    {summary['engine_only_p50']} / {summary['engine_only_p95']} ms")
    print("  slowest classes:")
    for r in summary["worst"]:
        print(f"    {r['class']:10s} {r['q'][:30]:32s} p95={r['http_p95']} ms")

    with open(a.out, "w") as f:
        json.dump({"summary": summary, "per_query": per_query}, f, indent=2, ensure_ascii=False)
    print(f"\nwrote {a.out}")


if __name__ == "__main__":
    main()
