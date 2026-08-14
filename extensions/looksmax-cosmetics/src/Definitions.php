<?php

namespace Local\Cosmetics;

use Illuminate\Database\ConnectionInterface;

/**
 * The catalogue, assembled from the two places that legitimately own its parts.
 *
 *   COMMERCE  `store_items`, by sku — name, blurb, price, rarity, icon,
 *             min_tier, min_rank, duration_days, active. The store's admin
 *             screen is the only thing that may change these, and because they
 *             are read here at request time a repricing is true immediately.
 *   VISUAL    `cosmetic_defs.spec`, by slug — the renderer and its parameters,
 *             plus the obtain rule. This extension owns it. See the migration
 *             for why it is NOT `store_items.payload`.
 *
 * Columns deliberately NOT used, and why:
 *   store_items.payload  overwritten by `store:sync` (Catalogue.php:42,76)
 *   store_items.color    a single hex; a frame needs 2-4 stops plus motion
 *   store_items.sort     kept for the shop's ordering; the equip screen orders
 *                        by rarity then cosmetic_defs.sort, which is a
 *                        different question ("what do I want to wear")
 *
 * Everything is read once per request and cached on the instance, because the
 * serializer runs on every user in a payload and a discussion listing carries
 * fifty of them.
 */
class Definitions
{
    public const RARITY_ORDER = ['common' => 0, 'uncommon' => 1, 'rare' => 2, 'epic' => 3, 'legendary' => 4];

    /**
     * kind => slug => def, for the whole request.
     *
     * STATIC on purpose. This class is resolved fresh out of the container by
     * the user serializer, which runs once per user in a payload — fifty times
     * on a discussion listing. An instance-level cache would therefore be a
     * cache of one and this would be fifty pairs of queries.
     *
     * @var array<string,array<string,array>>|null
     */
    private static ?array $cache = null;

    public function __construct(protected ConnectionInterface $db)
    {
    }

    /** Drop the per-request cache. The console and the tests need this. */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<string,array> slug => definition, for one kind */
    public function ofKind(string $kind): array
    {
        return $this->all()[$kind] ?? [];
    }

    public function find(string $kind, ?string $slug): ?array
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return $this->all()[$kind][$slug] ?? null;
    }

    /**
     * Every active definition, merged with its commerce row.
     *
     * @return array<string,array<string,array>> kind => slug => def
     */
    public function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $defs = $this->db->table('cosmetic_defs')
            ->where('active', 1)
            ->orderBy('kind')->orderBy('sort')->orderBy('slug')
            ->get()->all();

        $skus = array_values(array_filter(array_map(fn ($d) => $d->sku, $defs)));
        $items = [];
        if ($skus) {
            // One query for every commerce row this catalogue can reference.
            foreach ($this->db->table('store_items')->whereIn('sku', $skus)->get() as $row) {
                $items[$row->sku] = $row;
            }
        }

        $out = [];
        foreach ($defs as $d) {
            $spec = json_decode((string) $d->spec, true);
            if (!is_array($spec)) {
                // A malformed spec is a broken row, not a broken page: skip it
                // and leave everything else renderable.
                continue;
            }

            $item = $d->sku !== null ? ($items[$d->sku] ?? null) : null;

            // A cosmetic whose catalogue row was deactivated in the store admin
            // screen stops being OFFERED, but anybody already wearing it keeps
            // wearing it — a purchase is not undone by a delisting.
            $sellable = $item !== null && (int) $item->active === 1;

            $out[$d->kind][$d->slug] = [
                'kind' => $d->kind,
                'slug' => $d->slug,
                'sku' => $d->sku,
                'sort' => (int) $d->sort,
                'source' => $d->source,
                'spec' => $spec,
                'render' => (string) ($spec['render'] ?? 'ring'),
                'obtain' => is_array($spec['obtain'] ?? null) ? $spec['obtain'] : ['type' => 'never'],
                'sellable' => $sellable,

                // commerce, or sensible absences when nothing sells it
                'name' => $this->name($d->kind, $d->slug, $item),
                'blurb' => $this->blurb($d->kind, $d->slug, $item),
                'price' => $item !== null ? (int) $item->price : null,
                'rarity' => $item !== null ? (string) $item->rarity : $this->earnedRarity($spec),
                'icon' => $item !== null ? (string) $item->icon : 'ph:circle-half-tilt-bold',
                'minTier' => $item !== null ? $item->min_tier : ($spec['obtain']['tier'] ?? null),
                'minRank' => $item !== null ? $item->min_rank : null,
                'durationDays' => $item !== null ? $item->duration_days : null,

                // the CSS the renderer needs, precomputed so neither LESS nor
                // the browser has to do colour maths
                'css' => $this->css($spec),
            ];
        }

        return self::$cache = $out;
    }

    /**
     * Display name.
     *
     * A translation key first — Spanish is the source language on this forum
     * and the store's `name` column holds an English literal seeded from
     * looksmax-ranks' Catalog constant. The key wins; the column is the
     * fallback; the slug is the last resort so nothing ever renders blank.
     */
    private function name(string $kind, string $slug, ?object $item): string
    {
        $key = 'local-looksmax-cosmetics.lib.' . $kind . '.' . $slug . '.name';
        $out = $this->trans($key);
        if ($out !== null) {
            return $out;
        }

        return $item !== null && $item->name !== '' ? (string) $item->name : $slug;
    }

    private function blurb(string $kind, string $slug, ?object $item): string
    {
        $key = 'local-looksmax-cosmetics.lib.' . $kind . '.' . $slug . '.blurb';
        $out = $this->trans($key);
        if ($out !== null) {
            return $out;
        }

        return $item !== null ? (string) $item->blurb : '';
    }

    private function trans(string $key): ?string
    {
        try {
            $t = resolve(\Symfony\Contracts\Translation\TranslatorInterface::class);
        } catch (\Throwable $e) {
            return null;
        }

        $out = $t->trans($key);

        return ($out === '' || $out === $key) ? null : $out;
    }

    /**
     * Rarity for something nothing sells.
     *
     * Derived from how many people can hold it rather than asserted, so it
     * cannot drift from the truth: a frame two accounts have is not "rare"
     * because a constant says so.
     */
    private function earnedRarity(array $spec): string
    {
        $obtain = $spec['obtain'] ?? [];
        if (($obtain['type'] ?? '') === 'never') {
            return 'legendary';
        }

        $holders = $this->holderCount($obtain);
        if ($holders === null) {
            return 'rare';
        }

        return match (true) {
            $holders <= 10 => 'legendary',
            $holders <= 100 => 'epic',
            $holders <= 400 => 'rare',
            $holders <= 1200 => 'uncommon',
            default => 'common',
        };
    }

    /** How many accounts satisfy an obtain rule. Cached per request. */
    public function holderCount(array $obtain): ?int
    {
        static $memo = [];
        $key = json_encode($obtain);
        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }

        $n = match ($obtain['type'] ?? '') {
            'badge' => (int) $this->db->table('identity_badges')->where('badge', $obtain['badge'] ?? '')->count(),
            'tier' => (int) $this->db->table('users')
                ->whereIn('tier_slug', Ownership::tiersAtOrAbove((string) ($obtain['tier'] ?? '')))
                ->where(function ($q) {
                    $q->whereNull('tier_expires_at')->orWhere('tier_expires_at', '>', date('Y-m-d H:i:s'));
                })->count(),
            'sku' => (int) $this->db->table('store_entitlements')
                ->where('sku', $obtain['sku'] ?? '')->whereNull('revoked_at')->count(),
            default => null,
        };

        return $memo[$key] = $n;
    }

    /**
     * Turn a spec into the handful of custom properties the stylesheet reads.
     *
     * All of the colour arithmetic happens HERE, in PHP, and never in LESS.
     * That is not a style preference: Flarum compiles through wikimedia/less.php
     * where fade()/darken()/mix() are evaluated at compile time and abort the
     * whole build the moment they are handed a custom property — forum.css is
     * then never written and the forum serves unstyled pages. The stylesheet in
     * this extension therefore contains no colour function at all, and these
     * strings arrive as inline custom properties on the element.
     */
    private function css(array $spec): array
    {
        $colors = array_values(array_filter((array) ($spec['colors'] ?? []), 'is_string'));
        if (!$colors) {
            $colors = ['#9aa4b2'];
        }

        $glow = (array) ($spec['glow'] ?? []);
        $glowColor = (string) ($glow['color'] ?? $colors[0]);
        $glowAlpha = (float) ($glow['alpha'] ?? 0.35);
        $angle = (int) ($spec['angle'] ?? 140);

        $props = [
            '--cf-w' => (int) ($spec['width'] ?? 2) . 'px',
            '--cf-inset' => '-' . (int) ($spec['inset'] ?? 3) . 'px',
            '--cf-c1' => $colors[0],
            '--cf-glow' => $this->rgba($glowColor, $glowAlpha),
            '--cf-glow-size' => (int) ($glow['size'] ?? 8) . 'px',
        ];

        // A ring is a gradient of one stop; expressing every renderer through
        // the same two properties (--cf-sweep for the moving/gradient layer and
        // --cf-flat for the plain one) is what keeps this to five rules.
        $stops = implode(', ', $colors);
        $props['--cf-sweep'] = 'conic-gradient(from 0deg, ' . $stops . ')';
        $props['--cf-linear'] = 'linear-gradient(' . $angle . 'deg, ' . $stops . ')';

        if (($spec['render'] ?? '') === 'dashed') {
            $dash = max(2, (int) ($spec['dash'] ?? 12));
            $props['--cf-sweep'] = 'repeating-conic-gradient(from 0deg, ' . $colors[0]
                . ' 0deg ' . $dash . 'deg, transparent ' . $dash . 'deg ' . ($dash * 2) . 'deg)';
        }

        if (isset($spec['spin'])) {
            $props['--cf-spin'] = ((float) $spec['spin']) . 's';
        }
        if (isset($spec['spin2'])) {
            $props['--cf-spin2'] = ((float) $spec['spin2']) . 's';
        }
        if (isset($spec['pulse'])) {
            $props['--cf-pulse'] = ((float) $spec['pulse']) . 's';
        }
        if (isset($spec['inset2'])) {
            $props['--cf-inset2'] = '-' . (int) $spec['inset2'] . 'px';
        }

        if (($spec['render'] ?? '') === 'cover') {
            $props['--cb-fill'] = 'linear-gradient(' . $angle . 'deg, ' . $stops . ')';
            $props['--cb-tint'] = $this->rgba($colors[count($colors) - 1], 0.35);
        }

        return $props;
    }

    /** #rrggbb + alpha -> rgba(). No LESS, no color-mix, no surprises. */
    private function rgba(string $hex, float $alpha): string
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '9aa4b2';
        }

        return sprintf(
            'rgba(%d, %d, %d, %s)',
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
            rtrim(rtrim(number_format(max(0, min(1, $alpha)), 2, '.', ''), '0'), '.') ?: '0'
        );
    }
}
