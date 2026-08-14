<?php

/**
 * Two columns the first cut needed and did not have.
 *
 * `chat_presence.ip` — presence was keyed on a hash of the source address, and
 * behind the tunnel every anonymous reader shares one address, so every guest
 * in the world collapsed into a single row and the "here" count could not move.
 * The key is now (address, client id) and the address is kept separately so the
 * count can still be capped per address; an uncappable count is one anybody can
 * inflate with a loop.
 *
 * `chat_messages.deleted_by` — who removed a line. `deleted_at` alone says a
 * moderator action happened and nothing about who to ask.
 */
return [
    'up' => function (Illuminate\Database\Schema\Builder $schema) {
        if (! $schema->hasColumn('chat_presence', 'ip')) {
            $schema->table('chat_presence', function ($table) {
                $table->string('ip', 32)->nullable()->index();
            });
        }

        if (! $schema->hasColumn('chat_messages', 'deleted_by')) {
            $schema->table('chat_messages', function ($table) {
                $table->unsignedInteger('deleted_by')->nullable();
            });
        }
    },
    'down' => function (Illuminate\Database\Schema\Builder $schema) {
        if ($schema->hasColumn('chat_presence', 'ip')) {
            $schema->table('chat_presence', function ($table) {
                $table->dropColumn('ip');
            });
        }

        if ($schema->hasColumn('chat_messages', 'deleted_by')) {
            $schema->table('chat_messages', function ($table) {
                $table->dropColumn('deleted_by');
            });
        }
    },
];
