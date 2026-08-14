#!/usr/bin/env python3
"""
Pull the reaction slice out of the live scrape database into a small snapshot
the app container can read.

Why a slice and not the whole file: the scrape DB is 2.9 GB and is being
written to continuously by the running import. The app container has no mount
for it (docker inspect flarum-app shows exactly two mounts: the app volume and
/work/flarum/extensions), and copying 2.9 GB into a container to read three
columns is the wrong shape. The reaction data is post_reactions plus two
columns of posts, which comes out around 40 MB.

Read-only and non-destructive by construction:
  * opened with mode=ro through the URI, plus PRAGMA query_only
  * nothing under /work/lmx is written, created or locked for writing

Run on the host (osprey), NOT in the container:
  python3 extract-legacy.py --src /work/lmx/scraper/looksmax.db --out /work/reactions-assets/legacy.db
"""
from __future__ import annotations

import argparse
import os
import sqlite3
import time


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", default="/work/lmx/scraper/looksmax.db")
    ap.add_argument("--out", required=True)
    a = ap.parse_args()

    if os.path.exists(a.out):
        os.remove(a.out)

    src = sqlite3.connect(f"file:{a.src}?mode=ro", uri=True, timeout=60)
    src.execute("PRAGMA query_only = 1")

    dst = sqlite3.connect(a.out)
    dst.executescript(
        """
        PRAGMA journal_mode = OFF;
        PRAGMA synchronous = OFF;
        CREATE TABLE post_reactions (post_id INTEGER, reaction_id INTEGER, name TEXT,
                                     PRIMARY KEY (post_id, reaction_id));
        CREATE TABLE posts (id INTEGER PRIMARY KEY, reaction_score INTEGER,
                            reaction_summary TEXT);
        """
    )

    t0 = time.time()

    n = 0
    cur = src.execute("SELECT post_id, reaction_id, name FROM post_reactions")
    while True:
        rows = cur.fetchmany(50000)
        if not rows:
            break
        dst.executemany("INSERT OR IGNORE INTO post_reactions VALUES (?,?,?)", rows)
        n += len(rows)
        print(f"\r  post_reactions {n}", end="", flush=True)
    print()

    m = 0
    cur = src.execute(
        "SELECT id, reaction_score, reaction_summary FROM posts WHERE reaction_score > 0"
    )
    while True:
        rows = cur.fetchmany(50000)
        if not rows:
            break
        dst.executemany("INSERT OR IGNORE INTO posts VALUES (?,?,?)", rows)
        m += len(rows)
        print(f"\r  posts {m}", end="", flush=True)
    print()

    dst.commit()
    dst.execute("CREATE INDEX idx_pr ON post_reactions(post_id)")
    dst.commit()
    dst.close()
    src.close()

    size = os.path.getsize(a.out)
    print(f"  {a.out}  {size/1e6:.1f} MB  "
          f"{n} reaction rows, {m} scored posts, {time.time()-t0:.1f}s")

    # Distribution, so the numbers this feeds the backfill are visible here too
    # rather than only inside the container.
    d = sqlite3.connect(f"file:{a.out}?mode=ro", uri=True)
    print("\n  reaction types in the slice:")
    for rid, name, c in d.execute(
        "SELECT reaction_id, name, COUNT(*) FROM post_reactions "
        "GROUP BY reaction_id, name ORDER BY 3 DESC"
    ):
        print(f"    id={rid:<3} {name:<10} {c}")
    print("\n  posts with exactly one reaction type (the only derivable split): "
          f"{d.execute('SELECT COUNT(*) FROM (SELECT post_id FROM post_reactions GROUP BY post_id HAVING COUNT(*)=1)').fetchone()[0]}")


if __name__ == "__main__":
    main()
