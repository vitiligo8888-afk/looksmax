import json
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_context(viewport={"width":1440,"height":900}).new_page()
    pg.goto("http://localhost:8888/d/292-socialmaxxing-extremely-high-effort", wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(2000)
    print(json.dumps(pg.evaluate("""() => {
      const out=[];
      for (const el of document.querySelectorAll('.Post-body *')) {
        if (el.children.length) continue;
        const c=getComputedStyle(el).color;
        if (c==='rgb(102, 102, 102)') {
          out.push({tag:el.tagName, cls:(el.className||'').toString().slice(0,40),
                    inline:el.getAttribute('style'),
                    parentTag:el.parentElement.tagName,
                    parentInline:el.parentElement.getAttribute('style'),
                    text:(el.textContent||'').trim().slice(0,40)});
          if (out.length>3) break;
        }
      }
      return out;
    }"""), indent=2)); b.close()
