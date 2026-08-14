<?php

namespace Local\Store\Payments;

/**
 * The one mock in this extension.
 *
 * It approves every charge, invents an authorisation reference and touches no
 * network. It exists so the credit-pack path — the path where real money buys
 * forum currency — is built, routed, recorded and tested now, instead of being
 * a hole that gets discovered the week a processor is finally connected.
 *
 * It is deliberately loud about being a mock: the reference is prefixed
 * `mock_`, the store card says card payment is not live, and Purchase writes
 * `provider: card` on the order so every fake sale can be found in one query:
 *
 *   select * from store_orders where provider = 'card';
 *
 * A test card number that declines is supported (`4000000000000002`, Stripe's
 * own decline number) so the failure branch is exercisable without a real
 * gateway.
 */
class MockCardProvider implements PaymentProvider
{
    public const DECLINE_CARD = '4000000000000002';

    public function key(): string
    {
        return 'card';
    }

    public function label(): string
    {
        return resolve('translator')->trans('local-looksmax-store.forum.pay.card_label');
    }

    public function currency(): string
    {
        return 'USD';
    }

    public function charge(int $userId, int $amount, string $reference, array $context = []): Charge
    {
        $card = preg_replace('/\D/', '', (string) ($context['card'] ?? ''));

        if ($card === self::DECLINE_CARD) {
            return Charge::failed('card_declined', resolve('translator')->trans('local-looksmax-store.forum.error.card_declined'));
        }
        if ($card !== '' && strlen($card) < 12) {
            return Charge::failed('card_invalid', resolve('translator')->trans('local-looksmax-store.forum.error.card_invalid'));
        }
        if ($amount <= 0) {
            return Charge::failed('invalid_amount', resolve('translator')->trans('local-looksmax-store.forum.error.nothing_to_charge'));
        }

        return Charge::ok('mock_' . substr(hash('sha256', $reference), 0, 24));
    }

    public function refund(int $userId, int $amount, string $reference, string $providerRef): Charge
    {
        if (!str_starts_with($providerRef, 'mock_')) {
            return Charge::failed('not_mock', resolve('translator')->trans('local-looksmax-store.forum.error.not_mock_charge'));
        }

        return Charge::ok('mockref_' . substr(hash('sha256', $providerRef), 0, 24));
    }
}
