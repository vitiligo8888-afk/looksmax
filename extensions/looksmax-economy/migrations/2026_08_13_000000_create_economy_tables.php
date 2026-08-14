<?php
use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Ledger, not a counter.
 *
 * A single points column on users cannot answer "why does this person have
 * 4,000 points", cannot be audited when someone farms it, and cannot be
 * partially reversed when a post is deleted. Every award is a row; the balance
 * is the sum, cached on the user for read speed.
 */
return Migration::createTable('economy_transactions', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('user_id');
    $table->integer('delta');
    $table->string('reason', 40);           // post.created, reaction.received…
    $table->string('ref', 40)->nullable();  // post:123, discussion:9
    $table->unsignedInteger('actor_id')->nullable(); // who caused it
    $table->timestamp('created_at')->useCurrent();

    $table->index(['user_id', 'created_at']);
    $table->unique(['user_id', 'reason', 'ref'], 'econ_once'); // idempotent awards
});
