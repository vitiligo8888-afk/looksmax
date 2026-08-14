<?php

namespace Local\Store\Grants;

use Illuminate\Database\ConnectionInterface;

/**
 * A bundle is a list of SKUs granted at one price.
 *
 * It grants the CONTENTS, not a bundle object, so a refund walks the same
 * revoke handlers as the parts and the buyer's inventory looks identical to
 * having bought them separately. Every part is granted inside the same
 * transaction as the payment: a bundle that half-lands is the worst version of
 * the defect this whole design exists to prevent.
 *
 * A part that is already owned is skipped rather than fatal — otherwise
 * owning one gradient would make the starter bundle unbuyable forever — but if
 * NOTHING in it is new, the purchase is refused before the charge.
 */
class BundleGrant implements Grant
{
    public function __construct(
        protected Registry $registry,
        protected ConnectionInterface $db
    ) {
    }

    public function kinds(): array
    {
        return ['bundle'];
    }

    public function apply(int $userId, object $item, array $payload, int $orderId): array
    {
        $skus = (array) ($payload['skus'] ?? []);
        if (!$skus) {
            throw new \RuntimeException('that bundle is empty');
        }

        $granted = [];
        $skipped = [];

        foreach ($skus as $sku) {
            $part = $this->db->table('store_items')->where('sku', $sku)->first();
            if (!$part) {
                throw new \RuntimeException('bundle refers to a missing item: ' . $sku);
            }

            $partPayload = json_decode((string) $part->payload, true) ?: [];
            $handler = $this->registry->for($part->kind);

            try {
                $granted[$sku] = $handler->apply($userId, $part, $partPayload, $orderId);
            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'already')) {
                    $skipped[$sku] = $e->getMessage();
                    continue;
                }
                throw $e;
            }
        }

        if (!$granted) {
            throw new \RuntimeException('you already own everything in this bundle');
        }

        return ['bundle' => array_keys($granted), 'skipped' => array_keys($skipped)];
    }

    public function revoke(int $userId, object $entitlement): void
    {
        // Every part wrote its own entitlement row against the same order, and
        // the refund path revokes rows, so each part is revoked by its own
        // handler. Nothing extra to undo here.
    }
}
