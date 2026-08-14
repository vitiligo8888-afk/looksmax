<?php

namespace Local\UserInfo;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the signature decorator as its own <script>.
 *
 * A SECOND injector rather than appending to js/dist/forum.js, which
 * InjectScript already ships. Two reasons:
 *
 *  1. Independent failure. forum.js is the hover card, the author rail and the
 *     DM panel — the identity surface the forum leans on. A throw introduced
 *     while editing signatures must not take that down, and inside one
 *     <script> it would.
 *  2. Independent review. The signature renderer is the file that handles
 *     attacker-controlled text destined for every page view; keeping it as its
 *     own artefact means "what renders a signature" is one small file rather
 *     than a region of a large one.
 */
class InjectSignature
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/signature.js');

        if ($js === false) {
            return;
        }

        // An HTML parser ends a script at the first valid end tag wherever it
        // appears, including inside a string literal.
        $safe = str_replace(['</', '<!--'], ['<\\/', '<\\!--'], $js);

        $document->head[] = '<script data-lmx-signature>' . $safe . '</script>';
    }
}
