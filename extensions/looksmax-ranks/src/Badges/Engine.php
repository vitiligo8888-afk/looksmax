<?php

namespace Local\Ranks\Badges;

use Illuminate\Database\ConnectionInterface;
use Local\Ranks\Catalog;

/**
 * Badge evaluation, in bulk.
 *
 * Every check returns `[user_id => value]` for the WHOLE population in one
 * query, and the awarder compares that value against the badge's threshold.
 * The alternative — a per-user check — is 1,413 users times 26 badges, which is
 * 36,738 queries and a command nobody will ever run twice.
 *
 * Awards are idempotent (unique on user+badge), and every check is a pure
 * function of stored data, so `identity:badges --rebuild` deletes everything
 * and comes back to exactly the same set. That property is the reason none of
 * this is hand-granted: a badge you cannot recompute is a badge you cannot
 * argue about.
 */
class Engine
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    /**
     * Posts authored.
     *
     * The greater of the local count and the count carried in at import. It
     * reads BOTH because `comment_count` is owned by core and by the importer's
     * `recountUsers()`, which reset all 1,411 imported accounts from tens of
     * thousands of posts to under a hundred between one run of this command and
     * the next. `legacy_posts` is ours, only the backfill writes it, and taking
     * the max means a native poster who never existed on the source board is
     * still measured correctly.
     */
    public function posts(): array
    {
        $out = [];
        foreach ($this->db->table('users')->get(['id', 'comment_count', 'legacy_posts']) as $u) {
            $out[$u->id] = max((int) $u->comment_count, (int) $u->legacy_posts);
        }

        return $out;
    }

    /** Threads started: local discussions plus the count carried in at import. */
    public function threads(): array
    {
        $local = $this->db->table('discussions')->whereNull('hidden_at')
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) c')->pluck('c', 'user_id');

        $out = [];
        foreach ($this->db->table('users')->get(['id', 'legacy_threads']) as $u) {
            $out[$u->id] = max((int) $u->legacy_threads, (int) ($local[$u->id] ?? 0));
        }

        return $out;
    }

    /** Published guides authored. */
    public function guides(): array
    {
        return $this->db->table('guide_meta')
            ->join('discussions', 'discussions.id', '=', 'guide_meta.discussion_id')
            ->where('guide_meta.status', 'published')
            ->groupBy('discussions.user_id')
            ->selectRaw('discussions.user_id AS uid, COUNT(*) c')
            ->pluck('c', 'uid')->map(fn ($v) => (int) $v)->all();
    }

    /** Guide claims that carry a real source. Evidence, not assertion. */
    public function sourced(): array
    {
        return $this->db->table('guide_claims')
            ->join('discussions', 'discussions.id', '=', 'guide_claims.discussion_id')
            ->where(function ($q) {
                $q->whereNotNull('guide_claims.source_url')->orWhereNotNull('guide_claims.source_doi');
            })
            ->groupBy('discussions.user_id')
            ->selectRaw('discussions.user_id AS uid, COUNT(*) c')
            ->pluck('c', 'uid')->map(fn ($v) => (int) $v)->all();
    }

    /** Reactions received: native likes plus the reputation carried in. */
    public function reactions(): array
    {
        $local = $this->db->table('post_likes')
            ->join('posts', 'posts.id', '=', 'post_likes.post_id')
            ->groupBy('posts.user_id')
            ->selectRaw('posts.user_id AS uid, COUNT(*) c')
            ->pluck('c', 'uid');

        $out = [];
        foreach ($this->db->table('users')->get(['id', 'legacy_reactions']) as $u) {
            $out[$u->id] = (int) $u->legacy_reactions + (int) ($local[$u->id] ?? 0);
        }

        return $out;
    }

    /**
     * Reactions per post, but only once there are enough posts for the ratio to
     * mean anything. A single post with four likes is not a track record.
     */
    public function ratio(): array
    {
        $reactions = $this->reactions();
        $posts = $this->posts();

        $out = [];
        foreach ($posts as $id => $p) {
            $out[$id] = $p >= 200 ? (int) floor(($reactions[$id] ?? 0) / max(1, $p)) : 0;
        }

        return $out;
    }

    /**
     * Readers who reached the bottom of a thread this user started.
     *
     * This is the axis the analytics stream buys us and that neither post count
     * nor like count can see: someone can be liked a thousand times for
     * one-liners and never once have been read to the end.
     */
    public function readthrough(): array
    {
        return $this->db->table('analytics_events AS a')
            ->join('discussions AS d', 'd.id', '=', 'a.discussion_id')
            ->where('a.type', 'discussion.dwell')
            ->whereRaw("CAST(JSON_VALUE(a.props, '$.read_pct') AS UNSIGNED) >= 85")
            ->groupBy('d.user_id')
            ->selectRaw('d.user_id AS uid, COUNT(DISTINCT a.session, a.discussion_id) c')
            ->pluck('c', 'uid')->map(fn ($v) => (int) $v)->all();
    }

    /** Cumulative seconds spent reading this user's threads. */
    public function dwell(): array
    {
        return $this->db->table('analytics_events AS a')
            ->join('discussions AS d', 'd.id', '=', 'a.discussion_id')
            ->where('a.type', 'discussion.dwell')
            ->groupBy('d.user_id')
            ->selectRaw("d.user_id AS uid, FLOOR(SUM(CAST(JSON_VALUE(a.props, '$.dwell_ms') AS UNSIGNED)) / 1000) c")
            ->pluck('c', 'uid')->map(fn ($v) => (int) $v)->all();
    }

    /** Days since joining. */
    public function tenure(): array
    {
        return $this->db->table('users')
            ->selectRaw('id, DATEDIFF(NOW(), joined_at) d')
            ->pluck('d', 'id')->map(fn ($v) => max(0, (int) $v))->all();
    }

    /** Posts written between 02:00 and 05:00 local server time. */
    public function nightowl(): array
    {
        return $this->db->table('posts')
            ->whereRaw('HOUR(created_at) BETWEEN 2 AND 4')
            ->whereNull('hidden_at')
            ->groupBy('user_id')
            ->selectRaw('user_id AS uid, COUNT(*) c')
            ->pluck('c', 'uid')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Replies to threads that had been silent for 180 days or more.
     *
     * Correlated against the previous post in the same discussion, which is the
     * only definition of "dead thread" that survives a thread with a gap in the
     * middle rather than at the end.
     */
    public function necro(): array
    {
        $sql = <<<'SQL'
SELECT p.user_id AS uid, COUNT(*) c
FROM posts p
JOIN posts prev
  ON prev.discussion_id = p.discussion_id
 AND prev.number = (
       SELECT MAX(p2.number) FROM posts p2
       WHERE p2.discussion_id = p.discussion_id AND p2.number < p.number
     )
WHERE p.user_id IS NOT NULL
  AND p.hidden_at IS NULL
  AND DATEDIFF(p.created_at, prev.created_at) >= 180
GROUP BY p.user_id
SQL;

        $out = [];
        foreach ($this->db->select($sql) as $r) {
            $out[$r->uid] = (int) $r->c;
        }

        return $out;
    }

    /** Accounts that existed on the source board before the migration. */
    public function imported(): array
    {
        return $this->db->table('users')->whereNotNull('imported_id')
            ->pluck('id')->mapWithKeys(fn ($id) => [$id => 1])->all();
    }

    /** Reputation carried in from the source board. */
    public function legacy(): array
    {
        return $this->db->table('users')->pluck('legacy_reactions', 'id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Staff banners recovered from the source board's `users.banners` column.
     *
     * Stored as inventory rows of type `banner` at backfill time rather than as
     * a column, because a user can hold several ("Staff" AND "To the Moon!")
     * and a column cannot hold several.
     */
    public function bannerFlags(): array
    {
        $out = [];
        foreach ($this->db->table('identity_inventory')->where('type', 'banner')->get(['user_id', 'item']) as $r) {
            $out[$r->user_id][] = $r->item;
        }

        return $out;
    }

    // ------------------------------------------------------------------ award

    /**
     * Evaluate every badge and write the ones that are newly satisfied.
     *
     * Returns [awarded, points] so the caller can report a measured number
     * rather than "done".
     */
    public function run(callable $creditPoints, ?callable $progress = null): array
    {
        $existing = [];
        foreach ($this->db->table('identity_badges')->get(['user_id', 'badge']) as $r) {
            $existing[$r->user_id . '|' . $r->badge] = true;
        }

        $bannerFlags = $this->bannerFlags();
        $awarded = 0;
        $points = 0;
        $now = date('Y-m-d H:i:s');
        $batch = [];

        foreach (Catalog::BADGES as $badge) {
            $check = $badge['check'];

            if ($check === 'banner') {
                $values = [];
                foreach ($bannerFlags as $uid => $flags) {
                    if (in_array($badge['arg'], $flags, true)) {
                        $values[$uid] = 1;
                    }
                }
                $threshold = 1;
            } else {
                $values = method_exists($this, $check) ? $this->{$check}() : [];
                $threshold = max(1, (int) $badge['arg']);
            }

            foreach ($values as $uid => $value) {
                if ($value < $threshold || isset($existing[$uid . '|' . $badge['slug']])) {
                    continue;
                }

                $batch[] = [
                    'user_id' => (int) $uid,
                    'badge' => $badge['slug'],
                    'awarded_at' => $now,
                    'progress' => (int) $value,
                    'showcased' => 0,
                    'slot' => 0,
                ];
                $existing[$uid . '|' . $badge['slug']] = true;
                $awarded++;

                if ($badge['points'] > 0) {
                    $points += $creditPoints((int) $uid, $badge['slug'], (int) $badge['points']);
                }

                if (count($batch) >= 500) {
                    $this->db->table('identity_badges')->insertOrIgnore($batch);
                    $batch = [];
                }
            }

            $progress && $progress($badge['slug'], count($values));
        }

        if ($batch) {
            $this->db->table('identity_badges')->insertOrIgnore($batch);
        }

        $this->db->statement(
            'UPDATE users u SET badge_count = (SELECT COUNT(*) FROM identity_badges b WHERE b.user_id = u.id)'
        );

        return [$awarded, $points];
    }

    /** Rarity of every badge as a percentage of the member population. */
    public function rarities(): array
    {
        $total = max(1, (int) $this->db->table('users')->count());
        $out = [];
        foreach ($this->db->table('identity_badges')->groupBy('badge')->selectRaw('badge, COUNT(*) c')->get() as $r) {
            $out[$r->badge] = round(100 * $r->c / $total, 2);
        }
        foreach (Catalog::BADGES as $b) {
            $out[$b['slug']] ??= 0.0;
        }

        return $out;
    }
}
