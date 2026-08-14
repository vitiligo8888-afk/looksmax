from playwright.sync_api import sync_playwright
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_context(viewport={"width":1280,"height":900}).new_page()
    pg.goto("https://looksmax.lat/", wait_until="domcontentloaded", timeout=40000)
    pg.wait_for_timeout(8000)
    print(pg.evaluate("""() => {
      const a=document.querySelector('.Alert');
      if(!a) return {found:false};
      const cs=getComputedStyle(a); const r=a.getBoundingClientRect();
      let hiddenAncestor=null, n=a;
      while(n && n!==document.body){ const s=getComputedStyle(n);
        if(s.display==='none'||s.visibility==='hidden'){hiddenAncestor=(n.className||'').toString().slice(0,50);break;} n=n.parentElement; }
      return {found:true, display:cs.display, visibility:cs.visibility, w:Math.round(r.width), h:Math.round(r.height),
              onScreen: r.width>0&&r.height>0, hiddenAncestor, parent:(a.parentElement.className||'').toString().slice(0,60)};
    }"""))
    b.close()
