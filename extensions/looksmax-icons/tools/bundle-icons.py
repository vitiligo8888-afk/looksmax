#!/usr/bin/env python3
"""Bundle every icon this forum uses into a local file. Build time, not runtime.

WHY. The icon layer was a runtime shim: `<iconify-icon>` elements plus the
component script and the geometry API, both fetched from iconify.design on every
page load. Three consequences, all measured on the live site:

  1. It is a third-party request on every page view — the same class of problem
     we removed for post images. Every visitor's browser tells a third party
     exactly which icons this forum renders.
  2. It is racy. An `<iconify-icon>` has NO intrinsic size until its geometry
     arrives, so a flex parent stretches it. On /all, 68 of 122 icons had
     resolved after five seconds and 25 still measured zero by zero.
  3. It is a hard dependency on someone else's uptime for the forum to look
     like itself.

Run this when an icon id is added. It reads the ids actually referenced in the
source, fetches each set ONCE, and writes a local collection.

  python3 tools/bundle-icons.py
"""
import json, re, pathlib, subprocess, sys, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]      # extensions/
OUT = pathlib.Path(__file__).resolve().parents[1] / "js" / "dist" / "icons.json"
API = "https://api.iconify.design"

# The ids actually referenced, not a hand-kept list that drifts from the source.
pattern = re.compile(rb"\b([a-z0-9-]+):([a-z0-9-]+)\b")
KNOWN_SETS = {"ph", "lucide", "solar", "material-symbols", "simple-icons",
              "game-icons", "mdi", "tabler", "carbon", "bi", "ri"}

ids = set()
for path in ROOT.rglob("*"):
    if path.suffix not in {".php", ".js", ".less", ".ts"} or "node_modules" in str(path):
        continue
    # Test harnesses never reach a browser, and looksmax-icons/e2e/audit.ts
    # deliberately references an icon id that does not exist, to prove its
    # geometry audit goes red. Bundling from it would make this script fail on
    # a fixture — and, worse, would put a not_found id in the shipped bundle.
    if "e2e" in path.parts or "tests" in path.parts:
        continue
    try:
        blob = path.read_bytes()
    except OSError:
        continue
    for prefix, name in pattern.findall(blob):
        p, n = prefix.decode(), name.decode()
        if p in KNOWN_SETS:
            ids.add((p, n))

by_set: dict[str, list[str]] = {}
for prefix, name in sorted(ids):
    by_set.setdefault(prefix, []).append(name)

print(f"{len(ids)} icons across {len(by_set)} sets")

collections = {}
missing = []
for prefix, names in by_set.items():
    # One request per set, not per icon.
    url = f"{API}/{prefix}.json?icons=" + ",".join(names)
    req = urllib.request.Request(url, headers={"User-Agent": "looksmax-icon-bundler/1.0"})
    with urllib.request.urlopen(req, timeout=60) as r:
        data = json.load(r)
    got = data.get("icons", {})
    # An id that is an ALIAS comes back under `aliases`, not `icons`, and it
    # renders exactly the same — the component resolves the parent chain. The
    # previous check looked only at `icons` and so reported
    # material-symbols:ecg-heart-rounded and :exercise-rounded as missing on
    # every run, both of which are real, aliased, and present in the output.
    aliases = data.get("aliases", {}) or {}
    for n in names:
        if n not in got and n not in aliases:
            missing.append(f"{prefix}:{n}")
    entry = {
        "prefix": prefix,
        "icons": got,
        "width": data.get("width", 24),
        "height": data.get("height", 24),
    }
    if data.get("aliases"):
        entry["aliases"] = data["aliases"]
    collections[prefix] = entry
    print(f"  {prefix}: {len(got)}/{len(names)}")

if missing:
    print("MISSING (id does not exist in that set):", ", ".join(missing))

OUT.parent.mkdir(parents=True, exist_ok=True)
OUT.write_text(json.dumps(collections, separators=(",", ":")))
size = OUT.stat().st_size
print(f"wrote {OUT} ({size/1024:.1f} KB)")
if missing:
    sys.exit(1)
