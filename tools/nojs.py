import sys
from playwright.sync_api import sync_playwright
url=sys.argv[1]
with sync_playwright() as p:
    b=p.chromium.launch()
    ctx=b.new_context(java_script_enabled=False, viewport={"width":1280,"height":900})
    pg=ctx.new_page()
    try:
        pg.goto(url, wait_until="domcontentloaded", timeout=40000)
        print("JS DISABLED -> loaded OK, title:", pg.title())
    except Exception as e:
        print("JS DISABLED -> still failed:", str(e)[:120])
    b.close()
