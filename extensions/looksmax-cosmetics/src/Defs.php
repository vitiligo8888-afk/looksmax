<?php

namespace Local\Cosmetics;

/**
 * The shipped cosmetic definitions. DATA, not code.
 *
 * `php flarum cosmetics:sync` writes every row below into `cosmetic_defs`.
 * Adding a frame is adding an entry here — or an INSERT into that table with
 * `source` set to anything other than `shipped`, which sync will then leave
 * alone. Neither requires a line of renderer, stylesheet or JavaScript, because
 * the renderer is five parameterised CSS implementations and everything else is
 * a number in `spec`.
 *
 * ── what each field means ───────────────────────────────────────────────────
 *
 *  kind    frame | banner
 *  slug    the identifier. For the eight frames looksmax-ranks also knows, this
 *          is deliberately the SAME slug, so `users.avatar_frame` and
 *          `cosmetic_loadout.frame` can never mean different things.
 *  sku     the row in `store_items` that sells it, or null when it is earned.
 *          When set, NAME, BLURB, PRICE, RARITY, ICON and MIN_TIER are read
 *          from that row at request time — this file never restates them, so
 *          repricing something in the store admin screen is immediately true
 *          here. See Definitions::all().
 *  spec    the visual, plus the obtain rule. Never read by the store.
 *
 * ── spec.render, the five implementations ───────────────────────────────────
 *
 *  ring      one flat ring. colors[0].
 *  gradient  a static two-to-four-stop gradient ring, drawn with a border-box
 *            background and an xor mask so it is a ring and not a disc.
 *  conic     a rotating conic sweep, masked to an annulus.
 *  dashed    a rotating repeating-conic, i.e. a dashed ring that runs.
 *  dual      two counter-rotating rings at different radii.
 *  cover     (banners) a linear gradient plate with an optional tiled pattern.
 *
 * ── spec.obtain, the four real ownership sources ────────────────────────────
 *
 *  {"type":"sku","sku":"frame-ember"}
 *      A live row in `store_entitlements` (revoked_at IS NULL, expires_at NULL
 *      or in the future) — or, because this forum has a second purchase path
 *      that predates the store and writes only there (see HANDOFF-STORE.md §2),
 *      a live row in `identity_inventory`. Both are real purchases and refusing
 *      the second would blank the avatar of somebody who paid.
 *  {"type":"tier","tier":"vip"}
 *      An active membership at that tier or above. Looksmax+ tiers already
 *      advertise "Profile banner and accent colour" as a headline benefit
 *      (looksmax-ranks/src/Catalog.php:81) and nothing implemented it.
 *  {"type":"badge","badge":"legacy-elder"}
 *      A row in `identity_badges`. These are earned or imported, never sold,
 *      and the counts are real: legacy-elder 151, veteran-5y 86, liked-10k 151,
 *      contributor 7, staff 2. Measured 2026-08-13.
 *  {"type":"never"}
 *      Award-only. Nothing in this extension can grant it; it renders for
 *      whoever an operator gives it to through the identity extension.
 *
 * ── where the colours come from ─────────────────────────────────────────────
 *
 * Every hex below is COPIED from looksmax-brand/less/brand.less, which is
 * generated and contrast-measured by looksmax-brand/tools/palette.py. Nothing
 * here is invented. The brand token each value corresponds to is named in the
 * comment on its line. They are literals rather than var() references because
 * these strings are written into inline custom properties by the decorator and
 * a var() inside a gradient stop that is itself inside a var() does not
 * resolve reliably across the mask/border-box compositing this uses.
 */
final class Defs
{
    /** @return array<int,array{kind:string,slug:string,sku:?string,sort:int,spec:array}> */
    public static function all(): array
    {
        return array_merge(self::frames(), self::banners());
    }

    /** @return array<int,array> */
    private static function frames(): array
    {
        return [
            // ---------------------------------------------------- sold in the store
            [
                'kind' => 'frame', 'slug' => 'bronze-laurel', 'sku' => 'frame-bronze-laurel', 'sort' => 10,
                'spec' => [
                    'render' => 'ring',
                    'colors' => ['#c98b5e'],                          // --brand-rank-bronze
                    'width' => 2, 'inset' => 3,
                    'glow' => ['color' => '#c98b5e', 'size' => 5, 'alpha' => 0.28],
                    'obtain' => ['type' => 'sku', 'sku' => 'frame-bronze-laurel'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'silver-laurel', 'sku' => 'frame-silver-laurel', 'sort' => 20,
                'spec' => [
                    'render' => 'ring',
                    'colors' => ['#c3ccd8'],                          // --brand-rank-silver
                    'width' => 2, 'inset' => 3,
                    'glow' => ['color' => '#c3ccd8', 'size' => 7, 'alpha' => 0.35],
                    'obtain' => ['type' => 'sku', 'sku' => 'frame-silver-laurel'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'gold-laurel', 'sku' => 'frame-gold-laurel', 'sort' => 30,
                'spec' => [
                    'render' => 'gradient',
                    'angle' => 140,
                    // --brand-rank-ascended, --brand-rank-gold, --brand-rank-bronze
                    'colors' => ['#ffffff', '#e8c07d', '#c98b5e', '#e8c07d'],
                    'width' => 2, 'inset' => 3,
                    'glow' => ['color' => '#e8c07d', 'size' => 8, 'alpha' => 0.35],
                    'obtain' => ['type' => 'sku', 'sku' => 'frame-gold-laurel'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'neon', 'sku' => 'frame-neon', 'sort' => 40,
                'spec' => [
                    'render' => 'ring',
                    'colors' => ['#85ebff'],                          // --brand-cyan-300
                    'width' => 2, 'inset' => 3,
                    'pulse' => 3.4,
                    'glow' => ['color' => '#4ecee5', 'size' => 14, 'alpha' => 0.55],
                    'obtain' => ['type' => 'sku', 'sku' => 'frame-neon'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'ember', 'sku' => 'frame-ember', 'sort' => 50,
                'spec' => [
                    'render' => 'conic',
                    // --brand-danger, --brand-warn, --brand-rank-gold
                    'colors' => ['#f75d59', '#ffb346', '#e8c07d', '#f75d59'],
                    'width' => 3, 'inset' => 3,
                    'spin' => 4.5,
                    'glow' => ['color' => '#ffb346', 'size' => 10, 'alpha' => 0.45],
                    'obtain' => ['type' => 'sku', 'sku' => 'frame-ember'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'glacier', 'sku' => 'frame-glacier', 'sort' => 60,
                'spec' => [
                    'render' => 'conic',
                    // --brand-cyan-100 / -400 / -800
                    'colors' => ['#def9ff', '#69dff6', '#016a79', '#def9ff'],
                    'width' => 3, 'inset' => 3,
                    'spin' => 6,
                    'glow' => ['color' => '#69dff6', 'size' => 10, 'alpha' => 0.45],
                    'obtain' => ['type' => 'sku', 'sku' => 'frame-glacier'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'circuit', 'sku' => 'frame-circuit', 'sort' => 70,
                'spec' => [
                    'render' => 'dashed',
                    'colors' => ['#6dd88e'],                          // --brand-ok
                    'width' => 3, 'inset' => 3,
                    'spin' => 8, 'dash' => 12,
                    'glow' => ['color' => '#6dd88e', 'size' => 8, 'alpha' => 0.4],
                    'obtain' => ['type' => 'sku', 'sku' => 'frame-circuit'],
                ],
            ],

            // -------------------------------------------------------- award only
            [
                'kind' => 'frame', 'slug' => 'ascendant', 'sku' => null, 'sort' => 200,
                'spec' => [
                    'render' => 'dual',
                    // --brand-rank-ascended, --brand-rank-gold, --brand-rank-master
                    'colors' => ['#ffffff', '#e8c07d', '#a78bfa', '#ffffff'],
                    'width' => 2, 'inset' => 3, 'inset2' => 5,
                    'spin' => 7, 'spin2' => 11,
                    'glow' => ['color' => '#e8c07d', 'size' => 14, 'alpha' => 0.4],
                    'obtain' => ['type' => 'never'],
                ],
            ],

            // ------------------------------------------- earned from real history
            // Nothing below needs a catalogue row, a price or a purchase: the
            // ownership already exists in this forum's imported data. These are
            // the frames the operator asked to be "seeded from what users
            // already had" rather than invented.
            [
                'kind' => 'frame', 'slug' => 'elder', 'sku' => null, 'sort' => 110,
                'spec' => [
                    'render' => 'gradient',
                    'angle' => 200,
                    // --brand-violet-800 / -400 / -900
                    'colors' => ['#4a3289', '#ccc3ff', '#270e56', '#4a3289'],
                    'width' => 2, 'inset' => 3,
                    'glow' => ['color' => '#9b7dfb', 'size' => 8, 'alpha' => 0.35],
                    'obtain' => ['type' => 'badge', 'badge' => 'legacy-elder'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'veteran', 'sku' => null, 'sort' => 120,
                'spec' => [
                    'render' => 'ring',
                    'colors' => ['#8fd3e8'],                          // --brand-rank-platinum
                    'width' => 2, 'inset' => 4,
                    'glow' => ['color' => '#8fd3e8', 'size' => 9, 'alpha' => 0.4],
                    'obtain' => ['type' => 'badge', 'badge' => 'veteran-5y'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'consensus', 'sku' => null, 'sort' => 130,
                'spec' => [
                    'render' => 'conic',
                    // --brand-rank-luminary, --brand-violet-500, --brand-violet-200
                    'colors' => ['#f0a5d0', '#9b7dfb', '#e6e2fe', '#f0a5d0'],
                    'width' => 2, 'inset' => 3,
                    'spin' => 9,
                    'glow' => ['color' => '#f0a5d0', 'size' => 9, 'alpha' => 0.4],
                    'obtain' => ['type' => 'badge', 'badge' => 'liked-10k'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'contributor', 'sku' => null, 'sort' => 140,
                'spec' => [
                    'render' => 'gradient',
                    'angle' => 120,
                    // --brand-tier-plus, --brand-cyan-300
                    'colors' => ['#7aa2f7', '#85ebff', '#7aa2f7'],
                    'width' => 2, 'inset' => 3,
                    'glow' => ['color' => '#7aa2f7', 'size' => 8, 'alpha' => 0.4],
                    'obtain' => ['type' => 'badge', 'badge' => 'contributor'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'staff', 'sku' => null, 'sort' => 150,
                'spec' => [
                    'render' => 'dual',
                    // --brand-tier-founder, --brand-danger, --brand-warn
                    'colors' => ['#f7768e', '#f75d59', '#ffb346', '#f7768e'],
                    'width' => 2, 'inset' => 3, 'inset2' => 5,
                    'spin' => 8, 'spin2' => 13,
                    'glow' => ['color' => '#f7768e', 'size' => 12, 'alpha' => 0.4],
                    'obtain' => ['type' => 'badge', 'badge' => 'staff'],
                ],
            ],
        ];
    }

    /**
     * Profile banners.
     *
     * These deliver a benefit the forum already SELLS and never rendered:
     * looksmax-ranks/src/Catalog.php:80-81 lists "Profile banner and accent
     * colour" as a VIP headline, and the tier rows carry a `banner => true`
     * flag (Catalog.php:73,80,87,94) that no code read. So the obtain rules
     * below are the tier flags that were already promised, plus the same
     * badge-derived history the frames use.
     *
     * Deliberately NOT user-uploaded images: this forum has no moderation
     * surface for a 1600px image behind somebody's name, and looksmax-uploads
     * owns file handling. A generated plate needs no storage, no CDN, no
     * takedown path and cannot be a shock image.
     */
    private static function banners(): array
    {
        return [
            [
                'kind' => 'banner', 'slug' => 'nebula', 'sku' => null, 'sort' => 10,
                'spec' => [
                    'render' => 'cover', 'angle' => 135, 'pattern' => 'rays',
                    'colors' => ['#270e56', '#6e54bd', '#9b7dfb'],   // violet 900 / 700 / 500
                    'obtain' => ['type' => 'tier', 'tier' => 'vip'],
                ],
            ],
            [
                'kind' => 'banner', 'slug' => 'aurora', 'sku' => null, 'sort' => 20,
                'spec' => [
                    'render' => 'cover', 'angle' => 110, 'pattern' => 'none',
                    'colors' => ['#4a3289', '#1f96a9', '#4ecee5'],   // violet 800, cyan 700 / 500
                    'obtain' => ['type' => 'tier', 'tier' => 'elite'],
                ],
            ],
            [
                'kind' => 'banner', 'slug' => 'founder', 'sku' => null, 'sort' => 30,
                'spec' => [
                    'render' => 'cover', 'angle' => 160, 'pattern' => 'rays',
                    'colors' => ['#270e56', '#8669dd', '#f7768e'],   // violet 900 / 600, tier-founder
                    'obtain' => ['type' => 'tier', 'tier' => 'founder'],
                ],
            ],
            [
                'kind' => 'banner', 'slug' => 'elder', 'sku' => null, 'sort' => 40,
                'spec' => [
                    'render' => 'cover', 'angle' => 90, 'pattern' => 'grid',
                    'colors' => ['#050409', '#270e56', '#4a3289'],   // surface-0, violet 900 / 800
                    'obtain' => ['type' => 'badge', 'badge' => 'legacy-elder'],
                ],
            ],
            [
                'kind' => 'banner', 'slug' => 'veteran', 'sku' => null, 'sort' => 50,
                'spec' => [
                    'render' => 'cover', 'angle' => 120, 'pattern' => 'grid',
                    'colors' => ['#023d46', '#016a79', '#8fd3e8'],   // cyan 900 / 800, rank-platinum
                    'obtain' => ['type' => 'badge', 'badge' => 'veteran-5y'],
                ],
            ],
            [
                'kind' => 'banner', 'slug' => 'staff', 'sku' => null, 'sort' => 60,
                'spec' => [
                    'render' => 'cover', 'angle' => 145, 'pattern' => 'dots',
                    'colors' => ['#270e56', '#4a3289', '#f75d59'],   // violet 900 / 800, danger
                    'obtain' => ['type' => 'badge', 'badge' => 'staff'],
                ],
            ],
        ];
    }
}
