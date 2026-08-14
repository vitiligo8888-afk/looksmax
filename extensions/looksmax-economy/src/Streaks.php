<?php

namespace Local\Economy;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Daily streaks: the one earning rule that cannot be farmed by volume.
 *
 * Everything else in the ledger pays per action, so more actions pay more, and
 * the only defence is a ceiling. A streak pays for turning up on distinct days
 * and is therefore bounded by the calendar rather than by a cap somebody has to
 * guess. It is also the earning rule that rewards exactly the behaviour a forum
 * wants and cannot buy: coming back.
 *
 * Rules:
 *   * the first qualifying post of a UTC day extends the streak
 *   * a qualifying post is one that would earn anything at all, so a streak
 *     cannot be held with a one-word reply
 *   * a gap of one day is forgiven if the member holds a streak freeze from the
 *     store, which is spent automatically and recorded
 *   * a gap of more than one unfrozen day resets to 1, and `best` remembers
 *     what it was, because losing a 200 day streak with no trace of it is the
 *     kind of thing people quit over
 *   * every seventh day pays the weekly bonus on top
 *
 * The state lives in its own table rather than on `users` because it is written
 * on nearly every post and read almost never.
 */
class Streaks
{
    /**
     * A post has to be at least this long to hold a streak. Default; the
     * live number is `economy.streak.minLength` (Config::KEYS) and is read
     * through minLength() below, never through this constant directly —
     * TrackStreak and RevokeStreak both call minLength() so a settings
     * change and the farming-bug fix agree on the exact same threshold.
     */
    public const MIN_LENGTH = 80;

    public function __construct(
        protected ConnectionInterface $db,
        protected Ledger $ledger,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function minLength(): int
    {
        return (int) Config::get($this->settings, 'streak.minLength');
    }

    /**
     * Record activity for today and pay whatever it earned.
     *
     * $postId, when given, is written to economy_streak_days as the ANCHOR
     * for the day — the one post RevokeStreak will look for if it is later
     * deleted. Only the post that actually causes pay() to run (the first
     * qualifying post of a new day) becomes an anchor; a second qualifying
     * post the same day hits the `$row->last_day === $today` branch below
     * and returns before recordAnchor() is reached, which is correct: that
     * post did not earn anything, so there is nothing to revoke if it is
     * deleted.
     *
     * @return array{current:int,best:int,paid:int,frozen:bool}
     */
    public function touch(int $userId, ?string $today = null, ?int $postId = null): array
    {
        $today ??= gmdate('Y-m-d');

        $row = $this->db->table('economy_streaks')->where('user_id', $userId)->first();

        if (!$row) {
            $this->db->table('economy_streaks')->insert([
                'user_id' => $userId, 'current' => 1, 'best' => 1,
                'last_day' => $today, 'freezes_used' => 0, 'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $paid = $this->pay($userId, 1, $today);
            $this->recordAnchor($userId, $today, $postId);

            return ['current' => 1, 'best' => 1, 'paid' => $paid, 'frozen' => false];
        }

        if ($row->last_day === $today) {
            return ['current' => (int) $row->current, 'best' => (int) $row->best, 'paid' => 0, 'frozen' => false];
        }

        $gapDays = (int) floor((strtotime($today) - strtotime((string) $row->last_day)) / 86400);
        $frozen = false;
        $current = (int) $row->current;

        if ($gapDays === 1) {
            $current++;
        } elseif ($gapDays === 2 && $this->spendFreeze($userId)) {
            // Exactly one missed day, and they were carrying a freeze.
            $current++;
            $frozen = true;
        } else {
            $current = 1;
        }

        $best = max((int) $row->best, $current);

        $this->db->table('economy_streaks')->where('user_id', $userId)->update([
            'current' => $current,
            'best' => $best,
            'last_day' => $today,
            'freezes_used' => (int) $row->freezes_used + ($frozen ? 1 : 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $paid = $this->pay($userId, $current, $today);
        $this->recordAnchor($userId, $today, $postId);

        return ['current' => $current, 'best' => $best, 'paid' => $paid, 'frozen' => $frozen];
    }

    /**
     * Record which post anchors a streak day, for RevokeStreak to find later.
     * Unique on (user_id, day), and touch() only ever calls this once per
     * user per day (see the doc comment on touch()), so the insert failing
     * means only a genuine retry of the same request — safe to swallow.
     */
    private function recordAnchor(int $userId, string $day, ?int $postId): void
    {
        if ($postId === null) {
            return;
        }

        try {
            $this->db->table('economy_streak_days')->insert([
                'user_id' => $userId,
                'day' => $day,
                'post_id' => $postId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // already anchored for this day — idempotent, not an error
        }
    }

    /**
     * @return array{current:int,best:int,lastDay:?string,freezesUsed:int,heldToday:bool,nextMilestone:?int,toNextMilestone:?int}
     */
    public function of(int $userId): array
    {
        $row = $this->db->table('economy_streaks')->where('user_id', $userId)->first();
        $current = (int) ($row->current ?? 0);
        $today = gmdate('Y-m-d');

        $milestones = Config::streakMilestones($this->settings);
        $nextMilestone = null;
        foreach ($milestones as $n) {
            if ($n > $current) {
                $nextMilestone = $n;
                break;
            }
        }

        return [
            'current' => $current,
            'best' => (int) ($row->best ?? 0),
            'lastDay' => $row->last_day ?? null,
            'freezesUsed' => (int) ($row->freezes_used ?? 0),
            // Whether today already counts — the widget's "you're done for
            // today" state, vs. "post something to keep it going".
            'heldToday' => ($row->last_day ?? null) === $today,
            'nextMilestone' => $nextMilestone,
            'toNextMilestone' => $nextMilestone !== null ? $nextMilestone - $current : null,
            // True exactly on the days `current` sits ON a milestone value —
            // the forum widget compares this against what it last celebrated
            // (kept client-side) so a page reload does not re-fire the toast,
            // without this endpoint needing to remember "have I shown this
            // yet" itself.
            'atMilestone' => in_array($current, $milestones, true),
        ];
    }

    /**
     * The day and week awards, referenced by date so a replay cannot pay twice
     * — the ledger's unique (user, reason, ref) does the rest.
     *
     * Also where a milestone celebration bonus is paid the first time a
     * streak reaches one of `economy.streak.milestones` (default 7/30/100/365
     * days) — invented for this pass because a streak that only ever pays a
     * flat 5 or 40 gives an account no reason to notice day 100 versus day 93.
     * Paid via credit(), not award(): see Ledger::refreshRank()'s comment on
     * the identical countsForRank:false choice for the rank-up bonus, and the
     * same bound applies here — a fixed number of milestones (four by
     * default), each payable once per account, ever, via the `milestone:<n>`
     * ref.
     */
    private function pay(int $userId, int $current, string $day): int
    {
        $paid = $this->ledger->award($userId, 'streak.day', 'day:' . $day);

        $weekEvery = max(1, (int) Config::get($this->settings, 'streak.weekEvery'));
        if ($current > 0 && $current % $weekEvery === 0) {
            $paid += $this->ledger->award($userId, 'streak.week', 'week:' . $day);
        }

        foreach (Config::streakMilestones($this->settings) as $n) {
            if ($current === $n) {
                $bonus = (int) Config::get($this->settings, 'award.streakMilestone');
                if ($bonus > 0) {
                    $this->ledger->credit($userId, $bonus, 'streak.milestone', 'milestone:' . $n, false);
                }
                break; // $current cannot equal two different milestones at once
            }
        }

        return $paid;
    }

    /**
     * Spend a streak freeze bought in the store, if the store is installed and
     * the member is carrying one. Returns whether one was spent.
     */
    private function spendFreeze(int $userId): bool
    {
        if (!class_exists(\Local\Store\Entitlements::class)) {
            return false;
        }

        try {
            // Resolved from the container by name rather than injected, because
            // the store is an optional neighbour: type-hinting a class that may
            // not be installed turns a missing extension into a fatal in the
            // constructor of something the forum needs on every post.
            /** @var \Local\Store\Entitlements $entitlements */
            $entitlements = \Illuminate\Container\Container::getInstance()
                ->make(\Local\Store\Entitlements::class);

            return $entitlements->consume($userId, 'streakfreeze') !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
