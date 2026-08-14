<?php

namespace Local\Cosmetics;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Ship the decorator as its own <script>, with the render specs inlined.
 *
 * Two rules this obeys, both of which have already taken this forum down:
 *
 *  1. A Flarum extension's JS is CONCATENATED into one bundle. A bare IIFE
 *     appended there means one top-level throw kills every extension after it,
 *     and a malformed export aborts bootExtensions and blanks the entire SPA.
 *     A separate element is parsed and fails independently, so the worst case
 *     here is "no frames", never "no forum".
 *  2. `</` and `<!--` MUST be escaped before JSON goes inside a <script>. An
 *     HTML parser ends a script at the first valid end tag wherever it appears,
 *     including inside a string literal — looksmax-icons truncated the whole
 *     document that way and the page still returned 200.
 *
 * The specs are inlined rather than fetched because they are the same for every
 * visitor, they are ~4KB for nineteen cosmetics, and a frame that pops in a
 * second after the avatar is a worse artefact than no frame. The per-user part
 * (which slug each account wears) rides on the user payload the SPA already
 * has, so the common case makes NO network request at all.
 */
class InjectCosmetics
{
    public function __construct(protected Definitions $defs)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $js = @file_get_contents(__DIR__ . '/../js/dist/forum.js');
        if ($js === false) {
            return;
        }

        $payload = [];
        foreach ($this->defs->all() as $kind => $defs) {
            foreach ($defs as $slug => $def) {
                $payload[$kind][$slug] = [
                    'r' => $def['render'],
                    'q' => $def['rarity'],
                    'n' => $def['name'],
                    // The pattern selects a CSS rule rather than feeding one, so
                    // it travels as an attribute value and not as a property.
                    'p' => (string) ($def['spec']['pattern'] ?? 'none'),
                    // Same idea for a frame's silhouette: 'circle' (the default,
                    // no attribute needed) or one of the four non-circular
                    // shapes. It selects a CSS rule, so it is an attribute, not
                    // a custom property.
                    's' => (string) ($def['spec']['shape'] ?? 'circle'),
                    'c' => $def['css'],
                ];
            }
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $safe = str_replace(['</', '<!--'], ['<\\/', '<\\!--'], (string) $json);

        $document->head[] = '<script data-lmx-cosmetics-defs>try{window.__lmxCosDefs=' . $safe . ';}'
            . 'catch(e){console.warn("cosmetics: defs",e)}</script>';

        $document->head[] = '<script data-lmx-cosmetics>try{' . $js . '}'
            . 'catch(e){console.warn("cosmetics:",e)}</script>';
    }
}
