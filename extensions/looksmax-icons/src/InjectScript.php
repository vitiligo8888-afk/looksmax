<?php

namespace Local\Icons;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the icon layer as its own script element.
 *
 * Flarum merges extension JS into a single bundle, which means one top-level
 * throw stops every extension registered after it, and a wrong export shape
 * aborts bootExtensions and blanks the entire SPA. Both happened here. An
 * inline element is independently parsed and independently fails.
 *
 * It waits for the app rather than assuming load order, so it does not care
 * where in the page it lands.
 */
class InjectScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $dist = __DIR__ . '/../js/dist';

        /*
         * Order matters and is the whole point of this method.
         *
         *   1. the custom element definition, from OUR origin
         *   2. the icon geometry for every icon this forum can render,
         *      registered synchronously via Iconify.addCollection
         *   3. only then the swap script
         *
         * With (1) and (2) inline and synchronous, an <iconify-icon> has its
         * geometry the moment it is parsed, so it always has an intrinsic size.
         * The previous version loaded (1) from code.iconify.design and let each
         * element fetch its own geometry from api.iconify.design: measured on
         * /all, only 68 of 122 icons had resolved after five seconds and 25 had
         * zero size, which a flex parent then stretched into the "big weird
         * rotating icon" the operator reported.
         *
         * It also means the forum makes no third-party requests for icons at
         * all, so no visitor's browser reports our icon usage to anyone.
         *
         * ~122KB uncompressed for the pair, served gzipped with the document and
         * cached with it — cheaper than the round trips it replaces.
         */
        $component = @file_get_contents($dist . '/iconify-icon.min.js');
        $icons = @file_get_contents($dist . '/icons.json');
        $js = @file_get_contents($dist . '/forum.js');

        if ($js === false) {
            return;
        }

        if ($component !== false) {
            $document->head[] = '<script data-local-icons-component>' . $component . '</script>';
        }

        if ($icons !== false && json_decode($icons) !== null) {
            // addCollection takes one set at a time; the bundle is keyed by set.
            /*
             * addCollection lives on the CUSTOM ELEMENT CLASS, not on a global.
             * The bundle ends with `for (const t in s) o[t] = o.prototype[t] =
             * s[t]; e.define(t, o)` — the whole API is copied onto the element
             * constructor. Calling window.Iconify.addCollection (the name the
             * standalone iconify library uses) silently does nothing, and every
             * icon then falls back to fetching itself from the CDN: measured as
             * 200s to api.iconify.design still in the network log after the
             * bundle was already inlined.
             */
            /*
             * `</` MUST be escaped before this goes inside a <script> element.
             *
             * The icon bodies are raw SVG markup — "<path .../></svg>" — and an
             * HTML parser ends a <script> at the first "</" that starts a valid
             * end tag, wherever it appears, including inside a string literal.
             * Embedding the bundle unescaped therefore truncated the document
             * mid-head: everything after it, INCLUDING the index template and
             * its mount script, was discarded, and the front page rendered with
             * no forum rows at all. The page still returned 200.
             */
            $safe = str_replace(['</', '<!--'], ['<\\/', '<\\!--'], $icons);

            $document->head[] = '<script data-local-icons-data>try{'
                . 'var d=' . $safe . ';'
                . 'var A=(window.customElements&&window.customElements.get("iconify-icon"))||window.IconifyIcon||window.Iconify;'
                . 'if(A&&A.addCollection){for(var k in d){A.addCollection(d[k]);}}'
                . 'else{console.warn("icons: no addCollection on the component");}'
                . '}catch(e){console.warn("icons: bundle",e)}</script>';
        }

        $document->head[] = '<script data-local-icons>try{' . $js . '}catch(e){console.warn("icons:",e)}</script>';
    }
}
