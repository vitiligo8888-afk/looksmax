<?php

namespace Local\Store\Payments;

use Illuminate\Database\ConnectionInterface;
use Local\Economy\Ledger;

/**
 * Payment in Oro — the paid currency.
 *
 * The exact twin of PointsProvider, one column over: it spends the oro balance
 * through Ledger::oroSpend() (a locking debit, so two tabs cannot spend the
 * same oro twice) and refunds through Ledger::oroCredit() with no multiplier,
 * because a refund that hands back more than was taken is a money printer.
 *
 * The difference from PointsProvider is only WHICH balance moves. Oro is bought
 * with money and spent on premium items; points are earned and spent on the
 * rest. Neither provider ever touches the other's column.
 */
class OroProvider implements PaymentProvider
{
    public function __construct(
        protected Ledger $ledger,
        protected ConnectionInterface $db
    ) {
    }

    public function key(): string
    {
        return 'oro';
    }

    public function label(): string
    {
        return resolve('translator')->trans('local-looksmax-store.forum.pay.oro_label');
    }

    public function currency(): string
    {
        return 'oro';
    }

    public function charge(int $userId, int $amount, string $reference, array $context = []): Charge
    {
        if ($amount === 0) {
            return Charge::ok($reference); // free item, still an order
        }

        $spent = $this->ledger->oroSpend($userId, $amount, 'store.purchase', $reference);

        if ($spent === 0) {
            $balance = $this->ledger->oroBalance($userId);

            return Charge::failed(
                'insufficient_funds',
                resolve('translator')->trans('local-looksmax-store.forum.pay.short_oro', ['count' => max(0, $amount - $balance)]),
                max(0, $amount - $balance)
            );
        }

        return Charge::ok($reference);
    }

    public function refund(int $userId, int $amount, string $reference, string $providerRef): Charge
    {
        $given = $this->ledger->oroCredit($userId, $amount, 'store.refund', $reference);

        return $given === $amount
            ? Charge::ok($reference)
            : Charge::failed('already_refunded', resolve('translator')->trans('local-looksmax-store.forum.error.already_refunded'));
    }
}
