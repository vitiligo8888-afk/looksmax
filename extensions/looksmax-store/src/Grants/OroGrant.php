<?php

namespace Local\Store\Grants;

use Local\Economy\Ledger;
use Local\Store\Entitlements;

/**
 * Oro packs: the money-bought currency, and oro gifts between members.
 *
 * This is the money -> paid-currency path. A `kind: oro` item is charged in
 * real money (Purchase routes it through the card provider) and this grant
 * lands the oro with Ledger::oroCredit() — never the points ledger, so buying
 * oro can never move points or a rank.
 *
 * The twin of CreditsGrant, which did the same for the single-currency world.
 * The difference is the destination column: oro, not points. That difference
 * is the entire reason this file exists — it is what makes the two currencies
 * two currencies.
 */
class OroGrant implements Grant
{
    public function __construct(
        protected Ledger $ledger,
        protected Entitlements $entitlements
    ) {
    }

    public function kinds(): array
    {
        return ['oro'];
    }

    public function apply(int $userId, object $item, array $payload, int $orderId): array
    {
        $amount = (int) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \RuntimeException('that pack has no oro in it');
        }

        $given = $this->ledger->oroCredit($userId, $amount, 'store.oro', 'order:' . $orderId);
        if ($given !== $amount) {
            throw new \RuntimeException('oro was already issued for this order');
        }

        $this->entitlements->grant($userId, $item->sku, 'oro', ['amount' => $amount], null, null, $orderId);

        return ['oro' => $amount];
    }

    public function revoke(int $userId, object $entitlement): void
    {
        $payload = json_decode((string) $entitlement->payload, true) ?: [];
        $amount = (int) ($payload['amount'] ?? 0);

        if ($amount > 0) {
            // Can push the balance negative if the oro is already spent. That is
            // the correct outcome for a chargeback — the debt is visible and the
            // next purchase pays it off — which is why oroCredit is signed.
            $this->ledger->oroCredit($userId, -$amount, 'store.clawback', 'entitlement:' . $entitlement->id);
        }
    }
}
