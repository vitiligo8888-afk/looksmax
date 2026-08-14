<?php
use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The event spool. Wide-but-shallow on purpose: one row per thing that
 * happened, with a JSON bag for anything type-specific, so new event types
 * never need a migration.
 *
 * `sent_at` is the PostHog forwarding watermark — NULL means still pending, so
 * a PostHog outage becomes a backlog we can drain, not lost data.
 */
return Migration::createTable('analytics_events', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->string('type', 48);                 // discussion.viewed, post.created…
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('discussion_id')->nullable();
    $table->unsignedInteger('post_id')->nullable();
    $table->string('session', 40)->nullable();  // anonymous-stable id
    $table->string('path', 255)->nullable();
    $table->json('props')->nullable();          // tags, dwell_ms, scroll_pct…
    $table->timestamp('created_at')->useCurrent();
    $table->timestamp('sent_at')->nullable();

    $table->index(['type', 'created_at']);
    $table->index(['discussion_id', 'type']);
    $table->index(['user_id', 'created_at']);
    $table->index('sent_at');                   // flush scans pending only
});
