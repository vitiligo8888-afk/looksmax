"""Screenshot a specific selector. Usage: shoot_el.py <label> <path> <selector>"""
import sys
from playwright.sync_api import sync_playwright
label, path, sel = sys.argv[1], sys.argv[2], sys.argv[3]
OUT=r"C:\Users\chris\AppData\Local\Temp\claude\C--Users-chris-Documents\57221a13-8a26-4bdb-840e-d3ecb0e1f47b\scratchpad\shots"
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_context(viewport={"width":1440,"height":1000}).new_page()
    pg.goto("http://localhost:8888"+path, wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(1800)
    el=pg.query_selector(sel)
    (el or pg).screenshot(path=f"{OUT}/{label}.png")
    print("ok", sel)
    b.close()
