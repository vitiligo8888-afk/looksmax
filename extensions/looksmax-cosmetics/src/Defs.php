<?php

namespace Local\Cosmetics;

/**
 * The shipped cosmetic definitions. DATA, not code.
 *
 * `php flarum cosmetics:sync` writes every row below into `cosmetic_defs`.
 * Adding a frame is adding an entry here — or an INSERT into that table with
 * `source` set to anything other than `shipped`, which sync will then leave
 * alone. Neither requires a line of renderer, stylesheet or JavaScript, because
 * the renderer is thirteen parameterised CSS implementations (plus four
 * non-circular shapes, orthogonal to those) and everything else is a number
 * in `spec`.
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
 * ── spec.render, the thirteen implementations ───────────────────────────────
 *
 *  ring      one flat ring. colors[0].
 *  gradient  a static two-to-four-stop gradient ring, drawn with a border-box
 *            background and an xor mask so it is a ring and not a disc.
 *  conic     a rotating conic sweep, masked to an annulus.
 *  dashed    a rotating repeating-conic, i.e. a dashed ring that runs.
 *  dual      two counter-rotating rings at different radii.
 *  cover     (banners) a linear gradient plate with an optional tiled pattern.
 *
 *  — the elemental set, less/forum.less "elemental renderers" section. Each is
 *    a genuinely different silhouette (an inline SVG mask or a hand-built
 *    gradient recipe, never a recoloured ring) and a genuinely different
 *    motion, and each stays inside the 2-animated-layer / is-live budget the
 *    five renderers above already keep to —
 *
 *  blaze     fire. an SVG flame-lick annulus (jagged outer edge, round inner)
 *            that slowly rolls (spin), plus the same mask flickering opacity.
 *  frost     ice. an SVG angular-facet annulus, static, with a diagonal sheen
 *            sweeping across it (drift) for a cold shimmer.
 *  storm     lightning. a plain thin ring plus two SVG bolt glyphs that snap
 *            in and out of opacity in hard steps (pulse), not an easing fade.
 *  venom     toxic. a gradient annulus with radial "bubble" dots drifting
 *            across it (drift).
 *  void      shadow. a gradient annulus that itself slowly rotates (spin),
 *            with a pulsing dark vignette (pulse) behind it.
 *  blood     a gradient annulus with a repeating vertical drip pattern
 *            sliding down it (drift).
 *  cosmic    starfield. a slowly rotating nebula annulus (spin) with a second,
 *            static, twinkling star-dot annulus layered over it (pulse).
 *  glitch    cyber. a scanline-textured annulus plus a second colour's ring
 *            (colors[1], --cf-c2) that jump-cuts sideways in hard steps
 *            (pulse) for an RGB-split flicker.
 *
 * ── spec.shape, the four non-circular silhouettes ───────────────────────────
 *
 * Orthogonal to render — any renderer's colours can wear any shape — selected
 * by data-cf-shape, defaulting to 'circle' (no attribute, no extra CSS).
 *
 *  hex       a flat-top hexagon, clip-path on the wrapper AND the ring, so the
 *            avatar itself is cropped to it, not just the decoration.
 *  notched   an eight-point beveled-corner clip, same wrapper-level crop.
 *  ornate    an SVG corner-flourish annulus that REPLACES the render's own
 *            ring geometry with its own mask, plus a sweeping sheen.
 *  laurel    two SVG olive branches masked onto the ::after layer, added
 *            beside a plain ring rather than clipping the avatar — a wreath
 *            sits around a circle, it does not reshape it.
 *
 * ── the two extra spec fields the elemental/shape set introduces ───────────
 *
 *  spec.drift   seconds, like spec.spin/spec.pulse. Drives a background-
 *               position sweep (--cf-drift) instead of a rotation or an
 *               opacity cycle — venom's bubbles, blood's drip, frost's and
 *               ornate's sheen.
 *  spec.shape   see above. Read directly off the spec by InjectCosmetics, not
 *               computed into a custom property, because it selects a CSS
 *               rule rather than feeding one — same treatment as
 *               spec.pattern on a banner.
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
 * Every hex below is COPIED from looksmax-brand/less/brand.less where the hue
 * exists there, and the brand token each value corresponds to is named in the
 * comment on its line. They are literals rather than var() references because
 * these strings are written into inline custom properties by the decorator and
 * a var() inside a gradient stop that is itself inside a var() does not
 * resolve reliably across the mask/border-box compositing this uses.
 *
 * The elemental frames (blaze, frost, venom, void, blood, nature) are the one
 * deliberate exception: fire needs a true orange, blood needs a near-black
 * red, toxic needs an acid yellow-green, and brand.less — one violet ramp, one
 * cyan ramp, five semantic colours — has none of them. Those hexes are
 * INVENTED, and each says so on its line rather than pointing at a brand
 * token that isn't actually where the colour came from. They are chosen to
 * read on both the light and the neon-black theme, same as everything else
 * here; being invented does not exempt them from that.
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

            // ------------------------------------------------------ elemental
            // "ice and fire and lightning type shit" — ten themed frames, none
            // of them a recoloured ring: eight run a bespoke render (see the
            // docblock above and less/forum.less's "elemental renderers"
            // section) and four of the ten also carry a non-circular
            // spec.shape. Priced through real tiers and the real badges this
            // file already reads, not through a new store SKU — that catalogue
            // row lives in looksmax-ranks' Catalog::FRAMES /
            // looksmax-store/src/Seed.php, a different lane this task does not
            // touch, and a 'sku' obtain rule with no matching store_items row
            // is exactly the "obtain rule that isn't real" the docblock above
            // warns against.
            [
                'kind' => 'frame', 'slug' => 'blaze', 'sku' => null, 'sort' => 300,
                'spec' => [
                    'render' => 'blaze',
                    'colors' => ['#ff6a00', '#ffb346', '#f75d59'],   // INVENTED (fire orange) core, --brand-warn, --brand-danger
                    'width' => 3, 'inset' => 4,
                    'spin' => 5,
                    'glow' => ['color' => '#ff8a3d', 'size' => 12, 'alpha' => 0.5],   // INVENTED, between warn and danger
                    'obtain' => ['type' => 'tier', 'tier' => 'vip'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'frost', 'sku' => null, 'sort' => 310,
                'spec' => [
                    'render' => 'frost',
                    // INVENTED (ice-white core, brand has no near-white cyan), --brand-cyan-400, --brand-cyan-700
                    'colors' => ['#eafcff', '#69dff6', '#1f96a9'],
                    'width' => 3, 'inset' => 4,
                    'drift' => 4.5,
                    'glow' => ['color' => '#69dff6', 'size' => 11, 'alpha' => 0.45],   // --brand-cyan-400
                    'obtain' => ['type' => 'tier', 'tier' => 'vip'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'storm', 'sku' => null, 'sort' => 320,
                'spec' => [
                    'render' => 'storm',
                    // --brand-rank-ascended (base ring), --brand-cyan-300 (bolts, --cf-c2)
                    'colors' => ['#ffffff', '#85ebff'],
                    'width' => 2, 'inset' => 4,
                    'pulse' => 2.4,   // the bolts' snap interval, not an easing pulse
                    'glow' => ['color' => '#85ebff', 'size' => 13, 'alpha' => 0.55],
                    'obtain' => ['type' => 'tier', 'tier' => 'elite'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'venom', 'sku' => null, 'sort' => 330,
                'spec' => [
                    'render' => 'venom',
                    'colors' => ['#c8ff5e', '#6dd88e'],   // INVENTED (acid yellow-green, deliberately off the violet/cyan family), --brand-ok
                    'width' => 2, 'inset' => 3,
                    'drift' => 5.5,
                    'glow' => ['color' => '#c8ff5e', 'size' => 9, 'alpha' => 0.4],   // INVENTED, matches colors[0]
                    'obtain' => ['type' => 'tier', 'tier' => 'elite'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'royal', 'sku' => null, 'sort' => 340,
                'spec' => [
                    'render' => 'gradient', 'shape' => 'ornate',
                    'angle' => 135,
                    // --brand-rank-ascended, --brand-rank-gold, --brand-rank-bronze
                    'colors' => ['#ffffff', '#e8c07d', '#c98b5e'],
                    'width' => 2, 'inset' => 5,
                    'drift' => 5,   // the sheen sweeping across the corner-flourish mask
                    'glow' => ['color' => '#e8c07d', 'size' => 12, 'alpha' => 0.4],
                    'obtain' => ['type' => 'tier', 'tier' => 'founder'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'void', 'sku' => null, 'sort' => 350,
                'spec' => [
                    'render' => 'void', 'shape' => 'hex',
                    // --brand-bg, --brand-violet-900, --brand-violet-800
                    'colors' => ['#0e0c17', '#270e56', '#4a3289'],
                    'width' => 3, 'inset' => 5,
                    'spin' => 16, 'pulse' => 4,
                    'glow' => ['color' => '#6e54bd', 'size' => 14, 'alpha' => 0.5],   // --brand-violet-700, visible against the dark ring itself
                    'obtain' => ['type' => 'never'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'nature', 'sku' => null, 'sort' => 360,
                'spec' => [
                    'render' => 'ring', 'shape' => 'laurel',
                    // INVENTED (forest green, bracketing the one green brand.less has), --brand-ok
                    'colors' => ['#1f963a', '#6dd88e'],
                    'width' => 2, 'inset' => 4,
                    // No spin/pulse/drift: a wreath does not need to move to
                    // read as a wreath, and it is the cheapest frame in the set
                    // for it — zero animated layers, never enters ANIMATED{}.
                    'glow' => ['color' => '#6dd88e', 'size' => 8, 'alpha' => 0.3],
                    'obtain' => ['type' => 'tier', 'tier' => 'plus'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'blood', 'sku' => null, 'sort' => 370,
                'spec' => [
                    'render' => 'blood', 'shape' => 'notched',
                    'colors' => ['#8a0303', '#f75d59'],   // INVENTED (near-black red brand has no token for), --brand-danger
                    'width' => 2, 'inset' => 4,
                    'drift' => 3.2,
                    'glow' => ['color' => '#8a0303', 'size' => 10, 'alpha' => 0.45],   // INVENTED, matches colors[0]
                    'obtain' => ['type' => 'never'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'cosmic', 'sku' => null, 'sort' => 380,
                'spec' => [
                    'render' => 'cosmic',
                    // --brand-violet-900, --brand-violet-500, --brand-rank-luminary (--cf-c2 star accent)
                    'colors' => ['#270e56', '#9b7dfb', '#f0a5d0'],
                    'width' => 2, 'inset' => 4,
                    'spin' => 20, 'pulse' => 2.2,
                    'glow' => ['color' => '#9b7dfb', 'size' => 12, 'alpha' => 0.5],
                    'obtain' => ['type' => 'badge', 'badge' => 'liked-10k'],
                ],
            ],
            [
                'kind' => 'frame', 'slug' => 'glitch', 'sku' => null, 'sort' => 390,
                'spec' => [
                    'render' => 'glitch',
                    // --brand-cyan-500 (clean channel), --brand-tier-founder (--cf-c2, the offset ghost channel)
                    'colors' => ['#4ecee5', '#f7768e'],
                    'width' => 2, 'inset' => 3,
                    'pulse' => 2.6,   // the jump-cut interval, not an easing pulse
                    'glow' => ['color' => '#4ecee5', 'size' => 9, 'alpha' => 0.45],
                    'obtain' => ['type' => 'tier', 'tier' => 'plus'],
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
