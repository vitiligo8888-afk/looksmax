#!/usr/bin/env python3
"""
Build the compact legacy sidecar the backfill reads.

The scrape lives in a 2.6 GB SQLite file at /work/lmx/scraper/looksmax.db, which
is outside the Flarum container's mounts and far larger than anything that
should be dragged into one. This pulls out the ~6 MB of it that encodes standing
and writes it into the extension's data/ directory, which IS mounted, so the
console command can open it read-only.

Read-only on the source, always: the scraper is live and owned by another lane.

    python3 bin/extract-legacy.py \
        --src /work/lmx/scraper/looksmax.db \
        --out /work/flarum/extensions/looksmax-ranks/data/legacy.sqlite
"""
import argparse
import os
import sqlite3
import sys

ap = argparse.ArgumentParser()
ap.add_argument("--src", default="/work/lmx/scraper/looksmax.db")
ap.add_argument("--out", default="/work/flarum/extensions/looksmax-ranks/data/legacy.sqlite")
args = ap.parse_args()

if not os.path.exists(args.src):
    sys.exit(f"source not found: {args.src}")

os.makedirs(os.path.dirname(args.out), exist_ok=True)
if os.path.exists(args.out):
    os.unlink(args.out)

src = sqlite3.connect(f"file:{args.src}?mode=ro", uri=True)
out = sqlite3.connect(args.out)

out.executescript("""
CREATE TABLE legacy_users (
    id INTEGER PRIMARY KEY,
    title TEXT, banners TEXT, style_class TEXT,
    post_count INTEGER, reputation INTEGER, threads INTEGER,
    joined TEXT
);
CREATE TABLE legacy_post_scores (
    thread_id INTEGER, position INTEGER, author_id INTEGER, score INTEGER,
    PRIMARY KEY (thread_id, position)
);
CREATE TABLE legacy_thread_counts (
    thread_id INTEGER PRIMARY KEY, posts INTEGER
);
""")

# threads started, per author — the source users table does not carry it
threads = dict(src.execute("SELECT author_id, COUNT(*) FROM threads GROUP BY author_id"))
print(f"thread authors: {len(threads)}")

rows = src.execute("""
    SELECT id, title, banners, style_class, post_count, reputation, joined FROM users
""")
n = 0
batch = []
for r in rows:
    batch.append((r[0], r[1], r[2], r[3], r[4] or 0, r[5] or 0, threads.get(r[0], 0), r[6]))
    if len(batch) >= 5000:
        out.executemany("INSERT INTO legacy_users VALUES (?,?,?,?,?,?,?,?)", batch)
        n += len(batch)
        batch = []
if batch:
    out.executemany("INSERT INTO legacy_users VALUES (?,?,?,?,?,?,?,?)", batch)
    n += len(batch)
print(f"legacy_users: {n}")

# per-post reaction scores, keyed the way the backfill needs to join them:
# (source thread id, position within the thread) -> author + score
ps = src.execute("""
    SELECT thread_id, position, author_id, COALESCE(reaction_score, 0)
    FROM posts WHERE thread_id IS NOT NULL
""")
n = 0
batch = []
for r in ps:
    batch.append(tuple(r))
    if len(batch) >= 20000:
        out.executemany("INSERT OR REPLACE INTO legacy_post_scores VALUES (?,?,?,?)", batch)
        n += len(batch)
        batch = []
if batch:
    out.executemany("INSERT OR REPLACE INTO legacy_post_scores VALUES (?,?,?,?)", batch)
    n += len(batch)
print(f"legacy_post_scores: {n}")

# how many posts the SOURCE has for each thread. The backfill refuses to map
# scores onto a thread whose imported post count disagrees, because a partial
# import would silently attribute someone else's score to the wrong author.
out.execute("""
    INSERT INTO legacy_thread_counts
    SELECT thread_id, COUNT(*) FROM legacy_post_scores GROUP BY thread_id
""")
print("thread counts:", out.execute("SELECT COUNT(*) FROM legacy_thread_counts").fetchone()[0])

out.execute("CREATE INDEX idx_scores_thread ON legacy_post_scores(thread_id)")
out.commit()
out.close()
print(f"wrote {args.out} ({os.path.getsize(args.out) / 1e6:.1f} MB)")
