import json
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_context(viewport={"width":1440,"height":900}).new_page()
    pg.goto("http://localhost:8888/", wait_until="domcontentloaded")
    pg.evaluate("localStorage.setItem('lmx-scheme','light')")
    pg.goto("http://localhost:8888/d/292-socialmaxxing-extremely-high-effort", wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(2500)
    print(json.dumps(pg.evaluate("""() => {
      const out=[];
      for (const el of document.querySelectorAll('*')) {
        if (el.children.length) continue;
        const t=(el.textContent||'').trim();
        if (t!=='WilhelmThyWizard' && t!=='BlackRoronoa') continue;
        out.push({tag:el.tagName, cls:(el.className||'').toString().slice(0,60),
                  inline:el.getAttribute('style'),
                  color:getComputedStyle(el).color,
                  parentCls:(el.parentElement.className||'').toString().slice(0,60),
                  parentInline:el.parentElement.getAttribute('style')});
        if(out.length>2) break;
      }
      return out;
    }"""), indent=2)); b.close()
