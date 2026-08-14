<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A sidecar table rather than columns on `users`.
 *
 * Three lanes are adding user-shaped data at the same time (economy adds
 * points, ranks adds tier/cosmetic columns, this adds carried-over standing).
 * Columns on `users` from three extensions means three migrations racing on one
 * ALTER TABLE and a `users` row that nobody owns. A keyed sidecar can be
 * dropped, rebuilt and re-derived from the scrape in one command without
 * touching a row anyone else writes.
 *
 * Every column here is COPIED FROM THE SOURCE BOARD or COMPUTED FROM LOCAL
 * ROWS. Nothing is estimated, and the two kinds are deliberately not mixed:
 * `legacy_*` is what the account carried in, everything else is what is true on
 * this forum right now. The panel labels them separately for the same reason.
 */
return Migration::createTable('userinfo_profiles', function (Blueprint $table) {
    $table->integer('user_id')->unsigned()->primary();

    // --- carried over from the source board -------------------------------
    // users.title on XenForo: either a ladder name (Iron/Bronze/…/Luminary),
    // a purchased colour name (Zephir/Kraken/Fire/…), or a free-text custom
    // title. All three are real; which one it is gets resolved at render time
    // against the ranks catalogue rather than being guessed here.
    $table->string('legacy_title', 191)->nullable();
    // JSON array of rank banners, e.g. ["Staff"], ["Contributor","Staff"].
    $table->text('legacy_banners')->nullable();
    // The source usergroup id that drove username colouring. We do not have the
    // board's stylesheet, so this is carried as an opaque token and exposed as
    // a data attribute for whoever ends up owning colour.
    $table->string('legacy_style_class', 16)->nullable();
    $table->string('legacy_badge_id', 16)->nullable();
    $table->integer('legacy_posts')->unsigned()->default(0);
    $table->integer('legacy_reactions')->unsigned()->default(0);
    $table->integer('legacy_threads')->unsigned()->default(0);
    $table->date('legacy_joined')->nullable();

    // --- true of content that is actually on THIS forum --------------------
    // Reaction score summed over exactly the posts that were imported here,
    // not the account's lifetime score. See BackfillCommand::reactions().
    $table->integer('reactions_here')->unsigned()->default(0);
    // Which reaction types that content attracted: {"+1": 812, "JFL": 91, …}
    $table->text('reaction_mix')->nullable();
    $table->integer('posts_here')->unsigned()->default(0);
    $table->integer('discussions_here')->unsigned()->default(0);
    // Best reaction score on a single post that lives here.
    $table->integer('best_post_score')->unsigned()->default(0);

    $table->timestamp('computed_at')->nullable();

    $table->index('legacy_reactions');
    $table->index('reactions_here');
});
