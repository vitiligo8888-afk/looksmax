<?php

namespace Local\I18n;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship js/dist/forum.js as its own <script>.
 *
 * The house pattern on this install, and the reasoning is in
 * looksmax-reactions/src/InjectScript.php: Flarum concatenates every
 * extension's forum.js into a single bundle, so one top-level throw stops every
 * extension registered after it. This lane's script publishes the formatting
 * helpers other lanes call and the sentinel the deploy gate reads, so it is the
 * last thing that should be able to take out the page it is measuring.
 */
class InjectScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__.'/../js/dist/forum.js');

        if ($js === false) {
            return;
        }

        $document->head[] = '<script data-lmx-i18n>try{'.$js
            .'}catch(e){console.warn("i18n:",e)}</script>';
    }
}
