<?php

namespace Local\Economy;

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
    /** A post has to be at least this long to hold a streak. */
    public const MIN_LENGTH = 80;

    public function __construct(
        protected ConnectionInterface $db,
        protected Ledger $ledger
    ) {
    }

    /**
     * Record activity for today and pay whatever it earned.
     *
     * @return array{current:int,best:int,paid:int,frozen:bool}
     */
    public function touch(int $userId, ?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');

        $row = $this->db->table('economy_streaks')->where('user_id', $userId)->first();

        if (!$row) {
            $this->db->table('economy_streaks')->insert([
                'user_id' => $userId, 'current' => 1, 'best' => 1,
                'last_day' => $today, 'freezes_used' => 0, 'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return ['current' => 1, 'best' => 1, 'paid' => $this->pay($userId, 1, $today), 'frozen' => false];
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

        return ['current' => $current, 'best' => $best, 'paid' => $this->pay($userId, $current, $today), 'frozen' => $frozen];
    }

    public function of(int $userId): array
    {
        $row = $this->db->table('economy_streaks')->where('user_id', $userId)->first();

        return [
            'current' => (int) ($row->current ?? 0),
            'best' => (int) ($row->best ?? 0),
            'lastDay' => $row->last_day ?? null,
            'freezesUsed' => (int) ($row->freezes_used ?? 0),
        ];
    }

    /**
     * The day and week awards, referenced by date so a replay cannot pay twice
     * — the ledger's unique (user, reason, ref) does the rest.
     */
    private function pay(int $userId, int $current, string $day): int
    {
        $paid = $this->ledger->award($userId, 'streak.day', 'day:' . $day);

        if ($current > 0 && $current % 7 === 0) {
            $paid += $this->ledger->award($userId, 'streak.week', 'week:' . $day);
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
