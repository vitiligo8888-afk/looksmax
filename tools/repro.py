"""Load a page on the PUBLIC site and report what actually fails."""
import sys, json
from playwright.sync_api import sync_playwright
url = sys.argv[1]
OUT=r"C:\Users\chris\AppData\Local\Temp\claude\C--Users-chris-Documents\57221a13-8a26-4bdb-840e-d3ecb0e1f47b\scratchpad\shots"
with sync_playwright() as p:
    b=p.chromium.launch(); ctx=b.new_context(viewport={"width":1280,"height":900}); pg=ctx.new_page()
    errs=[]; failed=[]; responses=[]
    pg.on("console", lambda m: errs.append(m.type+": "+m.text[:300]) if m.type in ("error","warning") else None)
    pg.on("requestfailed", lambda r: failed.append(f"{r.method} {r.url[:120]} :: {r.failure}"))
    pg.on("pageerror", lambda e: errs.append("PAGEERROR: " + str(e)[:400]))
    pg.on("response", lambda r: responses.append((r.status, r.request.method, r.url[:130])) if r.status>=400 else None)
    try:
        pg.goto(url, wait_until="domcontentloaded", timeout=45000)
    except Exception as e:
        print("goto:", e)
    pg.wait_for_timeout(9000)
    state = pg.evaluate("""() => ({
      spinners: document.querySelectorAll('.LoadingIndicator, .loading-indicator, [class*=Loading]').length,
      posts: document.querySelectorAll('.Post, .PostStream-item').length,
      title: document.title,
      bodyLen: (document.body.innerText||'').length,
      hasStream: !!document.querySelector('.PostStream'),
      alert: (document.querySelector('.Alert')||{}).innerText || null
    })""")
    pg.screenshot(path=f"{OUT}/repro.png", full_page=False)
    print(json.dumps({"state":state,"consoleErrors":errs[:10],"failedRequests":failed[:10],"httpErrors":responses[:10]}, indent=2))
    b.close()
