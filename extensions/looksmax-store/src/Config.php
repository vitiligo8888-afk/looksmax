<?php

namespace Local\Store;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The one number this extension owns that is not a catalogue row: the ceiling
 * on how far a stack of boosts can push the earn rate and daily-cap boost.
 *
 * Everything else configurable about the store already lives in `store_items`
 * (Catalogue::sync() explicitly says why: "prices are the one part of an
 * economy that has to move without a deploy"). The boost ceiling cannot live
 * there because it is not a property of any one item — it is a property of
 * how items of kind `boost` COMBINE, which only Entitlements::boosts() ever
 * computes. Same KEYS-map shape as looksmax-userinfo's and
 * looksmax-economy's Config classes, one Flarum setting per key under
 * `store.*`.
 */
class Config
{
    public const KEYS = [
        // Boost items have never had a max_per_user (Seed::native() ships
        // them at the default 0 = unlimited), and deliberately still do not
        // — see Entitlements::boosts()'s header for why max_per_user is the
        // WRONG lever for a repeatable, time-limited item (purchaseCounts()
        // counts LIFETIME purchases, so max_per_user=1 on a 24h boost would
        // permanently block re-buying it after it expires). The ceiling
        // below is what makes unlimited repurchases safe instead: no matter
        // how many boosts are active at once, `earn` used by
        // Ledger::tierModifiers() cannot exceed this.
        'boost.maxEarnMultiplier'  => [4.0, 'float'],
        'boost.maxCapBoost'        => [4.0, 'float'],
    ];

    public static function get(SettingsRepositoryInterface $settings, string $key)
    {
        [$default, $cast] = self::KEYS[$key] ?? [null, 'raw'];
        $raw = $settings->get('store.' . $key);

        if ($raw === null || $raw === '') {
            return $default;
        }

        return $cast === 'float' ? (float) $raw : $raw;
    }
}
