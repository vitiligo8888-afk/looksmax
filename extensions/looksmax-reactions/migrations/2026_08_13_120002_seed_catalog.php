<?php

use Illuminate\Database\Schema\Builder;
use Local\Reactions\Catalog;

/**
 * Upsert the catalogue. Idempotent on `slug`, and it never touches `enabled`
 * or `position` on a row that already exists — those two are the admin's, and
 * a redeploy that silently re-enabled a reaction the admin turned off, or
 * reset their ordering, would be a bug that only shows up in production.
 *
 * Note the signature: Flarum's Migrator calls a closure migration with the
 * schema BUILDER, not a connection — typehinting ConnectionInterface here is a
 * TypeError at enable time, thrown from inside extension:enable where the only
 * visible symptom is that the extension will not turn on.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $now = date('Y-m-d H:i:s');
        foreach (Catalog::rows() as $r) {
            $existing = $db->table('reactions')->where('slug', $r['slug'])->first();
            $payload = [
                'identifier' => $r['slug'],
                'type' => $r['kind'],
                'display' => $r['display'],
                'asset' => $r['asset'],
                'tint' => $r['tint'],
                'grp' => $r['grp'],
                'xf_id' => $r['xf_id'],
                'points' => $r['points'],
                'updated_at' => $now,
            ];

            if ($existing) {
                $db->table('reactions')->where('slug', $r['slug'])->update($payload);
            } else {
                $db->table('reactions')->insert($payload + [
                    'slug' => $r['slug'],
                    'position' => $r['position'],
                    'enabled' => $r['enabled'],
                    'created_at' => $now,
                ]);
            }
        }
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();
        $db->table('reactions')->whereIn('slug', array_keys(Catalog::ITEMS))->delete();
    },
];
