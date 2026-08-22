<?php

namespace Local\Cosmetics;

use Illuminate\Database\ConnectionInterface;

/**
 * Does this account actually hold this cosmetic?
 *
 * The rule the operator set, and the reason for every query below: a frame
 * renders only when the target holds it FOR REAL. There is no "preview mode"
 * that leaks into the page, no client-supplied slug that is trusted, and no
 * table this extension writes in order to make something appear.
 *
 * Four sources, all of them owned by somebody else and all read-only here:
 *
 *   store_entitlements   the store's record of a delivered purchase. Live means
 *                        revoked_at IS NULL and (expires_at IS NULL OR future).
 *                        This is the primary source and the only one that can
 *                        be refunded.
 *   identity_inventory   the identity extension's ownership table. It exists
 *                        because looksmax-ranks has a SECOND purchase endpoint
 *                        that predates the store and writes only here
 *                        (Api/IdentityActionController.php:63-110, and
 *                        HANDOFF-STORE.md §2 records it as a known duplicate).
 *                        Somebody who bought a frame through that path paid
 *                        real credits, so ignoring this table would blank an
 *                        avatar that was paid for.
 *   users.tier_slug      an active membership. Tiers already advertise cosmetic
 *                        benefits (looksmax-ranks/src/Catalog.php:73-94) that
 *                        nothing implemented.
 *   identity_badges      earned or imported history. Never sold, never granted
 *                        by this extension.
 *
 * ── performance ────────────────────────────────────────────────────────────
 * A discussion listing serializes ~50 users and the index ~50 more. Per-user
 * queries there are 50 round trips. Instead, the three tables above are read
 * ONCE per request into static maps, each bounded and small:
 *   store_entitlements  filtered to kind IN (frame, banner)      —  0 rows today
 *   identity_inventory  filtered to type IN (frame, banner)      — 11 rows today
 *   identity_badges     filtered to the badges the catalogue actually
 *                       references (5 of them)                   — 397 rows today
 * Measured 2026-08-13 on the live database. The `founding` badge (1,412 rows)
 * is deliberately not referenced by any definition, both because a cosmetic
 * everybody holds is not a cosmetic and because it would triple this working
 * set.
 */
class Ownership
{
    /** Ladder order. Mirrors looksmax-ranks/src/Catalog.php TIERS ranks. */
    public const TIERS = ['standard', 'plus', 'vip', 'elite', 'founder'];

    private static ?array $entitlements = null;   // user_id => [slug => true]
    private static ?array $inventory = null;      // user_id => [type:slug => true]
    private static ?array $badges = null;         // user_id => [badge => true]
    private static ?array $loadout = null;        // user_id => object|null
    private static ?int $loadoutRows = null;

    public function __construct(
        protected ConnectionInterface $db,
        protected Definitions $defs,
        protected BannerUploads $bannerUploads
    ) {
    }

    /** @return string[] every tier at or above $tier */
    public static function tiersAtOrAbove(string $tier): array
    {
        $i = array_search($tier, self::TIERS, true);

        return $i === false ? [] : array_slice(self::TIERS, $i);
    }

    /** Reset the per-request caches. Only the console and the tests need this. */
    public static function flush(): void
    {
        self::$entitlements = self::$inventory = self::$badges = self::$loadout = null;
        self::$loadoutRows = null;
    }

    // ------------------------------------------------------------- ownership

    /**
     * Every slug of one kind this user may wear, each with WHY.
     *
     * The reason travels with the answer on purpose: the equip screen shows
     * "owned since you bought it" and "owned because you have the Old Guard
     * badge" differently, and a support question about a missing frame is
     * answerable from the API response instead of from four tables.
     *
     * @return array<string,string> slug => reason (sku|inventory|tier|badge)
     */
    public function ownedOfKind(int $userId, string $kind, ?string $tierSlug = null, ?string $tierExpires = null): array
    {
        $out = [];
        foreach ($this->defs->ofKind($kind) as $slug => $def) {
            $why = $this->reason($userId, $def, $tierSlug, $tierExpires);
            if ($why !== null) {
                $out[$slug] = $why;
            }
        }

        return $out;
    }

    /** Null when the user does not hold it, otherwise the reason it is held. */
    public function reason(int $userId, array $def, ?string $tierSlug = null, ?string $tierExpires = null): ?string
    {
        $obtain = $def['obtain'];
        $type = (string) ($obtain['type'] ?? 'never');

        // A store purchase is honoured no matter what the obtain rule says:
        // somebody who bought a frame and then let their VIP lapse keeps the
        // frame. That is what "owned" means, and the store's refund path is the
        // only thing that takes it back.
        if ($def['sku'] !== null) {
            if (isset($this->entitlements()[$userId][$def['sku']])) {
                return 'sku';
            }
            if (isset($this->inventory()[$userId][$def['kind'] . ':' . $def['slug']])) {
                return 'inventory';
            }
        }

        if ($type === 'sku') {
            return null;
        }

        if ($type === 'tier') {
            if ($tierSlug === null) {
                return null;
            }
            if ($tierExpires !== null && strtotime($tierExpires) < time()) {
                return null; // lapsed: the benefit stops, a purchase would not
            }
            $need = (string) ($obtain['tier'] ?? '');
            $have = array_search($tierSlug, self::TIERS, true);
            $want = array_search($need, self::TIERS, true);

            return ($have !== false && $want !== false && $have >= $want) ? 'tier' : null;
        }

        if ($type === 'badge') {
            return isset($this->badges()[$userId][(string) ($obtain['badge'] ?? '')]) ? 'badge' : null;
        }

        // 'never' — award only. It can still be held through the identity
        // inventory, which is checked above for sku-bearing rows; check it here
        // too so an awarded frame with no sku renders.
        if (isset($this->inventory()[$userId][$def['kind'] . ':' . $def['slug']])) {
            return 'inventory';
        }

        return null;
    }

    // --------------------------------------------------------------- loadout

    /**
     * What this user has equipped.
     *
     * `$legacyFrame` is `users.avatar_frame`, and the fallback is not
     * defensive programming — it is required for correctness. The store's own
     * grant handler EQUIPS what it delivers (looksmax-store/src/Grants/
     * CosmeticGrant.php:62, "nobody buys a colour to leave it in a drawer"),
     * and it does that through Standing::equip, which writes that column and
     * knows nothing about this extension. So a frame bought thirty seconds ago
     * has no row here and must still render.
     *
     * The fallback applies only when there is NO row at all. Once somebody has
     * used the wardrobe, a NULL frame in their row means "I took it off" and
     * must not be overridden by a stale column.
     *
     * @return array{frame: ?string, banner: ?string}
     */
    public function loadout(int $userId, ?string $legacyFrame = null): array
    {
        $rows = $this->loadoutMap();
        if ($rows !== null) {
            $r = $rows[$userId] ?? null;
        } else {
            static $single = [];
            if (!array_key_exists($userId, $single)) {
                $single[$userId] = $this->db->table('cosmetic_loadout')->where('user_id', $userId)->first();
            }
            $r = $single[$userId];
        }

        if ($r === null) {
            return ['frame' => $legacyFrame ?: null, 'banner' => null];
        }

        return [
            'frame' => $r->frame ?? null,
            'banner' => $r->banner ?? null,
        ];
    }

    /**
     * The equipped cosmetics this user is ENTITLED to wear, as bare slugs.
     *
     * Slugs and not specs, deliberately. This runs on every user in every API
     * payload, and a discussion listing carries fifty of them: shipping the
     * render CSS per user would repeat the same twelve custom properties fifty
     * times. The specs are injected ONCE per page in the head script
     * (src/InjectCosmetics.php) and the decorator joins the two by slug.
     *
     * @return array<string,string> kind => slug
     */
    public function live(int $userId, ?string $tierSlug, ?string $tierExpires, ?string $legacyFrame = null): array
    {
        $out = [];
        foreach ($this->loadout($userId, $legacyFrame) as $kind => $slug) {
            if ($slug === null) {
                continue;
            }

            // A custom upload is not a catalogue row — see BannerUploads.php.
            // The loadout row is trusted here (no re-check against the tier
            // gate or the ban flag): moderate() and BannerUploads::remove()
            // BOTH clear this same loadout column in the same write that
            // takes the file away, so 'custom' surviving to here already
            // means an active, undeleted upload exists. bannerUrl rides
            // alongside the slug because 'custom' has no DEFS.banner entry on
            // the client for the decorator to resolve a URL from — see
            // js/dist/forum.js decorateBanner()'s handling of this key.
            if ($kind === 'banner' && $slug === 'custom') {
                $out[$kind] = 'custom';
                $out['bannerUrl'] = $this->bannerUploads->urlFor($userId); // no query — see urlFor()'s docblock
                continue;
            }

            $def = $this->defs->find($kind, $slug);
            if ($def === null) {
                continue;
            }
            if ($this->reason($userId, $def, $tierSlug, $tierExpires) === null) {
                // Equipped but no longer held — refunded, revoked, lapsed tier,
                // or a badge recomputed away. Render nothing rather than
                // trusting the stale loadout row.
                continue;
            }
            $out[$kind] = $slug;
        }

        return $out;
    }

    // ----------------------------------------------------------- bulk loads

    private function entitlements(): array
    {
        if (self::$entitlements !== null) {
            return self::$entitlements;
        }

        $now = date('Y-m-d H:i:s');
        $map = [];
        $rows = $this->db->table('store_entitlements')
            ->whereIn('kind', ['frame', 'banner'])
            ->whereNull('revoked_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->get(['user_id', 'sku']);

        foreach ($rows as $r) {
            $map[(int) $r->user_id][(string) $r->sku] = true;
        }

        return self::$entitlements = $map;
    }

    private function inventory(): array
    {
        if (self::$inventory !== null) {
            return self::$inventory;
        }

        $now = date('Y-m-d H:i:s');
        $map = [];
        $rows = $this->db->table('identity_inventory')
            ->whereIn('type', ['frame', 'banner'])
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->get(['user_id', 'type', 'item']);

        foreach ($rows as $r) {
            $map[(int) $r->user_id][$r->type . ':' . $r->item] = true;
        }

        return self::$inventory = $map;
    }

    private function badges(): array
    {
        if (self::$badges !== null) {
            return self::$badges;
        }

        // Only the badges some definition actually references. Reading the whole
        // table would be 6,333 rows for an answer about five of them.
        $wanted = [];
        foreach ($this->defs->all() as $kind => $defs) {
            foreach ($defs as $def) {
                if (($def['obtain']['type'] ?? '') === 'badge' && !empty($def['obtain']['badge'])) {
                    $wanted[$def['obtain']['badge']] = true;
                }
            }
        }

        $map = [];
        if ($wanted) {
            foreach ($this->db->table('identity_badges')->whereIn('badge', array_keys($wanted))->get(['user_id', 'badge']) as $r) {
                $map[(int) $r->user_id][(string) $r->badge] = true;
            }
        }

        return self::$badges = $map;
    }

    /**
     * The whole loadout table, or null if it has grown past the point where
     * loading it wholesale is cheaper than asking per user.
     *
     * The threshold is not decoration: this table gains a row for every account
     * that ever equips anything, so on a 30k-account forum it eventually stops
     * being small. Above the cap, `loadout()` falls back to one memoised query
     * per user, which is the behaviour a serializer can afford for the handful
     * of distinct users in one payload.
     */
    private function loadoutMap(): ?array
    {
        if (self::$loadout !== null) {
            return self::$loadout;
        }
        if (self::$loadoutRows === null) {
            self::$loadoutRows = (int) $this->db->table('cosmetic_loadout')->count();
        }
        if (self::$loadoutRows > 5000) {
            return null;
        }

        $map = [];
        foreach ($this->db->table('cosmetic_loadout')->get(['user_id', 'frame', 'banner']) as $r) {
            $map[(int) $r->user_id] = $r;
        }

        return self::$loadout = $map;
    }
}
