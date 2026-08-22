<?php

use Illuminate\Database\Schema\Blueprint;

/**
 * Which currency an item is priced in.
 *
 * 'points' (the default, and everything that already existed) is spent from the
 * earned balance through PointsProvider. 'oro' is spent from the paid balance
 * through OroProvider. The `price` column means "cost in this item's currency",
 * so nothing about pricing, discounts or the order pipeline changes — only
 * which provider Purchase selects and which balance the card checks.
 *
 * Money-in packs (`kind` = 'oro' or the legacy 'credits') are NOT priced in a
 * forum currency at all — they are charged in real money — so this column is
 * ignored for them; Purchase keys those off `kind`, not `currency`.
 */
return [
    'up' => function (Illuminate\Database\Schema\Builder $schema) {
        if (!$schema->hasColumn('store_items', 'currency')) {
            $schema->table('store_items', function (Blueprint $table) {
                $table->string('currency', 16)->default('points')->after('price');
            });
        }
    },

    'down' => function (Illuminate\Database\Schema\Builder $schema) {
        if ($schema->hasColumn('store_items', 'currency')) {
            $schema->table('store_items', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    },
];
