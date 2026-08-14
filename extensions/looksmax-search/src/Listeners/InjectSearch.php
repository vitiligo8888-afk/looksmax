<?php

namespace Local\Search\Listeners;

use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the search UI as its own script element.
 *
 * Flarum concatenates every extension's forum JS into a single bundle. One
 * top-level throw there stops every extension registered after it, and a
 * malformed export aborts `bootExtensions` and blanks the entire SPA — both
 * have happened in this stack. An inline element is parsed and fails
 * independently, so a bug in search breaks search and nothing else.
 *
 * The config goes in as JSON rather than being interpolated into the script,
 * so a forum title containing a quote cannot produce a syntax error that takes
 * the whole file with it.
 */
class InjectSearch
{
    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../../js/dist/forum.js');
        if ($js === false) {
            return;
        }

        $config = [
            'enabled' => (bool) ($this->settings->get('looksmax-search.enabled') ?? true),
            'palette' => (bool) ($this->settings->get('looksmax-search.paletteEnabled') ?? true),
            'minChars' => (int) ($this->settings->get('looksmax-search.minChars') ?: 2),
            'debounceMs' => (int) ($this->settings->get('looksmax-search.debounceMs') ?: 120),
        ];

        $document->head[] = '<script id="lmx-search-config" type="application/json">'
            . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . '</script>';
        $document->head[] = '<script data-lmx-search>try{' . $js . '}catch(e){console.warn("[lmx-search]",e)}</script>';
    }
}
