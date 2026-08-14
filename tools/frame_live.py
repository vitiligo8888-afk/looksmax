import json
from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_context(viewport={"width":1440,"height":900}).new_page()
    pg.goto("http://localhost:8888/u/Chris", wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(2500)
    print(json.dumps(pg.evaluate("""() => {
      const fr=document.querySelector('.LmxCosFrame[data-cf]');
      if(!fr) return {error:'no frame element on page'};
      const cs=getComputedStyle(fr), bf=getComputedStyle(fr,'::before'), af=getComputedStyle(fr,'::after');
      return {
        dataCf: fr.getAttribute('data-cf'),
        dataRender: fr.getAttribute('data-cf-render'),
        classes: fr.className,
        cfC1: cs.getPropertyValue('--cf-c1').trim(),
        cfLinear: cs.getPropertyValue('--cf-linear').trim().slice(0,60),
        before: {display:bf.display, background:bf.background.slice(0,70), mask:(bf.maskImage||'').slice(0,60),
                 clip:(bf.clipPath||'').slice(0,50), border:bf.border, inset:bf.inset, anim:bf.animationName},
        after:  {display:af.display, background:af.background.slice(0,70), mask:(af.maskImage||'').slice(0,60),
                 clip:(af.clipPath||'').slice(0,50), anim:af.animationName}
      };
    }"""), indent=2)); b.close()
