#!/usr/bin/env python3
"""
Does search actually work in Russian, Turkish, Spanish and German on THIS corpus?

Not a synthetic test. Every query below is derived from a real document that is
really in the index: the probe pulls a document, takes a word out of it, asks
the engine for that word, and checks whether the document it came from comes
back. A query built from a real post that cannot retrieve that post is a defect
by construction, with no judgement call about what "should" match.

Four classes of check, because they fail for different reasons:

  roundtrip   — take word N of a real document, query it, expect that document.
                Measures raw retrievability per language.
  pair        — two spellings that a reader considers the same word
                (ё/е, ß/ss, İ/i, ñ/n). Meilisearch's normalizer handles some of
                these and provably does not handle others; this says which.
  position    — the same term as the last word of a query vs. not-last.
                Meilisearch expands a prefix ONLY on the final word, so this is
                where multi-word queries quietly lose recall.
  phrase      — a real multi-word span lifted verbatim out of a real document.
                This is what a person pasting a half-remembered sentence does.

Everything reports the query, the expectation, the hit ids and a verdict, so a
failure names itself rather than needing to be reproduced by hand.
"""

import argparse
import json
import random
import re
import sqlite3
import sys
import urllib.error
import urllib.request

WORD = re.compile(r"[^\W\d_]{4,}", re.UNICODE)


class Meili:
    def __init__(self, host, key):
        self.host, self.key = host.rstrip("/"), key

    def search(self, uid, body):
        req = urllib.request.Request(
            f"{self.host}/indexes/{uid}/search",
            data=json.dumps(body).encode(),
            method="POST",
            headers={"Authorization": f"Bearer {self.key}", "Content-Type": "application/json"},
        )
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                return json.loads(r.read())
        except urllib.error.HTTPError as e:
            raise RuntimeError(f"HTTP {e.code}: {e.read()[:400].decode(errors='replace')}") from None


def ids(res):
    return [h["id"] for h in res.get("hits", [])]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default="http://127.0.0.1:7703")
    ap.add_argument("--key", required=True)
    ap.add_argument("--index", default="lmx_posts")
    ap.add_argument("--db", default="/work/lmx/scraper/looksmax.db")
    ap.add_argument("--per-lang", type=int, default=25)
    ap.add_argument("--out", default="/work/search-scale/multilingual.json")
    a = ap.parse_args()

    m = Meili(a.host, a.key)
    random.seed(20260813)
    report = {"index": a.index, "roundtrip": {}, "pairs": [], "position": [], "phrase": {}}

    # ---------------------------------------------------------------- roundtrip
    #
    # Sampled straight out of the index rather than the database, so the document
    # is guaranteed present and the test cannot fail for the uninteresting reason
    # that it was never indexed.
    for lang in ["ru", "tr", "es", "de", "fr", "en"]:
        got = m.search(a.index, {
            "q": "", "filter": f'lang = "{lang}"', "limit": a.per_lang * 3,
            "attributesToRetrieve": ["id", "content", "lang", "discussion_id", "number"],
        })
        docs = [h for h in got.get("hits", []) if len(h.get("content", "")) > 120]
        random.shuffle(docs)
        docs = docs[: a.per_lang]

        rows, ok = [], 0
        for d in docs:
            words = [w for w in WORD.findall(d["content"]) if len(w) >= 5]
            if len(words) < 3:
                continue
            # A word from the MIDDLE of the document, not the first: the first
            # word is the easiest possible case and would flatter the result.
            w = words[len(words) // 2]
            # Filter to the source document. Without this the test measures
            # RANKING, not retrievability: a common English word matches
            # millions of posts and the one it was lifted from is not in the top
            # 40, which reads as a recall failure and is nothing of the kind.
            # (Measured: the unfiltered form reported en 1/25 and ru 13/25 — an
            # artefact of term frequency, not a real difference between the
            # languages.) With the filter, a miss means the engine genuinely
            # cannot match that word against the document it came from.
            # `id` is not a filterable attribute (and making it one would force
            # a full reindex), so the document is pinned with the pair that
            # uniquely identifies a post: its thread and its position in it.
            pin = f'discussion_id = {d.get("discussion_id", 0)} AND number = {d.get("number", 0)}'
            res = m.search(a.index, {
                "q": w, "limit": 5, "filter": pin,
                "attributesToRetrieve": ["id"],
            })
            hit = d["id"] in ids(res)
            # Kept alongside, so ranking is visible as its own number rather
            # than being silently folded into recall.
            unf = m.search(a.index, {"q": w, "limit": 20, "attributesToRetrieve": ["id"]})
            ok += hit
            rows.append({"doc": d["id"], "word": w, "found": hit,
                         "ranked_top20": d["id"] in ids(unf),
                         "n": unf.get("estimatedTotalHits", 0)})
        report["roundtrip"][lang] = {
            "tested": len(rows), "recalled": ok,
            "rate": round(ok / max(1, len(rows)), 3),
            "ranked_top20": sum(1 for r in rows if r["ranked_top20"]),
            "misses": [r for r in rows if not r["found"]][:8],
        }
        print(f"roundtrip {lang}: {ok}/{len(rows)}", flush=True)

    # -------------------------------------------------------------------- pairs
    #
    # Each entry: (label, spelling A, spelling B). The engine passes if a query
    # for A retrieves documents containing B and vice versa. These are the
    # specific normalizer gaps charabia is known to have, tested against real
    # corpus text rather than against a two-document fixture.
    PAIRS = [
        ("ru-yo", "ещё", "еще"), ("ru-yo2", "её", "ее"), ("ru-yo3", "всё", "все"),
        ("de-sz", "straße", "strasse"), ("de-sz2", "größe", "grosse"),
        ("de-umlaut", "schön", "schon"),
        ("tr-dotted", "ilişki", "iliski"), ("tr-dotless", "ışık", "isik"),
        ("es-tilde", "niño", "nino"), ("es-accent", "nariz", "narìz"),
        ("fr-cedilla", "français", "francais"),
    ]
    for label, x, y in PAIRS:
        rx = m.search(a.index, {"q": x, "limit": 5, "attributesToRetrieve": ["id"]})
        ry = m.search(a.index, {"q": y, "limit": 5, "attributesToRetrieve": ["id"]})
        ix, iy = set(ids(rx)), set(ids(ry))
        report["pairs"].append({
            "case": label, "a": x, "b": y,
            "hits_a": rx.get("estimatedTotalHits", 0),
            "hits_b": ry.get("estimatedTotalHits", 0),
            # Equivalent means the two spellings reach the same documents. If the
            # result sets are disjoint, they are different words to the engine.
            "overlap": len(ix & iy),
            "equivalent": bool(ix) and bool(iy) and len(ix & iy) > 0,
        })
        print(f"pair {label}: a={rx.get('estimatedTotalHits',0)} b={ry.get('estimatedTotalHits',0)} "
              f"overlap={len(ix & iy)}", flush=True)

    # ----------------------------------------------------------------- position
    #
    # Prefix expansion applies to the LAST query word only. So the same two terms
    # in the other order can return nothing. Measured on real pairs.
    for lang in ["ru", "tr", "de", "es"]:
        got = m.search(a.index, {
            "q": "", "filter": f'lang = "{lang}"', "limit": 40,
            "attributesToRetrieve": ["id", "content"],
        })
        for d in got.get("hits", [])[:6]:
            words = [w for w in WORD.findall(d.get("content", "")) if len(w) >= 7]
            if len(words) < 2:
                continue
            w1, w2 = words[0], words[1]
            stem = w1[: max(4, len(w1) - 3)]           # a deliberate partial word
            last = m.search(a.index, {"q": f"{w2} {stem}", "limit": 20, "attributesToRetrieve": ["id"]})
            first = m.search(a.index, {"q": f"{stem} {w2}", "limit": 20, "attributesToRetrieve": ["id"]})
            report["position"].append({
                "lang": lang, "doc": d["id"], "stem": stem, "other": w2,
                "stem_last_hits": last.get("estimatedTotalHits", 0),
                "stem_first_hits": first.get("estimatedTotalHits", 0),
                "stem_last_found": d["id"] in ids(last),
                "stem_first_found": d["id"] in ids(first),
            })

    # ------------------------------------------------------------------- phrase
    for lang in ["ru", "tr", "es", "de", "en"]:
        got = m.search(a.index, {
            "q": "", "filter": f'lang = "{lang}"', "limit": 30,
            "attributesToRetrieve": ["id", "content"],
        })
        rows, ok = [], 0
        for d in got.get("hits", [])[:12]:
            words = WORD.findall(d.get("content", ""))
            if len(words) < 8:
                continue
            span = " ".join(words[2:6])
            res = m.search(a.index, {"q": span, "limit": 20, "attributesToRetrieve": ["id"]})
            hit = d["id"] in ids(res)
            ok += hit
            rows.append({"doc": d["id"], "phrase": span, "found": hit})
        report["phrase"][lang] = {"tested": len(rows), "recalled": ok,
                                  "misses": [r for r in rows if not r["found"]][:5]}
        print(f"phrase {lang}: {ok}/{len(rows)}", flush=True)

    with open(a.out, "w") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)
    print(f"\nwrote {a.out}")


if __name__ == "__main__":
    main()
