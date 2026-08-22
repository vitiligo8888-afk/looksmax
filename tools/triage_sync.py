"""Validate completed triage batches, then apply them on the server.

    python tools/triage_sync.py            # validate + dry-run only
    python tools/triage_sync.py --apply    # actually apply

Every batch is validated LOCALLY first, because the failure mode of a bulk
content operation is silent and wide: a reviewer that drops ten ids, invents an
id, or writes "Keep " with a capital and a trailing space would otherwise be
applied as "no verdict" and those guides would sit untriaged forever while the
counter said they were done.

Checks per batch:
  - output ids are exactly the input ids, in the same order
  - verdict is exactly keep|delete
  - section is one of the five topical slugs, and non-empty on every keep
  - featured is a real boolean
Anything failing is reported and NOT uploaded; the rest still go.
"""
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TRIAGE = ROOT / "content" / "triage"
SECTIONS = {"looksmaxing", "softmaxing", "hardmaxing", "peptides", "anabolicos"}
SSH = ["ssh", "-o", "ConnectTimeout=25", "-o", "ControlMaster=no", "-o", "ControlPath=none", "looksmax"]


def _ssh_run(args, **kw):
    """
    subprocess.run over SSH, tolerating the box's fail2ban throttle.

    The host jails a burst of short connections and then answers 255. A bulk
    apply that hits that mid-run would otherwise lose the whole batch's verdicts
    and archive nothing. Backing off and retrying turns the throttle into a
    pause. Same helper translate_sync already carries.
    """
    import time
    delay = 20
    for attempt in range(4):
        r = subprocess.run(args, **kw)
        if r.returncode != 255:
            return r
        if attempt < 3:
            print(f"    ssh throttled (255), retrying in {delay}s")
            time.sleep(delay)
            delay *= 2
    return r

def validate(src_path: Path, out_path: Path):
    problems = []
    src = json.loads(src_path.read_text(encoding="utf-8"))
    try:
        out = json.loads(out_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as e:
        return [f"output is not valid JSON: {e}"], 0, 0

    si = [x["discussion_id"] for x in src]
    oi = [x.get("discussion_id") for x in out]

    if len(oi) != len(si):
        problems.append(f"expected {len(si)} entries, got {len(oi)}")
    if set(oi) != set(si):
        missing = set(si) - set(oi)
        extra = set(oi) - set(si)
        if missing:
            problems.append(f"missing {len(missing)} ids, e.g. {sorted(missing)[:5]}")
        if extra:
            problems.append(f"invented {len(extra)} ids, e.g. {sorted(extra)[:5]}")

    keep = dele = 0
    for e in out:
        i = e.get("discussion_id")
        v = e.get("verdict")
        s = e.get("section", "")
        if v == "keep":
            keep += 1
        elif v == "delete":
            dele += 1
        else:
            problems.append(f"id {i}: bad verdict {v!r}")
            continue
        if v == "keep":
            if s not in SECTIONS:
                problems.append(f"id {i}: bad section {s!r}")
        if not isinstance(e.get("featured", False), bool):
            problems.append(f"id {i}: featured is not a boolean")

    return problems, keep, dele

def main():
    apply = "--apply" in sys.argv
    done = sorted(TRIAGE.glob("*.done.json"))
    if not done:
        print("no completed batches found")
        return 0

    good, tot_k, tot_d = [], 0, 0
    for out_path in done:
        src_path = TRIAGE / out_path.name.replace(".done.json", ".json")
        if not src_path.exists():
            print(f"  {out_path.name}: no matching source batch — skipped")
            continue
        problems, k, d = validate(src_path, out_path)
        if problems:
            print(f"  {out_path.name}: REJECTED")
            for p in problems[:6]:
                print(f"      - {p}")
            continue
        print(f"  {out_path.name}: ok  ({k} keep, {d} delete)")
        good.append(out_path)
        tot_k += k
        tot_d += d

    if not good:
        print("\nnothing valid to apply")
        return 1

    print(f"\n{len(good)} batch(es) valid: {tot_k} keep, {tot_d} delete")
    if not apply:
        print("dry run — pass --apply to send them")
        return 0

    # One upload for everything, then one apply per batch.
    names = [p.name for p in good]
    tar = subprocess.run(
        ["tar", "cf", "-", "-C", str(TRIAGE)] + names,
        capture_output=True, check=True,
    )
    up = _ssh_run(
        SSH + ["rm -rf /srv/looksmax/tri-in && mkdir -p /srv/looksmax/tri-in && tar xf - -C /srv/looksmax/tri-in"
               " && docker exec flarum-app mkdir -p /flarum/app/storage/tri-in"
               " && docker cp /srv/looksmax/tri-in/. flarum-app:/flarum/app/storage/tri-in/"],
        input=tar.stdout,
    )
    if up.returncode != 0:
        print("\nupload failed after retries — nothing applied, safe to re-run")
        return 1

    cmds = " ; ".join(
        f"docker exec flarum-app php flarum lmx:guide:triage --apply=/flarum/app/storage/tri-in/{n} 2>&1 | head -2"
        for n in names
    )
    r = _ssh_run(SSH + [cmds + " ; docker exec flarum-app php flarum lmx:guide:triage --stats"])

    # Archive ONLY what was just applied, and only on success.
    #
    # This used to be a manual `mv content/triage/x*.json applied/`, which twice
    # archived batches whose verdicts had never been applied: once for seven
    # batches that had only been generated (350 guides that would have been
    # silently skipped and counted as done), and once for a batch whose agent
    # finished after the move, leaving its verdicts stranded. Archiving here,
    # keyed to the files this run actually applied, makes that impossible.
    if r.returncode != 0:
        print("\napply failed — nothing archived, safe to re-run")
        return 1

    adir = TRIAGE / "applied"
    adir.mkdir(exist_ok=True)
    for p in good:
        src = TRIAGE / p.name.replace(".done.json", ".json")
        p.replace(adir / p.name)
        if src.exists():
            src.replace(adir / src.name)
    print(f"\narchived {len(good)} applied batch(es) to {adir}")
    return 0

sys.exit(main())
