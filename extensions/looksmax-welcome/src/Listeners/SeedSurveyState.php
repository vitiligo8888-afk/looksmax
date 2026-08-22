<?php

namespace Local\Welcome\Listeners;

use Flarum\User\Event\Registered;
use Illuminate\Database\ConnectionInterface;

/**
 * Open the survey's "pending" row at the exact moment an account is born.
 *
 * This is the ONLY writer of new `lmx_welcome_survey_state` rows, and it fires
 * on `Flarum\User\Event\Registered` — genuine self-registration — deliberately
 * NOT on login, on first page view, or on any other "the account exists and is
 * active" signal. The presence of a row is the gate the rest of this extension
 * reads (see extend.php's UserSerializer mutator and the migration's
 * docblock): no row means "this account predates the feature, or existed
 * before it was enabled" and the survey never appears for it — which is what
 * makes the ~200k already-imported accounts on this install correctly never
 * see a "why did you join" prompt for a decision they made years ago on a
 * different board.
 *
 * `insertOrIgnore` rather than `insert`, so a duplicate fire (a retried
 * request, an extension reload replaying the event) cannot throw on the
 * primary key and cannot overwrite an answer that already exists.
 */
class SeedSurveyState
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function handle(Registered $event): void
    {
        $user = $event->user ?? null;
        if (!$user || !$user->id) {
            return;
        }

        try {
            $now = date('Y-m-d H:i:s');

            $this->db->table('lmx_welcome_survey_state')->insertOrIgnore([
                'user_id' => (int) $user->id,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // Onboarding is worth less than registration. A failure here must
            // never turn "create an account" into a 500.
        }
    }
}
