<?php

namespace Local\Welcome;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the forum bundle as its own <script>.
 *
 * Same reasoning as looksmax-userinfo/src/InjectScript.php and
 * InjectSignature.php: Flarum concatenates every extension's registered
 * forum.js into one bundle, so a top-level throw anywhere in that bundle
 * stops every extension after it. An independently-injected script fails
 * alone — a bug in the survey overlay must never be able to take the hover
 * card, the composer or anything else down with it.
 */
class InjectScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/forum.js');

        if ($js === false) {
            return;
        }

        // An HTML parser ends a <script> at the first valid end tag wherever
        // it appears, including inside a string literal.
        $safe = str_replace(['</', '<!--'], ['<\\/', '<\\!--'], $js);

        $document->head[] = '<script data-lmx-welcome>' . $safe . '</script>';
    }
}
