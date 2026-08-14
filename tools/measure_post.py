"""Measure the author rail vs the post body it sits beside."""
import json, sys
from playwright.sync_api import sync_playwright
path = sys.argv[1] if len(sys.argv)>1 else "/d/30660-lip-lift-results-2-weeks-post-op-pictures-included"
out={}
with sync_playwright() as p:
    b=p.chromium.launch()
    for name,(w,h) in {"desktop":(1440,900),"mobile":(390,844)}.items():
        pg=b.new_context(viewport={"width":w,"height":h}).new_page()
        pg.goto("http://localhost:8888"+path, wait_until="networkidle", timeout=45000)
        pg.wait_for_timeout(2500)
        out[name]=pg.evaluate("""() => {
          const r=e=>{const b=e.getBoundingClientRect();return{w:Math.round(b.width),h:Math.round(b.height)};};
          const posts=[...document.querySelectorAll('.Post')].slice(0,4);
          return posts.map(p=>{
            const rail=p.querySelector('.LmxAuthor');
            const body=p.querySelector('.Post-body');
            return {
              post: r(p),
              rail: rail? r(rail):null,
              body: body? r(body):null,
              railTallerBy: (rail&&body)? Math.round(rail.getBoundingClientRect().height-body.getBoundingClientRect().height):null
            };
          });
        }""")
        pg.context.close()
    b.close()
print(json.dumps(out, indent=2))
