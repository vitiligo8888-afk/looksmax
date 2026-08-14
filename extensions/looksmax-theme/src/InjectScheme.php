<?php

namespace Local\Theme;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the scheme switcher as its own <script>, first thing in <head>.
 *
 * Two rules this obeys, both for the same reasons InjectCosmetics documents:
 *
 *  1. A Flarum extension's JS is CONCATENATED into one bundle, so a bare IIFE
 *     appended there means one top-level throw kills every extension after it.
 *     A separate element is parsed and fails independently: the worst case here
 *     is "the forum is stuck on the default scheme", never "no forum".
 *  2. It must run BEFORE first paint. The whole point of reading the scheme
 *     synchronously is to avoid a white flash for anyone not on the default,
 *     which means this cannot wait for the bundle or for app.boot(). Being
 *     early in head is the feature, not an optimisation.
 *
 * The file is read from disk on each request rather than inlined at build time
 * because there is no build step in this deployment — js/dist/forum.js is the
 * source. It is ~3KB and sits in the opcache-adjacent page cache; if that ever
 * shows up in a profile, inline it at boot instead of adding a bundler.
 */
class InjectScheme
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/forum.js');

        if ($js === false) {
            return;
        }

        // An HTML parser ends a script at the first valid end tag wherever it
        // appears, including inside a string literal. Nothing below is
        // user-controlled today, but this file is edited by hand and the
        // failure mode is a truncated document that still returns 200.
        $safe = str_replace(['</', '<!--'], ['<\\/', '<\\!--'], $js);

        $document->head[] = '<script data-lmx-scheme>' . $safe . '</script>';
    }
}
