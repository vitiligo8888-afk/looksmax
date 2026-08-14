#!/usr/bin/env python3
"""
Build the small sidecar the backfill command reads.

The crawl DB is 2.6 GB at /work/lmx/scraper/looksmax.db, lives outside the
Flarum container's mounts, and is owned by the crawler lane — which is still
writing to it. This pulls out the ~25 MB of it that describes accounts and the
reception their posts got, and writes it into this extension's data/ directory,
which IS on the ./extensions bind mount, so `php flarum userinfo:backfill` can
open it read-only from inside the container.

Read-only on the source. Always. The crawler is live.

    python3 bin/extract-profiles.py \
        --src /work/lmx/scraper/looksmax.db \
        --out /work/flarum/extensions/looksmax-userinfo/data/profiles.sqlite

What comes across, and why each one is defensible:

  src_users          the account: title, banners, usergroup class, badge,
                     join date, lifetime post count and lifetime reaction
                     score. All columns the crawler observed on the member card.
  src_threads_by     threads started per author. The source users table does not
                     carry it, so it is counted from the threads table.
  src_post_scores    (thread, position) -> author + reaction score. This is the
                     join that lets the backfill say "reactions on the posts that
                     are actually on OUR forum" instead of quoting a lifetime
                     total for content most of which was never imported.
  src_post_reactions which of the nine reaction types each scraped post drew.
                     Presence per type per post, which is what the crawler
                     recorded — NOT a per-type count, and the UI says so.
"""
import argparse
import json
import os
import sqlite3
import sys
import time

ap = argparse.ArgumentParser()
ap.add_argument("--src", default="/work/lmx/scraper/looksmax.db")
ap.add_argument("--out", default="/work/flarum/extensions/looksmax-userinfo/data/profiles.sqlite")
args = ap.parse_args()

if not os.path.exists(args.src):
    sys.exit(f"source not found: {args.src}")

os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
tmp = args.out + ".building"
for p in (tmp, tmp + "-journal", tmp + "-wal"):
    if os.path.exists(p):
        os.unlink(p)

src = sqlite3.connect(f"file:{args.src}?mode=ro", uri=True)
out = sqlite3.connect(tmp)
out.executescript("""
PRAGMA journal_mode = OFF;
PRAGMA synchronous = OFF;

CREATE TABLE src_users (
    id INTEGER PRIMARY KEY,
    title TEXT, banners TEXT, style_class TEXT, badge_id TEXT,
    joined TEXT, post_count INTEGER, reputation INTEGER, threads INTEGER
);
CREATE TABLE src_post_scores (
    thread_id INTEGER NOT NULL, position INTEGER NOT NULL,
    author_id INTEGER, score INTEGER,
    PRIMARY KEY (thread_id, position)
) WITHOUT ROWID;
CREATE TABLE src_post_reactions (
    thread_id INTEGER NOT NULL, position INTEGER NOT NULL, name TEXT NOT NULL,
    PRIMARY KEY (thread_id, position, name)
) WITHOUT ROWID;
CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT);
""")

t0 = time.time()

# --- threads started per author -------------------------------------------
threads = dict(src.execute("SELECT author_id, COUNT(*) FROM threads WHERE author_id IS NOT NULL GROUP BY author_id"))
print(f"thread authors: {len(threads)}")

# --- accounts ---------------------------------------------------------------
n = 0
batch = []
for r in src.execute("SELECT id, title, banners, style_class, badge_id, joined, post_count, reputation FROM users"):
    batch.append((r[0], r[1], r[2], r[3], r[4], r[5], r[6] or 0, r[7] or 0, threads.get(r[0], 0)))
    if len(batch) >= 20000:
        out.executemany("INSERT INTO src_users VALUES (?,?,?,?,?,?,?,?,?)", batch)
        n += len(batch)
        batch = []
if batch:
    out.executemany("INSERT INTO src_users VALUES (?,?,?,?,?,?,?,?,?)", batch)
    n += len(batch)
print(f"src_users: {n}")

# --- per-post reception -----------------------------------------------------
# Keyed on (thread_id, position) rather than the source post id, because the
# importer walks a thread's posts in position order and Flarum assigns
# post.number sequentially from 1. Position is therefore the only key the two
# sides share, and it is the key the backfill joins on.
posts = 0
batch = []
postid_to_key = {}
for r in src.execute("SELECT id, thread_id, position, author_id, reaction_score FROM posts WHERE thread_id IS NOT NULL AND position IS NOT NULL"):
    postid_to_key[r[0]] = (r[1], r[2])
    batch.append((r[1], r[2], r[3], r[4] or 0))
    if len(batch) >= 20000:
        out.executemany("INSERT OR REPLACE INTO src_post_scores VALUES (?,?,?,?)", batch)
        posts += len(batch)
        batch = []
if batch:
    out.executemany("INSERT OR REPLACE INTO src_post_scores VALUES (?,?,?,?)", batch)
    posts += len(batch)
print(f"src_post_scores: {posts}")

# --- reaction types ---------------------------------------------------------
rx = 0
batch = []
for r in src.execute("SELECT post_id, name FROM post_reactions WHERE name IS NOT NULL"):
    key = postid_to_key.get(r[0])
    if not key:
        continue
    batch.append((key[0], key[1], r[1]))
    if len(batch) >= 20000:
        out.executemany("INSERT OR REPLACE INTO src_post_reactions VALUES (?,?,?)", batch)
        rx += len(batch)
        batch = []
if batch:
    out.executemany("INSERT OR REPLACE INTO src_post_reactions VALUES (?,?,?)", batch)
    rx += len(batch)
print(f"src_post_reactions: {rx}")

out.executemany("INSERT INTO meta VALUES (?,?)", [
    ("built_at", str(int(time.time()))),
    ("source", args.src),
    ("counts", json.dumps({"users": n, "post_scores": posts, "post_reactions": rx})),
])
out.commit()
out.execute("VACUUM")
out.close()
src.close()

os.replace(tmp, args.out)
size = os.path.getsize(args.out)
print(f"wrote {args.out} ({size / 1048576:.1f} MB) in {time.time() - t0:.1f}s")
