<?php

namespace Local\Ranks;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the identity layer as its own script element.
 *
 * Flarum merges every extension's JS into a single bundle, so one top-level
 * throw stops every extension registered after it and a wrong export shape
 * aborts bootExtensions and blanks the entire SPA. Both have happened on this
 * stack. An inline element is parsed independently and fails independently: if
 * this breaks, names lose their colour and nothing else changes.
 *
 * It waits for the DOM rather than for the app, so it does not care where in
 * the page it lands or which extensions loaded before it.
 */
class InjectIdentity
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/forum.js');
        if ($js === false) {
            return;
        }

        $document->head[] = '<script data-lmx-identity>try{' . $js . '}catch(e){console.warn("identity:",e)}</script>';
    }
}
