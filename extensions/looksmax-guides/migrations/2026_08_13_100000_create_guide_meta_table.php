<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Guide metadata as a satellite table, not columns on `discussions`.
 *
 * The ecosystem's habit is to ALTER the shared core table: on a 164-extension
 * test instance `discussions` reached 36 columns and `users` 40, most of them
 * NULL for most rows. At 2.2M discussions of which well under 1% are guides,
 * fifteen mostly-NULL columns is the same mistake. A satellite costs one join
 * on the guide page only, drops cleanly on uninstall, and cannot collide with
 * another vendor's column names.
 *
 * Everything queryable lives here rather than in the post body, because the
 * queries that make the format worth having — "guides due for review",
 * "low-risk well-evidenced guides under $50" — cannot run against
 * TextFormatter XML.
 */
return Migration::createTable('guide_meta', function (Blueprint $table) {
    $table->unsignedInteger('discussion_id')->primary();
    $table->unsignedInteger('post_id')->nullable();      // the structured first post
    $table->unsignedInteger('version')->default(1);
    $table->string('status', 20)->default('published');  // draft|published|needs_review|stale|superseded
    $table->unsignedInteger('superseded_by')->nullable();

    // --- the spec sheet: what makes guides comparable to each other ---------
    $table->unsignedTinyInteger('difficulty')->nullable();   // 1..5
    $table->unsignedInteger('cost_min')->nullable();         // minor units, USD
    $table->unsignedInteger('cost_max')->nullable();
    $table->unsignedInteger('time_to_result_d')->nullable();
    $table->string('risk_level', 12)->default('low');        // none|low|moderate|high|medical
    $table->string('reversibility', 12)->nullable();         // reversible|partly|permanent
    $table->boolean('requires_pro')->default(false);

    // --- the evidence profile, denormalised from guide_claims ---------------
    // Stored as JSON because the tier set is ours to change; a column per tier
    // would mean a migration every time we add one.
    $table->text('evidence_profile')->nullable();            // {"0":4,"3":2,...}
    $table->unsignedSmallInteger('claim_count')->default(0);
    $table->unsignedSmallInteger('sourced_count')->default(0);
    $table->decimal('evidence_score', 4, 3)->nullable();

    // --- the reading model -------------------------------------------------
    $table->unsignedInteger('word_count')->default(0);
    $table->unsignedSmallInteger('read_minutes')->default(0);
    $table->unsignedSmallInteger('section_count')->default(0);
    $table->text('toc')->nullable();                         // [{level,text,anchor}]

    // --- lifecycle ---------------------------------------------------------
    // A guide that nothing ever expires is the source board's failure mode: a
    // 2019 protocol recommending a withdrawn compound outranks a reviewed one
    // forever. review_due_at is materialised so the review queue is an index
    // scan rather than a computed comparison over 2.2M rows.
    $table->timestamp('published_at')->nullable();
    $table->timestamp('reviewed_at')->nullable();
    $table->unsignedInteger('reviewed_by')->nullable();
    $table->unsignedSmallInteger('review_interval_d')->default(365);
    $table->timestamp('review_due_at')->nullable();

    $table->timestamp('created_at')->useCurrent();
    $table->timestamp('updated_at')->nullable();

    $table->index(['status', 'review_due_at'], 'gm_review');
    $table->index(['status', 'risk_level', 'difficulty'], 'gm_filter');
    $table->index(['status', 'evidence_score'], 'gm_evidence');
});
