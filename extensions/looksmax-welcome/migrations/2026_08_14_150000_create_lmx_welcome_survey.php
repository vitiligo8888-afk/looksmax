<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * The "why did you join" survey, in two tables.
 *
 * Written as an explicit up/down (not Migration::createTable) because two
 * tables have to appear and disappear together, the same reasoning as
 * looksmax-userinfo's `2026_08_13_170000_create_lmx_dm.php`.
 *
 * ── why two tables and not one JSON column ──────────────────────────────────
 * The brief is explicit that this is "meant to be reused... a schema that can
 * be queried later for personalisation and analytics", not a throwaway flag.
 * A JSON blob on `users` (or a single sidecar row) can hold the same
 * information but cannot be queried with a plain `GROUP BY` on either MySQL
 * 5.7 or SQLite — both of which this install's dev/test path may hit — so
 * "how many people picked Peptides" would need a JSON function this codebase
 * does not otherwise rely on. A normalised answers table makes that a
 * three-line query (see Api/StatsController.php) and makes "everyone who
 * picked X" a plain WHERE, which is exactly the shape a future
 * personalisation feature wants (see Survey.php's docblock and this
 * extension's HANDOFF note for the read path).
 *
 * ── lmx_welcome_survey_state ─────────────────────────────────────────────────
 * One row per user, created the moment their account is REGISTERED (see
 * Listeners\SeedSurveyState — it listens for Flarum\User\Event\Registered,
 * not for anything at login time). That timing is deliberate: it is what
 * makes "no row" mean "this account predates the feature, or existed before
 * it was turned on" rather than "hasn't logged in yet", which is the
 * distinction the whole "shown ONCE to a NEW account" requirement rests on —
 * see extend.php's UserSerializer mutator, which serialises `lmxJoinSurvey`
 * as null when no row exists and the client never opens the overlay for that.
 *
 * `status` starts 'pending' and moves to exactly one of 'completed' /
 * 'skipped', once, via SurveyController — there is no path back to 'pending'.
 *
 * ── lmx_welcome_survey_answers ────────────────────────────────────────────────
 * One row per (user, chosen option), not one row per submission: a user who
 * changes their mind and saves again gets their row set REPLACED (delete +
 * reinsert in one transaction, see SurveyController::save()), so this table
 * is always "what this user currently says", not an append-only log of every
 * edit. `category` is denormalised from Survey::OPTIONS onto the row itself
 * (rather than joined at read time) specifically so `GROUP BY category` and
 * `GROUP BY option_key` both work without decoding the key — an analytics
 * query should not need to import Survey.php's PHP map to run.
 */
return [
    'up' => function (Builder $schema) {
        if (!$schema->hasTable('lmx_welcome_survey_state')) {
            $schema->create('lmx_welcome_survey_state', function (Blueprint $table) {
                $table->integer('user_id')->unsigned();
                $table->primary('user_id');
                // pending | completed | skipped
                $table->string('status', 16)->default('pending');
                $table->timestamp('responded_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();

                $table->index('status');
            });
        }

        if (!$schema->hasTable('lmx_welcome_survey_answers')) {
            $schema->create('lmx_welcome_survey_answers', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned();
                // 'section' | 'intent' — see Survey::CATEGORY_*.
                $table->string('category', 16);
                $table->string('option_key', 64);
                $table->timestamp('created_at')->nullable();

                // One vote per (user, option): re-saving the same choice is a
                // no-op, never a duplicate row inflating an analytics count.
                $table->unique(['user_id', 'option_key']);
                // "Everyone who picked X" — the query a future personalisation
                // or analytics feature actually runs.
                $table->index('option_key');
                $table->index('user_id');
            });
        }
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('lmx_welcome_survey_answers');
        $schema->dropIfExists('lmx_welcome_survey_state');
    },
];
