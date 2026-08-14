import json
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_context(viewport={"width":900,"height":600}).new_page()
    pg.goto("http://localhost:8888/", wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(1200)
    print(json.dumps(pg.evaluate("""() => {
      const defs = window.__lmxCosDefs || {};
      const frames = defs.frame || {};
      const slug = 'bronze-laurel';
      const d = frames[slug];
      if (!d) return {error:'no def', keys:Object.keys(defs), frameCount:Object.keys(frames).length};
      const fr=document.createElement('span');
      fr.className='LmxCosFrame lmx-frame is-live';
      fr.setAttribute('data-cf',slug);
      fr.setAttribute('data-cf-render', d.r||'ring');
      for (const [k,v] of Object.entries(d.c||{})) fr.style.setProperty(k,v);
      const av=document.createElement('span'); av.className='Avatar';
      av.style.cssText='width:64px;height:64px;border-radius:50%;display:block;background:#555';
      fr.appendChild(av); document.body.appendChild(fr);
      const before=getComputedStyle(fr,'::before');
      return {
        defKeys:Object.keys(d), render:d.r, cssMap:d.c,
        wrapperDisplay:getComputedStyle(fr).display,
        beforeDisplay:before.display, beforeContent:before.content,
        beforeBorder:before.border, beforeInset:before.inset,
        beforeZ:before.zIndex, beforeBg:before.background.slice(0,60)
      };
    }"""), indent=2)); b.close()
