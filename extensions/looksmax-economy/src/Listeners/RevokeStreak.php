<?php

namespace Local\Economy\Listeners;

use Flarum\Post\Event\Deleted;
use Illuminate\Database\ConnectionInterface;
use Local\Economy\Ledger;
use Local\Economy\Streaks;

/**
 * The farming bug this closes: write one qualifying post (>= minLength
 * characters), collect `streak.day` (and, on day 7/14/21…, `streak.week`
 * too), then delete the post. Before this listener existed, RevokePost never
 * touched the streak reasons at all — it only knows about `post.created` —
 * so the streak's currency stayed paid even though the only thing that
 * justified it was gone. Repeat daily and it is a second, unlimited income
 * stream layered on top of whatever post.created itself paid, immune to
 * every defence Streaks was designed around (the codebase's own header for
 * that file calls a streak "the one earning rule that cannot be farmed by
 * volume" — true only once deleting the qualifying post also fails to pay).
 *
 * The hard part is not reversing the currency — Ledger::revoke() already
 * does that safely by (user, reason, ref) — it is deciding WHETHER to. A
 * naive "any post deletion voids today's streak.day" is wrong the moment an
 * account writes two long posts in one day and deletes the first: the second
 * one still qualifies and the day is still honestly earned. Getting this
 * right needs to know which specific post CAUSED the day to pay, which
 * `economy_streaks` never recorded (it only keeps the latest current/best/
 * last_day) — hence the new `economy_streak_days` table and
 * Streaks::touch()'s anchor-recording, shipped in the same migration as this
 * listener.
 */
class RevokeStreak
{
    public function __construct(
        protected Ledger $ledger,
        protected Streaks $streaks,
        protected ConnectionInterface $db
    ) {
    }

    public function handle(Deleted $event): void
    {
        $post = $event->post;
        if (!$post || !$post->user_id) {
            return;
        }

        $userId = (int) $post->user_id;

        $created = $post->created_at ?? null;
        $ts = $created instanceof \DateTimeInterface
            ? $created->getTimestamp()
            : ($created ? strtotime((string) $created) : 0);

        if (!$ts) {
            return;
        }

        $day = gmdate('Y-m-d', $ts);

        $anchor = $this->db->table('economy_streak_days')
            ->where('user_id', $userId)->where('day', $day)->first();

        if (!$anchor || (int) $anchor->post_id !== (int) $post->id) {
            // Either this account never earned a streak day on the calendar
            // date this post was written, or a DIFFERENT post is the reason
            // it was earned. The post being deleted was, at most, extra
            // reading that day — nothing to revoke.
            return;
        }

        if ($this->anotherQualifyingPostExists($userId, $post->id, $ts)) {
            // The day is still honestly earned by something else. Move the
            // anchor to it so a FUTURE deletion of that post asks the same
            // question again, rather than leaving the row pointing at a post
            // that no longer exists.
            $this->reanchor($userId, $day, $post->id, $ts);
            return;
        }

        // Nothing left justifies this day. Take back exactly what it paid —
        // and only what it paid: revoke() is a no-op for whichever of these
        // two refs was never awarded (streak.week only fires every Nth day).
        $this->ledger->revoke($userId, 'streak.day', 'day:' . $day);
        $this->ledger->revoke($userId, 'streak.week', 'week:' . $day);

        $this->db->table('economy_streak_days')
            ->where('user_id', $userId)->where('day', $day)->delete();

        // Roll the LIVE current/best counters back one link, but only when
        // this day is still the most recent one on the chain (last_day still
        // equals it). A day buried in the middle of a streak that has since
        // continued for weeks is deliberately left alone: unwinding
        // current/best correctly for a historical link would mean replaying
        // every day after it too — a correctness project of its own, and one
        // that is no longer a CURRENCY bug once the revoke() above has run
        // (the points for this specific day are already gone either way).
        // What would remain is purely cosmetic — a displayed streak length
        // very slightly overstated for a day deep in the past — which this
        // fix does not attempt to chase.
        $streak = $this->db->table('economy_streaks')->where('user_id', $userId)->first();
        if ($streak && $streak->last_day === $day) {
            $prevDay = $this->db->table('economy_streak_days')
                ->where('user_id', $userId)->where('day', '<', $day)
                ->orderByDesc('day')->value('day');

            $this->db->table('economy_streaks')->where('user_id', $userId)->update([
                'current' => max(0, (int) $streak->current - 1),
                'last_day' => $prevDay,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Whether the account still has a standing post, other than the one
     * being deleted, long enough to hold a streak on the same UTC day.
     *
     * Length is approximated with SQL CHAR_LENGTH() on the raw stored
     * content, rather than the trim(strip_tags()) TrackStreak computes in
     * PHP. That is a deliberate, one-directional approximation: stripping
     * markup can only ever SHORTEN a string, so this check can under-revoke
     * (leave a day standing that the byte-perfect PHP check would have
     * voided) but can never over-revoke a day some other post still honestly
     * earns. Given the amounts at stake (a handful of points per day), that
     * is the side to be wrong on.
     */
    private function anotherQualifyingPostExists(int $userId, int $excludePostId, int $dayTs): bool
    {
        return $this->db->table('posts')
            ->where('user_id', $userId)
            ->where('id', '<>', $excludePostId)
            ->whereNull('hidden_at')
            ->whereRaw('created_at >= ? and created_at < ?', $this->dayBounds($dayTs))
            ->whereRaw('CHAR_LENGTH(content) >= ?', [$this->streaks->minLength()])
            ->exists();
    }

    private function reanchor(int $userId, string $day, int $excludePostId, int $dayTs): void
    {
        $replacement = $this->db->table('posts')
            ->where('user_id', $userId)
            ->where('id', '<>', $excludePostId)
            ->whereNull('hidden_at')
            ->whereRaw('created_at >= ? and created_at < ?', $this->dayBounds($dayTs))
            ->whereRaw('CHAR_LENGTH(content) >= ?', [$this->streaks->minLength()])
            ->orderBy('id')
            ->value('id');

        if ($replacement) {
            $this->db->table('economy_streak_days')
                ->where('user_id', $userId)->where('day', $day)
                ->update(['post_id' => $replacement]);
        }
    }

    private function dayBounds(int $ts): array
    {
        return [gmdate('Y-m-d 00:00:00', $ts), gmdate('Y-m-d 00:00:00', $ts + 86400)];
    }
}
