<?php
use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Shoutbox messages. Deliberately its own table rather than posts: these are
 * ephemeral, unthreaded, and should never appear in search, counts or feeds.
 */
return Migration::createTable('chat_messages', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('username', 60);
    $table->text('body');
    $table->timestamp('created_at')->useCurrent();
    $table->timestamp('deleted_at')->nullable();

    $table->index(['id', 'deleted_at']);
    $table->index('created_at');
});
