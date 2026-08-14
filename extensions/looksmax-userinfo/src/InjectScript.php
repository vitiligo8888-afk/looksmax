<?php

namespace Local\UserInfo;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the author panel as its own <script> element.
 *
 * Flarum concatenates every extension's forum.js into one bundle and runs it
 * through bootExtensions. One top-level throw anywhere in that file stops every
 * extension registered after it, and a wrong export shape blanks the SPA
 * outright — both have already happened on this install (see
 * looksmax-icons/src/InjectScript.php for the measurement). A separate element
 * is parsed and fails independently: if this breaks, posts lose their author
 * panel and nothing else.
 *
 * The try/catch is not belt-and-braces. It is what keeps a syntax-level failure
 * in this file from reaching window.onerror, where the e2e harness counts it as
 * a page-health failure for every other lane too.
 */
class InjectScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/forum.js');
        if ($js === false) {
            return;
        }

        $document->head[] = '<script data-lmx-userinfo>try{' . $js . '}catch(e){console.warn("userinfo:",e)}</script>';
    }
}
