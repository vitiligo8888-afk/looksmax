<?php

use Flarum\Extend;

/**
 * Iconify for Flarum.
 *
 * Flarum's icon() helper only speaks FontAwesome class names. This widens it to
 * any Iconify set (Phosphor, Lucide, Tabler, Material Symbols, Simple Icons and
 * ~150 others, around 200k icons) while leaving existing fa-* calls working, so
 * nothing in core or other extensions breaks.
 *
 * The API base defaults to the public Iconify endpoint but is meant to point at
 * a self-hosted iconify/api container, which keeps icon lookups on our own
 * infrastructure and works offline.
 */
return [
    // Injected as its own <script> rather than joined into the shared bundle.
    // Flarum concatenates every extension's JS into one file, so a top-level
    // throw anywhere kills every extension after it, and a malformed export
    // aborts bootExtensions and takes the whole SPA down. A separate element
    // has its own error boundary: if this breaks, only icons break.
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->content(Local\Icons\InjectScript::class),

    (new Extend\Frontend('admin'))
        ->content(Local\Icons\InjectScript::class),

    (new Extend\Settings())
        ->serializeToForum('icons.apiBase', 'icons.apiBase', null, 'https://api.iconify.design')
        ->serializeToForum('icons.map', 'icons.map'),
];
