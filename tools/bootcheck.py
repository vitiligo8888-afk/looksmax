import sys, json
from playwright.sync_api import sync_playwright
url=sys.argv[1]
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_context(viewport={"width":1280,"height":900}).new_page()
    reqs=[]
    pg.on("response", lambda r: reqs.append((r.status, r.url[:120])) if ("forum.js" in r.url or "forum.css" in r.url or "forum-es" in r.url) else None)
    pg.goto(url, wait_until="domcontentloaded", timeout=40000)
    pg.wait_for_timeout(8000)
    print(json.dumps(pg.evaluate("""() => ({
      hasWindowApp: typeof window.app !== 'undefined',
      hasFlarum: typeof window.flarum !== 'undefined',
      booted: !!(window.app && window.app.forum),
      currentRoute: (window.app && window.app.current && window.app.current.type) ? String(window.app.current.type.name||'') : null,
      appEl: !!document.getElementById('app'),
      bodyLen: (document.body.innerText||'').length,
      scriptCount: document.querySelectorAll('script').length
    })"""), indent=2))
    print("asset responses:", json.dumps(reqs, indent=2))
    b.close()
