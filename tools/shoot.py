#!/usr/bin/env python3
"""
Local render-and-screenshot harness for the forum.

Drives a real headless Chromium against the live forum over the SSH tunnel
(http://localhost:8888), waits for the Flarum SPA to actually paint, and writes
PNGs we can look at — desktop and mobile — plus captures any console errors and
failed requests so a page that renders while throwing is caught.

Usage:
  python tools/shoot.py <label> <path> [<path> ...]
Env:
  BASE   default http://localhost:8888
  SCHEME dark|light|neon  (sets localStorage lmx-scheme before load)
"""
import os, sys, json, pathlib

from playwright.sync_api import sync_playwright

BASE = os.environ.get("BASE", "http://localhost:8888")
SCHEME = os.environ.get("SCHEME", "")
OUT = pathlib.Path(os.environ.get("SHOT_DIR", os.path.expandvars(
    r"C:\Users\chris\AppData\Local\Temp\claude\C--Users-chris-Documents"
    r"\57221a13-8a26-4bdb-840e-d3ecb0e1f47b\scratchpad\shots")))
OUT.mkdir(parents=True, exist_ok=True)

VIEWPORTS = {"desktop": (1440, 900), "mobile": (390, 844)}


def shoot(label, paths):
    results = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for vp_name, (w, h) in VIEWPORTS.items():
            ctx = browser.new_context(viewport={"width": w, "height": h},
                                      device_scale_factor=1)
            page = ctx.new_page()
            errors, failed = [], []
            page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
            page.on("requestfailed", lambda r: failed.append(r.url))
            for i, path in enumerate(paths):
                url = BASE + path
                if SCHEME:
                    page.goto(BASE + "/", wait_until="domcontentloaded")
                    page.evaluate("s => localStorage.setItem('lmx-scheme', s)", SCHEME)
                page.goto(url, wait_until="networkidle", timeout=45000)
                page.wait_for_timeout(1200)  # let entry animations settle
                slug = path.strip("/").replace("/", "_") or "home"
                fn = OUT / f"{label}_{slug}_{vp_name}.png"
                page.screenshot(path=str(fn), full_page=(vp_name == "desktop"))
                results.append({"url": url, "vp": vp_name, "file": str(fn),
                                "errors": errors[-5:], "failed": failed[-5:]})
            ctx.close()
        browser.close()
    print(json.dumps(results, indent=2))


if __name__ == "__main__":
    label = sys.argv[1] if len(sys.argv) > 1 else "shot"
    paths = sys.argv[2:] or ["/"]
    shoot(label, paths)
