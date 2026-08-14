<?php
use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The audit trail streak revocation needed and did not have.
 *
 * `economy_streaks` only ever carries the LATEST state (current/best/last_day)
 * — it has no memory of which post caused which day to count, so before this
 * table existed there was no way to answer "did deleting this post actually
 * earn the streak.day row I am looking at, or was there another qualifying
 * post that day too?" without guessing. Guessing wrong in either direction is
 * bad: revoke a day that is still earned and an honest account loses a streak
 * they still hold; fail to revoke and the farming bug this migration exists to
 * close (RevokeStreak, in the same release) stays open.
 *
 * One row per (user, day) that a streak day was actually paid for, pointing at
 * the post that earned it. See Streaks::touch()/recordAnchor() for the write
 * side and Listeners\RevokeStreak for the read side.
 */
return Migration::createTable('economy_streak_days', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('user_id');
    $table->date('day');
    $table->unsignedInteger('post_id');
    $table->timestamp('created_at')->useCurrent();

    $table->unique(['user_id', 'day'], 'econ_streak_day_once');
    $table->index('post_id');
});
