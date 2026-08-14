<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Every search, as it was actually issued.
 *
 * This exists because a zero-result search is the single highest-signal event a
 * forum produces: it is a reader telling you, in their own words, what the
 * community does not have. Aggregated over a week it is a content plan. Nobody
 * reads it out of the web server access log, so it gets a table.
 *
 * `normalised` is the aggregation key — lowercased, whitespace-collapsed,
 * operators stripped — so `Jaw Surgery`, `jaw  surgery` and `jaw surgery
 * tag:guide` count as the same demand. `query` keeps what was typed, because
 * the exact words are the point when you are reading them back.
 *
 * `clicked_at`/`clicked_position` are written by a second request. A search
 * with results and no click is an ABANDONED search — a different and equally
 * useful failure from a search with no results at all, and one that no
 * result-count metric can see.
 */
return Migration::createTable('search_queries', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->string('query', 512);
    $table->string('normalised', 191);
    $table->unsignedInteger('user_id')->nullable();
    $table->string('session', 40)->nullable();
    $table->string('type', 16)->default('all');
    $table->unsignedInteger('result_count')->default(0);
    $table->unsignedInteger('engine_ms')->default(0);
    $table->unsignedInteger('total_ms')->default(0);
    $table->unsignedSmallInteger('offset')->default(0);
    $table->json('filters')->nullable();          // the parsed operator side
    $table->string('source', 16)->default('page'); // page | suggest | palette
    $table->timestamp('created_at')->useCurrent();

    $table->unsignedBigInteger('clicked_result_id')->nullable();
    $table->string('clicked_type', 16)->nullable();
    $table->unsignedSmallInteger('clicked_position')->nullable();
    $table->timestamp('clicked_at')->nullable();

    // The gap report: zero-result queries by demand, newest first.
    $table->index(['result_count', 'created_at'], 'search_queries_zero_index');
    // The aggregation the insights endpoint runs.
    $table->index(['normalised', 'created_at'], 'search_queries_norm_index');
    $table->index(['created_at'], 'search_queries_time_index');
    $table->index(['user_id', 'created_at'], 'search_queries_user_index');
});
