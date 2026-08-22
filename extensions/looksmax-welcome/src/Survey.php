<?php

namespace Local\Welcome;

/**
 * The "why did you join" catalogue — the ONE place that knows which option ids
 * exist, and therefore the ONE place SurveyController's `sanitize()` and the
 * client's rendered chips both read from. There is no second list anywhere:
 * a chip the client cannot show cannot be submitted (JS renders exactly this
 * array), and an id the server does not recognise cannot be stored
 * (`sanitize()` drops anything not in `OPTIONS`) — see SurveyController's
 * class header for why that direction of trust matters.
 *
 * ── two categories, one table shape ─────────────────────────────────────────
 * `section` mirrors the six front-page sections this forum actually has
 * (`Local\Index\Sections`, in the sibling `looksmax-index` extension) — same
 * six keys, same order, same colours. The hex values below are COPIED from
 * that class's own docblock rather than read from it at runtime: this
 * extension does not depend on looksmax-index being installed, enabled, or
 * unchanged, and a survey chip rendering the wrong colour is a much smaller
 * failure than a chip failing to render at all because a sibling lane's class
 * disappeared. If the six colours in Sections.php are ever regenerated, copy
 * the new hex values across by hand — grep both files for the value table.
 *
 * `intent` is the operator's own six motivations, verbatim from the brief:
 * "vengo a aprender", "quiero que me califiquen", "vengo por las guías",
 * "quiero mejorar mi piel/pelo", "me interesa cirugía", "quiero entrenar".
 * Deliberately given their OWN keys rather than reusing the section keys —
 * "I came for the guides" (an intent) and "Mejores Guías" (a section) are two
 * different signals that happen to correlate, and collapsing them would lose
 * exactly the distinction a future recommender wants: someone who picks
 * BOTH is a stronger signal than someone who picks either alone.
 *
 * ── icons ────────────────────────────────────────────────────────────────
 * looksmax-icons self-hosts a curated subset of Iconify, not the whole
 * library — an unbundled name renders as an empty square, per that
 * extension's own forum.js. Every name below was checked present in
 * `extensions/looksmax-icons/js/dist/icons.json` before it was written here.
 * Sections use the OUTLINE glyph (matching Sections.php, which deliberately
 * uses outline over fill — see that file's docblock), intents use the FILL
 * twin of a thematically related glyph. That is a visual grammar, not a
 * coincidence: a filled icon reads as "how I feel", an outline icon reads as
 * "a place on the map" — so a chip's weight tells you which kind of answer
 * it is before you've read the label.
 */
class Survey
{
    public const CATEGORY_SECTION = 'section';
    public const CATEGORY_INTENT = 'intent';

    /**
     * key => [category, icon, color]
     *
     * `color` is only set for `section` items (drives the chip's `--chip-color`
     * custom property client-side); `intent` items fall back to the theme's own
     * `--accent` in the UI, which is correct — an intent is not a colour-coded
     * taxonomy the way a section is.
     */
    public const OPTIONS = [
        // --- sections: what content you're here for -------------------------
        // Order matches Local\Index\Sections::SECTIONS exactly.
        'section_mejores_guias' => ['category' => self::CATEGORY_SECTION, 'icon' => 'ph:book-open-text', 'color' => '#bea7ff'],
        'section_looksmaxing'   => ['category' => self::CATEGORY_SECTION, 'icon' => 'ph:sparkle',         'color' => '#f8ae00'],
        'section_softmaxing'    => ['category' => self::CATEGORY_SECTION, 'icon' => 'ph:drop',             'color' => '#59dc7f'],
        'section_hardmaxing'    => ['category' => self::CATEGORY_SECTION, 'icon' => 'ph:warning',          'color' => '#ff89c3'],
        'section_peptides'      => ['category' => self::CATEGORY_SECTION, 'icon' => 'ph:syringe',          'color' => '#00dfe0'],
        'section_anabolicos'    => ['category' => self::CATEGORY_SECTION, 'icon' => 'ph:barbell',          'color' => '#ff8b83'],

        // --- intent: why you're actually here --------------------------------
        'intent_learn'     => ['category' => self::CATEGORY_INTENT, 'icon' => 'ph:graduation-cap-fill', 'color' => null],
        'intent_rate'      => ['category' => self::CATEGORY_INTENT, 'icon' => 'ph:star-fill',            'color' => null],
        'intent_guides'    => ['category' => self::CATEGORY_INTENT, 'icon' => 'ph:book-fill',            'color' => null],
        'intent_skin_hair' => ['category' => self::CATEGORY_INTENT, 'icon' => 'ph:drop-fill',            'color' => null],
        'intent_surgery'   => ['category' => self::CATEGORY_INTENT, 'icon' => 'ph:warning-fill',         'color' => null],
        'intent_train'     => ['category' => self::CATEGORY_INTENT, 'icon' => 'ph:barbell-fill',         'color' => null],
    ];

    public static function keys(): array
    {
        return array_keys(self::OPTIONS);
    }

    public static function isValid(string $key): bool
    {
        return isset(self::OPTIONS[$key]);
    }

    /**
     * Filter arbitrary client input down to known keys only, deduplicated and
     * order-preserved. This is the ONLY place the security model of the write
     * endpoint lives: whatever comes out of here is safe to insert as an
     * `option_key` value, because it can only ever be one of the keys above.
     */
    public static function sanitize(array $submitted): array
    {
        $out = [];
        foreach ($submitted as $k) {
            if (is_string($k) && isset(self::OPTIONS[$k]) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out;
    }

    public static function category(string $key): ?string
    {
        return self::OPTIONS[$key]['category'] ?? null;
    }

    /** i18n key for a chip's label. Spanish is the source language; see locale/es.yml. */
    public static function labelKey(string $key): string
    {
        return 'local-looksmax-welcome.forum.survey.option.' . $key;
    }

    /** The catalogue shape the client needs to render the chips, nothing more. */
    public static function forClient(): array
    {
        $out = [];
        foreach (self::OPTIONS as $key => $o) {
            $out[] = [
                'key' => $key,
                'category' => $o['category'],
                'icon' => $o['icon'],
                'color' => $o['color'],
                'labelKey' => self::labelKey($key),
            ];
        }

        return $out;
    }
}
