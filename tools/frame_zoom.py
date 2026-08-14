from playwright.sync_api import sync_playwright
OUT=r"C:\Users\chris\AppData\Local\Temp\claude\C--Users-chris-Documents\57221a13-8a26-4bdb-840e-d3ecb0e1f47b\scratchpad\shots"
PICK=['bronze-laurel','gold-laurel','blaze','frost','storm','void','cosmic','glitch','royal','nature','blood','venom']
BUILD="""(picks)=>{
  const frames=(window.__lmxCosDefs||{}).frame||{};
  document.body.innerHTML='';
  const wrap=document.createElement('div');
  wrap.style.cssText='display:grid;grid-template-columns:repeat(4,1fr);gap:56px;padding:56px;background:var(--bg)';
  for(const slug of picks){
    const d=frames[slug]; if(!d) continue;
    const cell=document.createElement('div');
    cell.style.cssText='display:flex;flex-direction:column;align-items:center;gap:14px';
    const fr=document.createElement('span');
    fr.className='LmxCosFrame lmx-frame is-live';
    fr.setAttribute('data-cf',slug);
    fr.setAttribute('data-cf-render',d.r||'ring');
    if(d.p&&d.p!=='none')fr.setAttribute('data-cf-pattern',d.p);
    if(d.s&&d.s!=='circle')fr.setAttribute('data-cf-shape',d.s);
    for(const [k,v] of Object.entries(d.c||{}))fr.style.setProperty(k,v);
    fr.style.setProperty('display','inline-block');
    const av=document.createElement('span'); av.className='Avatar';
    av.style.cssText='width:120px;height:120px;border-radius:50%;display:block;background:linear-gradient(135deg,#6a6a80,#33333f)';
    fr.appendChild(av);
    const l=document.createElement('div'); l.textContent=slug;
    l.style.cssText='font-size:13px;color:var(--ink-dim);font-family:var(--font-ui)';
    cell.appendChild(fr); cell.appendChild(l); wrap.appendChild(cell);
  }
  document.body.appendChild(wrap); return picks.length;
}"""
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_context(viewport={"width":1100,"height":1000}).new_page()
    pg.goto("http://localhost:8888/", wait_until="networkidle", timeout=45000)
    pg.wait_for_timeout(1200)
    n=pg.evaluate(BUILD,PICK); pg.wait_for_timeout(2000)
    pg.screenshot(path=OUT+r"\frames_zoom.png", full_page=True); print("rendered",n)
    b.close()
