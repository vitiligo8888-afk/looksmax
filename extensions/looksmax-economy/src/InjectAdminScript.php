<?php

namespace Local\Economy;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the admin settings screen as its own <script>, for the same reason the
 * forum bundle is one — see InjectScript.php and, for the original
 * measurement of what a top-level throw in the shared bundle does,
 * looksmax-userinfo/src/InjectAdminScript.php. The admin panel is where an
 * operator goes to turn OFF an extension that is misbehaving; it cannot be
 * the thing a misbehaving extension takes down.
 *
 * Every control registered here writes an `economy.*` setting that
 * src/Config.php reads, with the shipped constant as the fallback for
 * anything never touched. This is the direct fix for the audit's core
 * complaint: every award amount, daily cap, streak threshold and minimum
 * length used to be a PHP constant with no admin surface at all.
 */
class InjectAdminScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/admin.js');
        if ($js === false) {
            return;
        }

        $document->head[] = '<script data-lmx-economy-admin>try{' . $js . '}catch(e){console.warn("economy admin:",e)}</script>';
    }
}
