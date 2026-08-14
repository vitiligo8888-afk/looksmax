<?php

use Illuminate\Database\Schema\Blueprint;

/**
 * Effects a purchase put on a thread.
 *
 * Highlights and temporary stickies are the two perks the source board's own
 * users asked for most often that are not cosmetics, and both have to end on
 * their own — a "24 hour sticky" that stays stuck forever is not a product, it
 * is a moderation problem. Each row carries its own expiry and `store:expire`
 * reverses it.
 *
 * `restore` holds the value the column had before the perk touched it, so
 * un-stickying a thread that a moderator had already pinned cannot happen.
 */
return [
    'up' => function (Illuminate\Database\Schema\Builder $schema) {
        $schema->create('store_discussion_effects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('discussion_id');
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('kind', 24);           // highlight | sticky | bump
            $table->string('variant', 24)->nullable();
            $table->text('restore')->nullable();  // json of what to put back
            $table->timestamp('started_at')->useCurrent();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('ended_at')->nullable();

            $table->index(['discussion_id', 'kind']);
            $table->index('expires_at');
        });
    },

    'down' => function (Illuminate\Database\Schema\Builder $schema) {
        $schema->dropIfExists('store_discussion_effects');
    },
];
