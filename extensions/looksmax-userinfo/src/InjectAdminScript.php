<?php

namespace Local\UserInfo;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the admin settings screen as its own <script>, for the same reason the
 * forum bundle is one: a top-level throw inside the concatenated admin bundle
 * stops every extension registered after it, and the admin panel is where you
 * go to turn a broken extension OFF.
 *
 * See src/InjectScript.php for the measurement.
 */
class InjectAdminScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/admin.js');
        if ($js === false) {
            return;
        }

        $document->head[] = '<script data-lmx-userinfo-admin>try{' . $js . '}catch(e){console.warn("userinfo admin:",e)}</script>';
    }
}
