<?php

namespace Local\Store\Grants;

use Illuminate\Database\ConnectionInterface;
use Local\Ranks\Catalog;
use Local\Store\Entitlements;

/**
 * Boxes.
 *
 * Three rules, all of them there because loot boxes are where forum economies
 * usually start lying to people:
 *
 *   1. The odds on the card are the odds in this file, and the card renders
 *      them from the same payload the roll reads.
 *   2. A box never rolls something you already own. Duplicates are the oldest
 *      trick in the genre and they turn a gamble into a tax.
 *   3. If a bucket has nothing left to give — every animated style already
 *      owned — the roll falls through to the next bucket rather than failing,
 *      and if the whole pool is exhausted the purchase is refused BEFORE the
 *      charge, so nobody pays for an empty box.
 *
 * The result is written to the order meta, so "what did I get" is answerable
 * from the database months later and not just from a toast that has gone.
 */
class MysteryGrant implements Grant
{
    public function __construct(
        protected CosmeticGrant $cosmetics,
        protected Entitlements $entitlements,
        protected ConnectionInterface $db
    ) {
    }

    public function kinds(): array
    {
        return ['mystery'];
    }

    public function apply(int $userId, object $item, array $payload, int $orderId): array
    {
        $type = (string) ($payload['type'] ?? 'style');
        $pool = $this->pool($userId, $type);

        if (!$pool) {
            throw new \RuntimeException('you already own everything this box can contain');
        }

        $won = $this->roll($pool, (array) ($payload['odds'] ?? []), $type);

        // Reuse the cosmetic handler so a box grant and a direct purchase are
        // the same code path — including the equip, the inventory row and the
        // refund inverse.
        $fake = (object) [
            'sku' => $item->sku,
            'kind' => $type,
            'price' => 0,
            'duration_days' => null,
            'uses' => null,
        ];

        $result = $this->cosmetics->apply($userId, $fake, ['item' => $won['slug']], $orderId);

        return $result + [
            'box' => $item->sku,
            'won' => ['slug' => $won['slug'], 'name' => $won['name'], 'rarity' => $won['rarity'], 'worth' => (int) $won['price']],
        ];
    }

    public function revoke(int $userId, object $entitlement): void
    {
        $this->cosmetics->revoke($userId, $entitlement);
    }

    /** Everything of this type the user does not already own. */
    private function pool(int $userId, string $type): array
    {
        $owned = $this->db->table('identity_inventory')
            ->where('user_id', $userId)->where('type', $type)->pluck('item')->all();

        $all = $type === 'frame' ? Catalog::FRAMES : Catalog::STYLES;

        return array_values(array_filter($all, function ($row) use ($owned, $type) {
            if (in_array($row['slug'], $owned, true)) {
                return false;
            }
            if ((int) $row['price'] === 0) {
                return false; // awarded-only items are not box loot
            }
            if ($type === 'style' && $row['kind'] === 'rank') {
                return false;
            }

            return true;
        }));
    }

    /**
     * Weighted pick. Styles are bucketed by `kind`, frames by `rarity`, which
     * is what each catalogue actually varies along.
     */
    private function roll(array $pool, array $odds, string $type): array
    {
        $key = $type === 'frame' ? 'rarity' : 'kind';

        $buckets = [];
        foreach ($pool as $row) {
            $buckets[$row[$key]][] = $row;
        }

        // Order the buckets by their declared odds, descending, and walk them
        // with a single random draw. Empty buckets are skipped, and their
        // weight goes to whatever is left rather than to a failed roll.
        $weights = [];
        foreach ($buckets as $name => $rows) {
            $weights[$name] = (float) ($odds[$name] ?? 0.01);
        }

        $total = array_sum($weights) ?: 1.0;
        $draw = mt_rand(0, 999999) / 1000000 * $total;

        foreach ($weights as $name => $w) {
            $draw -= $w;
            if ($draw <= 0) {
                $rows = $buckets[$name];
                return $rows[array_rand($rows)];
            }
        }

        $rows = $buckets[array_key_first($buckets)];

        return $rows[array_rand($rows)];
    }
}
