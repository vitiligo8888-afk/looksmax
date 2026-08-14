<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Why the classifier's output is recorded and not just applied.
 *
 * `discussion_tag` says a discussion is in Peptides. It does not say WHY, how
 * confident the rule was, or which rule fired — so a bad rule becomes
 * indistinguishable from a moderator's deliberate choice the moment it runs, and
 * the only way back is to strip the tag from everything, including the rows a
 * human set by hand.
 *
 * This table is the audit trail. Every assignment records the score and the
 * matched terms, so `SELECT ... WHERE score = 4 ORDER BY ...` is a review queue,
 * and re-running the classifier only ever touches rows it put there itself.
 */
return Migration::createTable('lmx_index_sections', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('discussion_id');
    $table->unsignedInteger('tag_id');
    $table->string('section', 40);
    $table->unsignedSmallInteger('score')->default(0);
    $table->string('hits', 500)->default('');
    $table->timestamp('created_at')->nullable();

    $table->unique(['discussion_id', 'section']);
    $table->index('tag_id');
    $table->index('score');
});
