<?php

namespace Local\Economy;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the streak/progress widget as its own <script> element, not inside the
 * shared forum bundle.
 *
 * Same reasoning as every other injector in this codebase (see
 * looksmax-userinfo/src/InjectScript.php for the original measurement): a
 * top-level throw anywhere upstream in the concatenated bundle stops every
 * extension registered after it, and this extension previously had NO js/
 * directory at all — there was nothing to break. Now that it does, it gets
 * the same isolation every other surface on this forum already has, from day
 * one rather than after the first incident.
 */
class InjectScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/forum.js');
        if ($js === false || $js === '') {
            return;
        }

        $document->head[] = '<script data-lmx-economy>try{' . $js . '}catch(e){console.warn("economy:",e)}</script>';
    }
}
