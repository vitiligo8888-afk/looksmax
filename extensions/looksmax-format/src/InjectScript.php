<?php

namespace Local\Format;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the post-content behaviours as their own script element.
 *
 * Flarum merges extension JS into a single bundle, where one top-level throw
 * stops every extension registered after it and a wrong export shape aborts
 * bootExtensions and blanks the SPA. An inline element is independently parsed
 * and independently fails: if this breaks, spoilers stop remembering their
 * state and nothing else changes.
 */
class InjectScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__.'/../js/dist/forum.js');
        if ($js === false) {
            return;
        }

        $document->head[] = '<script data-local-format>try{'.$js.'}catch(e){console.warn("format:",e)}</script>';
    }
}
