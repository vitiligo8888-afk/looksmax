import json
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_context(viewport={"width":1440,"height":900}).new_page()
    pg.goto("http://localhost:8888/d/30660-lip-lift-results-2-weeks-post-op-pictures-included", wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(1500)
    print(json.dumps(pg.evaluate("""() => {
      const el = document.querySelector('.TagLabel.colored');
      if (!el) return 'no chip';
      const s = getComputedStyle(el);
      return {
        classes: el.className,
        inlineStyle: el.getAttribute('style'),
        tagBgVar: s.getPropertyValue('--tag-bg'),
        tagColorVar: s.getPropertyValue('--tag-color'),
        bg: s.backgroundColor,
        color: s.color,
        boxShadow: s.boxShadow.slice(0,60)
      };
    }"""), indent=2)); b.close()
