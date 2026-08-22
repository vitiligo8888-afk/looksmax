<?php

namespace Local\Economy;

use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Referral invite card, client side.
 *
 * A small, dismissible "Invita y gana" card for logged-in members: their own
 * invite link (looksmax.lat/?ref=<their id>), a copy button, and their join /
 * qualified tallies read straight off the UserSerializer payload this
 * extension already emits (`lmxReferral`).
 *
 * Why inline and self-contained rather than a Mithril component: this
 * extension ships a BUILT js/dist bundle with no source tree in the repo, so
 * there is nothing to add a component to and rebuild. This script therefore
 * touches NONE of Flarum's own DOM, routing, or components — it polls for the
 * booted app, reads the session user, and appends one element of its own
 * making, wrapped in try/catch throughout. The worst a failure can do is log a
 * warning and render no card. It is emitted only when referral.enabled is on,
 * so the default install adds nothing. A proper in-app panel is the follow-up
 * for whoever holds the JS source.
 */
class InjectInviteWidget
{
    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        if (! (bool) Config::get($this->settings, 'referral.enabled')) {
            return;
        }

        $js = <<<'JS'
(function(){var n=0;var t=setInterval(function(){try{n++;if(n>50){clearInterval(t);return;}
if(!window.app||!app.session||typeof app.session==='undefined')return;var u=app.session.user;if(typeof u==='undefined')return;clearInterval(t);
if(!u)return;if(localStorage.getItem('lmxInviteHidden'))return;
var id=u.id?u.id():null;if(!id)return;
var r=u.attribute?u.attribute('lmxReferral'):null;var j=r&&r.joined||0;var q=r&&r.qualified||0;
var link=location.origin+'/?ref='+id;
var c=document.createElement('div');c.setAttribute('data-lmx-invite','');
c.style.cssText='position:fixed;right:16px;bottom:16px;z-index:2147483000;max-width:300px;background:#12161c;color:#e6e6e6;border:1px solid #2a2f38;border-radius:12px;padding:14px;font:13px/1.45 system-ui,-apple-system,sans-serif;box-shadow:0 8px 28px rgba(0,0,0,.45)';
c.innerHTML='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px"><b style="color:#e8c07d">Invita y gana</b><span data-x style="cursor:pointer;opacity:.55;padding:2px 4px">&#10005;</span></div><div style="opacity:.85;margin-bottom:9px">Comparte tu link. Ganas puntos cuando alguien se une con &eacute;l y m&aacute;s cuando se vuelve activo.</div><div style="display:flex;gap:6px;margin-bottom:9px"><input readonly style="flex:1;min-width:0;background:#0b0e12;border:1px solid #2a2f38;color:#cfd3da;border-radius:8px;padding:6px 8px;font-size:12px"><button data-c style="background:#e8c07d;color:#12161c;border:0;border-radius:8px;padding:6px 11px;font-weight:600;cursor:pointer;white-space:nowrap">Copiar</button></div><div style="opacity:.7;font-size:12px">'+j+' unidos &middot; '+q+' activos</div>';
c.querySelector('input').value=link;
document.body.appendChild(c);
c.querySelector('[data-x]').onclick=function(){c.remove();try{localStorage.setItem('lmxInviteHidden','1')}catch(e){}};
c.querySelector('[data-c]').onclick=function(){var i=c.querySelector('input');try{i.select();navigator.clipboard.writeText(link)}catch(e){try{document.execCommand('copy')}catch(_){}}this.textContent='&iexcl;Listo!';var b=this;setTimeout(function(){b.textContent='Copiar'},1500)};
}catch(e){clearInterval(t);console.warn('invite:',e)}},300);})();
JS;

        $document->head[] = '<script data-lmx-invite-widget>' . $js . '</script>';
    }
}
