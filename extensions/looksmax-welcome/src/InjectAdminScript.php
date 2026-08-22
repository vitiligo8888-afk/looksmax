<?php

namespace Local\Welcome;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the (one-control) admin settings screen as its own <script>, wrapped in
 * its own try/catch — same shape and reasoning as
 * looksmax-userinfo/src/InjectAdminScript.php: the admin panel is where an
 * operator goes to turn a broken extension OFF, so it is the one bundle that
 * must never itself be what breaks.
 */
class InjectAdminScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/admin.js');

        if ($js === false) {
            return;
        }

        $document->head[] = '<script data-lmx-welcome-admin>try{' . $js . '}catch(e){console.warn("welcome admin:",e)}</script>';
    }
}
