<?php

namespace Local\Economy\Listeners;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Registered;
use Illuminate\Database\ConnectionInterface;
use Local\Economy\Config;
use Local\Economy\Ledger;

/**
 * Founding Member.
 *
 * The first `founding.cap` accounts to register on or before `founding.until`
 * are added to the Fundador group (a native Flarum group, so the badge itself
 * is data, not code) and paid a one-off `founding.bonus`.
 *
 * Why this is a registration listener and not a backfill script: this board's
 * ~30k accounts were imported from 2018 onward, so "the earliest N by join
 * date" are English-forum imports, not the people founding the Spanish
 * relaunch. The founder cohort is therefore whoever registers during a window
 * the OPERATOR opens (`founding.until`), which only new Registered events can
 * observe. The whole thing is off until then: `founding.enabled` false OR
 * `founding.until` empty both make handle() a no-op, so shipping it changes
 * nothing.
 */
class FoundingMember
{
    public function __construct(
        protected Ledger $ledger,
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
    ) {
    }

    public function handle(Registered $event): void
    {
        if (! (bool) Config::get($this->settings, 'founding.enabled')) {
            return;
        }

        $until = (string) Config::get($this->settings, 'founding.until');
        if ($until === '' || time() > strtotime($until . ' 23:59:59')) {
            return; // window never opened, or already closed
        }

        $groupId = (int) Config::get($this->settings, 'founding.groupId');
        if ($groupId <= 0) {
            return; // not wired to a group yet
        }

        $user = $event->user;

        // The cap is enforced against LIVE group membership, never a stored
        // counter. Counting the group is race-free — two simultaneous signups
        // both read the true count — and self-correcting if a fundador is ever
        // removed by hand. The denormalised `founding.count` written at the end
        // is only for display and is never trusted here.
        $cap = (int) Config::get($this->settings, 'founding.cap');
        $members = (int) $this->db->table('group_user')->where('group_id', $groupId)->count();
        if ($cap > 0 && $members >= $cap) {
            return;
        }

        // Idempotent: never double-add or double-pay one account.
        $already = $this->db->table('group_user')
            ->where('group_id', $groupId)
            ->where('user_id', $user->id)
            ->exists();
        if ($already) {
            return;
        }

        $this->db->table('group_user')->insert([
            'group_id' => $groupId,
            'user_id' => (int) $user->id,
        ]);

        // countsForRank:false — a founding gift is a welcome, not earned
        // standing, so it must not be able to buy the next rank. Same rule the
        // rank-up bonus follows (Ledger::refreshRank()).
        $bonus = (int) Config::get($this->settings, 'founding.bonus');
        if ($bonus > 0) {
            $this->ledger->credit((int) $user->id, $bonus, 'founding.member', 'founding:' . $user->id, false);
        }

        // Cheap denormalised counter for a "N / cap fundadores" front-page
        // widget. Read from ForumSerializer's economy payload; never a cap
        // source (see above).
        $this->settings->set('economy.founding.count', $members + 1);
    }
}
