<?php

namespace Local\Store;

use Local\Ranks\Catalog;

/**
 * The shipped catalogue.
 *
 * Two halves, and the split matters:
 *
 *   MIRRORED  memberships, username styles and avatar frames are declared in
 *             Local\Ranks\Catalog, because each one is versioned with the CSS
 *             that paints it. The store copies them into store_items so an
 *             admin can reprice, retire or gate them at runtime, and copies
 *             nothing else — the class stays the source of truth for what a
 *             style IS.
 *
 *   NATIVE    everything below that the identity layer has no opinion about:
 *             boosts, consumables, mystery boxes, bundles, credit packs.
 *
 * Prices for the native half were set against the measured balance
 * distribution on this install rather than picked as round numbers. On
 * 2026-08-13: 1,413 accounts, median balance 135, 100th-from-top 23,631, top
 * 200,000. So a 500-point item is an evening's posting for anybody, a
 * 5,000-point item is a real decision for a typical member, and anything above
 * 20,000 is aimed at the top hundred accounts. Memberships mirror the identity
 * catalogue's numbers exactly so the two cannot disagree about what VIP costs.
 */
class Seed
{
    /**
     * Native items. `kind` selects the grant handler; `payload` is that
     * handler's argument.
     */
    public static function native(): array
    {
        return [
            // ------------------------------------------------------- boosts
            [
                'sku' => 'boost-2x-24h', 'name' => 'Double earnings, 24 hours', 'category' => 'boosts',
                'kind' => 'boost', 'payload' => ['multiplier' => 2.0, 'hours' => 24],
                'price' => 2500, 'rarity' => 'uncommon', 'icon' => 'ph:lightning-fill', 'color' => '#e8c07d', 'sort' => 10,
                'blurb' => 'Everything you earn counts double for a day. Stacks with your membership multiplier.',
                'duration_days' => 1,
            ],
            [
                'sku' => 'boost-2x-7d', 'name' => 'Double earnings, 7 days', 'category' => 'boosts',
                'kind' => 'boost', 'payload' => ['multiplier' => 2.0, 'hours' => 168],
                'price' => 12000, 'rarity' => 'rare', 'icon' => 'ph:lightning-a-fill', 'color' => '#e8c07d', 'sort' => 11,
                'blurb' => 'The same thing for a week. Cheaper per day than buying seven of the above.',
                'duration_days' => 7,
            ],
            [
                'sku' => 'cap-raise-24h', 'name' => 'Raised daily ceiling, 24 hours', 'category' => 'boosts',
                'kind' => 'boost', 'payload' => ['capBoost' => 2.0, 'hours' => 24],
                'price' => 1800, 'rarity' => 'common', 'icon' => 'ph:gauge-fill', 'color' => '#7aa2f7', 'sort' => 12,
                'blurb' => 'Doubles the anti-farming daily caps for a day. For the days you actually are writing that much.',
                'duration_days' => 1,
            ],
            [
                'sku' => 'streak-freeze', 'name' => 'Streak freeze', 'category' => 'boosts',
                'kind' => 'streakfreeze', 'payload' => ['charges' => 1],
                'price' => 900, 'rarity' => 'common', 'icon' => 'ph:snowflake-fill', 'color' => '#8fd3e8', 'sort' => 13,
                'blurb' => 'Covers one missed day so a daily streak survives it. Spent automatically, oldest first.',
                'uses' => 1, 'max_per_user' => 0,
            ],

            // --------------------------------------------------- consumables
            [
                'sku' => 'thread-highlight-3d', 'name' => 'Highlight a thread, 3 days', 'category' => 'utility',
                'kind' => 'highlight', 'payload' => ['days' => 3, 'variant' => 'gold'],
                'price' => 3500, 'rarity' => 'uncommon', 'icon' => 'ph:highlighter-fill', 'color' => '#e8c07d', 'sort' => 20,
                'blurb' => 'Your thread gets a marked edge in the discussion list for three days. One thread, your choice, redeemed when you want it.',
                'uses' => 1,
            ],
            [
                'sku' => 'thread-sticky-24h', 'name' => 'Pin a thread, 24 hours', 'category' => 'utility',
                'kind' => 'sticky', 'payload' => ['hours' => 24],
                'price' => 9000, 'rarity' => 'rare', 'icon' => 'ph:push-pin-fill', 'color' => '#9ece6a', 'sort' => 21,
                'blurb' => 'Pins one of your threads to the top of its tag for a day, then unpins it. A moderator can end it early.',
                'uses' => 1,
            ],
            [
                'sku' => 'thread-bump', 'name' => 'Bump a thread', 'category' => 'utility',
                'kind' => 'bump', 'payload' => [],
                'price' => 750, 'rarity' => 'common', 'icon' => 'ph:arrow-fat-line-up-fill', 'color' => '#7aa2f7', 'sort' => 22,
                'blurb' => 'Moves one of your threads back to the top of the recent list without posting "bump".',
                'uses' => 1,
            ],
            [
                'sku' => 'username-change', 'name' => 'Username change', 'category' => 'utility',
                'kind' => 'rename', 'payload' => [],
                'price' => 6000, 'rarity' => 'uncommon', 'icon' => 'ph:identification-badge-fill', 'color' => '#bb9af7', 'sort' => 23,
                'blurb' => 'One change of the name on your account. Your old name is recorded on the order.',
                'uses' => 1,
            ],

            // -------------------------------------------------- mystery boxes
            // Odds are published on the card and enforced in MysteryGrant. A
            // box that cannot roll anything you already own is the difference
            // between a gamble and a scam.
            [
                'sku' => 'box-colour', 'name' => 'Colour box', 'category' => 'mystery',
                'kind' => 'mystery', 'payload' => [
                    'type' => 'style',
                    'odds' => ['solid' => 0.55, 'gradient' => 0.38, 'animated' => 0.07],
                    'floor' => 900,
                ],
                'price' => 3200, 'rarity' => 'uncommon', 'icon' => 'ph:package-fill', 'color' => '#bb9af7', 'sort' => 30,
                'blurb' => 'One username style you do not already own. 55% solid, 38% gradient, 7% animated. Cheapest animated style is worth 12,000.',
            ],
            [
                'sku' => 'box-frame', 'name' => 'Frame box', 'category' => 'mystery',
                'kind' => 'mystery', 'payload' => [
                    'type' => 'frame',
                    'odds' => ['common' => 0.4, 'uncommon' => 0.3, 'rare' => 0.24, 'epic' => 0.06],
                ],
                'price' => 4200, 'rarity' => 'rare', 'icon' => 'ph:gift-fill', 'color' => '#7aa2f7', 'sort' => 31,
                'blurb' => 'One avatar frame you do not already own. 40% common, 30% uncommon, 24% rare, 6% epic.',
            ],

            // ---------------------------------------------------- lifetime
            // The source board's best-selling product was not the monthly
            // subscription: post 10571773 lists Lifetime at $99 against $17 a
            // month (post 12207), a 5.8x multiple, and a second Lifetime tier
            // with frames at $124.99. People buy the end of the renewal, not
            // the discount. Priced here at six months of VIP and capped at 25,
            // because a permanent membership sold without a limit is a promise
            // the forum has to keep forever for a one-off payment.
            [
                'sku' => 'tier-vip-lifetime', 'name' => 'VIP for life', 'category' => 'membership',
                'kind' => 'tier', 'payload' => ['tier' => 'vip', 'days' => 0],
                'price' => 150000, 'rarity' => 'legendary', 'icon' => 'game-icons:laurels-trophy', 'color' => '#e8c07d', 'sort' => 25,
                'blurb' => 'VIP that never expires and never needs renewing. Twenty five of these exist.',
                'stock_total' => 25, 'max_per_user' => 1, 'discountable' => false, 'giftable' => true,
            ],

            // ------------------------------------------------------- bundles
            [
                'sku' => 'bundle-vip-start', 'name' => 'VIP starter', 'category' => 'bundles',
                'kind' => 'bundle', 'payload' => ['skus' => ['tier-vip', 'style-oceanic', 'boost-2x-24h']],
                'price' => 27500, 'rarity' => 'rare', 'icon' => 'ph:shopping-bag-open-fill', 'color' => '#e8c07d', 'sort' => 40,
                'blurb' => 'A month of VIP, the Oceanic gradient and a day of doubled earnings. Sold together for less than the three separately.',
            ],

            // ----------------------------------------------------- oro packs
            // The money -> paid-currency path. `kind: oro` routes through
            // Payments\MockCardProvider (approves everything, records a fake
            // auth reference) until a real processor is connected — the whole
            // pipeline around it (order, idempotency, grant, refund, audit) is
            // real now, so connecting Stripe/crypto later is a one-file swap.
            // `money` is in cents; `amount` is the oro credited. The bigger
            // packs give more oro per dollar, which is where the margin lives.
            [
                'sku' => 'oro-550', 'name' => '550 Oro', 'category' => 'oro',
                'kind' => 'oro', 'payload' => ['amount' => 550, 'money' => 500, 'currency' => 'USD'],
                'price' => 0, 'rarity' => 'common', 'icon' => 'ph:coins-fill', 'color' => '#e8c07d', 'sort' => 50,
                'blurb' => 'Un puñado de Oro para empezar. El pago con tarjeta es de prueba por ahora y no cobra nada.',
                'giftable' => true, 'refund_minutes' => 0,
            ],
            [
                'sku' => 'oro-1200', 'name' => '1,200 Oro', 'category' => 'oro',
                'kind' => 'oro', 'payload' => ['amount' => 1200, 'money' => 1000, 'currency' => 'USD'],
                'price' => 0, 'rarity' => 'uncommon', 'icon' => 'ph:coins-fill', 'color' => '#e8c07d', 'sort' => 51,
                'blurb' => '20% más de Oro por dólar. El pago con tarjeta es de prueba por ahora y no cobra nada.',
                'giftable' => true, 'refund_minutes' => 0,
            ],
            [
                'sku' => 'oro-2600', 'name' => '2,600 Oro', 'category' => 'oro',
                'kind' => 'oro', 'payload' => ['amount' => 2600, 'money' => 2000, 'currency' => 'USD'],
                'price' => 0, 'rarity' => 'rare', 'icon' => 'ph:coins-fill', 'color' => '#e8c07d', 'sort' => 52,
                'blurb' => 'La mejor relación. El pago con tarjeta es de prueba por ahora y no cobra nada.',
                'giftable' => true, 'refund_minutes' => 0,
            ],
            [
                'sku' => 'oro-7000', 'name' => '7,000 Oro', 'category' => 'oro',
                'kind' => 'oro', 'payload' => ['amount' => 7000, 'money' => 5000, 'currency' => 'USD'],
                'price' => 0, 'rarity' => 'legendary', 'icon' => 'ph:coins-fill', 'color' => '#e8c07d', 'sort' => 53,
                'blurb' => 'Para los que van en serio. El pago con tarjeta es de prueba por ahora y no cobra nada.',
                'giftable' => true, 'refund_minutes' => 0,
            ],

            // -------------------------------------------- premium (Oro-priced)
            // Items spent from the PAID balance. Same grant handlers as their
            // points cousins — only `currency: oro` changes which balance the
            // card checks — so nothing about delivery is special-cased. These
            // are what give Oro somewhere to go; an admin can price any item in
            // oro from the catalogue screen, this is just the shipped starting
            // set. `discountable: false` because a membership discount on the
            // premium currency is a discount on money.
            [
                'sku' => 'oro-boost-3x-7d', 'name' => 'Triple earnings, 7 days', 'category' => 'boosts',
                'kind' => 'boost', 'payload' => ['multiplier' => 3.0, 'hours' => 168],
                'price' => 1500, 'currency' => 'oro', 'rarity' => 'epic', 'icon' => 'ph:lightning-a-fill', 'color' => '#e8c07d', 'sort' => 5,
                'blurb' => 'Todo lo que ganas cuenta triple durante una semana. Se apila con tu membresía.',
                'duration_days' => 7, 'discountable' => false,
            ],
            [
                'sku' => 'oro-highlight-14d', 'name' => 'Highlight a thread, 14 days', 'category' => 'utility',
                'kind' => 'highlight', 'payload' => ['days' => 14, 'variant' => 'gold'],
                'price' => 800, 'currency' => 'oro', 'rarity' => 'rare', 'icon' => 'ph:highlighter-fill', 'color' => '#e8c07d', 'sort' => 15,
                'blurb' => 'Tu hilo con un borde marcado en la lista durante dos semanas. Uno, cuando quieras.',
                'uses' => 1, 'discountable' => false,
            ],
            [
                'sku' => 'oro-sticky-72h', 'name' => 'Pin a thread, 72 hours', 'category' => 'utility',
                'kind' => 'sticky', 'payload' => ['hours' => 72],
                'price' => 1200, 'currency' => 'oro', 'rarity' => 'epic', 'icon' => 'ph:push-pin-fill', 'color' => '#9ece6a', 'sort' => 16,
                'blurb' => 'Fija uno de tus hilos arriba de su etiqueta durante tres días.',
                'uses' => 1, 'discountable' => false,
            ],
        ];
    }

    /**
     * Rows mirrored out of the identity catalogue.
     *
     * Only the commercial columns are the store's: an admin repricing VIP here
     * changes what the store charges and nothing about what VIP means.
     */
    public static function mirrored(): array
    {
        if (!class_exists(Catalog::class)) {
            return [];
        }

        $out = [];

        foreach (Catalog::TIERS as $t) {
            if ((int) $t['price'] === 0) {
                continue; // standard is not sold; founder is granted
            }
            $out[] = [
                'sku' => 'tier-' . $t['slug'], 'name' => $t['name'] . ' membership', 'category' => 'membership',
                'kind' => 'tier', 'payload' => ['tier' => $t['slug'], 'days' => $t['days']],
                'price' => (int) $t['price'], 'rarity' => 'rare', 'icon' => $t['icon'], 'color' => $t['color'],
                'sort' => 10 * (int) $t['rank'],
                'blurb' => implode('. ', $t['headline']) . '.',
                'duration_days' => (int) $t['days'], 'managed' => true, 'giftable' => true,
                'discountable' => false, // a discount on the thing that grants the discount
            ];
        }

        foreach (Catalog::STYLES as $s) {
            if ($s['kind'] === 'rank' || (int) $s['price'] === 0) {
                continue;
            }
            $minTier = Catalog::tierForStyleKind($s['kind']);
            $out[] = [
                'sku' => 'style-' . $s['slug'], 'name' => $s['name'], 'category' => 'colours',
                'kind' => 'style', 'payload' => ['item' => $s['slug'], 'styleKind' => $s['kind']],
                'price' => (int) $s['price'], 'rarity' => $s['rarity'], 'icon' => 'ph:paint-brush-fill',
                'sort' => ['solid' => 10, 'gradient' => 20, 'animated' => 30][$s['kind']] ?? 40,
                'blurb' => $s['blurb'],
                'min_tier' => $minTier['slug'] ?? null,
                'max_per_user' => 1, 'managed' => true,
            ];
        }

        foreach (Catalog::FRAMES as $f) {
            if ((int) $f['price'] === 0) {
                continue;
            }
            $out[] = [
                'sku' => 'frame-' . $f['slug'], 'name' => $f['name'], 'category' => 'frames',
                'kind' => 'frame', 'payload' => ['item' => $f['slug']],
                'price' => (int) $f['price'], 'rarity' => $f['rarity'], 'icon' => 'ph:circle-half-tilt-bold',
                'sort' => 10, 'blurb' => $f['blurb'],
                'min_tier' => $f['tier'], 'max_per_user' => 1, 'managed' => true,
            ];
        }

        return $out;
    }

    public static function all(): array
    {
        return array_merge(self::mirrored(), self::native());
    }
}
