<?php
use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Who is here now. A row per viewer, refreshed on poll and aged out, which is
 * what makes the live indicator mean something instead of being decoration.
 */
return Migration::createTable('chat_presence', function (Blueprint $table) {
    $table->string('key', 40)->primary();     // user id or anonymous session
    $table->unsignedInteger('user_id')->nullable();
    $table->string('username', 60)->nullable();
    $table->timestamp('seen_at')->useCurrent();

    $table->index('seen_at');
});
