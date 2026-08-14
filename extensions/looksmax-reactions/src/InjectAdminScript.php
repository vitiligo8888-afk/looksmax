<?php

namespace Local\Reactions;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/** Same isolation rationale as InjectScript, for the admin bundle. */
class InjectAdminScript
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/admin.js');
        if ($js === false) {
            return;
        }

        $document->head[] = '<script data-lmx-reactions-admin>try{' . $js
            . '}catch(e){console.warn("reactions admin:",e)}</script>';
    }
}
