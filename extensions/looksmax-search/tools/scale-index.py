#!/usr/bin/env python3
"""
Bulk-index the acquisition corpus into Meilisearch, directly from the scraper's
SQLite database.

Why this exists as a separate program from the PHP `search:index` command:

  * The PHP command indexes the *Flarum* database, which is the source of truth
    for the live forum and is what the incremental pipeline keeps in sync. It is
    the right tool and the wrong scale — today it has 6k posts to work with.
  * The acquisition DB already holds 2.2M fully-enumerated threads and ~400k
    fetched posts of REAL, multilingual forum text. That is the only corpus on
    this box big enough to measure anything at scale, and it exists months
    before the importer will have moved it into Flarum.

So: same index settings (read from JSON dumped out of IndexSettings.php, never
hand-copied, so the two cannot drift), same document shape as DocumentBuilder
produces, different source table. What is measured here transfers.

Read posture is strictly read-only: `mode=ro` plus `PRAGMA query_only`. The
crawler is writing to this database continuously and must not be disturbed.
NDJSON rather than a JSON array, because a 200 MB array has to be fully
buffered and parsed on both ends; NDJSON streams.
"""

import argparse
import json
import multiprocessing as mp
import os
import random
import re
import sqlite3
import sys
import time
import urllib.error
import urllib.request

# --------------------------------------------------------------------- config

DB = "/work/lmx/scraper/looksmax.db"

# Byte-bounded, not row-bounded. Forum posts have a pathological length
# distribution -- most are two lines, some are 40 KB guides -- so a fixed row
# count is 300 KB most of the time and 12 MB when it hits a run of guides.
BATCH_BYTES = 12_000_000
BATCH_MAX_DOCS = 20_000


def connect(db=DB):
    c = sqlite3.connect(f"file:{db}?mode=ro", uri=True, timeout=30)
    c.execute("PRAGMA query_only=1")
    c.execute("PRAGMA busy_timeout=30000")
    c.row_factory = sqlite3.Row
    return c


# ------------------------------------------------------------------ meili i/o


class Meili:
    def __init__(self, host, key):
        self.host = host.rstrip("/")
        self.key = key

    def _req(self, method, path, body=None, ctype="application/json", raw=False):
        data = None
        if body is not None:
            data = body if raw else json.dumps(body).encode()
        req = urllib.request.Request(
            f"{self.host}{path}", data=data, method=method,
            headers={"Authorization": f"Bearer {self.key}", "Content-Type": ctype},
        )
        try:
            with urllib.request.urlopen(req, timeout=1200) as r:
                b = r.read()
                return json.loads(b) if b else {}
        except urllib.error.HTTPError as e:
            # A bare status code is never a verdict. Carry the engine's own
            # structured error out, because `invalid_document_id` and
            # `payload_too_large` need completely different fixes.
            raise RuntimeError(
                f"HTTP {e.code} {method} {path}: {e.read()[:800].decode(errors='replace')}"
            ) from None

    def create_index(self, uid, pk="id"):
        try:
            return self._req("POST", "/indexes", {"uid": uid, "primaryKey": pk})
        except RuntimeError as e:
            if "index_already_exists" in str(e):
                return {}
            raise

    def settings(self, uid, s):
        return self._req("PATCH", f"/indexes/{uid}/settings", s)

    def add_ndjson(self, uid, payload: bytes):
        return self._req("POST", f"/indexes/{uid}/documents", payload,
                         ctype="application/x-ndjson", raw=True)

    def task(self, uid):
        return self._req("GET", f"/tasks/{uid}")

    def stats(self, uid=None):
        return self._req("GET", f"/indexes/{uid}/stats" if uid else "/stats")

    def wait(self, task_uid, timeout=7200):
        """A 202 is not an index. Turn accepted into succeeded-or-raised."""
        deadline = time.time() + timeout
        while time.time() < deadline:
            t = self.task(task_uid)
            if t.get("status") in ("succeeded", "failed", "canceled"):
                if t["status"] != "succeeded":
                    raise RuntimeError(f"task {task_uid} {t['status']}: {t.get('error')}")
                return t
            time.sleep(0.5)
        raise RuntimeError(f"task {task_uid} did not settle in {timeout}s")

    def wait_queue(self, timeout=14400, label=""):
        """Block until the engine's whole enqueued+processing backlog drains."""
        deadline = time.time() + timeout
        while time.time() < deadline:
            r = self._req("GET", "/tasks?statuses=enqueued,processing&limit=1")
            if r.get("total", 0) == 0:
                return True
            time.sleep(2)
        raise RuntimeError(f"queue did not drain in {timeout}s {label}")


# ------------------------------------------------------- language guess (port)
#
# A direct port of Local\Search\Meili\Text::guessLang so the `lang` facet in a
# scale index means exactly what it means in the production index. Script
# detection first (that is a certainty), then a small stop-word vote for the
# Latin-script languages where the alphabet alone cannot decide.

CYR = re.compile(r"[Ѐ-ӿ]")
TR_CHARS = re.compile(r"[ğĞşŞıİçÇöÖüÜ]")
TR_WORDS = re.compile(r"\b(bir|ve|için|çok|daha|ama|gibi|ile|bu|şu)\b", re.I)
VOTES = [
    ("es", re.compile(r"\b(que|para|como|pero|porque|más|muy|todo|hacer|tiene|está|nariz|cara)\b", re.I)),
    ("de", re.compile(r"\b(und|nicht|auch|aber|schon|noch|sehr|eine|einen|dass|über|können)\b", re.I)),
    ("fr", re.compile(r"\b(que|pour|avec|dans|mais|plus|très|être|cette|comme|nez|visage)\b", re.I)),
    ("tr", re.compile(r"\b(bir|ve|için|çok|daha|ama|gibi|ile|değil|olarak)\b", re.I)),
    ("en", re.compile(r"\b(the|and|you|that|have|this|with|your|about|would|just|like)\b", re.I)),
]


FOLD = str.maketrans({"ё": "е", "Ё": "Е"})


def fold(s: str) -> str:
    """Port of Local\\Search\\Meili\\Text::fold. Must stay byte-identical in
    behaviour to the PHP, because the production query path folds with the PHP
    one and any divergence shows up as a query that matches nothing."""
    return s.translate(FOLD).replace("ß", "ss").replace("\u1e9e", "SS")


def guess_lang(text):
    if not text:
        return "und"
    s = text[:400]
    if CYR.search(s):
        return "ru"
    if TR_CHARS.search(s) and TR_WORDS.search(s):
        return "tr"
    best, best_n = "en", 0
    for lang, rx in VOTES:
        n = len(rx.findall(s))
        if n > best_n:
            best_n, best = n, lang
    return best if best_n >= 2 else "und"


def rank_score(reactions, comments, views, last_activity, now=None):
    """Port of DocumentBuilder::rankScore. Logs because engagement is Zipfian."""
    import math
    now = now or time.time()
    age_days = max(0.0, (now - (last_activity or 0)) / 86400.0)
    recency = math.exp(-age_days / 365.0)
    return round(
        2.2 * math.log1p(max(0, reactions or 0))
        + 1.4 * math.log1p(max(0, comments or 0))
        + 0.6 * math.log1p(max(0, views or 0))
        + 3.0 * recency,
        4,
    )


def slugify(s, ident):
    s = re.sub(r"[^a-z0-9]+", "-", (s or "").lower()).strip("-")
    return (s[:60] or f"t-{ident}")


# ---------------------------------------------------------- document builders


def build_thread_doc(r, forums, now):
    forum = forums.get(r["forum_id"], {})
    fname = forum.get("title") or ""
    fslug = forum.get("slug") or ""
    prefix = (r["prefix"] or "").strip()
    title = r["title"] or ""
    replies = r["replies"] or 0
    views = r["views"] or 0
    react = r["first_post_reaction_score"] or 0
    created = r["created_ts"] or 0
    last = r["last_post_ts"] or created
    tag_names = [fname] if fname else []
    tag_slugs = [fslug] if fslug else []
    lang = guess_lang(title)
    return {
        "id": r["id"],
        "title": title,
        "slug": r["slug"] or slugify(title, r["id"]),
        "excerpt": "",
        "title_s": fold(title),
        "excerpt_s": "",
        "title_de": fold(title) if lang == "de" else None,
        "excerpt_de": None,
        "tag_ids": [r["forum_id"]] if r["forum_id"] else [],
        "tag_slugs": tag_slugs,
        "tag_names": tag_names,
        "tag_colors": [""],
        "primary_tag": fslug,
        "prefixes": [prefix] if prefix else [],
        "restricted_tag_ids": [],
        "author_id": r["author_id"] or 0,
        "author": r["author_name"] or "",
        "created_at": created,
        "last_post_at": last,
        "comment_count": replies,
        "participant_count": 0,
        "views": views,
        "reactions": react,
        "length": len(title),
        "is_sticky": bool(r["sticky"]),
        "is_locked": bool(r["locked"]),
        "is_private": False,
        "is_hidden": False,
        "is_approved": True,
        "is_guide": False,
        "has_best_answer": False,
        "lang": lang,
        "rank_score": rank_score(react, replies, views, last, now),
    }


def build_post_doc(r, now):
    content = (r["text"] or "").strip()
    if not content:
        return None
    react = r["reaction_score"] or 0
    created = r["posted_ts"] or 0
    lang = guess_lang(content)
    return {
        "id": r["id"],
        "discussion_id": r["thread_id"] or 0,
        "discussion_title": r["thread_title"] or "",
        "discussion_slug": r["thread_slug"] or "",
        "number": r["position"] or 0,
        "content": content,
        "content_s": fold(content),
        "discussion_title_s": fold(r["thread_title"] or ""),
        "content_de": fold(content) if lang == "de" else None,
        "author_id": r["author_id"] or 0,
        "author": r["author_name"] or "",
        "created_at": created,
        "reactions": react,
        "length": len(content),
        "tag_ids": [r["forum_id"]] if r["forum_id"] else [],
        "tag_slugs": [],
        "prefixes": [],
        "restricted_tag_ids": [],
        "is_hidden": False,
        "is_private": False,
        "is_approved": True,
        "is_first": bool(r["is_first"]),
        "lang": lang,
        "rank_score": rank_score(react, 0, 0, created, now),
    }


# ------------------------------------------------------------------- pipeline


def flush(m, uid, docs, stats):
    if not docs:
        return
    payload = b"\n".join(json.dumps(d, ensure_ascii=False).encode() for d in docs)
    t0 = time.time()
    res = m.add_ndjson(uid, payload)
    stats["push_s"] += time.time() - t0
    stats["bytes"] += len(payload)
    stats["docs"] += len(docs)
    stats["tasks"].append(res.get("taskUid"))


def worker(args):
    kind, uid, lo, hi, host, key, limit = args
    m = Meili(host, key)
    c = connect()
    now = time.time()
    stats = {"docs": 0, "bytes": 0, "push_s": 0.0, "tasks": []}

    if kind == "threads":
        forums = {r["id"]: dict(r) for r in c.execute("select id,title,slug from forums")}
        q = ("select id,forum_id,title,slug,prefix,author_id,author_name,created_ts,"
             "replies,views,sticky,locked,first_post_reaction_score,last_post_ts "
             "from threads where id > ? and id <= ? order by id")
        build = lambda r: build_thread_doc(r, forums, now)
    else:
        q = ("select p.id,p.thread_id,p.position,p.author_id,p.author_name,p.posted_ts,"
             "p.text,p.reaction_score,p.is_first,t.title as thread_title,t.slug as thread_slug,"
             "t.forum_id as forum_id "
             "from posts p left join threads t on t.id=p.thread_id "
             "where p.id > ? and p.id <= ? order by p.id")
        build = lambda r: build_post_doc(r, now)

    buf, buf_bytes = [], 0
    n_seen = 0
    for r in c.execute(q, (lo, hi)):
        d = build(r)
        if d is None:
            continue
        n_seen += 1
        approx = 400 + len(d.get("content", "")) + len(d.get("title", "")) + len(d.get("excerpt", ""))
        if buf and (buf_bytes + approx > BATCH_BYTES or len(buf) >= BATCH_MAX_DOCS):
            flush(m, uid, buf, stats)
            buf, buf_bytes = [], 0
        buf.append(d)
        buf_bytes += approx
        if limit and n_seen >= limit:
            break
    flush(m, uid, buf, stats)
    c.close()
    return stats


def ranges(lo, hi, n):
    """Split an id space into n contiguous ranges (exclusive lo, inclusive hi)."""
    step = max(1, (hi - lo) // n + 1)
    out, cur = [], lo
    while cur < hi:
        out.append((cur, min(cur + step, hi)))
        cur += step
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--host", default="http://127.0.0.1:7703")
    ap.add_argument("--key", default=os.environ.get("MEILI_KEY", ""))
    ap.add_argument("--prefix", default="lmx")
    ap.add_argument("--settings-dir", default="/work/search-scale/settings")
    ap.add_argument("--kind", choices=["threads", "posts", "both"], default="both")
    ap.add_argument("--workers", type=int, default=8)
    ap.add_argument("--limit", type=int, default=0, help="max docs per worker (for curve points)")
    ap.add_argument("--fresh", action="store_true", help="delete the indexes first")
    ap.add_argument("--report", default="/work/search-scale/index-report.json")
    a = ap.parse_args()

    m = Meili(a.host, a.key)
    c = connect()

    kinds = ["threads", "posts"] if a.kind == "both" else [a.kind]
    report = {"host": a.host, "started": time.time(), "runs": []}

    for kind in kinds:
        uid = f"{a.prefix}_" + ("discussions" if kind == "threads" else "posts")
        setname = "discussions" if kind == "threads" else "posts"

        if a.fresh:
            try:
                m._req("DELETE", f"/indexes/{uid}")
                m.wait_queue(600, uid)
            except RuntimeError:
                pass

        m.create_index(uid)
        with open(f"{a.settings_dir}/{setname}.json") as f:
            s = json.load(f)
        t = m.settings(uid, s)
        if t.get("taskUid") is not None:
            m.wait(t["taskUid"])
        print(f"[{uid}] index + settings applied", flush=True)

        tbl = "threads" if kind == "threads" else "posts"
        lo, hi, total = c.execute(f"select min(id),max(id),count(*) from {tbl}").fetchone()
        print(f"[{uid}] source {tbl}: {total} rows, id {lo}..{hi}", flush=True)

        parts = ranges((lo or 1) - 1, hi or 1, a.workers)
        jobs = [(kind, uid, p[0], p[1], a.host, a.key, a.limit) for p in parts]

        t0 = time.time()
        with mp.Pool(len(jobs)) as pool:
            results = pool.map(worker, jobs)
        read_s = time.time() - t0
        docs = sum(r["docs"] for r in results)
        nbytes = sum(r["bytes"] for r in results)
        print(f"[{uid}] pushed {docs} docs / {nbytes/1e6:.1f} MB in {read_s:.1f}s "
              f"({docs/max(read_s,0.001):.0f} docs/s accepted)", flush=True)

        # Accepted is not indexed. Drain the engine's queue and time that too --
        # this is the number that actually bounds a reindex.
        t1 = time.time()
        m.wait_queue(28800, uid)
        drain_s = time.time() - t1
        wall = time.time() - t0

        st = m.stats(uid)
        run = {
            "index": uid, "source_rows": total, "docs_pushed": docs,
            "payload_bytes": nbytes, "push_wall_s": round(read_s, 2),
            "drain_s": round(drain_s, 2), "total_wall_s": round(wall, 2),
            "docs_per_s_end_to_end": round(docs / max(wall, 0.001), 1),
            "index_stats": st,
        }
        try:
            run["global_stats"] = m.stats()
        except RuntimeError:
            pass
        report["runs"].append(run)
        print(json.dumps(run, indent=2)[:1500], flush=True)

    report["finished"] = time.time()
    with open(a.report, "w") as f:
        json.dump(report, f, indent=2)
    print(f"\nwrote {a.report}", flush=True)


if __name__ == "__main__":
    main()
