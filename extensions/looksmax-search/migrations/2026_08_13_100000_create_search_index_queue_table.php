<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The indexing outbox.
 *
 * Writing to Meilisearch directly from a model event is the obvious design and
 * the wrong one. It couples every post submission to the availability of a
 * second network service: if the engine is down, restarting, or mid-snapshot,
 * the choice is between failing the user's post and silently losing the
 * document forever — and "silently" is what actually happens, because nobody
 * fails a post over a search index. The index then diverges from the database
 * permanently, with no record of what was missed.
 *
 * A row here instead. The transaction that creates the post creates the intent
 * to index it; a drain process applies it. An engine outage becomes a backlog
 * with a measurable depth and age, which is a thing you can alarm on, rather
 * than absence, which is not.
 *
 * `(kind, object_id)` is unique so that fifty edits to one post collapse into
 * one pending row — the drain reads current state from the database anyway, so
 * only the fact that it changed matters, never how many times.
 */
return Migration::createTable('search_index_queue', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->string('kind', 16);                  // discussions | posts | users | tags
    $table->unsignedBigInteger('object_id');
    $table->string('action', 8)->default('upsert'); // upsert | delete
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->text('last_error')->nullable();
    $table->timestamp('created_at')->useCurrent();
    $table->timestamp('available_at')->useCurrent();

    $table->unique(['kind', 'object_id'], 'search_queue_object_unique');
    // The drain's only query: oldest available rows first.
    $table->index(['available_at', 'id'], 'search_queue_drain_index');
});
