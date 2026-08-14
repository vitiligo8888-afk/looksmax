<?php

namespace Local\Index;

/**
 * The six curated front-page sections.
 *
 * ── What these are, and what they are NOT ────────────────────────────────────
 *
 * They are NOT a rename of anything in the imported board. Measured on the live
 * database before this file existed: 47 tags, of which 19 are XenForo forums
 * (`f-*`) and 26 are XenForo prefixes (`p-*`). There is no Peptides tag, no
 * Anabólicos, no Softmaxing and no Peligrosomaxing, and there never was — see
 * HANDOFF-UI.md §7 for the counts.
 *
 * So the sections are a CURATED VIEW, materialised additively: six NEW tags,
 * created by `php flarum lmx:index:sections`, which the classifier ADDS to a
 * discussion and never removes anything for. A thread keeps `f-2 Looksmaxing`
 * and `p-serious` AND gains `Softmaxing`. The 47-tag tree stays reachable and
 * nothing imported becomes unreachable.
 *
 * ── The names are the operator's, verbatim ──────────────────────────────────
 *
 * `Peptides · Anabólicos · Softmaxing · Looksmaxing · Peligrosomaxing ·
 * Mejores Guías`, in that order. They are not renamed, re-translated or
 * "improved" here or anywhere downstream. They live in `locale/*.yml` as
 * translation keys because Spanish is the forum's DEFAULT locale and English is
 * the translation; the strings below are only the slug, the colour and the icon
 * — no display copy is hardcoded in PHP.
 *
 * ── Colour ──────────────────────────────────────────────────────────────────
 *
 * Straight off the Palette.php ramp and by its rules: ONE lightness (L 0.80) and
 * ONE chroma for the whole set, hue assigned semantically. Roles are separated
 * by chroma, so the sections sit at C 0.175 — one step louder than the forums'
 * C 0.150 — because a section is a tier above a forum in the page's hierarchy
 * and that is the axis the palette uses to say so.
 *
 * Contrast measured against all three surfaces the chips can land on
 * (--bg #070910, --surface-1 #161d28, --surface-2 #1e2836) and against the dark
 * ink used on a filled tile (#10151d). Worst pair in the table is 6.57:1
 * (Anabólicos on --surface-2) against a 4.5:1 requirement. None of the six
 * collides with any of the 47 existing tag colours.
 *
 *   slug              hue   hex       worst surface   on #10151d
 *   peptides          195   #00dfe0       8.95            11.01
 *   anabolicos         25   #ff8b83       6.57             8.08
 *   softmaxing        150   #59dc7f       8.48            10.43
 *   looksmaxing        80   #f8ae00       7.82             9.63
 *   peligrosomaxing   355   #ff89c3       6.81             8.39
 *   mejores-guias     290   #bea7ff       7.20             8.86
 */
class Sections
{
    /** The lightness and chroma every section colour is generated at. */
    public const L = 0.80;
    public const C = 0.175;

    /**
     * key      the classifier's id and the i18n key fragment
     * slug     the tag slug — ASCII only, accents live in the display string
     * hue      OKLCH hue; the hex is derived, never hand-picked
     * icon     iconify name, bundled in looksmax-icons/js/dist/icons.json
     */
    public const SECTIONS = [
        'peptides' => ['slug' => 'peptides', 'hue' => 195, 'icon' => 'ph:syringe-fill'],
        'anabolicos' => ['slug' => 'anabolicos', 'hue' => 25, 'icon' => 'ph:barbell-fill'],
        'softmaxing' => ['slug' => 'softmaxing', 'hue' => 150, 'icon' => 'ph:drop-fill'],
        'looksmaxing' => ['slug' => 'looksmaxing', 'hue' => 80, 'icon' => 'ph:sparkle-fill'],
        'peligrosomaxing' => ['slug' => 'peligrosomaxing', 'hue' => 355, 'icon' => 'ph:warning-fill'],
        'mejores_guias' => ['slug' => 'mejores-guias', 'hue' => 290, 'icon' => 'ph:book-open-text-fill'],
    ];

    /** i18n namespace. The extension id is `local-looksmax-index`. */
    public const NS = 'local-looksmax-index.forum.section.';

    public static function keys(): array
    {
        return array_keys(self::SECTIONS);
    }

    public static function color(string $key): string
    {
        $s = self::SECTIONS[$key] ?? null;

        return $s ? Palette::oklch(self::L, self::C, (float) $s['hue']) : '#8fc1cd';
    }

    public static function icon(string $key): string
    {
        return self::SECTIONS[$key]['icon'] ?? 'ph:tag-fill';
    }

    public static function slug(string $key): string
    {
        return self::SECTIONS[$key]['slug'] ?? $key;
    }

    /** `…section.peptides.title` / `.desc` — the i18n lane owns the copy. */
    public static function titleKey(string $key): string
    {
        return self::NS . $key . '.title';
    }

    public static function descKey(string $key): string
    {
        return self::NS . $key . '.desc';
    }

    /** slug => key, for turning a tag row back into a section. */
    public static function bySlug(): array
    {
        $out = [];
        foreach (self::SECTIONS as $k => $s) {
            $out[$s['slug']] = $k;
        }

        return $out;
    }
}
