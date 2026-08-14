<?php

namespace Local\Store\Grants;

/**
 * One item kind, one handler.
 *
 * `apply()` runs inside the purchase transaction. It either finishes or it
 * throws, and a throw rolls the payment back with it — which is the whole
 * reason grants are not done in the controller after the money moves. There is
 * no third outcome and no partial success to clean up afterwards.
 *
 * `revoke()` is its inverse and runs on refund, on expiry and on a chargeback.
 * A grant whose inverse cannot be written is a grant that must not be sold.
 */
interface Grant
{
    /** Item kinds this handler owns. */
    public function kinds(): array;

    /**
     * @return array summary for the order row and the buyer's confirmation
     * @throws \RuntimeException to abort and roll back the whole purchase
     */
    public function apply(int $userId, object $item, array $payload, int $orderId): array;

    public function revoke(int $userId, object $entitlement): void;
}
