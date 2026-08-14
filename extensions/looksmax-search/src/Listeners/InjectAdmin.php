<?php

namespace Local\Search\Listeners;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/** Same independent-error-boundary reasoning as the forum bundle. */
class InjectAdmin
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../../js/dist/admin.js');
        if ($js === false) {
            return;
        }
        $document->head[] = '<script data-lmx-search-admin>try{' . $js . '}catch(e){console.warn("[lmx-search-admin]",e)}</script>';
    }
}
