<?php

namespace Local\Store\Listeners;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A "Store" entry in the admin panel's nav that opens the store's own admin
 * screen on the forum side.
 *
 * The management UI deliberately lives at /store/admin on the forum rather
 * than inside the admin SPA. It has to show the catalogue the way a member
 * sees it — the real card, the real colour, the real price after a tier
 * discount — and rebuilding those components a second time inside the admin
 * bundle would guarantee the two drift. The admin panel gets the doorway.
 *
 * Injected as a small standalone script and re-attached on mutation, because
 * the admin nav is Mithril-rendered and a one-shot append is wiped by the
 * first redraw.
 */
class InjectAdminLink
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = <<<'JS'
(function () {
  'use strict';
  function mount() {
    var nav = document.querySelector('.App-nav .Dropdown-menu, .AdminNav .Dropdown-menu, nav.AdminNav ul');
    if (!nav || nav.querySelector('.lmx-store-adminlink')) return;
    var li = document.createElement('li');
    li.className = 'lmx-store-adminlink';
    var a = document.createElement('a');
    a.className = 'Button Button--link';
    a.href = '/store/admin';
    a.target = '_blank';
    a.rel = 'noopener';
    a.textContent = (window.app && app.translator) ? app.translator.trans('local-looksmax-store.admin.nav.store') : 'Store';
    a.title = (window.app && app.translator) ? app.translator.trans('local-looksmax-store.admin.nav.store_title') : 'Catalogue, orders, balances and the audit trail';
    li.appendChild(a);
    nav.appendChild(li);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
  new MutationObserver(function () { mount(); })
    .observe(document.documentElement, { childList: true, subtree: true });
})();
JS;

        $document->foot[] = '<script data-lmx-store-admin>try{' . $js . '}catch(e){console.warn("store admin link:",e)}</script>';
    }
}
