<?php

namespace Local\Ranks;

use Flarum\User\User;

/**
 * The identity payload that rides on every serialized user.
 *
 * Static and dependency-free on purpose. This runs once per user per API
 * response — ~50 times for a discussion listing, ~20 for a post stream — so it
 * reads only denormalised columns already on the row and issues no queries at
 * all. Resolving a service out of the container here, or doing an ownership
 * lookup, would turn one page load into fifty round trips.
 *
 * Ownership cannot drift out from under it: Standing::equip() validates on
 * write and `identity:sync` re-validates the whole population. Those are the
 * two places where a query is affordable.
 */
class Render
{
    public static function user(User $user): array
    {
        $lifetime = (int) ($user->lifetime_points ?? 0);
        $rank = Catalog::rankFor($lifetime);
        $next = Catalog::nextRank($rank['slug']);

        $tierSlug = $user->tier_slug;
        if ($tierSlug && $user->tier_expires_at && strtotime((string) $user->tier_expires_at) < time()) {
            $tierSlug = 'standard'; // lapsed: the benefits stop, the inventory stays
        }
        $tier = Catalog::tier($tierSlug);

        $style = Catalog::style($user->name_style);
        if ($style && !in_array($style['kind'], $tier['styles'], true)) {
            $style = null;
        }

        $frame = Catalog::frame($user->avatar_frame);
        if ($frame && Catalog::tier($frame['tier'])['rank'] > $tier['rank']) {
            $frame = null;
        }

        $span = $next ? max(1, $next['min'] - $rank['min']) : 1;
        $into = $next ? max(0, $lifetime - $rank['min']) : $span;

        return [
            'points' => (int) ($user->points ?? 0),
            'lifetimePoints' => $lifetime,
            'rankSlug' => $rank['slug'],
            'rankName' => $rank['name'],
            'rankColor' => $rank['color'],
            'rankIcon' => $rank['icon'],
            'rankIndex' => $rank['index'],
            'rankProgress' => (int) round(100 * min(1, $into / $span)),
            'nextRankName' => $next['name'] ?? null,
            'toNextRank' => $next ? max(0, $next['min'] - $lifetime) : 0,
            'tierSlug' => $tier['slug'],
            'tierName' => $tier['name'],
            'tierColor' => $tier['color'],
            'tierIcon' => $tier['icon'],
            'nameStyle' => $style['slug'] ?? null,
            'nameClass' => $style['class'] ?? null,
            'frameClass' => $frame['class'] ?? null,
            'customTitle' => $user->custom_title ?: null,
            'titleColor' => $user->title_color ?: null,
            'profileAccent' => $user->profile_accent ?: null,
            'badgeCount' => (int) ($user->badge_count ?? 0),
        ];
    }
}
