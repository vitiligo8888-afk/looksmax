<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Saved searches.
 *
 * The query language makes a refined search a string, so saving one is saving a
 * string — but the value is not the string, it is `last_seen_id`. A saved
 * search that remembers the highest result id it has shown can answer "what is
 * new since I last looked", which turns a search into a standing subscription
 * to a topic that no tag covers: "limb lengthening in the Turkish section, more
 * than five reactions".
 */
return Migration::createTable('search_saved', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->string('name', 120);
    $table->string('query', 512);
    $table->string('type', 16)->default('all');
    $table->boolean('notify')->default(false);
    $table->unsignedBigInteger('last_seen_id')->default(0);
    $table->unsignedInteger('last_count')->default(0);
    $table->timestamp('created_at')->useCurrent();
    $table->timestamp('checked_at')->nullable();

    $table->unique(['user_id', 'name']);
    $table->index('user_id');
});
