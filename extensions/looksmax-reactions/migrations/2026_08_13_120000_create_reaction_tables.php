<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Deliberately the same table names and the same core columns as
 * fof/reactions (`reactions`, `post_reactions`), so a forum that ever ran that
 * extension migrates here with additive ALTERs and no data transformation, and
 * so the two can never be installed at once and fight over the same rows.
 *
 * Two differences that matter:
 *
 *  1. UNIQUE (post_id, user_id, reaction_id). fof/reactions has NO uniqueness
 *     constraint at all and enforces one-reaction-per-user in PHP only, which
 *     is how FriendsOfFlarum/reactions#64 stays reproducible via raw API calls.
 *     Here multiple DIFFERENT reactions per user per post are the intended
 *     behaviour and the same one twice is not, so the invariant that is
 *     actually wanted is expressible in the schema. It is expressed there.
 *
 *  2. `reactions.position`. fof/reactions has no ordering column on either
 *     branch, so reordering the picker means delete-and-recreate, and the FK is
 *     ON DELETE CASCADE — reordering destroys every historical reaction of that
 *     type. That is not a trade this forum can make with 381k imported rows.
 */
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('reactions')) {
            $schema->create('reactions', function (Blueprint $table) {
                $table->increments('id');
                // fof/reactions-compatible core
                $table->string('identifier', 191);
                $table->string('type', 32)->default('image');   // image | svg
                $table->boolean('enabled')->default(true);
                $table->string('display', 191)->nullable();
                // ours
                $table->string('slug', 64);
                $table->string('asset', 191)->nullable();
                $table->string('tint', 16)->nullable();
                $table->string('grp', 32)->default('extra');
                $table->integer('position')->unsigned()->default(0);
                $table->integer('xf_id')->unsigned()->nullable();
                $table->integer('points')->default(0);
                $table->timestamps();

                $table->unique('slug', 'reactions_slug_unique');
                $table->unique('xf_id', 'reactions_xf_id_unique');
                $table->index(['enabled', 'position'], 'reactions_enabled_position_index');
            });
        }

        if (!$schema->hasTable('post_reactions')) {
            $schema->create('post_reactions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('post_id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('reaction_id');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->unique(['post_id', 'user_id', 'reaction_id'], 'post_reactions_once');
                $table->index(['post_id', 'reaction_id'], 'post_reactions_post_reaction_index');
                // the leaderboard reads "reactions received by user X in period"
                // through the post author, and "given by user X" through this
                $table->index(['user_id', 'created_at'], 'post_reactions_user_time_index');

                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('reaction_id')->references('id')->on('reactions')->onDelete('cascade');
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('post_reactions');
        $schema->dropIfExists('reactions');
    },
];
