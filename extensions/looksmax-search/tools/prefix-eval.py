#!/usr/bin/env python3
"""
Which e5 prompt regime should the embedder use?

`intfloat/multilingual-e5-*` was trained with asymmetric prefixes — "query: "
on the question, "passage: " on the document. Meilisearch's `rest` embedder
cannot express that: it renders ONE request template for both indexing and
search, and it rejects an inline placeholder outright —

    in `request.inputs[0]`: Expected "{{text}}" inside of the repeated value

(measured 2026-08-13 against Meilisearch 1.53.0). So the only regimes reachable
without a second service are the two SYMMETRIC ones: no prefix at all, or the
same prefix on both sides via TEI's `--default-prompt`. This script measures
what choosing one of those actually costs against the canonical asymmetric
setup, on this corpus, rather than assuming it is free or fatal.

The evaluation is prior-free: no hand-written queries. A query is a real REPLY
post drawn from the corpus, and the relevant document is the discussion that
reply was written in. That is a genuine "someone described this topic in their
own words — find the thread" task, in whatever language the poster used.
"""
import json, os, sys, time, math, random, urllib.request, threading, queue
import numpy as np

MEILI = os.environ.get("MEILI", "http://127.0.0.1:7701")
EMB = os.environ.get("EMB", "http://127.0.0.1:8092")
KEY = os.environ["MK"]
POOL = int(os.environ.get("POOL", "5000"))
NQ = int(os.environ.get("NQ", "300"))
random.seed(20260813)


def meili(path, body=None, method="GET"):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(
        MEILI + path, data=data, method=method,
        headers={"Authorization": "Bearer " + KEY, "Content-Type": "application/json"})
    return json.load(urllib.request.urlopen(req, timeout=120))


def embed_many(texts, prefix="", conc=4, bs=32):
    """Returns a list of vectors, order-preserving."""
    out = [None] * len(texts)
    chunks = [(i, texts[i:i + bs]) for i in range(0, len(texts), bs)]
    q = queue.Queue()
    for c in chunks:
        q.put(c)

    def work():
        while True:
            try:
                start, batch = q.get_nowait()
            except queue.Empty:
                return
            body = json.dumps({"inputs": [prefix + t for t in batch], "truncate": True}).encode()
            req = urllib.request.Request(EMB + "/embed", data=body, method="POST",
                                         headers={"Content-Type": "application/json"})
            for attempt in range(3):
                try:
                    vecs = json.load(urllib.request.urlopen(req, timeout=600))
                    break
                except Exception as e:
                    if attempt == 2:
                        raise
                    time.sleep(2)
            for j, v in enumerate(vecs):
                out[start + j] = v

    ths = [threading.Thread(target=work) for _ in range(conc)]
    t0 = time.time()
    [t.start() for t in ths]
    [t.join() for t in ths]
    return out, time.time() - t0


def norm(v):
    n = math.sqrt(sum(x * x for x in v)) or 1.0
    return [x / n for x in v]


def doc_text(d, cap=400):
    return ("%s\n%s\n%s" % (
        d.get("title") or "",
        " ".join(d.get("tag_names") or []),
        (d.get("excerpt") or "")))[:cap]


# ---------------------------------------------------------------- corpus pull
# Order matters: pick the QUERIES first, then make sure their targets are in the
# pool. Building the pool first and hoping a random post sample lands inside it
# produced 3 usable pairs out of 300 on the first run — the pool is 5k of 48.5k
# discussions and replies are spread over all of them.
print("pulling reply posts to use as queries ...", flush=True)
queries = []
targets = {}
tries = 0
while len(queries) < NQ and tries < 30:
    tries += 1
    r = meili("/indexes/lmx_posts/search", {
        "q": "", "limit": 200, "offset": random.randint(0, 90000),
        "filter": "is_first = false AND length > 220 AND length < 1500 AND is_hidden = false",
        "attributesToRetrieve": ["id", "discussion_id", "content"],
    }, "POST")
    hits = r.get("hits", [])
    if not hits:
        continue
    for h in hits:
        did = h["discussion_id"]
        if did in targets or len(queries) >= NQ:
            continue
        txt = (h.get("content") or "").strip()
        if len(txt) < 180:
            continue
        try:
            d = meili(f"/indexes/lmx_discussions/documents/{did}"
                      "?fields=id,title,excerpt,tag_names")
        except Exception:
            continue
        targets[did] = d
        queries.append({"text": txt[:600], "target": did, "post": h["id"]})
print(f"  queries = {len(queries)} real reply posts, {len(targets)} target discussions", flush=True)
if len(queries) < 30:
    sys.exit("not enough evaluation queries — aborting rather than reporting a number from 12 samples")

print("building distractor pool ...", flush=True)
docs = list(targets.values())
seen = set(targets)
total = meili("/indexes/lmx_discussions/stats")["numberOfDocuments"]
off = 0
step = max(500, total // max(1, (POOL // 500)))
while len(docs) < POOL and off < total:
    batch = meili(f"/indexes/lmx_discussions/documents?limit=500&offset={off}"
                  "&fields=id,title,excerpt,tag_names")["results"]
    if not batch:
        break
    for d in batch:
        if d["id"] not in seen:
            seen.add(d["id"])
            docs.append(d)
    off += step
docs = docs[:max(POOL, len(targets))]
byid = {d["id"]: i for i, d in enumerate(docs)}
print(f"  pool = {len(docs)} discussions ({len(targets)} targets + distractors)", flush=True)

texts = [doc_text(d) for d in docs]
qtexts = [q["text"] for q in queries]

REGIMES = {
    "none          (symmetric, no prompt)": ("", ""),
    "query/query   (symmetric, TEI --default-prompt)": ("query: ", "query: "),
    "query/passage (canonical e5, needs a shim)": ("query: ", "passage: "),
}

results = {}
for name, (qp, dp) in REGIMES.items():
    dv, dt = embed_many(texts, dp)
    qv, qt = embed_many(qtexts, qp)
    D = np.array(dv, dtype=np.float32)
    Q = np.array(qv, dtype=np.float32)
    D /= (np.linalg.norm(D, axis=1, keepdims=True) + 1e-9)
    Q /= (np.linalg.norm(Q, axis=1, keepdims=True) + 1e-9)
    sims = Q @ D.T                                    # (nq, npool)
    order = np.argsort(-sims, axis=1)[:, :50]
    hit1 = hit5 = hit10 = 0
    mrr = 0.0
    for qi, q in enumerate(queries):
        tgt = byid[q["target"]]
        pos = np.where(order[qi] == tgt)[0]
        if len(pos):
            rank = int(pos[0]) + 1
            mrr += 1.0 / rank
            hit1 += rank <= 1
            hit5 += rank <= 5
            hit10 += rank <= 10
    n = len(queries)
    results[name] = dict(r1=hit1 / n, r5=hit5 / n, r10=hit10 / n, mrr=mrr / n,
                         docs_per_s=len(texts) / dt)
    print(f"{name:48s} R@1={hit1/n:.3f} R@5={hit5/n:.3f} R@10={hit10/n:.3f} "
          f"MRR={mrr/n:.3f}  ({len(texts)/dt:.0f} docs/s)", flush=True)

print()
print(json.dumps({"pool": len(docs), "queries": len(queries), "regimes": results}, indent=2))
