#!/usr/bin/env python3
"""Check every Iconify id this extension ships against the real Iconify API.

    python3 tools/verify-icons.py            # verify, exit 1 on any problem
    python3 tools/verify-icons.py --quiet    # only the summary

WHY THIS EXISTS

An Iconify name that does not exist does not error. `<iconify-icon icon="ph:be
ll-ringing">` for a set where the icon is called `bell-ringing-fill` renders an
empty box the same size as a real icon, and if the CDN is reachable it renders
an empty box after a network round trip. This has already shipped to this forum
once. Nothing in the browser, the build, or the LESS compiler will tell you.

The API is the only authority: an unknown name comes back in `not_found`, not as
an error, so a name must be checked for PRESENCE in `icons`/`aliases` — checking
that the request succeeded proves nothing.

WHAT IS CHECKED

  1. every `set:name` in js/dist/forum.js's MAP
  2. every `set:name` actually stored in js/dist/icons.json
  3. every id referenced as a string anywhere in this extension's PHP/JS/LESS
  4. the MAP and the source ids are all PRESENT in the local bundle, because a
     name that is real but not bundled falls back to fetching from
     api.iconify.design at runtime — a third-party request per visitor, and a
     race that leaves the element unsized until it lands
  5. every bundled icon is SQUARE. less/forum.less pins every icon to a 1em by
     1em box, which is correct for a square glyph and squashes anything else.
     A non-square icon must either be given its own rule or not be used, and
     this is the only place that will notice.
"""
import json
import pathlib
import re
import sys
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
BUNDLE = ROOT / "js" / "dist" / "icons.json"
API = "https://api.iconify.design"

KNOWN_SETS = {"ph", "lucide", "solar", "material-symbols", "simple-icons",
              "game-icons", "mdi", "tabler", "carbon", "bi", "ri"}

quiet = "--quiet" in sys.argv


def say(*a):
    if not quiet:
        print(*a)


# ---------------------------------------------------------------- gather ids
map_ids: dict[str, str] = {}
forum_js = (ROOT / "js" / "dist" / "forum.js").read_text()
m = re.search(r"var MAP = \{(.*?)\n  \};", forum_js, re.S)
if not m:
    print("FAIL: could not find the MAP literal in js/dist/forum.js")
    sys.exit(1)
for fa, iid in re.findall(r"'(fa-[a-z0-9-]+)':\s*'([a-z0-9-]+:[a-z0-9-]+)'", m.group(1)):
    if fa in map_ids and map_ids[fa] != iid:
        print(f"FAIL: {fa} is mapped twice, to {map_ids[fa]} and {iid}")
        sys.exit(1)
    map_ids[fa] = iid

source_ids: set[str] = set()
for path in ROOT.rglob("*"):
    if path.suffix not in {".php", ".js", ".less", ".ts"} or "node_modules" in str(path):
        continue
    # e2e/ and tools/ never reach a browser, and e2e/audit.ts deliberately
    # contains an id that does not exist — its self-test injects
    # `ph:this-icon-does-not-exist-at-all` to prove the geometry audit goes red.
    # Scanning them would make this tool fail on its own sibling's fixture.
    rel = path.relative_to(ROOT).parts[0]
    if rel in {"e2e", "tools"}:
        continue
    for prefix, name in re.findall(r"\b([a-z0-9-]+):([a-z0-9-]+)\b", path.read_text(errors="ignore")):
        if prefix in KNOWN_SETS:
            source_ids.add(f"{prefix}:{name}")

bundle = json.loads(BUNDLE.read_text())
bundled: set[str] = set()
nonsquare: list[str] = []
for prefix, coll in bundle.items():
    dw, dh = coll.get("width", 24), coll.get("height", 24)
    for name, icon in coll.get("icons", {}).items():
        bundled.add(f"{prefix}:{name}")
        w, h = icon.get("width", dw), icon.get("height", dh)
        if w != h:
            nonsquare.append(f"{prefix}:{name} ({w}x{h})")
    for name in coll.get("aliases", {}):
        bundled.add(f"{prefix}:{name}")

everything = sorted(set(map_ids.values()) | source_ids | bundled)

# A checker that has never rejected anything is an assumption, not a check.
# --self-test slips one plausible-looking but non-existent name into the batch
# and asserts the run goes red on it.
if "--self-test" in sys.argv:
    everything.append("ph:bell-ringing-outline")   # ph names it bell-ringing-bold
    map_ids["fa-selftest"] = "ph:bell-ringing-outline"
say(f"{len(map_ids)} MAP entries, {len(source_ids)} ids in source, "
    f"{len(bundled)} in the bundle -> {len(everything)} distinct ids to verify")

# ---------------------------------------------------------------- API check
by_set: dict[str, list[str]] = {}
for iid in everything:
    prefix, name = iid.split(":", 1)
    by_set.setdefault(prefix, []).append(name)

verified, failed = 0, []
for prefix, names in sorted(by_set.items()):
    url = f"{API}/{prefix}.json?icons=" + ",".join(sorted(names))
    req = urllib.request.Request(url, headers={"User-Agent": "looksmax-icon-verify/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            data = json.load(r)
    except Exception as e:                                    # noqa: BLE001
        # An unreachable API is NOT a pass. Say so and fail.
        print(f"FAIL: {prefix}: could not reach {API} ({e})")
        sys.exit(1)
    present = set(data.get("icons", {})) | set(data.get("aliases", {}))
    not_found = set(data.get("not_found", []))
    for n in sorted(names):
        if n in present:
            verified += 1
        else:
            failed.append(f"{prefix}:{n}" + (" [not_found]" if n in not_found else " [absent from response]"))
    say(f"  {prefix:<18} {len(names):>4} checked, {len(present & set(names)):>4} real"
        + (f", NOT FOUND: {sorted(not_found)}" if not_found else ""))

# ------------------------------------------------------------ local coverage
unbundled = sorted((set(map_ids.values()) | source_ids) - bundled)

problems = []
if failed:
    problems.append(f"{len(failed)} id(s) do not exist in Iconify: " + ", ".join(failed))
if unbundled:
    problems.append(
        f"{len(unbundled)} id(s) are real but NOT in js/dist/icons.json, so they would be "
        f"fetched from api.iconify.design at runtime: " + ", ".join(unbundled)
        + "  — run tools/bundle-icons.py")
if nonsquare:
    problems.append(
        f"{len(nonsquare)} bundled icon(s) are not square, and less/forum.less pins every "
        f"icon to a 1em square box, which will squash them: " + ", ".join(nonsquare))

print(f"\nverified against {API}: {verified}/{len(everything)}")
print(f"in the local bundle    : {len(everything) - len(unbundled)}/{len(everything)}")
print(f"square                 : {len(bundled) - len(nonsquare)}/{len(bundled)}")

if problems:
    print()
    for p in problems:
        print("  FAIL  " + p)
    sys.exit(1)
print("\n  PASS  every icon id is real, bundled locally, and square")
