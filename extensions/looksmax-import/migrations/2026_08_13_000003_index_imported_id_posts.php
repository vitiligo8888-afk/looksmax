<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Index for the quote jump-link lookup.
 *
 * Migration::addColumns cannot add an index, and without one every rendered
 * quote is a full scan of the posts table — 67% of posts contain a quote, so
 * that is one scan per post per page view.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('posts', 'imported_id')) {
            return;
        }
        $schema->table('posts', function (Blueprint $table) {
            $table->index('imported_id', 'posts_imported_id_index');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_imported_id_index');
        });
    },
];
