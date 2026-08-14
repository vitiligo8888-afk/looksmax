<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Imported XenForo reactions live in their own tables, and they have to,
 * because what the scrape recorded is a strictly weaker fact than what a
 * native reaction is.
 *
 * `post_reactions` on the scrape side is PRIMARY KEY (post_id, reaction_id)
 * with no user column and no timestamp. It records THAT a post drew a given
 * reaction type, not who did it, not when, and not how many. `posts` carries a
 * separate `reaction_score` integer which is the total across all types, and a
 * `reaction_summary` string which is XenForo's byline — at most three display
 * names and then "and N others", mostly "Deleted member 6401".
 *
 * So the honest model is:
 *
 *   legacy_post_reactions   one row per (post, type). `count` is NULL for
 *                           "this type was present, how many is unknown". It is
 *                           only ever a number when it can be DERIVED rather
 *                           than guessed: if a post drew exactly one type, then
 *                           that type's count is the whole reaction_score.
 *                           `exact` records which of the two it is, so nothing
 *                           downstream has to infer it from NULL-ness.
 *
 *   legacy_post_totals      the post-level score and byline, kept whole. This
 *                           is the number that was true on the source board and
 *                           it is displayed as such rather than being carved up
 *                           across types with a made-up distribution.
 *
 * Splitting a 926-reaction total across three present types by global type
 * frequency would produce numbers that look authoritative and are invented.
 * The whole point of keeping history is that it keeps meaning what it meant.
 */
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('legacy_post_reactions')) {
            $schema->create('legacy_post_reactions', function (Blueprint $table) {
                $table->unsignedInteger('post_id');
                $table->unsignedInteger('reaction_id');
                $table->unsignedInteger('count')->nullable();
                $table->boolean('exact')->default(false);

                $table->primary(['post_id', 'reaction_id']);
                $table->index('reaction_id', 'legacy_post_reactions_reaction_index');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
                $table->foreign('reaction_id')->references('id')->on('reactions')->onDelete('cascade');
            });
        }

        if (!$schema->hasTable('legacy_post_totals')) {
            $schema->create('legacy_post_totals', function (Blueprint $table) {
                $table->unsignedInteger('post_id')->primary();
                $table->unsignedInteger('score')->default(0);
                $table->string('summary', 512)->nullable();

                $table->index('score', 'legacy_post_totals_score_index');
                $table->foreign('post_id')->references('id')->on('posts')->onDelete('cascade');
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('legacy_post_reactions');
        $schema->dropIfExists('legacy_post_totals');
    },
];
