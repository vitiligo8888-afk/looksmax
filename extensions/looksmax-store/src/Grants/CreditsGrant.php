<?php

namespace Local\Store\Grants;

use Local\Economy\Ledger;
use Local\Store\Entitlements;

/**
 * Credits themselves: the card-bought packs, and credit gifts between members.
 *
 * Ledger::credit(), never award(): award() applies the recipient's membership
 * earn multiplier, so an Elite member buying a 10,000 pack would receive
 * 14,000. It also passes countsForRank = false, because buying credits must
 * not buy a rank — the ladder is earned and that is the only reason it means
 * anything.
 */
class CreditsGrant implements Grant
{
    public function __construct(
        protected Ledger $ledger,
        protected Entitlements $entitlements
    ) {
    }

    public function kinds(): array
    {
        return ['credits'];
    }

    public function apply(int $userId, object $item, array $payload, int $orderId): array
    {
        $amount = (int) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \RuntimeException('that pack has no credits in it');
        }

        $given = $this->ledger->credit($userId, $amount, 'store.credits', 'order:' . $orderId, false);
        if ($given !== $amount) {
            throw new \RuntimeException('credits were already issued for this order');
        }

        $this->entitlements->grant($userId, $item->sku, 'credits', ['amount' => $amount], null, null, $orderId);

        return ['credits' => $amount];
    }

    public function revoke(int $userId, object $entitlement): void
    {
        $payload = json_decode((string) $entitlement->payload, true) ?: [];
        $amount = (int) ($payload['amount'] ?? 0);

        if ($amount > 0) {
            // Can push a balance negative if the credits have already been
            // spent. That is the correct outcome for a chargeback — the debt is
            // visible and the next earnings pay it off — and it is why the
            // ledger is signed rather than clamped.
            $this->ledger->credit($userId, -$amount, 'store.clawback', 'entitlement:' . $entitlement->id, false);
        }
    }
}
