<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Per-user identity state.
 *
 * The catalogue itself (which styles exist, what they cost, what unlocks them)
 * lives in code, in Catalog.php, because it is versioned with the CSS that
 * renders it. These tables hold only what is per-user and therefore cannot live
 * in code: what you own, what you equipped, what you earned, what you paid for.
 *
 * Every table here is reconstructible except `identity_inventory`, which is the
 * record of a purchase and is therefore the one thing that must never be
 * rebuilt from a heuristic. Badges and memberships can be recomputed;
 * ownership cannot, so it is written once and never derived.
 */
return [
    'up' => function (Illuminate\Database\Schema\Builder $schema) {
        // ---------------------------------------------------------- ownership
        $schema->create('identity_inventory', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->string('type', 16);          // style | frame
            $table->string('item', 40);          // catalogue slug
            $table->string('source', 16)->default('purchase'); // purchase|award|import|grant
            $table->integer('paid')->default(0); // what it actually cost after discount
            $table->timestamp('acquired_at')->useCurrent();
            $table->timestamp('expires_at')->nullable(); // null = permanent

            $table->unique(['user_id', 'type', 'item'], 'inv_once');
            $table->index('user_id');
        });

        // -------------------------------------------------------- memberships
        // History, not current state: `users.tier_slug` is the cache, this is
        // the audit trail of every grant, purchase and renewal.
        $schema->create('identity_memberships', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->string('tier', 24);
            $table->string('source', 16)->default('purchase');
            $table->integer('paid')->default(0);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);

            $table->index(['user_id', 'active']);
            $table->index('expires_at');
        });

        // ------------------------------------------------------------- badges
        $schema->create('identity_badges', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->string('badge', 40);
            $table->timestamp('awarded_at')->useCurrent();
            $table->unsignedBigInteger('progress')->default(0); // value that satisfied it
            $table->boolean('showcased')->default(false);
            $table->unsignedTinyInteger('slot')->default(0);

            $table->unique(['user_id', 'badge'], 'badge_once');
            $table->index('badge');
            $table->index(['user_id', 'showcased']);
        });
    },

    'down' => function (Illuminate\Database\Schema\Builder $schema) {
        $schema->dropIfExists('identity_badges');
        $schema->dropIfExists('identity_memberships');
        $schema->dropIfExists('identity_inventory');
    },
];
