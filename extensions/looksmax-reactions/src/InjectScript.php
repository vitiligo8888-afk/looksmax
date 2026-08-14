<?php

namespace Local\Reactions;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the frontend as its own <script> element rather than through
 * Extend\Frontend::js().
 *
 * This is the house pattern on this install and it is not stylistic. Flarum
 * concatenates every extension's forum.js into one bundle and runs it through
 * bootExtensions: one top-level throw anywhere in that file stops every
 * extension registered after it, and a wrong export shape blanks the SPA
 * outright. Both have already happened here, and this lane's brief opens by
 * noting that another extension took the whole site to HTTP 500 an hour ago.
 * An inline element is parsed independently and fails independently — if this
 * script is broken, reactions break and nothing else does.
 *
 * It also means editing js/dist/forum.js takes effect on the next page load
 * with no build step, which matters because the app container has no node.
 */
class InjectScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/forum.js');
        if ($js === false) {
            return;
        }

        $document->head[] = '<script data-lmx-reactions>try{' . $js
            . '}catch(e){console.warn("reactions:",e)}</script>';
    }
}
