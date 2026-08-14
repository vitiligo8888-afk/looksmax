<?php

namespace Local\Store\Payments;

/**
 * The seam a real payment processor drops into.
 *
 * Two implementations ship: PointsProvider, which is real and moves the
 * forum's own currency through the ledger, and MockCardProvider, which is the
 * ONLY mocked thing in this extension and exists so the credit-pack path can
 * be built and tested end to end before a merchant account exists.
 *
 * What swapping in Stripe actually takes, in full:
 *
 *   1. A class implementing this interface. `charge()` creates a PaymentIntent
 *      with `amount` in minor units and returns Charge::ok($intent->id).
 *   2. One thing this interface deliberately does NOT have: a redirect. Card
 *      payments are asynchronous and the buyer leaves the page, so a real
 *      provider needs a third state — Charge::pending($clientSecret) — plus a
 *      webhook route that moves the order from `pending` to `granted` by
 *      calling Purchase::fulfil($orderId). The order state machine and
 *      store_orders.provider_ref were built for exactly that: the order is
 *      written before the money moves and the grant is a separate step.
 *   3. Idempotency is already handled on our side (store_orders.order_once);
 *      pass the same key to the processor as its idempotency key so a retried
 *      request cannot create a second intent.
 *   4. Refunds: `refund()` maps to a Stripe refund; the entitlement revocation
 *      and the audit row happen in Purchase::refund() regardless of provider.
 *   5. Tax, invoices, chargebacks and the disclosure text on the card are the
 *      parts nobody costs. A chargeback has to revoke the entitlement, which
 *      is the same code path as a refund, and that is why refunds go through
 *      one method rather than living in the controller.
 *
 * Until then the store sells for points, which is real money nobody has to
 * take a card number for.
 */
interface PaymentProvider
{
    public function key(): string;

    public function label(): string;

    /** 'points', or an ISO currency code for real money. */
    public function currency(): string;

    /**
     * @param int    $amount    points, or minor units of the currency
     * @param string $reference our own idempotent reference for this order
     */
    public function charge(int $userId, int $amount, string $reference, array $context = []): Charge;

    public function refund(int $userId, int $amount, string $reference, string $providerRef): Charge;
}
