import json, sys
from playwright.sync_api import sync_playwright
path, needle = sys.argv[1], sys.argv[2]
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_context(viewport={"width":1440,"height":900}).new_page()
    pg.goto("http://localhost:8888/", wait_until="domcontentloaded")
    pg.evaluate("localStorage.setItem('lmx-scheme','light')")
    pg.goto("http://localhost:8888"+path, wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(1500)
    print(json.dumps(pg.evaluate("""(needle) => {
      const hits=[...document.querySelectorAll('*')].filter(el=>!el.children.length&&(el.textContent||'').trim()===needle);
      return hits.slice(0,2).map(el=>({
        cls:(el.className||'').toString().slice(0,70),
        inline: el.getAttribute('style'),
        color: getComputedStyle(el).color,
        parentCls:(el.parentElement?.className||'').toString().slice(0,70),
        parentInline: el.parentElement?.getAttribute('style'),
        rankVar: getComputedStyle(el).getPropertyValue('--lmx-rank-color')
      }));
    }""", needle), indent=2)); b.close()
