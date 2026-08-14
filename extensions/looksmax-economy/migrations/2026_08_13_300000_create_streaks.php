<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Daily streak state.
 *
 * Its own table rather than four more columns on `users`: this is written on
 * most posts and read on a profile view, and `users` is already the widest,
 * hottest table in the schema — every discussion listing serialises fifty rows
 * of it.
 *
 * `best` exists so that losing a streak leaves a record of what was lost.
 */
return Migration::createTable('economy_streaks', function (Blueprint $table) {
    $table->unsignedInteger('user_id')->primary();
    $table->unsignedInteger('current')->default(0);
    $table->unsignedInteger('best')->default(0);
    $table->date('last_day')->nullable();
    $table->unsignedInteger('freezes_used')->default(0);
    $table->timestamp('updated_at')->useCurrent();

    $table->index('current');
});
