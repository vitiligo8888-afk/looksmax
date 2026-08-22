<?php

namespace Local\Store\Listeners;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Oro wallet, client side.
 *
 * A self-contained launcher pill (shows the member's oro balance) and a modal
 * that does the two things the paid currency needs a surface for: BUY oro with
 * money (the packs, through the mock card checkout) and SPEND oro on premium
 * items. Both go through the real /api/store/purchase endpoint, so everything
 * the backend enforces — pricing, stock, idempotency, grants — applies exactly
 * as it does to the points store.
 *
 * Why inline and self-contained rather than a Mithril component in the store's
 * bundle: this extension ships a BUILT js/dist with no source tree in the repo,
 * and the header is redrawn by Mithril, so anything injected INTO it is wiped
 * on the next redraw. This script therefore appends its own fixed element to
 * <body> (outside Mithril's managed tree) and never touches Flarum's DOM,
 * routing or components — the worst a failure can do is log a warning and show
 * no pill. Guests get nothing. A proper in-header chip is the follow-up for
 * whoever holds the store's JS source.
 */
class InjectOroWallet
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = <<<'JS'
(function(){
var tries=0, iv=setInterval(function(){
  try{
    tries++; if(tries>60){clearInterval(iv);return;}
    if(!window.app||!app.session||typeof app.session==='undefined')return;
    var u=app.session.user; if(typeof u==='undefined')return;
    clearInterval(iv);
    if(!u)return;               // guest: no wallet
    boot(u);
  }catch(e){clearInterval(iv);console.warn('oro:',e);}
},300);

function csrf(){try{return app.session.csrfToken||'';}catch(e){return '';}}
function oroOf(u){try{return u.attribute('oro')||0;}catch(e){return 0;}}
function fmt(n){return (n||0).toLocaleString('es-MX');}
function money(cents){return '$'+((cents||0)/100).toFixed(2);}

var GOLD='#e8c07d', INK='#12161c', LINE='#2a2f38', TXT='#e6e6e6';
var balance=0, host;

function boot(u){
  balance=oroOf(u);
  var pill=document.createElement('button');
  pill.setAttribute('data-lmx-oro','');
  pill.style.cssText='position:fixed;right:16px;top:64px;z-index:2147482000;display:flex;align-items:center;gap:6px;'
    +'background:'+INK+';color:'+GOLD+';border:1px solid '+LINE+';border-radius:999px;padding:6px 12px;'
    +'font:600 13px/1 system-ui,-apple-system,sans-serif;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.4)';
  pill.innerHTML='<span style="font-size:14px">&#9670;</span> <span data-oro-n>'+fmt(balance)+'</span> Oro';
  document.body.appendChild(pill);
  pill.onclick=open;
}

function setBalance(n){
  balance=(n==null?balance:n);
  var el=document.querySelector('[data-oro-n]'); if(el)el.textContent=fmt(balance);
  var b=document.querySelector('[data-modal-bal]'); if(b)b.textContent=fmt(balance);
  try{app.session.user.pushAttributes({oro:balance});}catch(e){}
}

function open(){
  if(document.querySelector('[data-oro-modal]'))return;
  host=document.createElement('div'); host.setAttribute('data-oro-modal','');
  host.style.cssText='position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.6);display:flex;'
    +'align-items:flex-start;justify-content:center;padding:40px 14px;overflow:auto;font:14px/1.45 system-ui,-apple-system,sans-serif';
  host.innerHTML='<div data-card style="max-width:520px;width:100%;background:'+INK+';color:'+TXT+';border:1px solid '+LINE
    +';border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.5)">'
    +'<div style="display:flex;justify-content:space-between;align-items:center;padding:16px 18px;border-bottom:1px solid '+LINE+'">'
    +'<div style="font-weight:700;font-size:16px">&#9670; Oro</div>'
    +'<div style="opacity:.85">Tienes <b data-modal-bal style="color:'+GOLD+'">'+fmt(balance)+'</b> Oro</div>'
    +'<span data-x style="cursor:pointer;opacity:.6;padding:2px 6px;font-size:18px">&#10005;</span></div>'
    +'<div data-status style="display:none;margin:12px 18px 0;padding:9px 12px;border-radius:9px;font-size:13px"></div>'
    +'<div data-body style="padding:14px 18px 18px"><div style="opacity:.7">Cargando&hellip;</div></div></div>';
  document.body.appendChild(host);
  host.addEventListener('click',function(e){if(e.target===host)close();});
  host.querySelector('[data-x]').onclick=close;
  load();
}
function close(){if(host){host.remove();host=null;}}

function status(msg,ok){
  var s=document.querySelector('[data-status]'); if(!s)return;
  s.style.display='block';
  s.style.background=ok?'rgba(158,206,106,.15)':'rgba(247,118,142,.15)';
  s.style.color=ok?'#9ece6a':'#f7768e';
  s.textContent=msg;
}

function load(){
  fetch('/api/store/oro',{credentials:'same-origin',headers:{'X-CSRF-Token':csrf()}})
    .then(function(r){return r.json();})
    .then(render)
    .catch(function(){document.querySelector('[data-body]').innerHTML='<div style="opacity:.7">La tienda no está disponible ahora.</div>';});
}

function render(data){
  if(data&&data.me&&typeof data.me.oro!=='undefined')setBalance(data.me.oro);
  var packs=(data&&data.packs)||[], items=(data&&data.items)||[];
  var h='';
  h+='<div style="font-weight:600;margin:2px 0 8px">Comprar Oro</div>';
  h+='<div style="opacity:.6;font-size:12px;margin-bottom:10px">El pago con tarjeta es de prueba por ahora y no cobra nada.</div>';
  h+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">';
  packs.forEach(function(p){
    h+='<div style="border:1px solid '+LINE+';border-radius:12px;padding:11px 12px;display:flex;flex-direction:column;gap:7px">'
      +'<div style="font-weight:700;color:'+GOLD+'">&#9670; '+fmt(p.payload&&p.payload.amount||0)+'</div>'
      +'<div style="opacity:.75;font-size:12px">'+money(p.money)+'</div>'
      +'<button data-buy-pack="'+p.sku+'" style="margin-top:2px;background:'+GOLD+';color:'+INK+';border:0;border-radius:8px;padding:7px 0;font-weight:700;cursor:pointer">Comprar</button></div>';
  });
  h+='</div>';
  if(items.length){
    h+='<div style="font-weight:600;margin:16px 0 8px">Gastar Oro</div><div style="display:flex;flex-direction:column;gap:8px">';
    items.forEach(function(it){
      var can=it.affordable && !it.locked;
      h+='<div style="border:1px solid '+LINE+';border-radius:12px;padding:11px 12px;display:flex;align-items:center;gap:10px">'
        +'<div style="flex:1;min-width:0"><div style="font-weight:600">'+esc(it.name)+'</div>'
        +'<div style="opacity:.7;font-size:12px">'+esc(it.blurb||'')+'</div>'
        +(it.locked?'<div style="color:#f7768e;font-size:12px;margin-top:3px">'+esc(it.locked)+'</div>':'')+'</div>'
        +'<div style="text-align:right"><div style="font-weight:700;color:'+GOLD+';white-space:nowrap">&#9670; '+fmt(it.price)+'</div>'
        +'<button data-buy-item="'+it.sku+'" '+(can?'':'disabled')+' style="margin-top:5px;background:'+(can?GOLD:'#2a2f38')+';color:'+(can?INK:'#6b7280')+';border:0;border-radius:8px;padding:6px 12px;font-weight:700;cursor:'+(can?'pointer':'not-allowed')+'">Comprar</button></div></div>';
    });
    h+='</div>';
  }
  var body=document.querySelector('[data-body]'); if(!body)return;
  body.innerHTML=h;
  body.querySelectorAll('[data-buy-pack]').forEach(function(b){b.onclick=function(){buy(b.getAttribute('data-buy-pack'),'card',b);};});
  body.querySelectorAll('[data-buy-item]').forEach(function(b){b.onclick=function(){buy(b.getAttribute('data-buy-item'),'oro',b);};});
}

function esc(s){var d=document.createElement('div');d.textContent=s==null?'':String(s);return d.innerHTML;}

function buy(sku,provider,btn){
  if(btn){btn.disabled=true;btn.innerHTML='&hellip;';}
  fetch('/api/store/purchase',{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrf()},
    body:JSON.stringify({sku:sku,provider:provider,key:'oro-'+sku+'-'+Date.now()})})
  .then(function(r){return r.json().then(function(d){return {ok:r.ok,d:d};});})
  .then(function(res){
    if(res.ok&&res.d&&res.d.ok){
      if(typeof res.d.oroBalance!=='undefined')setBalance(res.d.oroBalance);
      var g=res.d.granted||{};
      status(g.oro?('+'+fmt(g.oro)+' Oro'):'¡Comprado!',true);
      load();
    }else{
      status((res.d&&res.d.error)||'No se pudo completar la compra.',false);
      load();
    }
  })
  .catch(function(){status('Error de red. Intenta de nuevo.',false);load();});
}
})();
JS;

        $document->head[] = '<script data-lmx-oro-wallet>' . $js . '</script>';
    }
}
