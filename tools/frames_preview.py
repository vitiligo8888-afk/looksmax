#!/usr/bin/env python3
"""
Render EVERY avatar frame at once and screenshot it.

Frames are the one visual feature you cannot check by loading a page: each one
is worn by an account, so seeing all 23 would mean finding 23 users. This grabs
the render specs the forum already inlines for its own decorator
(window.__lmxCosDefs) and rebuilds the exact DOM shape the decorator produces
(.LmxCosFrame[data-cf] + the per-frame custom properties from Definitions::css)
for every frame in the catalogue, on the live stylesheet.

So it is a real render, not a mock: if a frame is broken in production it is
broken here.

Usage: python tools/frames_preview.py [scheme]
"""
import sys, pathlib
from playwright.sync_api import sync_playwright

SCHEME = sys.argv[1] if len(sys.argv) > 1 else "dark"
OUT = pathlib.Path(
    r"C:\Users\chris\AppData\Local\Temp\claude\C--Users-chris-Documents"
    r"\57221a13-8a26-4bdb-840e-d3ecb0e1f47b\scratchpad\shots")
OUT.mkdir(parents=True, exist_ok=True)

BUILD = r"""
(scheme) => {
  const defs = window.__lmxCosDefs || {};
  const frames = defs.frame || {};
  if (scheme === 'dark') localStorage.removeItem('lmx-scheme');
  else localStorage.setItem('lmx-scheme', scheme);

  document.body.innerHTML = '';
  const wrap = document.createElement('div');
  wrap.style.cssText = 'display:grid;grid-template-columns:repeat(6,1fr);gap:28px;'
    + 'padding:32px;background:var(--bg);min-height:100vh;font-family:var(--font-ui)';

  const slugs = Object.keys(frames).sort();
  for (const slug of slugs) {
    const d = frames[slug];
    const cell = document.createElement('div');
    cell.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:8px';

    // the decorator's shape: a .LmxCosFrame wrapper carrying data-cf + the
    // spec's custom properties, with the avatar inside it
    const fr = document.createElement('span');
    fr.className = 'LmxCosFrame lmx-frame is-live';
    // data-cf is the SLUG and data-cf-render is the render engine -- getting
    // these the wrong way round renders 23 plain circles and looks exactly
    // like "frames are broken in production".
    fr.setAttribute('data-cf', slug);
    fr.setAttribute('data-cf-render', d.r || 'ring');
    if (d.p && d.p !== 'none') fr.setAttribute('data-cf-pattern', d.p);
    if (d.s && d.s !== 'circle') fr.setAttribute('data-cf-shape', d.s);
    for (const [k, v] of Object.entries(d.c || {})) fr.style.setProperty(k, v);
    fr.style.setProperty('display', 'inline-block');

    const av = document.createElement('span');
    av.className = 'Avatar';
    av.style.cssText = 'width:64px;height:64px;border-radius:50%;display:block;'
      + 'background:linear-gradient(135deg,#5a5a6e,#2a2a38)';
    fr.appendChild(av);

    const label = document.createElement('div');
    label.textContent = slug;
    label.style.cssText = 'font-size:11px;color:var(--ink-dim);text-align:center';

    const rarity = document.createElement('div');
    rarity.textContent = (d.q || '') + ' · ' + (d.r || '');
    rarity.style.cssText = 'font-size:10px;color:var(--ink-faint)';

    cell.appendChild(fr); cell.appendChild(label); cell.appendChild(rarity);
    wrap.appendChild(cell);
  }
  document.body.appendChild(wrap);
  return { count: slugs.length, slugs };
}
"""

with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_context(viewport={"width": 1280, "height": 900}).new_page()
    pg.goto("http://localhost:8888/", wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(1200)
    info = pg.evaluate(BUILD, SCHEME)
    pg.wait_for_timeout(1500)  # let the animated frames reach a visible phase
    f = OUT / f"frames_{SCHEME}.png"
    pg.screenshot(path=str(f), full_page=True)
    print(f"{info['count']} frames -> {f}")
    print(", ".join(info["slugs"]))
    b.close()
