<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Referral edges: who brought whom in.
 *
 * One row per REFEREE (the unique index on referee_id enforces "an account can
 * only ever be referred once" — the first ?ref cookie present at registration
 * wins, and a later visit cannot re-attribute a signup that already happened).
 * referrer_id is not unique: one member can refer many.
 *
 * `qualified_at` is null until the referee proves they are a real, active
 * account (see Listeners/ReferralQualify.php) — the gate that stops a referrer
 * from farming the join bonus with throwaway signups. The join bonus is paid
 * immediately; the larger qualify bonus is paid only when this flips.
 *
 * Idempotency of the PAYOUTS is not this table's job — that is enforced by the
 * unique `ref` on economy_transactions (Ledger::credit()'s insert catch), using
 * refs `refj:<refereeId>` / `refq:<refereeId>`. This table only records the
 * edge and whether it has qualified yet.
 */
return Migration::createTable('lmx_referrals', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('referrer_id');
    $table->unsignedInteger('referee_id')->unique();
    $table->timestamp('created_at')->useCurrent();
    $table->timestamp('qualified_at')->nullable();

    $table->index('referrer_id');
    $table->index('qualified_at');
});
