<?php

namespace Local\Store\Payments;

/**
 * The result of asking a provider for money. Never an exception: a decline is
 * an ordinary outcome and the order row has to record it either way.
 */
class Charge
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $reference = '',
        public readonly string $code = '',
        public readonly string $error = '',
        public readonly int $shortfall = 0
    ) {
    }

    public static function ok(string $reference): self
    {
        return new self(true, $reference);
    }

    /** `code` is machine-readable; `error` is what the buyer is shown. */
    public static function failed(string $code, string $error, int $shortfall = 0): self
    {
        return new self(false, '', $code, $error, $shortfall);
    }
}
