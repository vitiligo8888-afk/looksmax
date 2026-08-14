import json,sys
from playwright.sync_api import sync_playwright
slug=sys.argv[1]
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_context().new_page()
    pg.goto("http://localhost:8888/", wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(1000)
    print(json.dumps(pg.evaluate("(s)=>((window.__lmxCosDefs||{}).frame||{})[s]", slug), indent=2)); b.close()
