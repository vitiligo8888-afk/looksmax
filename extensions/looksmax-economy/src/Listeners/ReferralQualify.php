<?php

namespace Local\Economy\Listeners;

use Flarum\Post\Event\Posted;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Local\Economy\Config;
use Local\Economy\Ledger;

/**
 * Referral qualification: the throwaway-account gate.
 *
 * The join bonus is paid the instant a referee registers; this pays the larger
 * qualify bonus only once that referee has actually contributed — reached
 * `referral.qualifyPosts` posts — which a farm of empty signups never does.
 *
 * Runs on every Posted event, so it is written to cost one indexed lookup on
 * the common path: `lmx_referrals.referee_id` is unique, and the overwhelming
 * majority of posters are not unqualified referees, so the first query returns
 * nothing and the handler is done. Only for a referee still inside the gate
 * does it count posts and, on crossing, flip the edge and pay — the
 * `qualified_at IS NULL` in the UPDATE's WHERE makes that flip fire exactly
 * once even under concurrent posts.
 */
class ReferralQualify
{
    public function __construct(
        protected Ledger $ledger,
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
    ) {
    }

    public function handle(Posted $event): void
    {
        if (! (bool) Config::get($this->settings, 'referral.enabled')) {
            return;
        }

        $post = $event->post;
        $refereeId = (int) ($post->user_id ?? 0);
        if ($refereeId <= 0) {
            return;
        }

        // Common path: is this poster a referee who has not qualified yet?
        // One hit on the unique referee_id index; almost always empty.
        $edge = $this->db->table('lmx_referrals')
            ->where('referee_id', $refereeId)
            ->whereNull('qualified_at')
            ->first(['referrer_id']);
        if (! $edge) {
            return;
        }

        $need = (int) Config::get($this->settings, 'referral.qualifyPosts');
        $posts = (int) $this->db->table('posts')
            ->where('user_id', $refereeId)
            ->where('type', 'comment')
            ->count();
        if ($posts < max(1, $need)) {
            return;
        }

        // Flip exactly once: the WHERE null is the lock, so a second concurrent
        // post updates zero rows and pays nothing.
        $flipped = $this->db->table('lmx_referrals')
            ->where('referee_id', $refereeId)
            ->whereNull('qualified_at')
            ->update(['qualified_at' => date('Y-m-d H:i:s')]);
        if ($flipped < 1) {
            return;
        }

        $bonus = (int) Config::get($this->settings, 'referral.qualifyBonus');
        if ($bonus > 0) {
            $this->ledger->credit((int) $edge->referrer_id, $bonus, 'referral.qualified', 'refq:' . $refereeId, false);
        }
    }
}
