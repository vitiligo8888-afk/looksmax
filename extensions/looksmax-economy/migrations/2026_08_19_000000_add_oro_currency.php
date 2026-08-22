<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The second currency: Oro.
 *
 * `points` (the existing balance) is EARNED — activity pays it, it drives the
 * rank ladder, and it is the only number in the system that cannot be bought.
 * `oro` is the opposite by design: it is BOUGHT with real money (through the
 * store's card provider) and spent on premium items, and it never touches
 * lifetime_points or a rank. Keeping them in two columns and two ledgers is the
 * whole point — a paid balance that could buy standing would make standing
 * meaningless, which is the exact failure the economy was built to avoid.
 *
 * economy_oro_transactions mirrors economy_transactions: every movement is one
 * signed row, idempotent on (user, reason, ref), so replaying a webhook or a
 * refund can never inflate a balance. It is a SEPARATE table so the points
 * history and the oro history never have to be untangled from one stream.
 */
return [
    'up' => function (Illuminate\Database\Schema\Builder $schema) {
        if (!$schema->hasColumn('users', 'oro')) {
            $schema->table('users', function (Blueprint $table) {
                $table->integer('oro')->default(0);
            });
        }

        if (!$schema->hasTable('economy_oro_transactions')) {
            $schema->create('economy_oro_transactions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('user_id');
                $table->integer('delta');            // signed: +buy, -spend, -clawback
                $table->string('reason', 40);
                $table->string('ref', 120)->nullable();
                $table->unsignedInteger('actor_id')->nullable();
                $table->timestamp('created_at')->useCurrent();

                // Idempotency, same contract the points ledger holds: a repeated
                // (user, reason, ref) is one movement, not two.
                $table->unique(['user_id', 'reason', 'ref'], 'oro_once');
                $table->index(['user_id', 'id']);
            });
        }
    },

    'down' => function (Illuminate\Database\Schema\Builder $schema) {
        $schema->dropIfExists('economy_oro_transactions');
        if ($schema->hasColumn('users', 'oro')) {
            $schema->table('users', function (Blueprint $table) {
                $table->dropColumn('oro');
            });
        }
    },
];
