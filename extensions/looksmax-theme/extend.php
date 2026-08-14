<?php

use Flarum\Extend;
use Local\Theme\InjectScheme;

/**
 * Theme extension.
 *
 * Flarum themes are ordinary extensions that ship LESS. Loading order matters:
 * this must come after core so its variable overrides win, which the admin
 * panel controls via extension order.
 */
return [
    // ORDER IS THE CASCADE. tokens first (everything downstream reads them),
    // base second (layering + page background — the two systemic things), then
    // chrome, then the component library, then the two content surfaces, then
    // motion last so a reduced-motion override cannot be undone by a component.
    //
    // less/forum.less used to sit between tokens and motion. It was the first
    // pass at this theme, written against hardcoded @surface-N hex, and it
    // carried the rule that caused the header bug:
    //   .App, .App-header, #content, .App-content { position: relative; z-index: 1 }
    // Every selector it owned is now covered by a token-driven file (its tag
    // tiles moved to tags.less verbatim in behaviour), so it is deleted rather
    // than left loaded and losing cascade fights silently.
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/tokens.less')
        ->css(__DIR__ . '/less/base.less')
        ->css(__DIR__ . '/less/chrome.less')
        ->css(__DIR__ . '/less/components.less')
        ->css(__DIR__ . '/less/tags.less')
        ->css(__DIR__ . '/less/content.less')
        ->css(__DIR__ . '/less/discussion.less')
        ->css(__DIR__ . '/less/motion.less')
        // Injected as its own <script> in <head>, NOT ->js(). It must run before
        // first paint to avoid a flash of the default scheme, and it must fail
        // independently of the extension bundle. See InjectScheme.
        ->content(InjectScheme::class),

    (new Extend\Frontend('admin'))
        ->css(__DIR__ . '/less/admin.less'),

    (new Extend\Theme())
        ->addCustomLessVariable('config-primary-color', fn () => '#e8c07d')
        // Follows the surface ramp to true black (tokens.less, 2026-08-14).
        ->addCustomLessVariable('config-secondary-color', fn () => '#000000')
        ->addCustomLessVariable('config-dark-mode', fn () => 'true'),
];
