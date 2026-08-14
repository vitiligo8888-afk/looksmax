<?php

namespace Local\Store\Grants;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\Ranks\Standing;
use Local\Store\Entitlements;

/**
 * Username styles and avatar frames.
 *
 * Ownership itself is not stored here — it belongs to the identity layer's
 * `identity_inventory`, which is what the name renderer reads. Writing a
 * second ownership table in the store would give the forum two answers to
 * "does this person own Fire". So this handler calls Standing and records a
 * store-side entitlement purely as the link between an order and what it
 * bought, which is what a refund needs.
 *
 * Buying something wearable equips it. Nobody buys a colour to leave it in a
 * drawer, and a purchase whose effect is invisible until you find a second
 * screen reads as a purchase that did not work.
 */
class CosmeticGrant implements Grant
{
    public function __construct(
        protected Standing $standing,
        protected Entitlements $entitlements,
        protected ConnectionInterface $db
    ) {
    }

    public function kinds(): array
    {
        return ['style', 'frame'];
    }

    public function apply(int $userId, object $item, array $payload, int $orderId): array
    {
        $type = $item->kind;                 // style | frame
        $slug = (string) ($payload['item'] ?? '');

        if ($slug === '') {
            throw new \RuntimeException('catalogue row ' . $item->sku . ' has no item in its payload');
        }

        $granted = $this->standing->grant($userId, $type, $slug, 'purchase', (int) $item->price);

        if (!$granted) {
            // Already in inventory. The purchase gates should have caught this,
            // so reaching here means two requests raced; refusing rolls the
            // payment back rather than charging for a duplicate.
            throw new \RuntimeException('already owned');
        }

        $this->entitlements->grant($userId, $item->sku, $type, ['item' => $slug], null, null, $orderId);

        $user = User::find($userId);
        $equipped = false;
        if ($user) {
            $equipped = $this->standing->equip($user, $type, $slug) === null;
        }

        return ['item' => $slug, 'type' => $type, 'equipped' => $equipped];
    }

    public function revoke(int $userId, object $entitlement): void
    {
        $payload = json_decode((string) $entitlement->payload, true) ?: [];
        $slug = (string) ($payload['item'] ?? '');
        $type = $entitlement->kind;

        if ($slug === '') {
            return;
        }

        $this->db->table('identity_inventory')
            ->where('user_id', $userId)->where('type', $type)->where('item', $slug)
            ->delete();

        // Unequip only if this is what they are wearing, so a refund of a
        // spare colour does not strip the one they kept.
        $column = $type === 'frame' ? 'avatar_frame' : 'name_style';
        $this->db->table('users')
            ->where('id', $userId)->where($column, $slug)
            ->update([$column => null]);
    }
}
