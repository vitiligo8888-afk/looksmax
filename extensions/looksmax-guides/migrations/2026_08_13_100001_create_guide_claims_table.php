<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * One row per evidence-tagged claim, extracted from the post XML at save time.
 *
 * This is what makes the evidence profile auditable rather than decorative. A
 * curator can list every T4 claim on the board and check them; a reader can be
 * shown which specific sentence is carrying a trial citation. Neither is
 * possible if tier only exists as a rendering attribute inside the post body.
 */
return Migration::createTable('guide_claims', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('discussion_id');
    $table->unsignedInteger('post_id');
    $table->unsignedSmallInteger('position')->default(0); // order within the doc
    $table->unsignedTinyInteger('tier')->default(0);
    $table->string('anchor', 64);
    $table->string('claim_text', 500);
    $table->string('source_url', 1024)->nullable();
    $table->string('source_doi', 128)->nullable();
    $table->boolean('disputed')->default(false);
    $table->timestamp('created_at')->useCurrent();

    $table->index(['discussion_id', 'position'], 'gc_doc');
    $table->index('tier', 'gc_tier');
    $table->index('source_doi', 'gc_doi');
});
