"""Ship completed guide translations, but only if they pass the verifier.

    python tools/translate_sync.py content/tw1            # verify only
    python tools/translate_sync.py content/tw1 --apply    # verify, then apply the ones that pass

The order matters and is the whole point: verify runs on the SERVER against the
original recovered from the database, and nothing is written to any post until
that passes. A translation that invented a citation or dropped an image is
rejected while it is still just a file.

Files ending .src.md are exports, not translations, and are skipped.
"""
import re
import subprocess
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SSH = ["ssh", "-o", "ConnectTimeout=25", "-o", "ControlMaster=no", "-o", "ControlPath=none", "looksmax"]
REMOTE = "/flarum/app/storage/tsync"

# Discussions that must NOT be published in translation, whatever a translation
# agent produced for them.
#
# The verifier is a STRUCTURAL gate — tag parity, link integrity, indentation.
# It has no opinion about what a guide says, and it never should: those are
# different jobs. But that meant nothing stopped d/801 from going live in
# Spanish after one agent translated it, even though three others had refused
# it and the operator had been told it would wait for their decision. The
# structural gate passed it, because structurally it was fine.
#
# So the policy decision lives here, as data, ahead of the gate. An id in this
# set is skipped and reported no matter which agent wrote the file or how clean
# it is. Removing an id from this set is a deliberate act.
#
#   801 — "Dust's IndiaMart Guide": customs-evasion routing (ship UK, re-route
#         via Northern Ireland), a named freight forwarder, named vendors and a
#         vendor phone number, covering DNP and desomorphine. Not steroid
#         dosing, which is why it is treated separately from the drug content
#         the operator asked to keep. Awaiting their decision.
HOLD = {
    801: "operational customs-evasion / smuggling logistics — awaiting operator decision",
}


def ssh(cmd, stdin=None, capture=False, tries=4):
    """
    Run one remote command, tolerating the box's SSH rate limiting.

    This host runs fail2ban, and a long working session opens a lot of short
    connections. When it throttles, ssh exits 255 and the previous version of
    this script raised CalledProcessError mid-upload — losing a whole verified
    wave and, worse, leaving the remote staging directory half-written.
    Backing off and retrying turns a lost run into a pause. A genuine failure
    still surfaces, just after the retries are exhausted.
    """
    delay = 20
    for attempt in range(1, tries + 1):
        r = subprocess.run(SSH + [cmd], input=stdin,
                           capture_output=True, text=not isinstance(stdin, bytes))
        if r.returncode != 255:
            return r
        if attempt < tries:
            print(f"    ssh throttled (255), retrying in {delay}s "
                  f"[{attempt}/{tries - 1}]")
            time.sleep(delay)
            delay *= 2
    print("    ssh still refusing after retries — the host is rate limiting. "
          "Nothing was applied; re-run later.")
    return r

def local_checks(p: Path):
    """
    Gate on real incompleteness; REPAIR the two mechanical defects.

    The translation agents work by copying the source verbatim and then editing
    only prose — which is what made tag and URL drift impossible. The side
    effect is that they faithfully carry over defects that were already in the
    original: a line indented four spaces before an [img], or a stray `\\~`
    inside a URL. Failing the file for those punishes the method that made it
    correct, and on the first wave it rejected 9 of 21 good translations.

    Both are deterministic to fix and the house rules already require it, so
    they are fixed here and reported, not bounced back to an agent. Anything
    that needs judgement — a missing title, an empty body — still fails, and
    the authoritative comparison against the original still happens server-side.

    Returns (problems, fixes).
    """
    txt = p.read_text(encoding="utf-8", errors="replace")
    problems, fixes = [], []

    if not txt.startswith("---"):
        return ["no front matter"], fixes
    end = txt.find("\n---", 3)
    if end == -1:
        return ["unterminated front matter"], fixes

    head, body = txt[3:end], txt[end + 4:]

    m = re.search(r"^\s*translated_title:\s*(.*)$", head, re.M)
    if not m or not m.group(1).strip().strip('"\''):
        problems.append("translated_title is empty (file looks unfinished)")
    if not body.strip():
        problems.append("body is empty")
    if problems:
        return problems, fixes

    original = body

    # 1. dedent BBCode that would otherwise render as a grey code block
    body, n = re.subn(r"^[ \t]{4,}(?=\[(?:img|url|color|size|b|i|quote|spoiler))",
                      "", body, flags=re.M | re.I)
    if n:
        fixes.append(f"dedented {n} line(s) before BBCode")

    # 2. Make every URL match the ORIGINAL byte-for-byte.
    #
    # NOT a blanket unescape. An earlier version stripped `\~` and `\_` from
    # every URL on the theory that escapes break links — true only when the
    # TRANSLATION introduced them. Several of these guides legitimately contain
    # `de\_DE` and `Pycnogenol\_OralSkinCare` in the author's own text, so
    # unescaping made the translation disagree with the original and the
    # server verifier correctly rejected four good files as having "dropped"
    # and "invented" the same link.
    #
    # The source of truth is the sibling .src.md. Translations preserve line
    # structure 1:1 and never translate a URL, so URL N on body line i must equal
    # URL N on the SOURCE body's line i. We restore per line, per position.
    #
    # This replaced a global set-based pass that had two failures, both of which
    # shipped bad files: (1) it only re-added an escape the SOURCE carried, so an
    # escape the TRANSLATOR added (the common case — `#:~:text=` fragments come
    # back as `#:\~:text=`) was never repaired; (2) when the same URL appears in
    # two escapings in one guide — plain in a [url="…"] attribute, escaped in the
    # link text, which is exactly how the source writes citations — the bare-form
    # replace rewrote the ATTRIBUTE to the escaped form and broke it. Per-line,
    # per-position alignment fixes both and still cannot invent anything: every
    # replacement value is the source's own URL for that position.
    src_path = p.with_name(p.stem + ".src.md")
    if src_path.exists():
        src = src_path.read_text(encoding="utf-8", errors="replace")
        se = src.find("\n---", 3)
        src_body = src[se + 4:] if se != -1 else src
        url_re = r"https?://[^\s\[\]\"'<>]+"
        s_lines, b_lines = src_body.split("\n"), body.split("\n")
        restored = 0
        if len(s_lines) == len(b_lines):
            for i, bl in enumerate(b_lines):
                s_urls = re.findall(url_re, s_lines[i])
                b_urls = re.findall(url_re, bl)
                if len(s_urls) != len(b_urls):
                    continue  # structural mismatch — the verifier will catch it
                for su, bu in zip(s_urls, b_urls):
                    # only reconcile escaping differences, never distinct URLs
                    if su != bu and su.replace("\\", "") == bu.replace("\\", ""):
                        bl = bl.replace(bu, su, 1)
                        restored += 1
                b_lines[i] = bl
            body = "\n".join(b_lines)
        if restored:
            fixes.append(f"restored {restored} URL(s) to the original's exact escaping")

    if body != original:
        p.write_text(txt[:end + 4] + body, encoding="utf-8")

    return problems, fixes

def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    apply = "--apply" in sys.argv
    if not args:
        print("usage: translate_sync.py <dir> [--apply]")
        return 2

    d = (ROOT / args[0]).resolve()
    files = [p for p in sorted(d.glob("*.md")) if not p.name.endswith(".src.md")]
    if not files:
        print(f"no translated .md files in {d}")
        return 1

    good = []
    for p in files:
        # Policy gate first, before any structural check — a held guide is not
        # published however well-formed its translation is.
        try:
            held = int(p.stem)
        except ValueError:
            held = None
        if held in HOLD:
            print(f"  {p.name}: WITHHELD — {HOLD[held]}")
            p.unlink()
            print(f"      deleted the translation so no later run can pick it up")
            continue

        probs, fixes = local_checks(p)
        if probs:
            print(f"  {p.name}: HELD BACK")
            for x in probs:
                print(f"      - {x}")
            continue
        good.append(p)
        note = ("  [repaired: " + "; ".join(fixes) + "]") if fixes else ""
        print(f"  {p.name}: ok{note}")

    if not good:
        print("\nnothing passed local checks")
        return 1

    print(f"\nuploading {len(good)} file(s) for server-side verification…")
    tar = subprocess.run(
        ["tar", "cf", "-", "-C", str(d)] + [p.name for p in good],
        capture_output=True, check=True,
    )
    up = ssh(f"rm -rf /srv/looksmax/tsync && mkdir -p /srv/looksmax/tsync"
             f" && tar xf - -C /srv/looksmax/tsync"
             f" && docker exec flarum-app rm -rf {REMOTE}"
             f" && docker exec flarum-app mkdir -p {REMOTE}"
             f" && docker cp /srv/looksmax/tsync/. flarum-app:{REMOTE}/",
             stdin=tar.stdout)
    if up.returncode != 0:
        return 1

    print("\n--- verifier ---")
    r = ssh(f"docker exec flarum-app php flarum lmx:guide:verify --dir={REMOTE} --no-ansi")
    if r.returncode == 255:
        return 1
    # Strip any ANSI the CLI still emits before parsing. Colour codes wrapping
    # the word "FAILED" are exactly how two bad translations (d/42887, d/1182)
    # slipped this gate once: the summary counted them failed, but the per-file
    # "\x1b[31mFAILED\x1b[0m" line did not match the regex below, so nothing was
    # quarantined and the failures were applied. --no-ansi plus this strip make
    # the match independent of terminal colouring.
    #
    # Parse stdout AND stderr combined. The verifier writes its per-file "FAILED"
    # detail to stderr while the summary tally goes to stdout, so a stdout-only
    # parse found "N failed" in the tally but could name zero of them — which
    # tripped the abort backstop and blocked the good files on every batch that
    # had any failure. Merging the streams lets the names resolve and the good
    # files apply while only the truly-bad ones quarantine.
    out = re.sub(r"\x1b\[[0-9;]*m", "", (r.stdout or "") + "\n" + (r.stderr or ""))
    print(out.strip())

    # Quarantine the failures and ship the rest.
    #
    # Aborting the whole wave because one file is bad throws away a dozen good
    # translations and makes each retry re-do work that already passed. The
    # failures are moved to failed/ so a later pass can pick them up, and the
    # remainder proceeds — the gate is still absolute per FILE, which is the
    # level that matters.
    failed = set(re.findall(r"(\S+\.md) \(d/\d+\) FAILED", out))

    # Cross-check against the verifier's own tally. If it reports N failures but
    # we could not name N files, we do not know which are bad — refuse to apply
    # ANYTHING rather than ship an unidentified failure. This is the backstop
    # that would have caught the ANSI bug above even if the strip missed a case.
    tally = re.search(r"(\d+)\s+passed,\s+(\d+)\s+failed", out)
    if tally and int(tally.group(2)) != len(failed):
        print(f"\nverifier reported {tally.group(2)} failure(s) but only "
              f"{len(failed)} could be identified by name — aborting, nothing "
              f"applied. Inspect the verifier output above and re-run.")
        return 1
    if failed:
        qdir = d / "failed"
        qdir.mkdir(exist_ok=True)
        for name in sorted(failed):
            src = d / name
            if src.exists():
                src.rename(qdir / name)
                sib = d / (src.stem + ".src.md")
                if sib.exists():
                    sib.replace(qdir / sib.name)
        print(f"\nquarantined {len(failed)} failing file(s) to {qdir} — they need redoing:")
        for name in sorted(failed):
            print(f"      {name}")
        good = [p for p in good if p.name not in failed]
        if not good:
            print("nothing left to apply")
            return 1
        # re-stage without the failures so the apply cannot pick them up
        tar = subprocess.run(["tar", "cf", "-", "-C", str(d)] + [p.name for p in good],
                             capture_output=True, check=True)
        if ssh(f"rm -rf /srv/looksmax/tsync && mkdir -p /srv/looksmax/tsync"
               f" && tar xf - -C /srv/looksmax/tsync"
               f" && docker exec flarum-app rm -rf {REMOTE}"
               f" && docker exec flarum-app mkdir -p {REMOTE}"
               f" && docker cp /srv/looksmax/tsync/. flarum-app:{REMOTE}/",
               stdin=tar.stdout).returncode != 0:
            return 1

    if not apply:
        print(f"\n{len(good)} verified. pass --apply to write them to the forum.")
        return 0

    print("\n--- applying ---")
    out = ssh(f"docker exec flarum-app php flarum lmx:translate --dir={REMOTE} --force 2>&1 | tail -30"
              f" ; docker exec flarum-app php flarum lmx:guide:triage --stats")
    print(out.stdout or out.stderr)
    return 0

sys.exit(main())
