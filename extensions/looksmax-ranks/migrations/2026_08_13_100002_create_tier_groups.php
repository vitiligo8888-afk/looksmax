<?php

use Local\Ranks\Catalog;

/**
 * Every paid tier gets a real Flarum group.
 *
 * This is what stops the tiers from being decoration. A group is the unit core
 * permission checks understand, so "VIP can post in this tag", "Elite can
 * bypass the flood gate", "Plus can upload larger attachments" are all
 * expressible in the admin panel by an operator who has never seen this code,
 * and they are enforced by Flarum rather than by us.
 *
 * Group membership is a projection of identity_memberships, reconciled by
 * `identity:sync`. Nothing writes group_user by hand.
 */
return [
    'up' => function (Illuminate\Database\Schema\Builder $schema) {
        $db = $schema->getConnection();

        foreach (Catalog::TIERS as $tier) {
            if (!$tier['group']) {
                continue;
            }

            $exists = $db->table('groups')->where('name_singular', $tier['group'])->exists();
            if ($exists) {
                continue;
            }

            $db->table('groups')->insert([
                'name_singular' => $tier['group'],
                'name_plural' => $tier['group'],
                'color' => $tier['color'],
                'icon' => 'fas fa-crown',
                'is_hidden' => 0,
            ]);
        }
    },

    'down' => function (Illuminate\Database\Schema\Builder $schema) {
        $db = $schema->getConnection();
        $names = array_filter(array_column(Catalog::TIERS, 'group'));
        $ids = $db->table('groups')->whereIn('name_singular', $names)->pluck('id');
        $db->table('group_user')->whereIn('group_id', $ids)->delete();
        $db->table('groups')->whereIn('id', $ids)->delete();
    },
];
