<?php

namespace Local\Store;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The one settings screen this extension's own Config.php needs: the boost
 * stacking ceiling. See Config.php and Entitlements::boosts() for why this
 * exists — everything else the store sells is priced from `store_items`
 * (edited at /store/admin, the forum-side screen InjectAdminLink.php links
 * to), but the ceiling on how far STACKED boosts can push the earn rate is
 * not a property of any one catalogue row, so it lives here instead, in the
 * one place every other `Local\*\Config` class already puts its settings.
 *
 * Separate <script> element for the same reason as every other injector in
 * this codebase: a top-level throw anywhere upstream in the concatenated
 * admin bundle must not stop THIS extension's controls (or anyone else's)
 * from registering. See looksmax-userinfo/src/InjectAdminScript.php for the
 * original measurement.
 */
class InjectAdminScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/admin.js');
        if ($js === false) {
            return;
        }

        $document->head[] = '<script data-lmx-store-admin-settings>try{' . $js . '}catch(e){console.warn("store admin settings:",e)}</script>';
    }
}
