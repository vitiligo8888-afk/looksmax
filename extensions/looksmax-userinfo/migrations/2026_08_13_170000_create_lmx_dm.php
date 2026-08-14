<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Direct messages, in this extension's own namespace.
 *
 * ── Why not fof/byobu ───────────────────────────────────────────────────────
 * fof/byobu 1.4.7 is MIT, requires flarum/core ^1.8.6 (this install is 1.8.18)
 * and is the only actively maintained 1.x PM extension. It was evaluated and
 * rejected for THIS install for two measured reasons, both recorded here so the
 * decision can be reversed on evidence rather than on memory:
 *
 *   1. It has no messages table. A private discussion IS a row in `discussions`
 *      with `is_private = 1`, scoped by a visibility scope. Several lanes on
 *      this install read the `discussions` table directly rather than through
 *      core's scopes — looksmax-index's blocks and looksmax-search's
 *      DocumentBuilder both build their own SELECTs — so installing it would
 *      make every one of those a place where a private conversation can leak
 *      into a public listing. looksmax-search does filter `is_private`
 *      (extensions/looksmax-search/src/Search/Engine.php:108); the index blocks
 *      were not audited by this lane and are not this lane's to change.
 *   2. Installing it means `composer require` inside the container, and this
 *      repo does not track the application's composer.json — only the
 *      extensions. That is a remote-only, unversioned change to the live site,
 *      which the deploy rules for this repo forbid.
 *
 * A private table has neither problem: nothing else on the install can read it
 * by accident, and it ships and rolls back with this extension.
 *
 * ── Shape ───────────────────────────────────────────────────────────────────
 * Threads, participants and messages, not "conversations/messages": generic
 * table names are how two extensions collide on one database. Everything is
 * prefixed `lmx_dm_`.
 *
 * A participant row is never deleted when someone leaves, it is stamped with
 * `left_at`. Deleting it would make the other side's copy of the conversation
 * lose its counterpart and would silently rewrite history.
 *
 * Written as an explicit up/down rather than through Migration::createTable
 * because three tables have to appear and disappear together: a half-applied
 * DM schema is a 500 on every card that offers the action.
 */
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('lmx_dm_threads')) {
            $schema->create('lmx_dm_threads', function (Blueprint $table) {
                $table->increments('id');
                // Optional. A DM started from a hover card has no subject and
                // should not be forced to invent one; the UI titles it with the
                // participants instead.
                $table->string('subject', 191)->nullable();
                $table->integer('created_by')->unsigned()->nullable();
                $table->integer('message_count')->unsigned()->default(0);
                $table->integer('last_message_id')->unsigned()->nullable();
                // Denormalised so the inbox is one indexed sort, not a join
                // with MAX() over every message the account has ever received.
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index('last_message_at');
            });
        }

        if (!$schema->hasTable('lmx_dm_participants')) {
            $schema->create('lmx_dm_participants', function (Blueprint $table) {
                $table->integer('thread_id')->unsigned();
                $table->integer('user_id')->unsigned();
                $table->timestamp('joined_at')->nullable();
                // The read cursor. Compared against messages.created_at rather
                // than against a counter, so a message inserted out of order
                // cannot desynchronise it.
                $table->timestamp('last_read_at')->nullable();
                $table->timestamp('left_at')->nullable();

                $table->primary(['thread_id', 'user_id']);
                // The inbox query is "my threads, newest first": user_id leads.
                $table->index(['user_id', 'left_at']);
            });
        }

        if (!$schema->hasTable('lmx_dm_messages')) {
            $schema->create('lmx_dm_messages', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('thread_id')->unsigned();
                $table->integer('user_id')->unsigned()->nullable();
                $table->text('content');
                $table->timestamp('created_at')->nullable();
                $table->timestamp('edited_at')->nullable();
                // Soft delete: the thread's shape (who said how much, when) is
                // part of what the other participant already read.
                $table->timestamp('deleted_at')->nullable();

                $table->index(['thread_id', 'id']);
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('lmx_dm_messages');
        $schema->dropIfExists('lmx_dm_participants');
        $schema->dropIfExists('lmx_dm_threads');
    },
];
