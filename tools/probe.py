"""Inspect computed styles of a selector/text on a page. Usage: probe.py <path> <text>"""
import sys, json
from playwright.sync_api import sync_playwright
path = sys.argv[1]; needle = sys.argv[2]
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_context(viewport={"width":1440,"height":900}).new_page()
    pg.goto("http://localhost:8888"+path, wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(1500)
    out = pg.evaluate("""(needle) => {
      const hits = [...document.querySelectorAll('*')].filter(el => !el.children.length && (el.textContent||'').trim() === needle);
      return hits.slice(0,3).map(el => {
        const s = getComputedStyle(el);
        let n = el.parentElement, chain = [];
        while (n && n !== document.documentElement && chain.length < 4) {
          const cs = getComputedStyle(n);
          chain.push({cls:(n.className||'').toString().slice(0,45), bg:cs.backgroundColor, bgImg:cs.backgroundImage.slice(0,30)});
          n = n.parentElement;
        }
        return {cls:(el.className||'').toString().slice(0,60), color:s.color, ownBg:s.backgroundColor, chain};
      });
    }""", needle)
    print(json.dumps(out, indent=2)); b.close()
