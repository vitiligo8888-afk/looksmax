<?php

namespace Local\Economy;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * Daily and weekly quests: a checklist that turns "post and you'll eventually
 * earn something" into "do these three things today for a bonus", which is the
 * concrete ask behind "tasks to earn currency" in the gamification brief.
 *
 * ── why this needs no new listener and no new state table ──────────────────
 * Every quest's PROGRESS is a count already sitting in `economy_transactions`
 * — the exact rows AwardPost/AwardDiscussion/AwardReaction/TrackStreak already
 * write, for reasons already carried by the daily-goals bar in
 * SummaryController. Reading "posts today" a second time to also answer "posts
 * toward today's quest" costs one more WHERE on a query this class already
 * runs, not a second award pipeline. Concretely: `RevokePost` and
 * `RevokeDiscussion` already reverse the transaction a deleted post or thread
 * earned, so a quest computed live from those counts is REVOKED FOR FREE —
 * delete two of your three posts before claiming and the quest goes back to
 * 2/3, no extra code. That is the same idempotency and reversibility guarantee
 * every other award in this extension carries, inherited rather than
 * reimplemented.
 *
 * ── what is NOT reversible, and why that is the accepted shape ─────────────
 * Once a quest is CLAIMED, the bonus itself is not clawed back if the
 * qualifying posts are deleted afterward. This is the same trade
 * Ledger::refreshRank() already documents for the rank-up bonus and
 * Streaks::pay() for the streak-milestone bonus: both are threshold crossings
 * over multiple actions rather than a mirror of one, both are paid through
 * credit() with a ref that can only ever fire once, and both are small and
 * bounded rather than reversible. Quest rewards are sized the same way here —
 * a handful of points per quest, comparable to a single streak day, never
 * comparable to what deleting the underlying posts would claw back on ITS OWN
 * award path (which does revoke). Reversing the base award and leaving the
 * bonus in place, rather than trying to claw back a bonus this class did not
 * originate, is the bounded, well-understood choice the ledger already made
 * twice before this file existed.
 *
 * ── idempotency ──────────────────────────────────────────────────────────
 * claim() pays through `Ledger::credit()` with `ref = 'quest:<key>:<period>'`
 * — the same (user, reason, ref) unique constraint every other award in this
 * ledger relies on, so a double-click or a replayed request cannot pay twice.
 *
 * ── numbers are configurable, the checklist is not ──────────────────────────
 * Which quests exist is code, same as Ledger::AWARDS — a quest's identity is
 * tied to its translation strings and its icon, not something an operator
 * retypes in a settings box. Every TARGET and every REWARD is a setting under
 * `economy.quest.*`, read through Config, so a content push can raise "post 3
 * times" to "post 5 times" the same afternoon it decides to, exactly like
 * every award amount and daily cap in Config::KEYS already can.
 */
class Quests
{
    /**
     * @var array<int,array{key:string,scope:string,kind:string,reason:?string,icon:string}>
     */
    public const DEFS = [
        // --- daily, reset every UTC day -------------------------------------
        ['key' => 'post3', 'scope' => 'daily', 'kind' => 'ledger_count', 'reason' => 'post.created', 'icon' => 'ph:chat-circle-dots-fill'],
        ['key' => 'give3', 'scope' => 'daily', 'kind' => 'ledger_count', 'reason' => 'reaction.given', 'icon' => 'ph:hand-heart-fill'],
        ['key' => 'received1', 'scope' => 'daily', 'kind' => 'ledger_count', 'reason' => 'reaction.received', 'icon' => 'ph:heart-fill'],
        ['key' => 'streak', 'scope' => 'daily', 'kind' => 'streak_held', 'reason' => null, 'icon' => 'ph:fire-fill'],

        // --- weekly, reset every ISO week -----------------------------------
        ['key' => 'post15', 'scope' => 'weekly', 'kind' => 'ledger_count', 'reason' => 'post.created', 'icon' => 'ph:chats-circle-fill'],
        ['key' => 'thread1', 'scope' => 'weekly', 'kind' => 'ledger_count', 'reason' => 'discussion.started', 'icon' => 'ph:tree-structure-fill'],
        ['key' => 'reactions25', 'scope' => 'weekly', 'kind' => 'ledger_count', 'reason' => 'reaction.received', 'icon' => 'ph:hand-heart-fill'],
        ['key' => 'streak7', 'scope' => 'weekly', 'kind' => 'streak_current', 'reason' => null, 'icon' => 'game-icons:laurel-crown'],
    ];

    public function __construct(
        protected ConnectionInterface $db,
        protected Ledger $ledger,
        protected Streaks $streaks,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function enabled(): bool
    {
        return (bool) Config::get($this->settings, 'quest.enabled');
    }

    /** UTC calendar day. */
    public static function dailyPeriod(): string
    {
        return gmdate('Y-m-d');
    }

    /** ISO-8601 year-week, e.g. "2026-W33" — Monday-anchored, matches gmdate('W'). */
    public static function weeklyPeriod(): string
    {
        return gmdate('o') . '-W' . gmdate('W');
    }

    private static function windowStart(string $scope): string
    {
        if ($scope === 'daily') {
            return gmdate('Y-m-d') . ' 00:00:00';
        }

        // Monday 00:00 UTC of the current ISO week.
        $dow = (int) gmdate('N'); // 1 (Mon) .. 7 (Sun)
        return gmdate('Y-m-d 00:00:00', time() - ($dow - 1) * 86400);
    }

    private function targetKey(array $def): string
    {
        return 'quest.' . $def['scope'] . '.' . $def['key'] . '.target';
    }

    private function rewardKey(array $def): string
    {
        return 'quest.' . $def['scope'] . '.' . $def['key'] . '.reward';
    }

    public function target(array $def): int
    {
        return max(1, (int) Config::get($this->settings, $this->targetKey($def)));
    }

    public function reward(array $def): int
    {
        return max(0, (int) Config::get($this->settings, $this->rewardKey($def)));
    }

    /**
     * Every quest's live state for one account: progress, target, reward,
     * whether it is already claimed, and whether it can be claimed right now.
     *
     * @return array<int,array>
     */
    public function state(int $userId): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $streak = $this->streaks->of($userId);

        // One query per scope for the counted quests, grouped by reason, rather
        // than one query per quest — four daily reasons in one pass, three
        // weekly ones in another.
        $dailyCounts = $this->countsSince(
            $userId,
            self::windowStart('daily'),
            array_column(array_filter(self::DEFS, fn ($d) => $d['scope'] === 'daily' && $d['kind'] === 'ledger_count'), 'reason')
        );
        $weeklyCounts = $this->countsSince(
            $userId,
            self::windowStart('weekly'),
            array_column(array_filter(self::DEFS, fn ($d) => $d['scope'] === 'weekly' && $d['kind'] === 'ledger_count'), 'reason')
        );

        // Which refs are already claimed, in one query — see claimRef().
        $refs = [];
        foreach (self::DEFS as $def) {
            $refs[] = $this->claimRef($def);
        }
        $claimed = $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->whereIn('reason', ['quest.daily', 'quest.weekly'])
            ->whereIn('ref', $refs)
            ->pluck('ref')->all();
        $claimedSet = array_flip($claimed);

        $out = [];
        foreach (self::DEFS as $def) {
            $target = $this->target($def);

            $progress = match ($def['kind']) {
                'ledger_count' => (int) (($def['scope'] === 'daily' ? $dailyCounts : $weeklyCounts)[$def['reason']] ?? 0),
                'streak_held' => $streak['heldToday'] ? 1 : 0,
                'streak_current' => min($target, (int) $streak['current']),
                default => 0,
            };

            $ref = $this->claimRef($def);
            $isClaimed = isset($claimedSet[$ref]);
            $met = $progress >= $target;

            $out[] = [
                'key' => $def['key'],
                'scope' => $def['scope'],
                'icon' => $def['icon'],
                'progress' => min($progress, $target),
                'target' => $target,
                'reward' => $this->reward($def),
                'claimed' => $isClaimed,
                'claimable' => $met && !$isClaimed,
                'period' => $def['scope'] === 'daily' ? self::dailyPeriod() : self::weeklyPeriod(),
            ];
        }

        return $out;
    }

    /** @return array<string,int> reason => count of positive-delta rows since $since */
    private function countsSince(int $userId, string $since, array $reasons): array
    {
        $reasons = array_values(array_unique(array_filter($reasons)));
        if (!$reasons) {
            return [];
        }

        return $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->where('delta', '>', 0)
            ->whereIn('reason', $reasons)
            ->groupBy('reason')
            ->selectRaw('reason, COUNT(*) c')
            ->pluck('c', 'reason')
            ->map(fn ($v) => (int) $v)->all();
    }

    private function claimRef(array $def): string
    {
        $period = $def['scope'] === 'daily' ? self::dailyPeriod() : self::weeklyPeriod();

        return 'quest:' . $def['key'] . ':' . $period;
    }

    private function findDef(string $key): ?array
    {
        foreach (self::DEFS as $def) {
            if ($def['key'] === $key) {
                return $def;
            }
        }

        return null;
    }

    /**
     * Claim one quest's reward. Returns the amount credited (0 on any refusal
     * — unknown key, quests disabled, not yet met, or already claimed).
     *
     * Recomputes progress from the live ledger right before paying — a client
     * that cached yesterday's "3/3" and posts the claim after midnight, or
     * after deleting the qualifying posts, is refused here regardless of what
     * it believes the state to be. The reward counts toward lifetime_points
     * (rank progress): a quest reward is a genuine engagement bonus, not a
     * system multiplier, and Ledger::credit()'s own refreshRank() call is what
     * makes crossing a rank threshold via a quest pay the same bounded,
     * once-ever rank-up bonus a threshold crossed by ordinary posting would.
     */
    public function claim(int $userId, string $key): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        $def = $this->findDef($key);
        if ($def === null) {
            return 0;
        }

        $found = null;
        foreach ($this->state($userId) as $row) {
            if ($row['key'] === $key) {
                $found = $row;
                break;
            }
        }

        if ($found === null || !$found['claimable'] || $found['reward'] <= 0) {
            return 0;
        }

        $reason = $def['scope'] === 'daily' ? 'quest.daily' : 'quest.weekly';

        return $this->ledger->credit($userId, $found['reward'], $reason, $this->claimRef($def), true);
    }
}
