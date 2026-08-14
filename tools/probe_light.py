import json
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_context(viewport={"width":1440,"height":900}).new_page()
    pg.goto("http://localhost:8888/", wait_until="domcontentloaded")
    pg.evaluate("localStorage.setItem('lmx-scheme','light')")
    pg.goto("http://localhost:8888/", wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(1500)
    print(json.dumps(pg.evaluate("""() => {
      const cs = getComputedStyle(document.documentElement);
      const hits = [...document.querySelectorAll('*')].filter(el => !el.children.length && (el.textContent||'').trim()==='Etiquetas');
      const el = hits[0];
      let chain=[], n = el ? el.parentElement : null;
      while (n && n!==document.documentElement && chain.length<5){
        const s=getComputedStyle(n);
        chain.push({cls:(n.className||'').toString().slice(0,40), bg:s.backgroundColor, bgImg:s.backgroundImage.slice(0,50)});
        n=n.parentElement;
      }
      return {
        dataScheme: document.documentElement.getAttribute('data-scheme'),
        bgToken: cs.getPropertyValue('--bg').trim(),
        inkToken: cs.getPropertyValue('--ink').trim(),
        brandBg: cs.getPropertyValue('--brand-bg').trim(),
        bodyBg: getComputedStyle(document.body).backgroundColor,
        elColor: el ? getComputedStyle(el).color : null,
        chain
      };
    }"""), indent=2)); b.close()
