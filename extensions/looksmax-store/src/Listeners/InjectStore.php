<?php

namespace Local\Store\Listeners;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the store's JS as its own <script> in the HEAD, not in the shared
 * bundle.
 *
 * Two independent reasons, both measured on this install rather than assumed:
 *
 * 1. The shared bundle is not reliable here. flarum/markdown's s9e preview
 *    module calls `new XSLTProcessor` at top level (assets/forum.js line 336 of
 *    458 on 2026-08-13); Chrome has removed XSLTProcessor, so every extension
 *    registration after that line — they all live at 377+ — never runs. An
 *    extension that needs a ROUTE to exist cannot be downstream of that.
 *
 * 2. A route has to be registered before the app boots. Flarum's boot script
 *    runs after the bundle, and `$document->foot` lands after boot, which is
 *    too late for m.route. The head is the only place that runs early enough,
 *    and from there the file installs a property hook on `window.flarum` and
 *    registers the route the moment core assigns `flarum.core` — before
 *    `flarum.core.app.boot()` on the next script element.
 *
 * The whole file is wrapped in try/catch. If any of it fails the forum is
 * unchanged and `/store` still returns a real page, because the server-side
 * route is registered by the Frontend extender and does not depend on this at
 * all.
 */
class InjectStore
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../../js/dist/forum.js');

        if ($js === false || $js === '') {
            return;
        }

        $js = str_replace('</script', '<\/script', $js);

        $document->head[] = '<script data-lmx-store>try{' . $js . '}catch(e){console.warn("store:",e)}</script>';
    }
}
