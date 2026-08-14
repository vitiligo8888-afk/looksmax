<?php

namespace Local\Store\Grants;

use Local\Store\Entitlements;

/**
 * Everything whose effect is a row with a clock or a charge count on it:
 * earning boosts, raised caps, streak freezes, and the consumables that are
 * redeemed later against a thread or a username.
 *
 * These are separated from cosmetics because they are the ones that END. A
 * boost with no expiry is a permanent economy change sold for the price of a
 * day, and a pin with no expiry is a moderation problem sold for 9,000 points.
 * Both facts are enforced by the expiry column, not by anybody remembering.
 *
 * Consumables are granted with charges and NOT applied: buying a pin does not
 * pin anything, because the buyer has to choose which thread. Redeem handles
 * that, spends the charge conditionally in SQL, and writes the effect row that
 * `store:expire` later reverses.
 */
class BenefitGrant implements Grant
{
    public function __construct(protected Entitlements $entitlements)
    {
    }

    public function kinds(): array
    {
        return ['boost', 'streakfreeze', 'highlight', 'sticky', 'bump', 'rename'];
    }

    public function apply(int $userId, object $item, array $payload, int $orderId): array
    {
        $expires = null;
        if (isset($payload['hours'])) {
            $expires = date('Y-m-d H:i:s', time() + (int) $payload['hours'] * 3600);
        } elseif ($item->duration_days !== null && (int) $item->duration_days > 0 && $item->uses === null) {
            $expires = date('Y-m-d H:i:s', time() + (int) $item->duration_days * 86400);
        }

        $uses = $item->uses === null ? null : (int) $item->uses;

        $id = $this->entitlements->grant(
            $userId,
            $item->sku,
            $item->kind,
            $payload,
            $expires,
            $uses,
            $orderId
        );

        return [
            'entitlement' => $id,
            'kind' => $item->kind,
            'expiresAt' => $expires,
            'uses' => $uses,
            // Consumables need the buyer told what to do next, or the purchase
            // looks like it did nothing.
            'redeem' => $uses !== null ? $item->kind : null,
        ];
    }

    public function revoke(int $userId, object $entitlement): void
    {
        // The entitlement row itself is stamped revoked by the refund path;
        // nothing else was written, so there is nothing else to undo.
    }
}
