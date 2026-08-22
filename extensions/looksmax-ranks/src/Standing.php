<?php

namespace Local\Ranks;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Everything about "where does this account stand", in one place.
 *
 * Resolution order matters and is deliberate:
 *
 *   1. rank comes from lifetime_points and nothing else, so it cannot be bought
 *   2. tier comes from an unexpired membership row, or standard
 *   3. an equipped cosmetic renders only if it is BOTH owned and permitted by
 *      the current tier
 *
 * (3) is the rule that makes an expiring tier honest. When a VIP membership
 * lapses the gradient name style stops rendering, but the inventory row stays,
 * so renewing restores it instantly and nobody loses a purchase. The
 * alternative — deleting inventory on expiry — turns a lapsed subscription into
 * a support ticket.
 */
class Standing
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    // ------------------------------------------------------------- resolution

    /**
     * The full payload, for the profile and the store. Queries freely; called
     * once per page, not once per name.
     */
    public function payload(User $user): array
    {
        $points = (int) ($user->points ?? 0);
        $lifetime = (int) ($user->lifetime_points ?? 0);

        $rank = Catalog::rankFor($lifetime);
        $next = Catalog::nextRank($rank['slug']);
        $tier = $this->activeTier($user);

        $style = $this->effectiveStyle($user, $tier);
        $frame = $this->effectiveFrame($user, $tier);

        // Progress toward the next rank, as a fraction of the band the user is
        // currently inside — not a fraction of the whole ladder, which would
        // read as 2% forever.
        $span = $next ? max(1, $next['min'] - $rank['min']) : 1;
        $into = $next ? max(0, $lifetime - $rank['min']) : $span;

        return [
            'points' => $points,
            'lifetimePoints' => $lifetime,
            'rankSlug' => $rank['slug'],
            'rankName' => $rank['name'],
            'rankColor' => $rank['color'],
            'rankIcon' => $rank['icon'],
            'rankIndex' => $rank['index'],
            'nextRank' => $next ? ['slug' => $next['slug'], 'name' => $next['name'], 'min' => $next['min'], 'color' => $next['color']] : null,
            'rankProgress' => (int) round(100 * min(1, $into / $span)),
            'toNextRank' => $next ? max(0, $next['min'] - $lifetime) : 0,

            'tierSlug' => $tier['slug'],
            'tierName' => $tier['name'],
            'tierColor' => $tier['color'],
            'tierIcon' => $tier['icon'],
            'tierExpiresAt' => $user->tier_expires_at ? (string) $user->tier_expires_at : null,
            'earnMultiplier' => $tier['earn'],
            'storeDiscount' => $tier['discount'],

            'nameStyle' => $style['slug'] ?? null,
            'nameClass' => $style['class'] ?? null,
            'nameKind' => $style['kind'] ?? null,
            'avatarFrame' => $frame['slug'] ?? null,
            'frameClass' => $frame['class'] ?? null,
            'customTitle' => $user->custom_title ?: null,
            'titleColor' => $user->title_color ?: null,
            'profileAccent' => $user->profile_accent ?: null,
            'badgeCount' => (int) ($user->badge_count ?? 0),
            'showcase' => $this->showcase((int) $user->id),
        ];
    }

    /** The tier that is actually in force right now. */
    public function activeTier(User $user): array
    {
        $slug = $user->tier_slug;
        if (!$slug || $slug === 'standard') {
            return Catalog::tier('standard');
        }

        $expiry = $user->tier_expires_at;
        if ($expiry && strtotime((string) $expiry) < time()) {
            return Catalog::tier('standard'); // lapsed; sync will clean the row up
        }

        return Catalog::tier($slug);
    }

    /**
     * The style that renders, which is not necessarily the style equipped: a
     * lapsed tier silently falls back to the rank colour rather than showing a
     * benefit the account no longer has.
     */
    public function effectiveStyle(User $user, ?array $tier = null): ?array
    {
        $tier ??= $this->activeTier($user);
        $style = Catalog::style($user->name_style);

        if (!$style) {
            return null;
        }
        if (!in_array($style['kind'], $tier['styles'], true)) {
            return null;
        }
        if ($style['kind'] !== 'rank' && !$this->owns((int) $user->id, 'style', $style['slug'])) {
            return null;
        }

        return $style;
    }

    public function effectiveFrame(User $user, ?array $tier = null): ?array
    {
        $tier ??= $this->activeTier($user);
        $frame = Catalog::frame($user->avatar_frame);

        if (!$frame) {
            return null;
        }
        if (Catalog::tier($frame['tier'])['rank'] > $tier['rank']) {
            return null;
        }
        if (!$this->owns((int) $user->id, 'frame', $frame['slug'])) {
            return null;
        }

        return $frame;
    }

    public function owns(int $userId, string $type, string $item): bool
    {
        return $this->db->table('identity_inventory')
            ->where('user_id', $userId)->where('type', $type)->where('item', $item)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->exists();
    }

    public function inventory(int $userId): array
    {
        return $this->db->table('identity_inventory')
            ->where('user_id', $userId)
            ->get(['type', 'item', 'source', 'acquired_at', 'expires_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** Badges pinned to the trophy case, in slot order. */
    public function showcase(int $userId): array
    {
        $rows = $this->db->table('identity_badges')
            ->where('user_id', $userId)->where('showcased', 1)
            ->orderBy('slot')->limit(12)
            ->pluck('badge');

        $out = [];
        foreach ($rows as $slug) {
            $b = Catalog::badge($slug);
            if ($b) {
                $out[] = ['slug' => $b['slug'], 'name' => $b['name'], 'icon' => $b['icon'], 'tier' => $b['tier']];
            }
        }

        return $out;
    }

    /**
     * Every badge, for the achievements screen — including, for anything not
     * yet earned, how close the account actually is.
     *
     * Before this existed, `progress` was only ever the value that satisfied a
     * badge (identity_badges.progress, written once at award time); a LOCKED
     * badge always read 0, which is a trophy case that shows what you have and
     * says nothing about what you are working toward. `Badges\Engine::valueFor()`
     * fills that in with one small scoped query per measurable locked badge —
     * fine here because this is a "your own achievements page" call, not
     * something serialized across a listing of fifty users (see IdentityController
     * ::me(), the only caller). The five-minute widget poll uses
     * nearestMeasurableBadge() below instead, which is deliberately cheaper.
     *
     * `progressPct` is capped at 99 for anything not owned, even if the raw
     * value has already crossed the threshold — a badge is "not yet earned"
     * until `identity:badges` (or the live check that feeds it) actually writes
     * the row, and a bar reading 100% while the lock icon is still on it is a
     * worse bug than one reading 99%.
     */
    public function badges(int $userId): array
    {
        $rows = $this->db->table('identity_badges')->where('user_id', $userId)
            ->get(['badge', 'awarded_at', 'progress', 'showcased', 'slot'])->keyBy('badge');

        /** @var \Local\Ranks\Badges\Engine|null $engine */
        $engine = null;
        $unmeasurable = ['banner', 'imported', 'legacy'];

        $out = [];
        foreach (Catalog::badges() as $b) {
            $row = $rows[$b['slug']] ?? null;
            $owned = (bool) $row;
            $target = max(1, (int) $b['arg']);

            $progress = (int) ($row->progress ?? 0);
            if (!$owned && !in_array($b['check'], $unmeasurable, true)) {
                $engine ??= resolve(\Local\Ranks\Badges\Engine::class);
                try {
                    $progress = $engine->valueFor((string) $b['check'], $userId);
                } catch (\Throwable $e) {
                    $progress = 0; // a broken check must still render a locked badge, just with no bar
                }
            }

            $out[] = $b + [
                'owned' => $owned,
                'awardedAt' => $row->awarded_at ?? null,
                'progress' => $progress,
                'target' => $target,
                'progressPct' => $owned ? 100 : (int) min(99, round(100 * min(1, $progress / $target))),
                'showcased' => (bool) ($row->showcased ?? false),
                'rarity' => $this->badgeRarity($b['slug']),
            ];
        }

        return $out;
    }

    /**
     * A cheap approximation of "closest locked badge", for surfaces that poll
     * often — the economy widget refreshes every five minutes for every
     * signed-in member (looksmax-economy/js/dist/forum.js). badges() above is
     * correct but costs one query per measurable locked badge (up to ~20 for a
     * fresh account); fine for a page a member opens on demand, wrong for a
     * background poll multiplied across the whole online population.
     *
     * Restricted to the badges reconstructable from the `users` row plus one
     * cheap join — posts, threads, reactions, tenure — which happens to cover
     * most of the ladder members actually chase (Hundred/Thousand/Ten Thousand,
     * Conversationalist/Agenda Setter, Well Received/Crowd Favourite/Consensus,
     * One Year/Three Years). Guides, sourced claims, read-through, dwell time
     * and necro-posting stay dashboard-only (badges()) — rarer wins, and not
     * worth a fourth and fifth query on every poll for every member.
     *
     * @return array{slug:string,name:string,icon:string,tier:string,progress:int,target:int,remaining:int,progressPct:int}|null
     */
    public function nearestMeasurableBadge(int $userId): ?array
    {
        $row = $this->db->table('users')->where('id', $userId)
            ->first(['comment_count', 'legacy_posts', 'legacy_threads', 'legacy_reactions', 'joined_at']);
        if (!$row) {
            return null;
        }

        $owned = $this->db->table('identity_badges')->where('user_id', $userId)->pluck('badge')->all();
        $ownedSet = array_flip($owned);

        $reactionsLocal = (int) $this->db->table('post_likes')
            ->join('posts', 'posts.id', '=', 'post_likes.post_id')
            ->where('posts.user_id', $userId)->count();

        $values = [
            'posts' => max((int) $row->comment_count, (int) $row->legacy_posts),
            'threads' => (int) $row->legacy_threads,
            'reactions' => (int) $row->legacy_reactions + $reactionsLocal,
            'tenure' => $row->joined_at
                ? max(0, (int) floor((time() - strtotime((string) $row->joined_at)) / 86400))
                : 0,
        ];

        $best = null;
        foreach (Catalog::BADGES as $b) {
            if (isset($ownedSet[$b['slug']]) || !isset($values[$b['check']])) {
                continue;
            }

            $target = max(1, (int) $b['arg']);
            $value = $values[$b['check']];
            $pct = (int) min(99, round(100 * min(1, $value / $target)));

            if ($best === null || $pct > $best['progressPct']) {
                $localized = Catalog::badge($b['slug']) ?? $b;
                $best = [
                    'slug' => $b['slug'],
                    'name' => $localized['name'],
                    'icon' => $b['icon'],
                    'tier' => $b['tier'],
                    'progress' => $value,
                    'target' => $target,
                    'remaining' => max(0, $target - $value),
                    'progressPct' => $pct,
                ];
            }
        }

        return $best;
    }

    /** The most recently earned badges, newest first — feeds the widget's "new achievement" celebration. */
    public function recentBadges(int $userId, int $limit = 3): array
    {
        $rows = $this->db->table('identity_badges')->where('user_id', $userId)
            ->orderByDesc('awarded_at')->limit($limit)->get(['badge', 'awarded_at']);

        $out = [];
        foreach ($rows as $r) {
            $b = Catalog::badge((string) $r->badge);
            if ($b) {
                $out[] = ['slug' => $b['slug'], 'name' => $b['name'], 'icon' => $b['icon'], 'tier' => $b['tier'], 'awardedAt' => (string) $r->awarded_at];
            }
        }

        return $out;
    }

    /**
     * What fraction of the population holds this badge. A trophy whose rarity
     * is invisible is just an icon; showing "0.4% of members" is what makes a
     * trophy case worth looking at.
     *
     * Cached in the settings table because it is a full scan and it moves
     * slowly; `identity:badges` refreshes it.
     */
    public function badgeRarity(string $slug): ?float
    {
        static $map = null;
        if ($map === null) {
            $raw = $this->db->table('settings')->where('key', 'identity.badge_rarity')->value('value');
            $map = $raw ? (json_decode($raw, true) ?: []) : [];
        }

        return isset($map[$slug]) ? (float) $map[$slug] : null;
    }

    // ------------------------------------------------------------- mutation

    /**
     * Equip a cosmetic. Returns an error string, or null on success.
     *
     * Validation is server-side and total: the UI hides what you cannot use,
     * but the UI is not a security boundary and a POST is a POST.
     */
    public function equip(User $user, string $type, ?string $item): ?string
    {
        if ($item === null || $item === '') {
            $col = $type === 'frame' ? 'avatar_frame' : 'name_style';
            $this->db->table('users')->where('id', $user->id)->update([$col => null]);

            return null;
        }

        $tier = $this->activeTier($user);

        if ($type === 'style') {
            $style = Catalog::style($item);
            if (!$style) {
                return Catalog::trans('forum.error.no_such_style');
            }
            if (!in_array($style['kind'], $tier['styles'], true)) {
                $need = Catalog::tierForStyleKind($style['kind']);

                return $need
                    ? Catalog::trans('forum.error.style_needs_tier', [
                        'tier' => $need['name'],
                        'kind' => Catalog::trans('lib.style_kind.' . $style['kind']),
                    ])
                    : Catalog::trans('forum.error.style_not_on_tier');
            }
            if ($style['kind'] !== 'rank' && !$this->owns((int) $user->id, 'style', $item)) {
                return Catalog::trans('forum.error.style_not_owned');
            }
            $this->db->table('users')->where('id', $user->id)->update(['name_style' => $item]);

            return null;
        }

        if ($type === 'frame') {
            $frame = Catalog::frame($item);
            if (!$frame) {
                return Catalog::trans('forum.error.no_such_frame');
            }
            if (Catalog::tier($frame['tier'])['rank'] > $tier['rank']) {
                return Catalog::trans('forum.error.frame_needs_tier', ['tier' => Catalog::tier($frame['tier'])['name']]);
            }
            if (!$this->owns((int) $user->id, 'frame', $item)) {
                return Catalog::trans('forum.error.frame_not_owned');
            }
            $this->db->table('users')->where('id', $user->id)->update(['avatar_frame' => $item]);

            return null;
        }

        return Catalog::trans('forum.error.unknown_cosmetic');
    }

    /** Price after the tier discount, which is the number the store must show. */
    public function priceFor(User $user, array $item): int
    {
        $tier = $this->activeTier($user);

        return (int) floor($item['price'] * (1 - $tier['discount']));
    }

    /**
     * Grant ownership without payment. Used by the importer, by awards and by
     * the admin grant path. Idempotent on (user, type, item).
     */
    public function grant(int $userId, string $type, string $item, string $source = 'award', int $paid = 0, ?string $expires = null): bool
    {
        try {
            $this->db->table('identity_inventory')->insert([
                'user_id' => $userId,
                'type' => $type,
                'item' => $item,
                'source' => $source,
                'paid' => $paid,
                'acquired_at' => date('Y-m-d H:i:s'),
                'expires_at' => $expires,
            ]);

            return true;
        } catch (\Throwable $e) {
            return false; // already owned
        }
    }

    /**
     * Start or extend a membership.
     *
     * Extending rather than replacing is the correct behaviour when someone
     * renews early, and it is the behaviour that stops a renewal from being a
     * downgrade.
     */
    public function grantTier(int $userId, string $tierSlug, string $source = 'grant', int $paid = 0, ?int $days = null): void
    {
        $tier = Catalog::tier($tierSlug);
        $days ??= $tier['days'];

        $current = $this->db->table('identity_memberships')
            ->where('user_id', $userId)->where('tier', $tierSlug)->where('active', 1)
            ->orderByDesc('expires_at')->first();

        $base = ($current && $current->expires_at && strtotime($current->expires_at) > time())
            ? strtotime($current->expires_at)
            : time();

        $expires = $days > 0 ? date('Y-m-d H:i:s', $base + $days * 86400) : null;

        $this->db->table('identity_memberships')->insert([
            'user_id' => $userId,
            'tier' => $tierSlug,
            'source' => $source,
            'paid' => $paid,
            'started_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expires,
            'active' => 1,
        ]);

        $this->refreshTier($userId);
    }

    /**
     * Recompute the cached tier on the user from the membership rows, and
     * reconcile the Flarum group that backs it.
     */
    public function refreshTier(int $userId): array
    {
        $now = date('Y-m-d H:i:s');

        $rows = $this->db->table('identity_memberships')
            ->where('user_id', $userId)->where('active', 1)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })->get();

        $best = Catalog::tier('standard');
        $expires = null;
        foreach ($rows as $r) {
            $t = Catalog::tier($r->tier);
            if ($t['rank'] > $best['rank']) {
                $best = $t;
                $expires = $r->expires_at;
            }
        }

        $this->db->table('users')->where('id', $userId)->update([
            'tier_slug' => $best['slug'],
            'tier_expires_at' => $expires,
        ]);

        // deactivate anything that has run out, so the history stays readable
        $this->db->table('identity_memberships')
            ->where('user_id', $userId)->where('active', 1)
            ->whereNotNull('expires_at')->where('expires_at', '<=', $now)
            ->update(['active' => 0]);

        $this->syncGroups($userId, $best['slug']);

        return $best;
    }

    /**
     * Project the tier onto Flarum groups.
     *
     * Exactly one tier group at a time, and only tier groups are touched — a
     * moderator who is also VIP must not lose Mod when their VIP lapses.
     */
    public function syncGroups(int $userId, string $tierSlug): void
    {
        $names = array_filter(array_column(Catalog::TIERS, 'group'));
        if (!$names) {
            return;
        }

        $groups = $this->db->table('groups')->whereIn('name_singular', $names)->pluck('id', 'name_singular');
        $want = Catalog::tier($tierSlug)['group'];
        $wantId = $want ? ($groups[$want] ?? null) : null;

        $this->db->table('group_user')
            ->where('user_id', $userId)
            ->whereIn('group_id', $groups->values()->all())
            ->when($wantId, fn ($q) => $q->where('group_id', '!=', $wantId))
            ->delete();

        if ($wantId && !$this->db->table('group_user')->where('user_id', $userId)->where('group_id', $wantId)->exists()) {
            $this->db->table('group_user')->insert(['user_id' => $userId, 'group_id' => $wantId]);
        }
    }

    public function refreshRank(int $userId): string
    {
        $lifetime = (int) $this->db->table('users')->where('id', $userId)->value('lifetime_points');
        $slug = Catalog::rankFor($lifetime)['slug'];
        $this->db->table('users')->where('id', $userId)->update(['rank_slug' => $slug]);

        return $slug;
    }
}
