<?php

namespace Local\Index;

/**
 * The per-forum colour and icon system.
 *
 * ── Why this file exists ─────────────────────────────────────────────────────
 *
 * The importer wrote one colour for every forum. Measured on the live database:
 * all 19 `f-*` tags carry `#7aa2f7`, and 20 of the 26 `p-*` prefix tags carry
 * `#414868`. The index has always emitted `--tag` per forum from `tags.color`
 * (RenderIndex), so the mechanism was never broken — the DATA was monotone, and
 * every forum icon on the front page rendered the same desaturated blue.
 * `#414868` on `#161d28` also measures 1.99:1, which is why "News" was
 * unreadable as a chip.
 *
 * ── How the palette is built ────────────────────────────────────────────────
 *
 * Not 47 hand-picked hues. Every colour is generated at a FIXED lightness and
 * chroma in OKLCH and varies only in hue, which is what makes forty-seven
 * colours read as one system instead of a bag of highlighters: no colour is
 * louder than any other, because loudness is the axis that is held constant.
 * Roles are separated by chroma, not lightness:
 *
 *   forums     L 0.80  C 0.150   the loudest thing on the index
 *   prefixes   L 0.80  C 0.150   same band: a prefix chip is a peer of a forum
 *   languages  L 0.80  C 0.105   quieter, and clustered in one arc of the wheel
 *                                so the seven language rooms read as siblings
 *   categories L 0.78  C 0.055   near-neutral; these are headings, not objects
 *   muted      L 0.74  C 0.030   "Cope", "Over", "OP" — grammar, not topic
 *
 * ── Contrast is measured, not asserted ──────────────────────────────────────
 *
 * Every value below was checked against all three surfaces it can land on —
 * --bg #070910, --surface-1 #161d28, --surface-2 #1e2836 — and the WORST pair
 * in the whole table is 6.45:1 (`p-cope` on --surface-2), against a 4.5:1
 * requirement. The generator and its contrast table live in the commit that
 * added this file; `e2e/visual/sweep.ts` re-checks the rendered result on every
 * run, which is the check that actually matters.
 *
 * Hue assignment is semantic, not arbitrary: the pills are a spectrum
 * (black/blue/red/white), the two rating rooms are adjacent violets, money and
 * fitness are greens, the language rooms occupy a single teal-to-blue arc.
 */
class Palette
{
    /** slug => [hex, iconify icon name] */
    public const TAGS = [
        // ---- categories: near-neutral headings
        'c-1' => ['#d2b095', 'ph:stack-fill'],
        'c-10' => ['#8fc1cd', 'ph:info-fill'],
        'c-18' => ['#9ac3ab', 'ph:globe-hemisphere-west-fill'],

        // ---- forums
        'f-2' => ['#ff9c68', 'ph:barbell-fill'],                  // Looksmaxing
        'f-16' => ['#ff948c', 'ph:question-fill'],                // Looksmaxing Questions
        'f-27' => ['#fd95dc', 'ph:scissors-fill'],                // Cosmetic Surgery
        'f-28' => ['#92d36c', 'ph:heartbeat-fill'],               // Fitness & Health
        'f-7' => ['#cda6ff', 'ph:star-half-fill'],                // Ratings
        'f-26' => ['#a2b4ff', 'ph:lock-key-fill'],                // Private Ratings
        'f-9' => ['#eab532', 'ph:trophy-fill'],                   // Best of the Best
        'f-8' => ['#bbc949', 'ph:money-wavy-fill'],               // Moneymaking & Success
        'f-17' => ['#fca942', 'ph:currency-btc-fill'],            // Cryptocurrency
        'f-3' => ['#5fc6ff', 'ph:chats-circle-fill'],             // Offtopic
        'f-11' => ['#00d7f3', 'ph:megaphone-fill'],               // News & Announcements

        // ---- language rooms: one arc of the wheel, lower chroma
        'f-21' => ['#7ed3a6', 'ph:translate-fill'],               // Language-Specific Sections
        'f-19' => ['#8bd19a', 'circle-flags:es'],
        'f-20' => ['#6cd4ba', 'circle-flags:de'],
        'f-22' => ['#60d3cd', 'circle-flags:fr'],
        'f-25' => ['#5ed1de', 'circle-flags:tr'],
        'f-29' => ['#6bccf1', 'circle-flags:ru'],
        'f-24' => ['#8fc1ff', 'ph:globe-simple-fill'],            // Other Languages

        // ---- prefixes: the pills are a deliberate spectrum
        'p-blackpill' => ['#9cb6ff', 'ph:pill-fill'],
        'p-bluepill' => ['#51c9ff', 'ph:pill-fill'],
        'p-redpill' => ['#ff929e', 'ph:pill-fill'],
        'p-whitepill' => ['#00dbd3', 'ph:pill-fill'],

        'p-guide' => ['#82d67a', 'ph:book-open-text-fill'],
        'p-lifefuel' => ['#f5ae39', 'ph:sun-fill'],
        'p-rage' => ['#ff92b1', 'ph:fire-fill'],
        'p-jfl' => ['#d4a3ff', 'ph:smiley-x-eyes-fill'],
        'p-nsfw' => ['#f598e8', 'ph:eye-closed-bold'],
        'p-serious' => ['#00d2ff', 'ph:seal-check-fill'],
        'p-theory' => ['#baadff', 'ph:brain-fill'],
        'p-news' => ['#00d7f0', 'ph:newspaper-fill'],
        'p-motivation' => ['#ff9f5f', 'ph:rocket-launch-fill'],
        'p-method' => ['#22dcb3', 'ph:flask-fill'],
        'p-success' => ['#a8ce5a', 'ph:check-circle-fill'],
        'p-story' => ['#ffa54b', 'ph:book-bookmark-fill'],
        'p-venting' => ['#ff93c8', 'ph:cloud-lightning-fill'],
        'p-mogs' => ['#e69dfc', 'ph:crown-fill'],
        'p-mog-battle' => ['#dba1ff', 'ph:sword-fill'],
        'p-looksmax' => ['#ff9a71', 'ph:sparkle-fill'],
        'p-looksmaxxing' => ['#ff967f', 'ph:sparkle-fill'],
        'p-discussion' => ['#00dae4', 'ph:chat-teardrop-dots-fill'],

        // ---- grammar rather than topic: deliberately almost colourless
        'p-cope' => ['#a2abbe', 'ph:bandaids-fill'],
        'p-over' => ['#a2abbe', 'ph:skull-fill'],
        'p-op' => ['#9bb1a4', 'ph:user-focus-fill'],
        'p-not-op' => ['#9bb1a4', 'ph:user-minus-fill'],
    ];

    /**
     * The fallback for a slug this table has never seen — a new forum, or a
     * prefix imported after this was written.
     *
     * It is NOT a fixed colour, because "everything unknown is the same blue"
     * is the exact defect this file exists to remove. The slug is hashed to a
     * hue and rendered at the same lightness and chroma as everything else, so
     * an unlisted forum still lands inside the system and still clears
     * contrast — it just was not placed on purpose.
     */
    public static function forSlug(?string $slug): array
    {
        $slug = (string) $slug;
        if (isset(self::TAGS[$slug])) {
            return self::TAGS[$slug];
        }

        $hue = (crc32($slug) % 360);
        $chroma = str_starts_with($slug, 'c-') ? 0.055 : 0.150;
        $light = str_starts_with($slug, 'c-') ? 0.78 : 0.80;

        return [self::oklch($light, $chroma, $hue), str_starts_with($slug, 'p-') ? 'ph:tag-fill' : 'ph:chats-circle-fill'];
    }

    public static function color(?string $slug, ?string $fallback = null): string
    {
        return self::forSlug($slug)[0] ?: ($fallback ?: '#8fc1cd');
    }

    public static function icon(?string $slug, ?string $fallback = null): string
    {
        return self::forSlug($slug)[1] ?: ($fallback ?: 'ph:chats-circle-fill');
    }

    /**
     * OKLCH -> sRGB hex, gamut-clipped per channel.
     *
     * Present so the fallback above lands in the same colour space as the table
     * rather than in a different one, which would make an unlisted forum
     * visibly not belong. The matrices are the standard OKLab ones.
     */
    public static function oklch(float $L, float $C, float $H): string
    {
        $h = deg2rad($H);
        $a = $C * cos($h);
        $b = $C * sin($h);

        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $rgb = [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];

        $out = '#';
        foreach ($rgb as $v) {
            $v = max(0.0, min(1.0, $v));
            $v = $v <= 0.0031308 ? 12.92 * $v : 1.055 * $v ** (1 / 2.4) - 0.055;
            $out .= str_pad(dechex((int) round($v * 255)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }
}
