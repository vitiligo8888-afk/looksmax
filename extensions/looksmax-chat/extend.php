<?php

use Flarum\Extend;
use Local\Chat\Api\ChatController;
use Local\Chat\RenderChat;

/**
 * The shoutbox.
 *
 * The forum-facing JS is injected as its own <script> element rather than added
 * to the shared bundle. Flarum concatenates every extension's JS into one file,
 * so a top-level throw anywhere kills every extension after it; an inline
 * element has its own error boundary. The same conclusion looksmax-icons and
 * looksmax-ranks reached, for the same reason.
 *
 * It also has to be able to mount into `.LmxIndex-side`, which looksmax-index
 * builds as raw DOM outside Mithril and re-creates on redraw — a Mithril
 * component cannot own a node that another extension replaces underneath it.
 */
return [
    (new Extend\Routes('api'))
        ->get('/chat', 'chat.list', ChatController::class)
        ->post('/chat', 'chat.post', ChatController::class)
        ->delete('/chat/{id:[0-9]+}', 'chat.delete', ChatController::class),

    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->content(RenderChat::class),

    (new Extend\Locales(__DIR__ . '/locale')),
];
