#!/usr/bin/env bun
/**
 * Search UI audit — measures what actually renders, then screenshots it.
 *
 * Nothing in here asserts a pass. It reports numbers, because every defect the
 * operator has found on this surface was invisible to a boolean check and
 * obvious in a measurement or a picture: a highlight that never painted, a
 * dropdown that hit-tests to the element behind it, a 170px search field on a
 * 390px screen.
 *
 *   export PATH=/root/.bun/bin:$PATH
 *   cd /work/flarum && bun extensions/looksmax-search/e2e/ui-audit.ts --out /work/flarum/search-ui
 *   ... --login 1     # the same sweep with a session
 */
import { mkdirSync, writeFileSync } from "node:fs";
import { Browser } from "../../../e2e/visual/cdp.ts";

const arg = (n: string, d: string) => {
  const i = process.argv.indexOf(`--${n}`);
  return i === -1 ? d : process.argv[i + 1];
};
const BASE = arg("base", "http://127.0.0.1:8888");
const OUT = arg("out", "/work/flarum/search-ui");
const PORT = Number(arg("port", "21850"));
const LOGIN = arg("login", "") === "1";
/* The forum negotiates locale from Accept-Language before falling back to
 * settings.default_locale (which is `es`). Headless Chrome says en-US, so an
 * unconfigured run measures the ENGLISH strings. `locale=es` is the cookie the
 * forum's own switcher writes — verified by driving that switcher and reading
 * document.cookie back. */
const LOCALE = arg("locale", "");
mkdirSync(OUT, { recursive: true });

const report: any[] = [];
function say(label: string, value: any) {
  report.push({ label, value });
  console.log(`  ${label.padEnd(44)} ${typeof value === "object" ? JSON.stringify(value) : value}`);
}

/* Relative luminance / contrast, over real computed rgb()/color() strings.
 * `opaqueBg` walks up until it finds a non-transparent fill, because every
 * surface in this theme is a tint of the one below it. */
const CONTRAST_FN = `
  function rgb(s){
    var m=String(s).match(/[\\d.]+/g)||[];
    var nums=m.map(Number);
    // color(srgb r g b / a) gives 0..1 components; rgb() gives 0..255.
    if(/^color\\(/.test(String(s))) nums=nums.map(function(v,i){ return i<3? v*255 : v; });
    return nums;
  }
  function lum(c){ return c.slice(0,3).map(function(v){ v/=255; return v<=0.03928? v/12.92 : Math.pow((v+0.055)/1.055,2.4); })
    .reduce(function(a,x,i){ return a + x*[0.2126,0.7152,0.0722][i]; },0); }
  function over(fg, bg){ var f=rgb(fg), b=(bg&&bg.length)?bg:[0,0,0]; var a=f.length>3? f[3]:1;
    return [0,1,2].map(function(i){ return f[i]*a + b[i]*(1-a); }); }
  function ratio(fg,bg){ var L1=lum(fg),L2=lum(bg); var hi=Math.max(L1,L2),lo=Math.min(L1,L2);
    return Math.round(((hi+0.05)/(lo+0.05))*100)/100; }
  function opaqueBg(el){
    var n=el, stack=[];
    while(n && n.nodeType===1){
      var c=getComputedStyle(n).backgroundColor, m=rgb(c);
      if(m.length && (m.length<4 || m[3]>0)) { if(m.length>3 && m[3]<1) stack.push(c); else { var base=rgb(c);
        for(var i=stack.length-1;i>=0;i--) base=over(stack[i], base); return base; } }
      n=n.parentElement;
    }
    var base=rgb(getComputedStyle(document.body).backgroundColor||'rgb(7,9,16)');
    for(var j=stack.length-1;j>=0;j--) base=over(stack[j], base);
    return base;
  }
  function contrastOf(el){ var cs=getComputedStyle(el); var bg=opaqueBg(el.parentElement||el);
    var ownBg=rgb(cs.backgroundColor); var under = (ownBg.length>3 && ownBg[3]>0)? over(cs.backgroundColor,bg) : bg;
    return ratio(over(cs.color, under), under); }
`;

/* Every point-based hit test in one place, so a "it paints on top" claim is
 * always the composited page answering, never a z-index read off a stylesheet. */
const HITTEST_FN = `
  function hitTest(surface){
    if(!surface) return {surface:'none'};
    var r=surface.getBoundingClientRect();
    var pts=[[r.x+r.width/2, r.y+6],[r.x+r.width/2, r.y+r.height/2],[r.x+10, r.y+r.height-6],[r.right-10, r.y+r.height/2]];
    var hits=pts.map(function(p){
      var e=document.elementFromPoint(p[0],p[1]);
      if(!e) return 'null(offscreen)';
      return (surface.contains(e)||e===surface) ? 'INSIDE' : 'BLOCKED:'+e.tagName.toLowerCase()+'.'+String(e.className||'').slice(0,44);
    });
    var chain=[], n=surface.parentElement;
    while(n && n!==document.documentElement){
      var cs=getComputedStyle(n);
      var why=[];
      if(cs.transform!=='none') why.push('transform');
      if(cs.filter!=='none') why.push('filter');
      if(cs.backdropFilter&&cs.backdropFilter!=='none') why.push('backdrop-filter');
      if(cs.perspective!=='none') why.push('perspective');
      if(cs.willChange!=='auto') why.push('will-change:'+cs.willChange);
      if(String(cs.contain).indexOf('paint')>=0) why.push('contain:paint');
      if(cs.isolation==='isolate') why.push('isolation');
      if(cs.opacity!=='1') why.push('opacity:'+cs.opacity);
      if(cs.mixBlendMode!=='normal') why.push('blend');
      if(cs.position!=='static'&&cs.zIndex!=='auto') why.push('z:'+cs.zIndex+'/'+cs.position);
      if(why.length) chain.push(n.tagName.toLowerCase()+'.'+String(n.className||'').slice(0,30)+' ['+why.join(' ')+']');
      n=n.parentElement;
    }
    return { box:{x:Math.round(r.x),y:Math.round(r.y),w:Math.round(r.width),h:Math.round(r.height)},
             hits: hits, z: getComputedStyle(surface).zIndex,
             stackingAncestors: chain };
  }
`;

const PRELUDE = CONTRAST_FN + HITTEST_FN;

async function main() {
  const b = await Browser.launch(PORT, "1440,1000");
  if (LOCALE) {
    await b.send("Network.setCookie", { name: "locale", value: LOCALE, domain: "127.0.0.1", path: "/" });
  }
  let who = "anon";
  if (LOGIN) {
    who = String(await b.login("admin", "1IxmV2IMZ8cnulvIHBT0", BASE));
    say("login", who);
  }
  const tag = (LOGIN ? "in" : "out") + (LOCALE ? "-" + LOCALE : "");
  say("locale", await b.eval(`document.documentElement.lang + " / cookie:" + document.cookie`).catch(() => "?"));

  // ------------------------------------------------------- highlighter, unit
  // Exercised through the exported hook rather than inferred from pixels: an
  // offset bug in the folder is invisible in a screenshot until it eats a
  // letter, and these are the cases (accents, ñ, Cyrillic, phrases) that eat it.
  await b.viewport({ name: "1440", width: 1440, height: 1000 });
  await b.goto(`${BASE}/`);
  say("highlighter-unit", await b.eval(`(function(){
    var hl = window.lmxSearch && window.lmxSearch._hl;
    if(!hl) return {available:false};
    function probe(text, q){
      var t = hl.terms(null, q);
      var rs = hl.ranges(text, t);
      return rs.map(function(r){ return text.slice(r[0], r[1]); });
    }
    return {
      available: true,
      accent_query_plain:  probe('Cirugía de nariz y mandíbula', 'cirugia'),
      accent_query_marked: probe('Cirugia de nariz', 'cirugía'),
      enye:                probe('el niño tiene buen mentón', 'nino menton'),
      cyrillic:            probe('Обычный ПАРЕНЬ и его парень', 'парень'),
      cyrillic_yo:         probe('ещё раз', 'еще'),
      phrase:              probe('a mewing guide for beginners', '"mewing guide"'),
      prefix_ok:           probe('mewing works', 'mew'),
      midword_rejected:    probe('looksmaxing', 'ooks'),
      negatives_skipped:   probe('jaw cope thread', 'jaw -cope'),
      operators_skipped:   probe('serious jaw thread', 'jaw tag:serious sort:new'),
      offsets_exact:       (function(){ var s='niño ñu'; var t=hl.terms(null,'nino'); var r=hl.ranges(s,t);
                              return r.length===1 && s.slice(r[0][0],r[0][1])==='niño'; })()
    };
  })()`));

  for (const vp of [
    { name: "1440", width: 1440, height: 1000 },
    { name: "390", width: 390, height: 844, mobile: true },
  ]) {
    await b.viewport(vp);
    const W = vp.name;

    // ---------------------------------------------------------- results page
    b.resetNetwork();
    await b.goto(`${BASE}/search?q=mewing`);
    await Bun.sleep(900);
    await b.fullShot(`${OUT}/${tag}-${W}-results.png`);
    say(`${W} results-page`, await b.eval(`(function(){ ${PRELUDE}
      var page=document.querySelector('#lmx-search-page');
      var rows=[].slice.call(document.querySelectorAll('.lmx-r'));
      var marks=[].slice.call(document.querySelectorAll('#lmx-search-page mark'));
      var chips=[].slice.call(document.querySelectorAll('#lmx-search-page .lmx-tag'));
      var chipRatios=chips.map(function(c){ return contrastOf(c.querySelector('.lmx-tag-n')||c); });
      var markRatios=marks.slice(0,25).map(function(m){ return contrastOf(m); });
      var r=page&&page.getBoundingClientRect();
      var counts={};
      rows.forEach(function(n){ var k=n.getAttribute('data-kind')||'?'; counts[k]=(counts[k]||0)+1; });
      var live=document.querySelector('#lmx-search-page [aria-live]');
      return {
        pageWidth: r? Math.round(r.width):0,
        rows: rows.length, kinds: counts,
        marks: marks.length,
        markContrastMin: markRatios.length? Math.min.apply(null,markRatios):null,
        markContrastMax: markRatios.length? Math.max.apply(null,markRatios):null,
        avatars: document.querySelectorAll('#lmx-search-page .lmx-av').length,
        avatarGenerated: document.querySelectorAll('#lmx-search-page .lmx-av--gen').length,
        avatarImages: document.querySelectorAll('#lmx-search-page .lmx-av--img').length,
        tagChips: chips.length,
        tagChipContrastMin: chipRatios.length? Math.round(Math.min.apply(null,chipRatios)*100)/100 : null,
        tagChipsBelowAA: chipRatios.filter(function(x){return x<4.5;}).length,
        distinctCatColours: (function(){ var s={}; chips.forEach(function(c){ s[getComputedStyle(c).getPropertyValue('--cat').trim()]=1; }); return Object.keys(s).length; })(),
        facetBtns: document.querySelectorAll('.lmx-facet-v').length,
        dateInputs: document.querySelectorAll('.lmx-date').length,
        ariaLive: live? live.textContent.slice(0,40) : null,
        ariaLiveCount: document.querySelectorAll('#lmx-search-page [aria-live]').length,
        sortOptions: document.querySelectorAll('.lmx-sort option').length,
        overflowX: document.documentElement.scrollWidth > window.innerWidth ? document.documentElement.scrollWidth : 0,
        contentDisplay: getComputedStyle(document.querySelector('#content')||document.body).display,
        titleSample: (document.querySelector('.lmx-r-title')||{}).textContent
      };
    })()`));
    if (b.consoleErrors.length) say(`${W} console`, b.consoleErrors.slice(0, 4));
    const bad = b.badResponses.filter((r) => !/api\/tags\?include/.test(r.url));
    if (bad.length) say(`${W} bad-http`, bad.slice(0, 6));
    if (b.failedImages.length) say(`${W} failed-images`, b.failedImages.slice(0, 4));

    // ------------------------------------------------- filtered + URL + back
    await b.eval(`(function(){ var f=document.querySelectorAll('.lmx-facet-v'); if(f[0]) f[0].click(); })()`);
    await Bun.sleep(1200);
    await b.shot(`${OUT}/${tag}-${W}-filtered.png`);
    const filtered = await b.eval(`(function(){
      return { url: location.pathname+location.search,
               chips: [].slice.call(document.querySelectorAll('.lmx-chip')).map(function(c){return c.textContent.trim();}),
               rows: document.querySelectorAll('.lmx-r').length,
               count: (document.querySelector('.lmx-s-count')||{}).textContent };
    })()`);
    say(`${W} filtered`, filtered);

    await b.send("Page.navigateToHistoryEntry", { entryId: 0 }).catch(() => {});
    await b.eval(`history.back()`);
    await Bun.sleep(1400);
    say(`${W} back-button`, await b.eval(`(function(){
      return { url: location.pathname+location.search,
               chips: document.querySelectorAll('.lmx-chip').length,
               rows: document.querySelectorAll('.lmx-r').length,
               count: (document.querySelector('.lmx-s-count')||{}).textContent };
    })()`));

    // ----------------------------------------------- chip removal round-trip
    await b.goto(`${BASE}/search?q=${encodeURIComponent('mewing tag:"Best of the Best"')}`);
    await Bun.sleep(900);
    const beforeChip = await b.eval(`(function(){ return {url:location.search, chips:document.querySelectorAll('.lmx-chip').length}; })()`);
    await b.eval(`(function(){ var c=document.querySelector('.lmx-chip:not(.lmx-chip--clear)'); if(c) c.click(); })()`);
    await Bun.sleep(1200);
    say(`${W} chip-remove (quoted+capitalised)`, {
      before: beforeChip,
      after: await b.eval(`(function(){ return {url:decodeURIComponent(location.search), chips:document.querySelectorAll('.lmx-chip').length, rows:document.querySelectorAll('.lmx-r').length}; })()`),
    });

    // ------------------------------------------------------- empty /search
    await b.goto(`${BASE}/search`);
    await Bun.sleep(1100);
    await b.fullShot(`${OUT}/${tag}-${W}-landing.png`);
    say(`${W} landing`, await b.eval(`(function(){
      var p=document.querySelector('#lmx-search-page');
      return { heading: (document.querySelector('.lmx-landing-hero h2')||{}).textContent,
               sections: [].slice.call(document.querySelectorAll('.lmx-land-sec h3')).map(function(h){return h.textContent.trim();}),
               tagChips: document.querySelectorAll('.lmx-land-tags .lmx-tag').length,
               operators: document.querySelectorAll('.lmx-op').length,
               recentChips: document.querySelectorAll('.lmx-qchip').length,
               stillSaysNothingMatched: /Nothing matched|No encontramos nada/.test(p? p.innerText : '') };
    })()`));

    // ---------------------------------------------------------- zero results
    await b.goto(`${BASE}/search?q=zzqqxwv`);
    await Bun.sleep(1500);
    await b.fullShot(`${OUT}/${tag}-${W}-zero.png`);
    say(`${W} zero-results`, await b.eval(`(function(){
      return { heading: (document.querySelector('.lmx-empty-hero h2')||{}).textContent,
               sub: (document.querySelector('.lmx-empty-hero p')||{}).textContent,
               offers: [].slice.call(document.querySelectorAll('.lmx-empty-sug')).map(function(s){return s.textContent.trim().slice(0,60);}),
               tagsOffered: document.querySelectorAll('.lmx-empty-tags .lmx-tag').length };
    })()`));

    // zero results WITH a stem that exists, to prove the probe offers something
    await b.goto(`${BASE}/search?q=mewingggg`);
    await Bun.sleep(2000);
    await b.shot(`${OUT}/${tag}-${W}-zero-stem.png`);
    say(`${W} zero-results (recoverable)`, await b.eval(`(function(){
      return { offers: [].slice.call(document.querySelectorAll('.lmx-empty-sug')).map(function(s){return s.textContent.trim().slice(0,70);}) };
    })()`));

    // ------------------------------------------------------ accents/cyrillic
    for (const [q, name] of [["nariz", "es"], ["парень", "ru"], ["\"mewing guide\"", "phrase"], ["mandibula", "accent-fold"]] as const) {
      await b.goto(`${BASE}/search?q=${encodeURIComponent(q)}`);
      await Bun.sleep(800);
      say(`${W} hl:${name} "${q}"`, await b.eval(`(function(){
        var marks=[].slice.call(document.querySelectorAll('#lmx-search-page mark'));
        return { rows: document.querySelectorAll('.lmx-r').length, marks: marks.length,
                 sample: marks.slice(0,6).map(function(m){return m.textContent;}) };
      })()`));
      if (W === "1440") await b.shot(`${OUT}/${tag}-${W}-hl-${name}.png`);
    }

    // ------------------------------------------------------------ loading
    // Throttled so the skeletons are on screen when the shutter opens; this is
    // the state, not a mock of it.
    await b.goto(`${BASE}/search?q=mewing`);
    await Bun.sleep(700);
    await b.eval(`(function(){
      var of_=window.fetch;
      window.fetch=function(u,o){ if(String(u).indexOf('/api/looksmax/search')>=0)
        return new Promise(function(r){ setTimeout(function(){ r(of_(u,o)); }, 6000); });
        return of_(u,o); };
      var f=document.querySelector('.lmx-tab:not(.lmx-tab-on)'); if(f) f.click();
    })()`);
    await Bun.sleep(900);
    await b.shot(`${OUT}/${tag}-${W}-loading.png`);
    say(`${W} loading`, await b.eval(`(function(){
      var page=document.querySelector('#lmx-search-page');
      return { skeletons: document.querySelectorAll('.lmx-skel-row').length,
               headerStillThere: !!document.querySelector('.lmx-s-form'),
               tabsStillThere: !!document.querySelector('.lmx-tabs'),
               loadingClass: page? page.className : null };
    })()`));

    // ------------------------------------------------------------- error
    await b.goto(`${BASE}/search?q=mewing`);
    await Bun.sleep(700);
    await b.eval(`(function(){
      window.fetch=function(u){ if(String(u).indexOf('/api/looksmax/search')>=0)
        return Promise.resolve(new Response('{"error":"engine exploded"}',{status:503,statusText:'Service Unavailable'}));
        return Promise.reject(new Error('blocked')); };
      var f=document.querySelector('.lmx-tab:not(.lmx-tab-on)'); if(f) f.click();
    })()`);
    await Bun.sleep(1400);
    await b.shot(`${OUT}/${tag}-${W}-error.png`);
    say(`${W} error-state`, await b.eval(`(function(){
      var e=document.querySelector('.lmx-error');
      return { present: !!e, role: e? e.getAttribute('role'):null,
               text: e? e.innerText.replace(/\\s+/g,' ').slice(0,180):null,
               retryButton: !!document.querySelector('.lmx-error .lmx-btn') };
    })()`));

    // ------------------------------------------- header field + its dropdown
    await b.goto(`${BASE}/`);
    await Bun.sleep(500);
    say(`${W} header-field`, await b.eval(`(function(){
      var inp=document.querySelector('.Search-input input')||document.querySelector('.Search input');
      var btn=document.querySelector('#lmx-hdr-search');
      var bb=btn&&btn.getBoundingClientRect();
      var out={ mobileSearchButton: btn? {visible:getComputedStyle(btn.parentElement).display!=='none',
                 x:Math.round(bb.x),y:Math.round(bb.y),w:Math.round(bb.width),h:Math.round(bb.height)} : null };
      if(inp){
        var r=inp.getBoundingClientRect();
        var before=getComputedStyle(inp.parentElement,'::before');
        out.input={ w:Math.round(r.width), h:Math.round(r.height), x:Math.round(r.x), y:Math.round(r.y),
                    onScreen: r.x >= 0 && r.right <= window.innerWidth,
                    placeholder: inp.placeholder, role: inp.getAttribute('role'),
                    ariaExpanded: inp.getAttribute('aria-expanded') };
        // NOT OUR FILE: looksmax-theme/less/chrome.less:239 .Search-input::before
        out.glyph={ w: before.width, h: before.height, square: before.width===before.height,
                    left: before.left, top: before.top, bg: before.backgroundColor };
      }
      return out;
    })()`));

    // open it the way a person does
    if (vp.mobile) {
      await b.eval(`(function(){ var b=document.querySelector('#lmx-hdr-search'); if(b) b.click(); })()`);
    } else {
      await b.eval(`(function(){ var i=document.querySelector('.Search-input input'); if(i) i.focus(); })()`);
    }
    await Bun.sleep(400);
    await b.eval(`(function(){
      var i=(document.querySelector('.lmx-dd--sheet .lmx-dd-input')) || document.querySelector('.Search-input input');
      if(!i) return; i.focus(); i.value='mew';
      i.dispatchEvent(new Event('input',{bubbles:true}));
    })()`);
    await Bun.sleep(1600);
    await b.shot(`${OUT}/${tag}-${W}-dropdown.png`);
    say(`${W} dropdown (on index)`, await b.eval(`(function(){ ${PRELUDE}
      var dd=document.querySelector('#lmx-dd');
      var open=dd&&dd.classList.contains('lmx-open');
      var box=dd&&dd.querySelector('.lmx-dd-box');
      var anchor=document.querySelector('.Search-input input');
      var out=Object.assign({ open: !!open, mode: dd? dd.className : null,
        rows: dd? dd.querySelectorAll('.lmx-r').length : 0,
        marks: dd? dd.querySelectorAll('mark').length : 0,
        avatars: dd? dd.querySelectorAll('.lmx-av').length : 0,
        groups: dd? [].slice.call(dd.querySelectorAll('.lmx-dd-group')).map(function(g){return g.textContent.trim();}) : [],
        status: dd? (dd.querySelector('.lmx-dd-status')||{}).textContent : null,
        parentOfPopup: dd? dd.parentElement.tagName : null,
        nativeSearchResultsDisplay: (function(){ var n=document.querySelector('.Search-results');
          return n? getComputedStyle(n).display : 'absent'; })(),
        aria: anchor? { role:anchor.getAttribute('role'), expanded:anchor.getAttribute('aria-expanded'),
          activedescendant:anchor.getAttribute('aria-activedescendant'), controls:anchor.getAttribute('aria-controls') } : null,
        optionRoles: dd? dd.querySelectorAll('[role=option]').length : 0,
        listboxRoles: dd? dd.querySelectorAll('[role=listbox]').length : 0
      }, hitTest(box));
      return out;
    })()`));

    // arrow-key navigation, Enter target, Escape focus restore
    say(`${W} keyboard in dropdown`, await b.eval(`(async function(){
      var dd=document.querySelector('#lmx-dd');
      var inp=(dd&&dd.classList.contains('lmx-dd--sheet'))? dd.querySelector('.lmx-dd-input') : document.querySelector('.Search-input input');
      if(!inp) return {input:'none'};
      function key(k){ inp.dispatchEvent(new KeyboardEvent('keydown',{key:k,bubbles:true,cancelable:true})); }
      var sel0=dd.querySelector('.lmx-sel');
      key('ArrowDown'); key('ArrowDown');
      var sel2=dd.querySelector('.lmx-sel');
      key('ArrowUp');
      var sel1=dd.querySelector('.lmx-sel');
      var ad=inp.getAttribute('aria-activedescendant');
      key('Escape');
      await new Promise(function(r){setTimeout(r,120);});
      return { firstSelected: sel0? sel0.id : null,
               movedDown: sel2? sel2.id : null,
               movedBackUp: sel1? sel1.id : null,
               activedescendantTracks: ad,
               activedescendantMatchesSelection: !!(sel1 && ad === sel1.id),
               closedOnEscape: !document.querySelector('#lmx-dd.lmx-open'),
               focusRestoredTo: document.activeElement? (document.activeElement.className||document.activeElement.tagName) : null };
    })()`, true));

    // ------------------------------------------------------- keyboard: "/"
    await b.goto(`${BASE}/`);
    await Bun.sleep(500);
    await b.send("Input.dispatchKeyEvent", { type: "keyDown", key: "/", code: "Slash", text: "/", windowsVirtualKeyCode: 191 });
    await b.send("Input.dispatchKeyEvent", { type: "keyUp", key: "/", code: "Slash", windowsVirtualKeyCode: 191 });
    await Bun.sleep(600);
    await b.shot(`${OUT}/${tag}-${W}-slash.png`);
    say(`${W} slash-key`, await b.eval(`(function(){
      var dd=document.querySelector('#lmx-dd');
      var a=document.activeElement;
      return { surfaceOpen: !!(dd&&dd.classList.contains('lmx-open')),
               mode: dd? (dd.classList.contains('lmx-dd--sheet')?'sheet':'dropdown') : null,
               focused: a? (a.className||a.tagName) : null,
               focusIsATextField: !!(a && a.tagName==='INPUT'),
               slashTyped: !!(a && a.value && a.value.indexOf('/')>=0) };
    })()`));

    // Ctrl+K
    await b.goto(`${BASE}/`);
    await Bun.sleep(400);
    await b.send("Input.dispatchKeyEvent", { type: "keyDown", key: "k", code: "KeyK", modifiers: 2, windowsVirtualKeyCode: 75 });
    await Bun.sleep(600);
    await b.shot(`${OUT}/${tag}-${W}-ctrlk.png`);
    say(`${W} ctrl-k`, await b.eval(`(function(){
      var dd=document.querySelector('#lmx-dd');
      return { open: !!(dd&&dd.classList.contains('lmx-open')),
               mode: dd? (dd.classList.contains('lmx-dd--sheet')?'sheet':'dropdown') : null,
               focused: document.activeElement? (document.activeElement.className||document.activeElement.tagName):null };
    })()`));

    // --------------------------------- dropdown over a SCROLLED discussion
    await b.goto(`${BASE}/d/1482-mewing-guide-detailed`);
    await Bun.sleep(900);
    await b.eval(`window.scrollTo(0, 1400)`);
    await Bun.sleep(500);
    if (vp.mobile) await b.eval(`(function(){ var x=document.querySelector('#lmx-hdr-search'); if(x) x.click(); })()`);
    else await b.eval(`(function(){ var i=document.querySelector('.Search-input input'); if(i) i.focus(); })()`);
    await Bun.sleep(400);
    await b.eval(`(function(){ var i=document.querySelector('.lmx-dd--sheet .lmx-dd-input')||document.querySelector('.Search-input input');
      if(i){ i.focus(); i.value='mew'; i.dispatchEvent(new Event('input',{bubbles:true})); } })()`);
    await Bun.sleep(1600);
    await b.shot(`${OUT}/${tag}-${W}-dropdown-scrolled.png`);
    say(`${W} dropdown (scrolled discussion)`, await b.eval(`(function(){ ${PRELUDE}
      var dd=document.querySelector('#lmx-dd');
      var box=dd&&dd.querySelector('.lmx-dd-box');
      return Object.assign({ open: !!(dd&&dd.classList.contains('lmx-open')),
        rows: dd? dd.querySelectorAll('.lmx-r').length:0 }, hitTest(box));
    })()`));

    // ------------------------------------------ mobile filter sheet (390 only)
    if (vp.mobile) {
      await b.goto(`${BASE}/search?q=mewing`);
      await Bun.sleep(1000);
      await b.eval(`(function(){ var t=document.querySelector('.lmx-filters-toggle'); if(t) t.click(); })()`);
      await Bun.sleep(600);
      await b.shot(`${OUT}/${tag}-390-filters-sheet.png`);
      say(`390 filter sheet`, await b.eval(`(function(){ ${PRELUDE}
        var f=document.querySelector('.lmx-facets');
        return Object.assign({ open: document.documentElement.classList.contains('lmx-facets-open'),
          facetButtons: document.querySelectorAll('.lmx-facet-v').length,
          dateInputs: document.querySelectorAll('.lmx-date').length }, hitTest(f));
      })()`));
    }
  }

  writeFileSync(`${OUT}/report-${tag}.json`, JSON.stringify(report, null, 2));
  console.log(`\n  wrote ${OUT}/report-${tag}.json`);
  await b.close();
}

main().catch((e) => { console.error("AUDIT FAILED", e); process.exit(1); });
