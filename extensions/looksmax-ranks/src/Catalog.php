<?php

namespace Local\Ranks;

/**
 * The whole identity model as data.
 *
 * Ranks, membership tiers, cosmetics and badges are declared here rather than
 * seeded into tables, because they are code-shaped: their unlock rules and
 * their CSS are versioned with the extension, and a catalogue row that has
 * drifted from the stylesheet that renders it is a bug with no error message.
 * What DOES live in tables is the per-user state — who owns what, who equipped
 * what, who earned what and when.
 *
 * Three axes, deliberately independent (see /root/identity-notes/DESIGN.md §1):
 *
 *   RANK  earned from the ledger only. Cannot be bought.
 *   TIER  bought with points or granted. Cannot be earned by posting.
 *   COSMETIC  bought, or unlocked by reaching a rank/tier.
 *
 * The source board conflated the first and third onto one `style_class` column
 * and the cross-tabulation of style against title shows the seam: Iron through
 * Luminary is monotone in post count and reputation, while Zephir, Kraken,
 * Fuchsia and Fire are not monotone in anything. Those are purchases.
 */
class Catalog
{
    /**
     * Earned ladder. `min` is lifetime_points from the economy ledger.
     *
     * Thresholds were cut against the measured distribution after backfill
     * rather than picked as round numbers — see identity-notes/LADDER.md. Names
     * are the source board's own ladder, which its data shows is real.
     */
    public const RANKS = [
        ['slug' => 'greycel',  'name' => 'Greycel',  'min' => 0,       'color' => '#7c8695', 'icon' => 'ph:circle-dashed-bold',        'blurb' => 'Just arrived. Read before you post.'],
        ['slug' => 'iron',     'name' => 'Iron',     'min' => 150,     'color' => '#9aa4b2', 'icon' => 'game-icons:iron-hulled-warship', 'blurb' => 'Posting, and being read.'],
        ['slug' => 'bronze',   'name' => 'Bronze',   'min' => 700,     'color' => '#c98b5e', 'icon' => 'ph:medal-fill',                'blurb' => 'A known face in a section.'],
        ['slug' => 'silver',   'name' => 'Silver',   'min' => 2500,    'color' => '#c3ccd8', 'icon' => 'ph:medal-fill',                'blurb' => 'Contributes more than it consumes.'],
        ['slug' => 'gold',     'name' => 'Gold',     'min' => 7500,    'color' => '#e8c07d', 'icon' => 'ph:medal-military-fill',       'blurb' => 'Threads people come back to.'],
        ['slug' => 'platinum', 'name' => 'Platinum', 'min' => 20000,   'color' => '#8fd3e8', 'icon' => 'solar:crown-minimalistic-bold', 'blurb' => 'Reference-grade output.'],
        ['slug' => 'diamond',  'name' => 'Diamond',  'min' => 45000,   'color' => '#7ee0d3', 'icon' => 'ph:diamond-fill',              'blurb' => 'Rare. Earned over years.'],
        ['slug' => 'master',   'name' => 'Master',   'min' => 90000,   'color' => '#a78bfa', 'icon' => 'game-icons:laurel-crown',      'blurb' => 'Shapes how the board thinks.'],
        ['slug' => 'luminary', 'name' => 'Luminary', 'min' => 125000,  'color' => '#f0a5d0', 'icon' => 'ph:sparkle-fill',              'blurb' => 'Top of the ladder.'],
        ['slug' => 'ascended', 'name' => 'Ascended', 'min' => 250000,  'color' => '#ffffff', 'icon' => 'lmx:ascended',     'blurb' => 'The ladder ran out.'],
    ];

    /**
     * Membership tiers.
     *
     * Every perk listed here is enforced somewhere in code, not decoration:
     * `earn` multiplies ledger awards, `discount` reduces store prices,
     * `capBoost` raises the anti-farming daily caps, `titleLen` bounds the
     * custom title, `styles` gates which cosmetic classes can be equipped,
     * `showcase` bounds the trophy case, and `group` puts the member in a real
     * Flarum group so core permission checks see the tier.
     *
     * A benefit nobody can observe is not a benefit, so each tier also names
     * the three things a member will actually notice first.
     */
    public const TIERS = [
        [
            'slug' => 'standard', 'name' => 'Standard', 'price' => 0, 'days' => 0,
            'color' => '#7c8695', 'icon' => 'ph:user-fill', 'group' => null, 'rank' => 0,
            'earn' => 1.0, 'discount' => 0.0, 'capBoost' => 1.0, 'titleLen' => 0,
            'styles' => ['rank'], 'showcase' => 3, 'banner' => false, 'accent' => false,
            'headline' => ['Everything readable', 'Earn at base rate', 'Rank ladder + 3 showcase slots'],
        ],
        [
            'slug' => 'plus', 'name' => 'Looksmax+', 'price' => 7500, 'days' => 30,
            'color' => '#7aa2f7', 'icon' => 'ph:plus-circle-fill', 'group' => 'Looksmax+', 'rank' => 1,
            'earn' => 1.10, 'discount' => 0.05, 'capBoost' => 1.25, 'titleLen' => 40,
            'styles' => ['rank', 'solid'], 'showcase' => 5, 'banner' => true, 'accent' => false,
            'headline' => ['A custom title under your name', 'Solid name colours', '+10% on everything you earn'],
        ],
        [
            'slug' => 'vip', 'name' => 'VIP', 'price' => 25000, 'days' => 30,
            'color' => '#e8c07d', 'icon' => 'solar:crown-bold', 'group' => 'VIP', 'rank' => 2,
            'earn' => 1.25, 'discount' => 0.10, 'capBoost' => 1.5, 'titleLen' => 60,
            'styles' => ['rank', 'solid', 'gradient'], 'showcase' => 8, 'banner' => true, 'accent' => true,
            'headline' => ['Gradient name styles', 'Avatar frames', 'Profile banner and accent colour'],
        ],
        [
            'slug' => 'elite', 'name' => 'Elite', 'price' => 75000, 'days' => 30,
            'color' => '#bb9af7', 'icon' => 'game-icons:rank-3', 'group' => 'Elite', 'rank' => 3,
            'earn' => 1.40, 'discount' => 0.20, 'capBoost' => 2.0, 'titleLen' => 80,
            'styles' => ['rank', 'solid', 'gradient', 'animated'], 'showcase' => 12, 'banner' => true, 'accent' => true,
            'headline' => ['Animated name styles', '20% off the store', 'Doubled daily earning ceiling'],
        ],
        [
            'slug' => 'founder', 'name' => 'Founder', 'price' => 0, 'days' => 0,
            'color' => '#f7768e', 'icon' => 'game-icons:laurels-trophy', 'group' => 'Founder', 'rank' => 4,
            'earn' => 1.50, 'discount' => 0.25, 'capBoost' => 2.0, 'titleLen' => 100,
            'styles' => ['rank', 'solid', 'gradient', 'animated', 'legendary'], 'showcase' => 12, 'banner' => true, 'accent' => true,
            'headline' => ['Granted, never sold', 'Legendary name styles', 'Permanent — no renewal'],
        ],
    ];

    /**
     * Username styles.
     *
     * `class` is the CSS class appended to `.lmx-name`; every one of them has a
     * hand-written rule in less/styles.less. The LESS is hand-written and not
     * generated because Flarum's LESS runs through the PHP port, which has no
     * `each()` and no `range()` and fails SILENTLY — forum.css simply never
     * gets written. A loop here would have cost an afternoon.
     *
     * `kind` is the gate: 'rank' styles come free with the ladder, everything
     * else needs the tier to allow that kind AND the item to be owned.
     *
     * Nine of these (zephir, mistral, solstice, equinox, sphinx, kraken,
     * apricot, fuchsia, fire) are the source board's own named VIP colours,
     * recovered from `users.title` where it does not sit on the rank ladder.
     */
    public const STYLES = [
        // --- free with rank ------------------------------------------------
        ['slug' => 'rank-default', 'name' => 'Rank colour', 'kind' => 'rank', 'price' => 0, 'rarity' => 'common', 'class' => 'ns-rank', 'blurb' => 'Whatever your current rank paints you.'],

        // --- solids: Looksmax+ ---------------------------------------------
        ['slug' => 'crimson',  'name' => 'Crimson',  'kind' => 'solid', 'price' => 1200, 'rarity' => 'common', 'class' => 'ns-crimson',  'blurb' => 'Flat arterial red.'],
        ['slug' => 'azure',    'name' => 'Azure',    'kind' => 'solid', 'price' => 1200, 'rarity' => 'common', 'class' => 'ns-azure',    'blurb' => 'The board classic.'],
        ['slug' => 'emerald',  'name' => 'Emerald',  'kind' => 'solid', 'price' => 1200, 'rarity' => 'common', 'class' => 'ns-emerald',  'blurb' => 'Cool green, high contrast on dark.'],
        ['slug' => 'apricot',  'name' => 'Apricot',  'kind' => 'solid', 'price' => 1600, 'rarity' => 'uncommon', 'class' => 'ns-apricot', 'blurb' => 'Recovered from the source board.'],
        ['slug' => 'fuchsia',  'name' => 'Fuchsia',  'kind' => 'solid', 'price' => 1600, 'rarity' => 'uncommon', 'class' => 'ns-fuchsia', 'blurb' => 'Recovered from the source board.'],
        ['slug' => 'slate',    'name' => 'Slate',    'kind' => 'solid', 'price' => 900,  'rarity' => 'common', 'class' => 'ns-slate',    'blurb' => 'For people who want less, not more.'],

        // --- gradients: VIP -------------------------------------------------
        ['slug' => 'zephir',    'name' => 'Zephir',    'kind' => 'gradient', 'price' => 4500, 'rarity' => 'rare', 'class' => 'ns-zephir',    'blurb' => 'Pale sky into steel. From the source board.'],
        ['slug' => 'mistral',   'name' => 'Mistral',   'kind' => 'gradient', 'price' => 4500, 'rarity' => 'rare', 'class' => 'ns-mistral',   'blurb' => 'Cold front. From the source board.'],
        ['slug' => 'solstice',  'name' => 'Solstice',  'kind' => 'gradient', 'price' => 4500, 'rarity' => 'rare', 'class' => 'ns-solstice',  'blurb' => 'Long light. From the source board.'],
        ['slug' => 'equinox',   'name' => 'Equinox',   'kind' => 'gradient', 'price' => 4500, 'rarity' => 'rare', 'class' => 'ns-equinox',   'blurb' => 'Half dark, half not. From the source board.'],
        ['slug' => 'sphinx',    'name' => 'Sphinx',    'kind' => 'gradient', 'price' => 5200, 'rarity' => 'rare', 'class' => 'ns-sphinx',    'blurb' => 'Sand and basalt. From the source board.'],
        ['slug' => 'vaporwave', 'name' => 'Vaporwave', 'kind' => 'gradient', 'price' => 4800, 'rarity' => 'rare', 'class' => 'ns-vaporwave', 'blurb' => 'Cyan to magenta, unapologetic.'],
        ['slug' => 'goldleaf',  'name' => 'Gold Leaf', 'kind' => 'gradient', 'price' => 6000, 'rarity' => 'rare', 'class' => 'ns-goldleaf',  'blurb' => 'Beaten metal, not yellow.'],
        ['slug' => 'oceanic',   'name' => 'Oceanic',   'kind' => 'gradient', 'price' => 4200, 'rarity' => 'uncommon', 'class' => 'ns-oceanic', 'blurb' => 'Deep water gradient.'],

        // --- animated: Elite --------------------------------------------------
        ['slug' => 'winter',    'name' => 'Winter',    'kind' => 'animated', 'price' => 14000, 'rarity' => 'epic', 'class' => 'ns-winter',   'blurb' => 'A frost sheen travels the name.'],
        ['slug' => 'slime',     'name' => 'Slime',     'kind' => 'animated', 'price' => 14000, 'rarity' => 'epic', 'class' => 'ns-slime',    'blurb' => 'Toxic green, slowly dripping.'],
        ['slug' => 'striped',   'name' => 'Striped',   'kind' => 'animated', 'price' => 12000, 'rarity' => 'epic', 'class' => 'ns-striped',  'blurb' => 'Barber pole. Loud on purpose.'],
        ['slug' => 'kraken',    'name' => 'Kraken',    'kind' => 'animated', 'price' => 16000, 'rarity' => 'epic', 'class' => 'ns-kraken',   'blurb' => 'Abyssal teal, moving. From the source board.'],
        ['slug' => 'fire',      'name' => 'Fire',      'kind' => 'animated', 'price' => 18000, 'rarity' => 'epic', 'class' => 'ns-fire',     'blurb' => 'The top style on the source board.'],
        ['slug' => 'aurora',    'name' => 'Aurora',    'kind' => 'animated', 'price' => 16000, 'rarity' => 'epic', 'class' => 'ns-aurora',   'blurb' => 'Slow polar drift.'],
        ['slug' => 'prism',     'name' => 'Prism',     'kind' => 'animated', 'price' => 20000, 'rarity' => 'epic', 'class' => 'ns-prism',    'blurb' => 'Full hue rotation. Use sparingly.'],

        // Luminary is deliberately 'animated' and not 'legendary'. The source
        // board had 479 accounts on its Luminary classes; making it legendary
        // would mean granting all of them Founder, which is the one tier that is
        // supposed to be unbuyable. A style being rare is a rarity label, not a
        // reason to inflate the tier that unlocks it.
        ['slug' => 'luminary',  'name' => 'Luminary',  'kind' => 'animated', 'price' => 22000, 'rarity' => 'legendary', 'class' => 'ns-luminary', 'blurb' => 'Emits light rather than colour. The top of the source board wore this.'],

        // --- legendary: Founder / awarded only --------------------------------
        ['slug' => 'obsidian',  'name' => 'Obsidian',  'kind' => 'legendary', 'price' => 0, 'rarity' => 'legendary', 'class' => 'ns-obsidian', 'blurb' => 'Awarded. Black with a gold edge.'],
        ['slug' => 'staff',     'name' => 'Staff',     'kind' => 'legendary', 'price' => 0, 'rarity' => 'legendary', 'class' => 'ns-staff',    'blurb' => 'Not for sale. Ever.'],
    ];

    /**
     * Avatar frames. Rendered as a ::before ring on the avatar, so they cost
     * nothing in DOM and cannot break the avatar itself if the CSS fails.
     */
    public const FRAMES = [
        ['slug' => 'bronze-laurel', 'name' => 'Bronze Laurel', 'price' => 2500,  'rarity' => 'common',    'class' => 'fr-bronze',   'tier' => 'vip', 'blurb' => 'A plain warm ring.'],
        ['slug' => 'silver-laurel', 'name' => 'Silver Laurel', 'price' => 5000,  'rarity' => 'uncommon',  'class' => 'fr-silver',   'tier' => 'vip', 'blurb' => 'Brushed metal.'],
        ['slug' => 'gold-laurel',   'name' => 'Gold Laurel',   'price' => 9000,  'rarity' => 'rare',      'class' => 'fr-gold',     'tier' => 'vip', 'blurb' => 'Two-tone gold, static.'],
        ['slug' => 'neon',          'name' => 'Neon',          'price' => 11000, 'rarity' => 'rare',      'class' => 'fr-neon',     'tier' => 'vip', 'blurb' => 'Cyan halo with bloom.'],
        ['slug' => 'ember',         'name' => 'Ember',         'price' => 15000, 'rarity' => 'epic',      'class' => 'fr-ember',    'tier' => 'elite', 'blurb' => 'A conic sweep of heat, rotating.'],
        ['slug' => 'glacier',       'name' => 'Glacier',       'price' => 15000, 'rarity' => 'epic',      'class' => 'fr-glacier',  'tier' => 'elite', 'blurb' => 'Cold conic sweep, rotating.'],
        ['slug' => 'circuit',       'name' => 'Circuit',       'price' => 18000, 'rarity' => 'epic',      'class' => 'fr-circuit',  'tier' => 'elite', 'blurb' => 'Dashed trace that runs the perimeter.'],
        ['slug' => 'ascendant',     'name' => 'Ascendant',     'price' => 0,     'rarity' => 'legendary', 'class' => 'fr-ascendant','tier' => 'founder', 'blurb' => 'Awarded. Double ring, counter-rotating.'],
    ];

    /**
     * Badges.
     *
     * `check` names a method on Badges\Engine. Everything is computed from data
     * the forum already has, so a badge can be recomputed from scratch and will
     * come back identical — no hand-granting, no drift, no arguments.
     *
     * `tier` drives the frame colour in the trophy case. `points` is credited
     * once through the ledger, so badges feed progression rather than sitting
     * beside it.
     */
    public const BADGES = [
        // participation
        ['slug' => 'first-post',   'name' => 'First Blood',      'tier' => 'bronze',  'icon' => 'ph:egg-crack-fill',            'points' => 10,   'check' => 'posts', 'arg' => 1,     'blurb' => 'Posted once.'],
        ['slug' => 'posts-100',    'name' => 'Hundred',          'tier' => 'bronze',  'icon' => 'ph:chat-teardrop-dots-fill',   'points' => 40,   'check' => 'posts', 'arg' => 100,   'blurb' => '100 posts.'],
        ['slug' => 'posts-1k',     'name' => 'Thousand',         'tier' => 'silver',  'icon' => 'ph:chats-circle-fill',         'points' => 200,  'check' => 'posts', 'arg' => 1000,  'blurb' => '1,000 posts.'],
        ['slug' => 'posts-10k',    'name' => 'Ten Thousand',     'tier' => 'gold',    'icon' => 'ph:infinity-bold',             'points' => 1200, 'check' => 'posts', 'arg' => 10000, 'blurb' => '10,000 posts.'],
        ['slug' => 'posts-50k',    'name' => 'Immovable',        'tier' => 'mythic',  'icon' => 'game-icons:stone-tablet',      'points' => 6000, 'check' => 'posts', 'arg' => 50000, 'blurb' => '50,000 posts. Eleven accounts on the source board managed it.'],

        // authorship
        ['slug' => 'threads-10',   'name' => 'Conversationalist','tier' => 'bronze',  'icon' => 'ph:tree-structure-fill',       'points' => 60,   'check' => 'threads', 'arg' => 10,  'blurb' => 'Started 10 threads.'],
        ['slug' => 'threads-100',  'name' => 'Agenda Setter',    'tier' => 'silver',  'icon' => 'ph:broadcast-fill',            'points' => 400,  'check' => 'threads', 'arg' => 100, 'blurb' => 'Started 100 threads.'],
        ['slug' => 'guide-author', 'name' => 'Guide Author',     'tier' => 'gold',    'icon' => 'ph:book-open-text-fill',       'points' => 500,  'check' => 'guides',  'arg' => 1,   'blurb' => 'Wrote something people are sent back to.'],
        ['slug' => 'guide-5',      'name' => 'Reference Shelf',  'tier' => 'platinum','icon' => 'ph:books-fill',                'points' => 2000, 'check' => 'guides',  'arg' => 5,   'blurb' => 'Five published guides.'],
        ['slug' => 'sourced',      'name' => 'Cites Sources',    'tier' => 'gold',    'icon' => 'academicons:open-access',      'points' => 600,  'check' => 'sourced', 'arg' => 10,  'blurb' => '10 guide claims backed by a real source.'],

        // reception
        ['slug' => 'liked-100',    'name' => 'Well Received',    'tier' => 'bronze',  'icon' => 'ph:heart-fill',                'points' => 60,   'check' => 'reactions', 'arg' => 100,   'blurb' => '100 reactions received.'],
        ['slug' => 'liked-1k',     'name' => 'Crowd Favourite',  'tier' => 'silver',  'icon' => 'ph:heartbeat-fill',            'points' => 300,  'check' => 'reactions', 'arg' => 1000,  'blurb' => '1,000 reactions received.'],
        ['slug' => 'liked-10k',    'name' => 'Consensus',        'tier' => 'gold',    'icon' => 'ph:hand-heart-fill',           'points' => 1500, 'check' => 'reactions', 'arg' => 10000, 'blurb' => '10,000 reactions received.'],
        ['slug' => 'liked-100k',   'name' => 'Gravitational',    'tier' => 'mythic',  'icon' => 'game-icons:black-hole-bolas',  'points' => 8000, 'check' => 'reactions', 'arg' => 100000,'blurb' => '100,000 reactions received.'],
        ['slug' => 'ratio',        'name' => 'Signal',           'tier' => 'platinum','icon' => 'ph:wave-sine-bold',            'points' => 900,  'check' => 'ratio',     'arg' => 3,     'blurb' => 'At least 3 reactions per post across 200+ posts. Quality, not volume.'],

        // being read — the axis nobody else can measure
        ['slug' => 'read-through', 'name' => 'Read To The End',  'tier' => 'silver',  'icon' => 'ph:book-open-user-fill',       'points' => 250,  'check' => 'readthrough', 'arg' => 50,  'blurb' => '50 readers reached the bottom of your threads.'],
        ['slug' => 'held-attention','name' => 'Held Attention',  'tier' => 'gold',    'icon' => 'ph:hourglass-high-fill',       'points' => 700,  'check' => 'dwell',       'arg' => 3600,'blurb' => 'An hour of cumulative reading time on your threads.'],

        // tenure and habit
        ['slug' => 'veteran-1y',   'name' => 'One Year',         'tier' => 'bronze',  'icon' => 'ph:calendar-check-fill',       'points' => 100,  'check' => 'tenure', 'arg' => 365,  'blurb' => 'A year on the board.'],
        ['slug' => 'veteran-3y',   'name' => 'Three Years',      'tier' => 'silver',  'icon' => 'ph:calendar-star-fill',        'points' => 400,  'check' => 'tenure', 'arg' => 1095, 'blurb' => 'Three years on the board.'],
        ['slug' => 'veteran-5y',   'name' => 'Old Guard',        'tier' => 'gold',    'icon' => 'game-icons:tombstone',   'points' => 900,  'check' => 'tenure', 'arg' => 1825, 'blurb' => 'Five years on the board.'],
        ['slug' => 'nightowl',     'name' => 'Night Owl',        'tier' => 'bronze',  'icon' => 'ph:moon-stars-fill',           'points' => 80,   'check' => 'nightowl', 'arg' => 50, 'blurb' => '50 posts between 02:00 and 05:00.'],
        ['slug' => 'necromancer',  'name' => 'Necromancer',      'tier' => 'silver',  'icon' => 'game-icons:raise-skeleton',    'points' => 150,  'check' => 'necro', 'arg' => 5,    'blurb' => 'Revived five threads dead for six months or more.'],

        // standing carried in
        // Pays NOTHING, deliberately. It was 300, and since every imported
        // account holds it, that put a 310-point floor under the entire
        // population and emptied the bottom rank: Greycel went from 516 holders
        // to 1. A badge that everyone has is a marker of provenance, not an
        // achievement, and it must not move the ladder.
        ['slug' => 'founding',     'name' => 'Founding Member',  'tier' => 'platinum','icon' => 'game-icons:standing-potion',   'points' => 0,    'check' => 'imported', 'arg' => 0,  'blurb' => 'Here before the migration.'],
        ['slug' => 'legacy-elder', 'name' => 'Elder',            'tier' => 'gold',    'icon' => 'game-icons:wisdom',            'points' => 800,  'check' => 'legacy', 'arg' => 10000,'blurb' => 'Arrived carrying serious standing.'],
        ['slug' => 'staff',        'name' => 'Staff',            'tier' => 'mythic',  'icon' => 'ph:shield-star-fill',          'points' => 0,    'check' => 'banner', 'arg' => 'Staff', 'blurb' => 'Runs the place.'],
        ['slug' => 'contributor',  'name' => 'Contributor',      'tier' => 'platinum','icon' => 'ph:hand-coins-fill',           'points' => 0,    'check' => 'banner', 'arg' => 'Contributor', 'blurb' => 'Kept the lights on.'],
    ];

    /** Badge tier -> the colour its frame is drawn in. */
    public const BADGE_TIERS = [
        'bronze'   => '#c98b5e',
        'silver'   => '#c3ccd8',
        'gold'     => '#e8c07d',
        'platinum' => '#8fd3e8',
        'mythic'   => '#f0a5d0',
    ];

    public const RARITIES = [
        'common'    => '#9aa4b2',
        'uncommon'  => '#9ece6a',
        'rare'      => '#7aa2f7',
        'epic'      => '#bb9af7',
        'legendary' => '#e8c07d',
    ];

    // -------------------------------------------------------- localisation

    /**
     * Display text for one catalogue row, in the reader's language.
     *
     * The keys are DERIVED from the row's slug rather than stored in the row,
     * so the constants above stay a pure data structure and stay usable as
     * identifiers. looksmax-userinfo resolves its rank lookup by matching
     * Catalog::RANKS name values (RankSource.php:106), and looksmax-store
     * seeds name/blurb out of these constants into store_items (Seed.php:189);
     * a translation key sitting in those columns would break the first
     * silently and persist into the database in the second.
     *
     * A missing key falls back to the English literal already in the row, so a
     * half-deployed locale pack degrades to English and never to `local-...`.
     * Every lookup below runs its result through here, which is why the store,
     * the profile and the DOM decorator all read translated text without
     * knowing that this class translates anything.
     */
    private static function localize(string $group, ?array $row): ?array
    {
        if ($row === null || !isset($row['slug'])) {
            return $row;
        }

        $t = resolve(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $base = 'local-looksmax-ranks.lib.' . $group . '.' . $row['slug'] . '.';

        foreach (['name', 'blurb'] as $field) {
            if (!isset($row[$field]) || !is_string($row[$field])) {
                continue;
            }
            $out = $t->trans($base . $field);
            if ($out !== '' && $out !== $base . $field) {
                $row[$field] = $out;
            }
        }

        if (isset($row['headline']) && is_array($row['headline'])) {
            foreach ($row['headline'] as $i => $line) {
                $out = $t->trans($base . 'headline.' . $i);
                if ($out !== '' && $out !== $base . 'headline.' . $i) {
                    $row['headline'][$i] = $out;
                }
            }
        }

        return $row;
    }

    /** A `local-looksmax-ranks.` key, translated. ICU params, named. */
    public static function trans(string $key, array $params = []): string
    {
        return resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)
            ->trans('local-looksmax-ranks.' . $key, $params);
    }

    /** Every rank, display-ready, with its ladder index. */
    public static function ranks(): array
    {
        $out = [];
        foreach (self::RANKS as $i => $r) {
            $out[] = self::localize('rank', $r) + ['index' => $i];
        }

        return $out;
    }

    /** Every membership tier, display-ready. */
    public static function tiers(): array
    {
        return array_map(fn (array $t) => self::localize('tier', $t), self::TIERS);
    }

    /** Every badge, display-ready. */
    public static function badges(): array
    {
        return array_map(fn (array $b) => self::localize('badge', $b), self::BADGES);
    }

    // ------------------------------------------------------------- lookups

    public static function rankFor(int $points): array
    {
        $rank = self::RANKS[0];
        foreach (self::RANKS as $i => $r) {
            if ($points >= $r['min']) {
                $rank = $r + ['index' => $i];
            }
        }

        return self::localize('rank', $rank) + ['index' => array_search($rank['slug'], array_column(self::RANKS, 'slug'), true)];
    }

    /** The rank above this one, or null at the top. Drives the progress bar. */
    public static function nextRank(string $slug): ?array
    {
        $i = array_search($slug, array_column(self::RANKS, 'slug'), true);

        return $i === false ? null : self::localize('rank', self::RANKS[$i + 1] ?? null);
    }

    public static function rank(string $slug): array
    {
        foreach (self::RANKS as $i => $r) {
            if ($r['slug'] === $slug) {
                return self::localize('rank', $r) + ['index' => $i];
            }
        }

        return self::localize('rank', self::RANKS[0]) + ['index' => 0];
    }

    public static function tier(?string $slug): array
    {
        foreach (self::TIERS as $t) {
            if ($t['slug'] === $slug) {
                return self::localize('tier', $t);
            }
        }

        return self::localize('tier', self::TIERS[0]);
    }

    public static function style(?string $slug): ?array
    {
        foreach (self::STYLES as $s) {
            if ($s['slug'] === $slug) {
                return self::localize('style', $s);
            }
        }

        return null;
    }

    public static function frame(?string $slug): ?array
    {
        foreach (self::FRAMES as $f) {
            if ($f['slug'] === $slug) {
                return self::localize('frame', $f);
            }
        }

        return null;
    }

    public static function badge(string $slug): ?array
    {
        foreach (self::BADGES as $b) {
            if ($b['slug'] === $slug) {
                return self::localize('badge', $b);
            }
        }

        return null;
    }

    /** Every purchasable item, flattened, for the store. */
    public static function shopItems(): array
    {
        $out = [];
        foreach (self::STYLES as $s) {
            if ($s['kind'] === 'rank') {
                continue;
            }
            $out[] = self::localize('style', $s) + ['type' => 'style'];
        }
        foreach (self::FRAMES as $f) {
            $out[] = self::localize('frame', $f) + ['type' => 'frame', 'kind' => 'frame'];
        }

        return $out;
    }

    /** Minimum tier that may equip a style of this kind. */
    public static function tierForStyleKind(string $kind): ?array
    {
        foreach (self::TIERS as $t) {
            if (in_array($kind, $t['styles'], true)) {
                return self::localize('tier', $t);
            }
        }

        return null;
    }
}
