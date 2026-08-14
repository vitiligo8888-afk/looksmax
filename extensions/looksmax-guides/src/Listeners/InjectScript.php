<?php

namespace Local\Guides\Listeners;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship this extension's JS in its own <script> element instead of relying on
 * the shared forum.js bundle.
 *
 * This is not a stylistic preference. Measured on this install, in Chromium
 * 149 headless:
 *
 *   Uncaught ReferenceError: XSLTProcessor is not defined
 *     at init (…/assets/forum.js:335)
 *
 * Line 335 is `xslt.init(xsl)`, executed at TOP LEVEL of the s9e/TextFormatter
 * preview module that flarum/markdown contributes. Chrome has removed
 * XSLTProcessor, so that call throws during evaluation of the concatenated
 * bundle — and an uncaught throw at script top level aborts the rest of that
 * script. Everything after line 335 never runs. The bundle is 1,150 lines and
 * every extension's JS lives after 335.
 *
 * The evidence that this is real and not theoretical: on this forum right now,
 * `document.querySelectorAll('iconify-icon').length === 0`. The Iconify shim
 * is installed, enabled, present in the bundle, and completely inert, with no
 * console error attributable to it, because it is downstream of the throw.
 *
 * A separate <script> element has its own error boundary: a throw in one
 * script does not stop the next. So this extension's frontend survives any
 * top-level failure in any other extension's bundle, which — given the
 * ecosystem sweep found 90 override conflicts and a live 164-extension test
 * that hit four distinct fatal classes — is the correct default for anything
 * that has to keep working.
 *
 * Cost: one uncached inline script (~14 KB) instead of a slice of a cached
 * bundle. That is a good trade for a feature that is otherwise silently
 * absent.
 */
class InjectScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $path = __DIR__ . '/../../js/dist/forum.js';

        if (!is_readable($path)) {
            return;
        }

        $js = file_get_contents($path);

        if ($js === false || $js === '') {
            return;
        }

        // Guard against the closing-tag injection that inlining always risks.
        // The source is our own file, but this costs nothing and means a
        // future string in the bundle cannot break the document.
        $js = str_replace('</script', '<\/script', $js);

        $document->foot[] = '<script data-src="looksmax-guides">' . $js . '</script>';
    }
}
