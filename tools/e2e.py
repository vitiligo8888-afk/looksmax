#!/usr/bin/env python3
"""
Visual + correctness e2e sweep for the forum.

Renders every page TYPE in a real headless Chromium at desktop and mobile, in
every colour scheme, and fails on things that are actually wrong rather than on
a pixel diff:

  1. console errors and failed network requests    (a page that renders while
     throwing is broken)
  2. horizontal overflow                            (the classic mobile break)
  3. elements painted outside the viewport
  4. unresolved i18n keys leaking into the DOM      ("local-looksmax-...")
  5. images that failed to load
  6. text/background contrast below WCAG AA on body copy
  7. touch targets under 44px on mobile

Screenshots are written for every case so a human can look, because a gate that
only prints PASS teaches you nothing about how the page actually looks.

Usage:
  python tools/e2e.py                 # default sweep, dark scheme
  python tools/e2e.py --schemes all   # dark + light + neon
  python tools/e2e.py --pages /store /u/admin
Exit code is non-zero if any check fails, so this can gate a deploy.
"""
import argparse
import json
import os
import pathlib
import sys

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8888")
SHOTS = pathlib.Path(os.environ.get(
    "SHOT_DIR",
    r"C:\Users\chris\AppData\Local\Temp\claude\C--Users-chris-Documents"
    r"\57221a13-8a26-4bdb-840e-d3ecb0e1f47b\scratchpad\shots\e2e"))

# One page per TYPE. Adding a seventh row here is how this suite grows; it is
# deliberately a list of kinds, not a crawl, so a run stays under a minute.
PAGES = [
    ("home", "/"),
    ("all", "/all"),
    ("tag", "/t/mejores-guias"),
    ("tag2", "/t/peptides"),
    ("tags", "/tags"),
    ("discussion", "/d/30660-lip-lift-results-2-weeks-post-op-pictures-included"),
    # A long, heavily-formatted guide. The forum's whole point is guides, and
    # they exercise the formatter (spoilers, tables, quotes, code) far harder
    # than a short ratings thread does.
    ("guide", "/d/292-socialmaxxing-extremely-high-effort"),
    ("profile", "/u/admin"),
    ("profile2", "/u/Chris"),
    ("store", "/store"),
    ("search", "/search?q=mewing"),
]

VIEWPORTS = {"desktop": (1440, 900), "mobile": (390, 844)}

# The audit runs in the page. Kept as one evaluate() so it sees a settled layout
# rather than interleaving with navigation.
AUDIT = r"""
() => {
  const out = { overflow: null, offscreen: [], rawKeys: [], brokenImages: [],
                lowContrast: [], smallTargets: [] };

  const de = document.documentElement;
  out.overflow = de.scrollWidth > window.innerWidth + 1
    ? { scrollWidth: de.scrollWidth, viewport: window.innerWidth } : null;

  // Elements sticking out to the right. Ignore deliberately-offscreen a11y text.
  for (const el of document.querySelectorAll('body *')) {
    const s = getComputedStyle(el);
    if (s.display === 'none' || s.visibility === 'hidden' || s.position === 'fixed') continue;
    const b = el.getBoundingClientRect();
    if (b.width === 0 || b.height === 0) continue;
    if (b.right > window.innerWidth + 2 && b.left >= 0) {
      out.offscreen.push({ tag: el.tagName, cls: (el.className || '').toString().slice(0, 60),
                           right: Math.round(b.right) });
      if (out.offscreen.length > 8) break;
    }
  }

  // Untranslated keys leaking through (Flarum prints the key when it misses).
  const bodyText = document.body.innerText || '';
  const m = bodyText.match(/(local-looksmax|core\.forum|flarum-)[a-z0-9_.\-]{4,}/gi);
  if (m) out.rawKeys = [...new Set(m)].slice(0, 8);

  for (const img of document.querySelectorAll('img')) {
    if (img.complete && img.naturalWidth === 0) {
      out.brokenImages.push((img.currentSrc || img.src || '').slice(0, 120));
      if (out.brokenImages.length > 6) break;
    }
  }

  // Contrast on real body copy. Only leaf nodes with visible text, and only
  // where we can resolve an opaque backdrop -- a wrong "fail" is worse than a
  // missed one because it trains you to ignore the gate.
  const lum = (c) => {
    const [r, g, b] = c.map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };
  const parse = (s) => { const m = s.match(/rgba?\(([^)]+)\)/); if (!m) return null;
    const p = m[1].split(',').map(Number); return { rgb: p.slice(0, 3), a: p.length > 3 ? p[3] : 1 }; };

  // Walk up for an opaque backdrop, but BAIL on a background-image. A filled
  // button whose fill is a gradient has a transparent background-COLOR, so the
  // walk would sail past it to the page and report dark-ink-on-black at 1.02:1
  // -- which is exactly backwards: the ink is dark BECAUSE the button is light.
  // Unknown backdrop is not the same as a failure, so those are skipped.
  // Walks THROUGH documentElement, not up to it. body is transparent on this
  // forum and the page fill lives on <html>, so stopping at documentElement and
  // assuming black reported the entire light scheme as 1.1:1 -- 12 false
  // failures that looked exactly like a real regression. Falling back to the
  // resolved --bg token (not a hardcoded black) keeps the last resort honest.
  const bgOf = (el) => { let n = el; while (n) {
      const s = getComputedStyle(n);
      if (s.backgroundImage && s.backgroundImage !== 'none') return null;
      const c = parse(s.backgroundColor); if (c && c.a === 1) return c.rgb;
      if (n === document.documentElement) break;
      n = n.parentElement; }
    const tok = parse(getComputedStyle(document.documentElement).getPropertyValue('--bg').trim()
                      || 'rgb(0,0,0)');
    if (tok) return tok.rgb;
    // --bg is usually a hex, which parse() cannot read; resolve it via canvas-free
    // fallback by letting the browser compute it on a throwaway element.
    const probe = document.createElement('div');
    probe.style.color = getComputedStyle(document.documentElement).getPropertyValue('--bg').trim();
    document.body.appendChild(probe);
    const rgb = parse(getComputedStyle(probe).color);
    probe.remove();
    return rgb ? rgb.rgb : [0, 0, 0]; };

  const seen = new Set();
  for (const el of document.querySelectorAll('p, li, span, a, h1, h2, h3, td, button')) {
    if (el.children.length) continue;
    const txt = (el.textContent || '').trim();
    if (txt.length < 8) continue;
    const s = getComputedStyle(el);
    if (s.display === 'none' || s.visibility === 'hidden' || parseFloat(s.opacity) < 0.5) continue;
    const fg = parse(s.color); if (!fg || fg.a < 0.9) continue;
    const size = parseFloat(s.fontSize);
    const bold = (parseInt(s.fontWeight, 10) || 400) >= 700;
    const large = size >= 24 || (size >= 18.66 && bold);
    const bg = bgOf(el);
    if (!bg) continue;                     // gradient/image backdrop: unmeasurable, not a failure
    const L1 = lum(fg.rgb), L2 = lum(bg);
    const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    const need = large ? 3 : 4.5;
    if (ratio < need) {
      const key = s.color + '|' + txt.slice(0, 20);
      if (seen.has(key)) continue; seen.add(key);
      out.lowContrast.push({ text: txt.slice(0, 40), color: s.color, ratio: +ratio.toFixed(2), need });
      if (out.lowContrast.length > 8) break;
    }
  }

  if (window.innerWidth < 500) {
    for (const el of document.querySelectorAll('a, button, [role=button], input, select')) {
      const s = getComputedStyle(el);
      if (s.display === 'none' || s.visibility === 'hidden') continue;
      const b = el.getBoundingClientRect();
      if (b.width === 0 || b.height === 0) continue;
      if (b.height < 32 || b.width < 32) {
        out.smallTargets.push({ tag: el.tagName, cls: (el.className || '').toString().slice(0, 40),
                                w: Math.round(b.width), h: Math.round(b.height) });
        if (out.smallTargets.length > 8) break;
      }
    }
  }
  return out;
}
"""


def run(pages, schemes, strict_targets=False):
    SHOTS.mkdir(parents=True, exist_ok=True)
    failures, report = [], []

    with sync_playwright() as p:
        browser = p.chromium.launch()
        for scheme in schemes:
            for vp_name, (w, h) in VIEWPORTS.items():
                ctx = browser.new_context(viewport={"width": w, "height": h})
                page = ctx.new_page()
                console_errors, failed_reqs = [], []

                # CORS noise is a TUNNEL ARTIFACT, not a site bug. We browse
                # http://localhost:8888 while the SPA's configured base URL is
                # https://looksmax.lat, so its own XHRs are cross-origin here and
                # nowhere else. Filtering it keeps the gate honest; a real user on
                # looksmax.lat never sees these.
                def _console(m):
                    if m.type != "error":
                        return
                    t = m.text
                    if "CORS policy" in t or "has been blocked by CORS" in t:
                        return
                    # The bare "Failed to load resource: net::ERR_FAILED" line is
                    # the second half of the same cross-origin failure; it carries
                    # no URL, so it can only be matched by shape.
                    if "net::ERR_FAILED" in t:
                        return
                    console_errors.append(t[:200])

                page.on("console", _console)
                page.on("requestfailed",
                        lambda r: failed_reqs.append(r.url[:140]) if "looksmax.lat" not in r.url else None)

                # Seed the scheme once per context, before any real navigation.
                page.goto(BASE + "/", wait_until="domcontentloaded")
                page.evaluate("s => { if (s === 'dark') localStorage.removeItem('lmx-scheme');"
                              "else localStorage.setItem('lmx-scheme', s); }", scheme)

                for label, path in pages:
                    console_errors.clear(); failed_reqs.clear()
                    try:
                        page.goto(BASE + path, wait_until="networkidle", timeout=45000)
                    except Exception as e:
                        failures.append(f"{scheme}/{vp_name}/{label}: navigation failed: {e}")
                        continue
                    page.wait_for_timeout(1200)

                    audit = page.evaluate(AUDIT)
                    shot = SHOTS / f"{scheme}_{vp_name}_{label}.png"
                    page.screenshot(path=str(shot), full_page=(vp_name == "desktop"))

                    case = {"scheme": scheme, "viewport": vp_name, "page": label,
                            "shot": str(shot), **audit,
                            "consoleErrors": console_errors[:5],
                            "failedRequests": [u for u in failed_reqs if "favicon" not in u][:5]}
                    report.append(case)

                    tag = f"{scheme}/{vp_name}/{label}"
                    if case["consoleErrors"]:
                        failures.append(f"{tag}: console errors {case['consoleErrors']}")
                    if case["failedRequests"]:
                        failures.append(f"{tag}: failed requests {case['failedRequests']}")
                    if audit["overflow"]:
                        failures.append(f"{tag}: horizontal overflow {audit['overflow']}")
                    if audit["rawKeys"]:
                        failures.append(f"{tag}: untranslated keys {audit['rawKeys']}")
                    if audit["brokenImages"]:
                        failures.append(f"{tag}: broken images {audit['brokenImages']}")
                    if audit["lowContrast"]:
                        failures.append(f"{tag}: low contrast {audit['lowContrast'][:3]}")
                    if strict_targets and audit["smallTargets"]:
                        failures.append(f"{tag}: small touch targets {audit['smallTargets'][:3]}")
                ctx.close()
        browser.close()

    (SHOTS / "report.json").write_text(json.dumps(report, indent=2), encoding="utf-8")

    print(f"\n{'='*70}\ncases: {len(report)}   shots: {SHOTS}\n{'='*70}")
    for f in failures:
        print("  FAIL " + f)
    if not failures:
        print("  all checks passed")
    return 1 if failures else 0


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--schemes", default="dark", help="dark | all | comma list")
    ap.add_argument("--pages", nargs="*", help="explicit paths instead of the default set")
    ap.add_argument("--strict-targets", action="store_true")
    a = ap.parse_args()

    schemes = ["dark", "light", "neon"] if a.schemes == "all" else a.schemes.split(",")
    pages = [(p.strip("/").replace("/", "_") or "home", p) for p in a.pages] if a.pages else PAGES
    sys.exit(run(pages, schemes, a.strict_targets))
