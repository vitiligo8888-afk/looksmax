"""Screenshot the sections block in LIST view (the forums list) at both sizes."""
from playwright.sync_api import sync_playwright
OUT = r"C:\Users\chris\AppData\Local\Temp\claude\C--Users-chris-Documents\57221a13-8a26-4bdb-840e-d3ecb0e1f47b\scratchpad\shots"
with sync_playwright() as p:
    b = p.chromium.launch()
    for name, (w, h) in {"desktop": (1440, 900), "mobile": (390, 844)}.items():
        c = b.new_context(viewport={"width": w, "height": h})
        pg = c.new_page()
        errs = []
        pg.on("console", lambda m: errs.append(m.text) if m.type == "error" else None)
        pg.goto("http://localhost:8888/", wait_until="domcontentloaded")
        pg.evaluate("localStorage.setItem('lmxIndexView','list')")
        pg.goto("http://localhost:8888/", wait_until="networkidle", timeout=45000)
        pg.wait_for_timeout(1500)
        el = pg.query_selector(".LmxSections")
        (el or pg).screenshot(path=f"{OUT}/listview_{name}.png")
        print(name, "ok", "errors:", errs[:3])
        c.close()
    b.close()
