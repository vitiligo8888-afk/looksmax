<?php

namespace Local\Store\Payments;

use Illuminate\Database\ConnectionInterface;
use Local\Economy\Ledger;

/**
 * Payment in the forum's own currency. Not a mock: this moves a real balance
 * through the real ledger and every movement is a row in economy_transactions.
 *
 * The debit is Ledger::spend(), which takes a row lock on the user before
 * comparing the balance, so the two-tabs-one-balance race cannot spend the
 * same points twice. The refund is Ledger::credit() with countsForRank false —
 * award() would apply the buyer's membership earn multiplier and hand back
 * more than was taken, which is a money printer wearing a refund's clothes.
 */
class PointsProvider implements PaymentProvider
{
    public function __construct(
        protected Ledger $ledger,
        protected ConnectionInterface $db
    ) {
    }

    public function key(): string
    {
        return 'points';
    }

    public function label(): string
    {
        return resolve('translator')->trans('local-looksmax-store.forum.pay.credits_label');
    }

    public function currency(): string
    {
        return 'points';
    }

    public function charge(int $userId, int $amount, string $reference, array $context = []): Charge
    {
        if ($amount === 0) {
            return Charge::ok($reference); // free item, still an order
        }

        $spent = $this->ledger->spend($userId, $amount, 'store.purchase', $reference);

        if ($spent === 0) {
            $balance = (int) $this->db->table('users')->where('id', $userId)->value('points');

            return Charge::failed(
                'insufficient_funds',
                resolve('translator')->trans('local-looksmax-store.forum.pay.short', ['count' => max(0, $amount - $balance)]),
                max(0, $amount - $balance)
            );
        }

        return Charge::ok($reference);
    }

    public function refund(int $userId, int $amount, string $reference, string $providerRef): Charge
    {
        $given = $this->ledger->credit($userId, $amount, 'store.refund', $reference, false);

        return $given === $amount
            ? Charge::ok($reference)
            : Charge::failed('already_refunded', resolve('translator')->trans('local-looksmax-store.forum.error.already_refunded'));
    }
}
