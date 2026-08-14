<?php

namespace Local\Store;

/**
 * A refusal the buyer is entitled to see: not enough credits, sold out, closed.
 *
 * Separate from an ordinary exception because the two need different
 * treatment. A refusal is expected, is shown verbatim, and marks the order
 * `refused`; anything else is a bug, is logged, is shown as a generic apology,
 * and marks the order `failed`. Collapsing them is how "Integrity constraint
 * violation" ends up in a shopping cart.
 *
 * The property is `reason` and not `code` for a reason worth keeping: PHP 8.3
 * refuses to redeclare Exception's non-readonly `$code` as a readonly promoted
 * property, and it does it with a FATAL at class-load time. That fatal was
 * emitted mid-request, so php-fpm returned a 200 with an HTML error body, the
 * open transaction was rolled back by the connection closing, and the store's
 * own e2e suite recorded "insufficient funds returned 200" and "three of five
 * buyers won a stock of one" — with the database in fact perfectly consistent
 * (one order granted, one debit, stock_sold 1). Every visible symptom pointed
 * at the concurrency control; the cause was a property name.
 */
class PurchaseRefused extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $shortfall = 0
    ) {
        parent::__construct($message);
    }
}
