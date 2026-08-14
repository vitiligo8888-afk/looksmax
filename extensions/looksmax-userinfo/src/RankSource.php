<?php

namespace Local\UserInfo;

use Flarum\User\User;

/**
 * The one place that knows where a rank comes from.
 *
 * Ranks, tiers and cosmetics are owned by local/looksmax-ranks, which is being
 * built in parallel. Nothing in this extension may define a competing ladder,
 * so every rank fact is resolved here and everything else consumes the result.
 * If ranks moves house, this file is the whole migration.
 *
 * Three sources, in priority order:
 *
 *   1. LIVE — looksmax-ranks is installed AND the account has earned something
 *      (lifetime_points > 0). Its Render::user() payload is authoritative and
 *      is passed through unchanged, keys and all.
 *   2. LEGACY LADDER — the account carried a source-board title that IS a rank
 *      on that same catalogue ("Iron", "Gold", "Zephir", "Fire"). The board's
 *      ladder and the ranks catalogue are the same ladder — the catalogue was
 *      built from it — so matching by name consumes their colours rather than
 *      inventing a second set.
 *   3. NONE — a custom title like "squishy squishy!" is a user title, not a
 *      rank. It renders as a title and no rank chip appears. Inventing a rank
 *      from post count here is exactly the "invented stats" failure.
 *
 * The economy lane backfills lifetime_points; until it does, (1) never fires
 * and every imported account resolves through (2). That is the intended
 * behaviour, not a stopgap: what someone carried in is real, and a rank derived
 * from a ledger that is still empty would not be.
 */
class RankSource
{
    /** Colour used for a title we cannot place on any ladder. */
    private const NEUTRAL = '#98a3b3';

    public static function available(): bool
    {
        return class_exists(\Local\Ranks\Catalog::class);
    }

    /**
     * @return array{
     *   slug:?string, name:?string, color:string, icon:?string, kind:string,
     *   source:string, progress:?int, nextName:?string, toNext:?int
     * }
     */
    public static function resolve(User $user, ?Profile $profile): array
    {
        $none = [
            'slug' => null, 'name' => null, 'color' => self::NEUTRAL, 'icon' => null,
            'kind' => 'none', 'source' => 'none',
            'progress' => null, 'nextName' => null, 'toNext' => null,
        ];

        // (1) live progression, only once the ledger has actually run
        if (self::available() && (int) ($user->lifetime_points ?? 0) > 0) {
            try {
                $p = \Local\Ranks\Render::user($user);

                return [
                    'slug' => $p['rankSlug'] ?? null,
                    'name' => $p['rankName'] ?? null,
                    'color' => $p['rankColor'] ?? self::NEUTRAL,
                    'icon' => $p['rankIcon'] ?? null,
                    'kind' => 'rank',
                    'source' => 'ranks',
                    'progress' => $p['rankProgress'] ?? null,
                    'nextName' => $p['nextRankName'] ?? null,
                    'toNext' => $p['toNextRank'] ?? null,
                ];
            } catch (\Throwable $e) {
                // fall through: a broken neighbour must not blank the panel
            }
        }

        // (2) the title the account carried in, matched against the same catalogue
        $title = trim((string) ($profile->legacy_title ?? ''));
        if ($title === '') {
            return $none;
        }

        $match = self::matchCatalog($title);

        return $match ?: $none;
    }

    /**
     * Match a source-board title against the ranks catalogue by name.
     *
     * Case-insensitive because the board is inconsistent about it. Returns null
     * for anything that is not on either list, which is the common case and the
     * correct answer — most titles are free text.
     */
    private static function matchCatalog(string $title): ?array
    {
        if (!self::available()) {
            return null;
        }

        $key = mb_strtolower($title);

        foreach (\Local\Ranks\Catalog::RANKS as $i => $r) {
            if (mb_strtolower($r['name']) === $key) {
                return [
                    'slug' => $r['slug'], 'name' => $r['name'], 'color' => $r['color'],
                    'icon' => $r['icon'], 'kind' => 'rank', 'source' => 'legacy-ladder',
                    'progress' => null,
                    'nextName' => \Local\Ranks\Catalog::RANKS[$i + 1]['name'] ?? null,
                    'toNext' => null,
                ];
            }
        }

        // Purchased colour names (Zephir, Kraken, Fire …). These are a cosmetic
        // on the source board, not a ladder position, so they are marked as
        // such: the chip renders, the progress bar does not.
        foreach (\Local\Ranks\Catalog::STYLES as $s) {
            if (mb_strtolower($s['name']) === $key) {
                $rarity = \Local\Ranks\Catalog::RARITIES[$s['rarity']] ?? self::NEUTRAL;

                return [
                    'slug' => $s['slug'], 'name' => $s['name'], 'color' => $rarity,
                    'icon' => null, 'kind' => 'style', 'source' => 'legacy-style',
                    'progress' => null, 'nextName' => null, 'toNext' => null,
                ];
            }
        }

        return null;
    }

    /**
     * Role banners are not ranks — they are what the board printed above the
     * avatar — so they are resolved here too but kept on a separate axis.
     *
     * Only names the scrape actually contains get a colour; anything else falls
     * back to neutral rather than being dropped, because a banner we have not
     * seen before is still real.
     */
    public static function banner(string $name): array
    {
        $known = [
            'administrator' => ['#f2748a', 'ph:shield-star-fill'],
            'staff' => ['#f2748a', 'ph:shield-check-fill'],
            'moderator' => ['#e6b169', 'ph:gavel-fill'],
            'contributor' => ['#6ea8fe', 'ph:hand-coins-fill'],
            'to the moon!' => ['#b58cf0', 'ph:rocket-launch-fill'],
        ];

        [$color, $icon] = $known[mb_strtolower($name)] ?? [self::NEUTRAL, 'ph:seal-fill'];

        return ['name' => $name, 'color' => $color, 'icon' => $icon];
    }
}
