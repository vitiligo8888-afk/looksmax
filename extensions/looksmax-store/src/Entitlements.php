<?php

namespace Local\Store;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * What a purchase entitles you to, and for how long.
 *
 * A cosmetic is owned forever and lives in the identity layer's inventory. A
 * BENEFIT is different: a boost runs out, a highlight ends, a pin unpins, a
 * consumable has charges. All of those are rows here with an `expires_at` or a
 * `uses_left`, and everything that reads a perk reads it through this class so
 * there is exactly one definition of "still in force".
 *
 * The rule that keeps this honest: nothing is deleted. An expired boost keeps
 * its row with a past expiry, a spent consumable keeps its row at zero uses, a
 * refunded entitlement is stamped `revoked_at`. "Why did my double earnings
 * stop" has to be answerable from the table.
 */
class Entitlements
{
    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function grant(
        int $userId,
        string $sku,
        string $kind,
        array $payload = [],
        ?string $expiresAt = null,
        ?int $uses = null,
        ?int $orderId = null,
        ?string $note = null
    ): int {
        return (int) $this->db->table('store_entitlements')->insertGetId([
            'user_id' => $userId,
            'order_id' => $orderId,
            'sku' => $sku,
            'kind' => $kind,
            'payload' => json_encode($payload),
            'granted_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
            'uses_left' => $uses,
            'note' => $note,
        ]);
    }

    /** Live rows, optionally of one kind. Consumables with 0 charges are out. */
    public function active(int $userId, ?string $kind = null): array
    {
        $now = date('Y-m-d H:i:s');
        $q = $this->db->table('store_entitlements')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->where(function ($q) {
                $q->whereNull('uses_left')->orWhere('uses_left', '>', 0);
            });

        if ($kind !== null) {
            $q->where('kind', $kind);
        }

        return $q->orderByDesc('id')->get()->map(function ($r) {
            $r->payload = json_decode((string) $r->payload, true) ?: [];
            return $r;
        })->all();
    }

    /**
     * Earn multiplier and daily-cap multiplier from active boosts.
     *
     * Boosts multiply rather than max(), which is a deliberate choice: two
     * stacked 2x boosts are 4x and cost twice as much. That comment used to
     * end there, and the claim that "the ceiling that stops that being a
     * problem is the daily cap, not the multiplier" was wrong: the daily cap
     * bounds how many AWARDS count per day, not how big each one is, and
     * `active($userId, 'boost')` has no limit on how many boost rows can be
     * simultaneously active — buying N of `boost-2x-24h` multiplies `earn` by
     * 2^N with nothing to stop it. Ledger::tierModifiers() feeds this
     * straight into `$delta = (int) round($base * $multiplier * $earn)`,
     * written into an `int(11)` `points` column whose signed range tops out
     * at 2,147,483,647 — and this install already carries a founder balance
     * of 999,999,999, so an uncapped multiplier is not a theoretical
     * overflow, it is one large `best_answer.awarded` (40 base) away from one
     * on an account that has stacked a double-digit N.
     *
     * The fix is a hard ceiling, not max_per_user on the boost SKUs: see
     * Config.php's header for why max_per_user is the wrong tool for a
     * repeatable, time-limited item. Reading it through Local\Store\Config
     * rather than a local constant is what makes it possible for an operator
     * to tighten the ceiling the same hour a stacking pattern is spotted,
     * exactly like every other number Catalogue.php already argues should be
     * a setting rather than a deploy.
     */
    public function boosts(int $userId): array
    {
        $earn = 1.0;
        $cap = 1.0;

        foreach ($this->active($userId, 'boost') as $row) {
            $earn *= (float) ($row->payload['multiplier'] ?? 1.0);
            $cap *= (float) ($row->payload['capBoost'] ?? 1.0);
        }

        $maxEarn = (float) Config::get($this->settings, 'boost.maxEarnMultiplier');
        $maxCap = (float) Config::get($this->settings, 'boost.maxCapBoost');

        return [
            'earn' => min($earn, max(1.0, $maxEarn)),
            'capBoost' => min($cap, max(1.0, $maxCap)),
        ];
    }

    /**
     * Spend one charge of a consumable. Returns the row that was spent, or
     * null. The decrement is conditional in SQL, so two simultaneous redeems
     * of the last charge cannot both succeed.
     */
    public function consume(int $userId, string $kind, ?string $sku = null): ?object
    {
        $rows = $this->active($userId, $kind);
        foreach ($rows as $row) {
            if ($sku !== null && $row->sku !== $sku) {
                continue;
            }
            if ($row->uses_left === null) {
                continue;
            }

            $affected = $this->db->table('store_entitlements')
                ->where('id', $row->id)
                ->where('uses_left', '>', 0)
                ->update(['uses_left' => $this->db->raw('uses_left - 1')]);

            if ($affected === 1) {
                return $row;
            }
        }

        return null;
    }

    public function revokeByOrder(int $orderId, string $note = 'refunded'): int
    {
        return $this->db->table('store_entitlements')
            ->where('order_id', $orderId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s'), 'note' => $note]);
    }

    /**
     * True if any charge of this order's entitlements has already been spent.
     *
     * This is the test that stops "buy a pin, use the pin, refund the pin".
     * The charges an order started with are read back from the catalogue row
     * rather than remembered on the entitlement, so a later change to the item
     * cannot retroactively make a spent charge look unspent.
     */
    public function partlyUsed(int $orderId): bool
    {
        $rows = $this->db->table('store_entitlements')
            ->where('store_entitlements.order_id', $orderId)
            ->leftJoin('store_items', 'store_items.sku', '=', 'store_entitlements.sku')
            ->get(['store_entitlements.uses_left', 'store_items.uses']);

        foreach ($rows as $r) {
            if ($r->uses_left !== null && (int) $r->uses_left < (int) ($r->uses ?? 0)) {
                return true;
            }
        }

        return false;
    }
}
