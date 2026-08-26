<?php

namespace Local\Economy;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * La ruleta diaria, lado cliente.
 *
 * Misma regla que el resto de superficies de esta extensión: script propio
 * añadido al documento, fuera del árbol que gestiona Mithril, porque estas
 * extensiones publican un js/dist ya construido y no hay árbol de fuentes que
 * compilar. Lo peor que puede pasar si algo falla aquí es que no haya ruleta.
 *
 * La librería (CrazyTim/spin-wheel, MIT — ver js/vendor/README.md) NO viaja en
 * este script: son 28 KB que solo hacen falta cuando alguien abre la ruleta, y
 * se piden una vez a /api/economy/wheel/lib.js, que las sirve cacheadas.
 *
 * El premio lo decide el servidor. Este código llama a POST /wheel/spin, recibe
 * un índice, y anima hacia él. No sortea nada: si lo hiciera, se ganaría con la
 * consola abierta.
 */
class InjectWheel
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = <<<'JS'
(function(){
var LIB='/api/economy/wheel/lib.js';
var host=null, wheel=null, girando=false, estado=null;

function csrf(){try{return app.session.csrfToken||'';}catch(e){return '';}}
function sesion(){try{return app.session.user||null;}catch(e){return null;}}

// Paleta: gris para lo comun, oro para lo gordo. El salto de color hace legible
// de un vistazo que segmentos valen la pena, sin leer un numero.
// El color separa las TRES cosas que puede haber en un segmento: nada, puntos,
// y cosmetico. Sin ese salto, el 1% se pierde entre cifras.
function colorDe(q){
  if(q.kind==='frame') return q.label==='Vacío' ? '#e8c07d' : '#8c2f2f';
  var p=q.points||0;
  if(p<=0)   return '#242424';   // los vacios, casi el fondo: no compiten con nada
  if(p>=500) return '#7a6743';
  if(p>=150) return '#5f5f5f';
  if(p>=50)  return '#4d4d4d';
  return '#3d3d3d';
}
function tintaDe(q){
  if(q.kind==='frame') return q.label==='Vacío' ? '#12161c' : '#ffe9e9';
  return (q.points||0)<=0 ? '#6e6e6e' : '#f9f9f9';
}
function etiqueta(q){
  if(q.kind==='frame') return q.label;
  return (q.points||0)<=0 ? '—' : String(q.points);
}

function cargarLib(){
  return new Promise(function(res,rej){
    if(window.spinWheel&&window.spinWheel.Wheel) return res();
    var s=document.createElement('script');
    s.src=LIB; s.async=true;
    s.onload=function(){ (window.spinWheel&&window.spinWheel.Wheel)?res():rej(new Error('sin Wheel')); };
    s.onerror=function(){ rej(new Error('no cargo')); };
    document.head.appendChild(s);
  });
}

function abrir(){
  if(document.querySelector('[data-lmx-wheel]')) return;
  host=document.createElement('div');
  host.setAttribute('data-lmx-wheel','');
  host.style.cssText='position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.66);display:flex;'
    +'align-items:flex-start;justify-content:center;padding:40px 14px;overflow:auto;'
    +'font:14px/1.45 system-ui,-apple-system,sans-serif';
  host.innerHTML='<div style="max-width:420px;width:100%;background:#2e2e2e;color:#f9f9f9;'
    +'border:1px solid #4d4d4d;box-shadow:0 20px 60px rgba(0,0,0,.55)">'
    +'<div style="display:flex;justify-content:space-between;align-items:center;padding:15px 18px;border-bottom:1px solid #4d4d4d">'
    +'<b style="font-size:16px">Ruleta diaria</b>'
    +'<span data-x style="cursor:pointer;opacity:.6;padding:2px 8px;font-size:18px">&#10005;</span></div>'
    +'<div data-cuerpo style="padding:16px 18px 20px"><div style="opacity:.7">Cargando&hellip;</div></div></div>';
  document.body.appendChild(host);
  host.addEventListener('click',function(e){ if(e.target===host) cerrar(); });
  host.querySelector('[data-x]').onclick=cerrar;
  cargar();
}

function cerrar(){
  if(girando) return;
  if(wheel&&wheel.remove){ try{wheel.remove();}catch(e){} }
  wheel=null;
  if(host){ host.remove(); host=null; }
  if(location.hash==='#ruleta'){ history.replaceState(null,'',location.pathname+location.search); }
}

function cuerpo(){ return host&&host.querySelector('[data-cuerpo]'); }

function cargar(){
  var b=cuerpo(); if(!b) return;
  Promise.all([
    fetch('/api/economy/wheel',{credentials:'same-origin'}).then(function(r){return r.json();}),
    cargarLib()
  ]).then(function(rs){
    estado=(rs[0]&&rs[0].data)||{};
    if(!estado.enabled){ b.innerHTML='<div style="opacity:.75">La ruleta esta apagada ahora mismo.</div>'; return; }
    pintar();
  }).catch(function(){
    b.innerHTML='<div style="opacity:.75">No se pudo cargar la ruleta. Intentalo en un momento.</div>';
  });
}

function pintar(){
  var b=cuerpo(); if(!b) return;
  var yo=sesion();
  // Cuadrado de verdad: con max-height el contenedor salia 383x320 y la
  // ruleta se dibujaba centrada en una caja rectangular, desperdiciando
  // ancho. max-width + aspect-ratio deja los dos lados iguales.
  b.innerHTML='<div data-lienzo style="width:100%;max-width:300px;aspect-ratio:1/1;margin:0 auto 14px"></div>'
    +'<div style="text-align:center;font-size:12.5px;opacity:.72;margin-bottom:10px">Premio mayor: '
    +'<b style="color:#e8c07d">marco '+((estado.jackpot&&estado.jackpot.label)||'')+'</b>'
    +' &middot; '+((estado.jackpot&&estado.jackpot.chance)||0)+'%</div>'
    +'<div data-msj style="min-height:22px;text-align:center;margin-bottom:12px;opacity:.85"></div>'
    +'<div data-pie></div>';

  var items=(estado.prizes||[]).map(function(p){
    return {label:etiqueta(p), backgroundColor:colorDe(p), labelColor:tintaDe(p)};
  });

  wheel=new window.spinWheel.Wheel(b.querySelector('[data-lienzo]'),{
    items: items,
    radius: 0.92,
    // Arrastrar la ruleta a mano dejaria girarla sin pedir premio al servidor:
    // parece que funciona y no paga nada. Solo el boton gira.
    isInteractive: false,
    borderWidth: 2,
    borderColor: '#4d4d4d',
    lineWidth: 1,
    lineColor: '#1a1a1a',
    itemLabelFontSizeMax: 22,
    itemLabelRadius: 0.86,
    itemLabelRadiusMax: 0.36,
    itemLabelAlign: 'right',
    pointerAngle: 0,
    rotationResistance: -40,
    onRest: function(){ girando=false; sincronizarPie(); }
  });

  // Ya giro hoy: la ruleta se coloca en el segmento ganado y se dice cual fue.
  // Apuntar al premio sin nombrarlo obliga a contar porciones para saber que
  // toco, que es justo el trabajo que la interfaz deberia ahorrar.
  if(estado.won!=null && estado.wonIndex!=null){
    try{ wheel.spinToItem(estado.wonIndex, 0, true, 0, 1, null); }catch(e){}
    if(estado.wonFrame){
      msj('Tu último giro te dio un marco nuevo', '#e8c07d');
    } else {
      msj(estado.won>0 ? ('Tu último giro dio '+estado.won+' puntos') : 'Tu último giro no sacó nada.',
          estado.won>0 ? '#e8c07d' : '#c0c0c0');
    }
  }

  if(!yo){ msj('Inicia sesion para girar.'); }
  sincronizarPie();
}

function msj(t,color){
  var m=host&&host.querySelector('[data-msj]');
  if(m){ m.textContent=t||''; m.style.color=color||'inherit'; }
}

var BTN='width:100%;border:0;padding:11px;font-weight:800;font-size:15px;cursor:pointer;';
var NOTA='text-align:center;opacity:.72;font-size:12.5px;margin-top:9px';

function boton(texto,fn){
  return '<button data-girar style="'+BTN+'background:#eaf0ff;color:#12161c">'+texto+'</button>';
}

function sincronizarPie(){
  var pie=host&&host.querySelector('[data-pie]'); if(!pie) return;
  var yo=sesion();

  if(!yo){
    pie.innerHTML='<a href="/login" style="display:block;text-align:center;background:#eaf0ff;color:#12161c;'
      +'padding:10px;font-weight:700;text-decoration:none">Acceder</a>';
    return;
  }
  if(girando){
    pie.innerHTML='<button disabled style="'+BTN+'background:#3a3a3a;color:#8a8a8a;cursor:default">Girando&hellip;</button>';
    return;
  }

  // El gratuito del dia manda: nunca se cobra mientras quede uno gratis.
  if(estado.freeAvailable){
    pie.innerHTML=boton('Girar gratis')
      +'<div style="'+NOTA+'">Tu giro diario. Despues puedes pagar '+estado.cost+' puntos por otro.</div>';
    pie.querySelector('[data-girar]').onclick=girar;
    return;
  }

  // De pago. El boton DICE el precio: cobrar sin que se lea el numero antes de
  // pulsar seria lo peor que podria hacer esta pantalla.
  if(estado.canSpinPaid){
    pie.innerHTML=boton('Girar por '+estado.cost+' puntos')
      +'<div style="'+NOTA+'">Llevas '+estado.paidToday+' de '+estado.paidMax
      +' giros de pago hoy &middot; Saldo: '+estado.balance+' puntos</div>';
    pie.querySelector('[data-girar]').onclick=girar;
    return;
  }

  // No puede: decir POR QUE, no solo que no.
  var motivo;
  if(estado.paidToday>=estado.paidMax){
    motivo='Ya gastaste tus '+estado.paidMax+' giros de pago de hoy. Vuelve en '+cuenta(estado.resetsIn||0)+'.';
  } else if(estado.balance<estado.cost){
    motivo='Te faltan '+(estado.cost-estado.balance)+' puntos para otro giro. Saldo: '+estado.balance+'.';
  } else {
    motivo='Vuelve en '+cuenta(estado.resetsIn||0)+' para tu giro gratis.';
  }
  pie.innerHTML='<div style="text-align:center;opacity:.75;font-size:13px">'+motivo+'</div>';
}

function cuenta(seg){
  var h=Math.floor(seg/3600), m=Math.floor((seg%3600)/60);
  if(h>0) return h+' h '+m+' min';
  if(m>0) return m+' min';
  return 'un momento';
}

function girar(){
  if(girando||!estado.canSpin) return;
  girando=true; msj(''); sincronizarPie();

  fetch('/api/economy/wheel/spin',{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrf()}})
   .then(function(r){ return r.json().then(function(d){ return {ok:r.ok,d:d}; }); })
   .then(function(res){
     if(!res.ok||!res.d||res.d.ok!==true){
       girando=false;
       msj((res.d&&res.d.error)||'No se pudo girar.','#ffb4a8');
       if(res.d&&res.d.state) estado=res.d.state;
       sincronizarPie();
       return;
     }
     estado=res.d.state||estado;
     var i=res.d.index, pts=res.d.points;

     // 6 vueltas y 4.2s: suficiente para que se lea como sorteo y no tanto como
     // para que de pereza. El destino ya lo decidio el servidor; esto es solo
     // como se llega hasta el.
     try{ wheel.spinToItem(i, 4200, true, 6, 1, null); }catch(e){ girando=false; }

     setTimeout(function(){
       girando=false;
       var neto = res.d.paid ? (pts - (res.d.cost||0)) : pts;
       var texto, tono='#c0c0c0';
       if(res.d.kind==='frame' && res.d.frame){
         texto='¡Ganaste el marco '+res.d.frameName+'! Ya está en tu vestuario.';
         tono='#e8c07d';
       } else if(res.d.already){
         // Repetido: decirlo, no disimularlo con un premio en puntos a secas.
         texto='Salió el marco '+res.d.frameName+', pero ya lo tenías: '+pts+' puntos en su lugar.';
         tono='#e8c07d';
       } else if(pts<=0){
         texto = res.d.paid ? ('Nada esta vez. −'+res.d.cost+' puntos.') : 'Nada esta vez.';
       } else if(res.d.paid){
         texto = 'Ganaste '+pts+' puntos (neto '+(neto>=0?'+':'')+neto+')';
         tono = neto>0 ? '#e8c07d' : '#c0c0c0';
       } else {
         texto = 'Ganaste '+pts+' puntos';
         tono = '#e8c07d';
       }
       msj(texto, tono);
       sincronizarPie();
       refrescarPuntos(neto);
     }, 4350);
   })
   .catch(function(){ girando=false; msj('No se pudo girar.','#ffb4a8'); sincronizarPie(); });
}

// El chip de puntos de la cabecera lo pinta otra extension; sumarle en sitio
// evita que el usuario tenga que recargar para ver lo que acaba de ganar.
function refrescarPuntos(delta){
  try{
    var u=sesion(); if(!u) return;
    if(u.data&&u.data.attributes&&typeof u.data.attributes.points==='number'){
      u.data.attributes.points+=delta;
    }
    if(window.m&&m.redraw) m.redraw();
  }catch(e){}
}

document.addEventListener('click',function(e){
  var a=e.target&&e.target.closest?e.target.closest('a[href="#ruleta"]'):null;
  if(a){ e.preventDefault(); abrir(); }
},true);
if(location.hash==='#ruleta'){ setTimeout(abrir,400); }
window.addEventListener('hashchange',function(){ if(location.hash==='#ruleta') abrir(); });

// Entrada en la cabecera, junto a las misiones. Mithril repinta ese <ul>, asi
// que se reinserta con un observer en lugar de pelear con el diff — mismo
// patron que InjectQuests.
function item(){
  var ul=document.querySelector('.Header-secondary > ul');
  if(!ul || ul.querySelector('[data-lmx-wheel-nav]')) return;
  var li=document.createElement('li');
  li.className='item-lmxWheel'; li.setAttribute('data-lmx-wheel-nav','');
  li.innerHTML='<a href="#ruleta" class="Button Button--link lmxHdr-link" title="Ruleta diaria" aria-label="Ruleta diaria">'
    +'<iconify-icon icon="lmx:wheel" aria-hidden="true"></iconify-icon></a>';
  var q=ul.querySelector('.item-lmxQuests');
  if(q&&q.nextSibling) ul.insertBefore(li,q.nextSibling);
  else if(q) ul.appendChild(li);
  else ul.insertBefore(li, ul.firstChild);
}
function boot(){
  item();
  var h=document.querySelector('.Header-secondary');
  if(!h) return false;
  new MutationObserver(item).observe(h,{childList:true,subtree:true});
  return true;
}
if(!boot()){ var n=0, iv=setInterval(function(){ if(boot()||++n>40) clearInterval(iv); },250); }
})();
JS;

        $document->head[] = '<script data-lmx-wheel-panel>try{' . $js . '}catch(e){console.warn("wheel:",e)}</script>';
    }
}
