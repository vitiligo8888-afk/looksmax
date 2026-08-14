"""Strip one inline <script data-X> from the HTML and see if the page still crashes."""
import sys, re
from playwright.sync_api import sync_playwright
url = sys.argv[1]
marker = sys.argv[2] if len(sys.argv) > 2 else None

def run(marker):
    with sync_playwright() as p:
        b = p.chromium.launch()
        ctx = b.new_context(viewport={"width":1280,"height":900})
        pg = ctx.new_page()
        if marker:
            def handler(route):
                r = route.fetch()
                body = r.text()
                # remove just that one script element
                pat = re.compile(r"<script " + re.escape(marker) + r"[^>]*>.*?</script>", re.S)
                new, n = pat.subn("", body)
                route.fulfill(response=r, body=new, headers={**r.headers, "content-length": str(len(new))})
            pg.route(lambda u: u.rstrip("/").endswith("tretinoin"), handler)
        ok = True; note = ""
        try:
            pg.goto(url, wait_until="domcontentloaded", timeout=30000)
            pg.wait_for_timeout(6000)
            st = pg.evaluate("() => ({posts: document.querySelectorAll('.Post').length, stream: !!document.querySelector('.PostStream')})")
            note = str(st)
        except Exception as e:
            ok = False; note = str(e)[:90]
        b.close()
        return ok, note

ok, note = run(marker)
print(("PASS " if ok else "CRASH") + f"  stripped={marker or '(nothing)'}  {note}")
