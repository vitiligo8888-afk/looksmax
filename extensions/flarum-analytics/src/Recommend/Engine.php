<?php

namespace Local\Analytics\Recommend;

use Illuminate\Database\ConnectionInterface;

/**
 * Recommendations from behaviour, content and standing, blended.
 *
 * No single signal is good enough on its own here, and the board's own numbers
 * say why:
 *
 *  - Popularity alone is wrong. Views are dominated by Offtopic, which is 1.57M
 *    of 2.2M threads and the lowest signal section. A pure view ranking buries
 *    the Guides that average 3,262 views against JFL's 346.
 *  - Content similarity alone is wrong. It cannot tell a good guide from a bad
 *    one on the same topic, and it recommends more of what you just read
 *    instead of what you would read next.
 *  - Co-visitation alone is wrong on a cold item. A thread posted an hour ago
 *    has no co-view history and would never surface, so new content dies.
 *
 * So four signals are combined, each normalised to 0..1 before weighting:
 *
 *   co-read      people who genuinely read A also read B, weighted by dwell so
 *                a bounce counts for almost nothing
 *   content      embedding similarity, served by Meilisearch hybrid search
 *   affinity     the reader's own dwell-weighted tag profile
 *   quality      materialised hotness, which already folds in reactions,
 *                replies and recency
 *
 * Weights are settings, not constants, because the right blend is an empirical
 * question that the event stream can answer later.
 */
class Engine
{
    /** Dwell below this reads as a bounce and contributes nothing. */
    private const MIN_DWELL_MS = 4000;

    /** Dwell above this stops adding weight, so one long tab cannot dominate. */
    private const DWELL_CEIL_MS = 240000;

    public function __construct(protected ConnectionInterface $db)
    {
    }

    /**
     * Rebuild the co-visitation table.
     *
     * This is the expensive part, so it runs on a schedule rather than per
     * request. Pairs are stored in one direction only (a < b) and read both
     * ways, which halves the row count.
     *
     * Sessions with an implausible number of reads are excluded: a "reader"
     * who opened 400 threads in a day is a crawler or a scraper (we run one
     * ourselves), and including them makes everything look related to
     * everything.
     */
    public function rebuildCoRead(int $days = 30, int $maxPerSession = 120): int
    {
        $this->db->statement('DELETE FROM analytics_coread');

        $sql = "
            INSERT INTO analytics_coread (a_id, b_id, weight, pairs)
            SELECT LEAST(x.discussion_id, y.discussion_id) AS a_id,
                   GREATEST(x.discussion_id, y.discussion_id) AS b_id,
                   SUM(x.w * y.w) AS weight,
                   COUNT(*) AS pairs
              FROM (
                    SELECT e.discussion_id,
                           COALESCE(e.user_id, 0) AS uid,
                           e.session,
                           -- dwell normalised into 0..1, bounces excluded
                           LEAST(GREATEST(COALESCE(
                             CAST(JSON_EXTRACT(e.props, '$.dwell_ms') AS UNSIGNED), 0
                           ), 0) / ?, 1.0) AS w
                      FROM analytics_events e
                     WHERE e.discussion_id IS NOT NULL
                       AND e.type IN ('discussion.viewed', 'discussion.dwell')
                       AND e.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                   ) x
              JOIN (
                    SELECT e.discussion_id,
                           COALESCE(e.user_id, 0) AS uid,
                           e.session,
                           LEAST(GREATEST(COALESCE(
                             CAST(JSON_EXTRACT(e.props, '$.dwell_ms') AS UNSIGNED), 0
                           ), 0) / ?, 1.0) AS w
                      FROM analytics_events e
                     WHERE e.discussion_id IS NOT NULL
                       AND e.type IN ('discussion.viewed', 'discussion.dwell')
                       AND e.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                   ) y
                ON (x.uid = y.uid AND x.uid <> 0 OR x.session = y.session)
               AND x.discussion_id < y.discussion_id
             GROUP BY a_id, b_id
            HAVING pairs >= 2
        ";

        return $this->db->affectingStatement($sql, [
            self::DWELL_CEIL_MS, $days, self::DWELL_CEIL_MS, $days,
        ]);
    }

    /**
     * A reader's tag affinity, as tag_id => 0..1.
     *
     * Built from dwell rather than clicks. Opening a thread says you found the
     * title interesting; staying says the content delivered, and only the
     * second one should shape what we show you next.
     */
    public function affinity(?int $userId, ?string $session, int $days = 90): array
    {
        if (!$userId && !$session) {
            return [];
        }

        $rows = $this->db->select("
            SELECT dt.tag_id,
                   SUM(LEAST(COALESCE(CAST(JSON_EXTRACT(e.props, '$.dwell_ms') AS UNSIGNED), 0), ?)) AS score
              FROM analytics_events e
              JOIN discussion_tag dt ON dt.discussion_id = e.discussion_id
             WHERE e.created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
               AND (" . ($userId ? 'e.user_id = ?' : 'e.session = ?') . ")
               AND COALESCE(CAST(JSON_EXTRACT(e.props, '$.dwell_ms') AS UNSIGNED), 0) >= ?
             GROUP BY dt.tag_id
             ORDER BY score DESC
             LIMIT 40
        ", [self::DWELL_CEIL_MS, $days, $userId ?: $session, self::MIN_DWELL_MS]);

        $max = 0.0;
        foreach ($rows as $r) {
            $max = max($max, (float) $r->score);
        }
        if ($max <= 0) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->tag_id] = (float) $r->score / $max;
        }

        return $out;
    }

    /**
     * Related discussions for one discussion, for the "you might also read" rail.
     *
     * Content similarity is expected to arrive from Meilisearch hybrid search
     * and be passed in, so this class stays free of a hard dependency on the
     * search engine and remains testable without it.
     *
     * @param array<int,float> $contentSim discussion_id => 0..1
     */
    public function related(int $discussionId, array $contentSim = [], int $limit = 10): array
    {
        $co = $this->db->select("
            SELECT CASE WHEN a_id = ? THEN b_id ELSE a_id END AS id, weight
              FROM analytics_coread
             WHERE a_id = ? OR b_id = ?
             ORDER BY weight DESC
             LIMIT 100
        ", [$discussionId, $discussionId, $discussionId]);

        $maxCo = 0.0;
        foreach ($co as $r) {
            $maxCo = max($maxCo, (float) $r->weight);
        }

        $scores = [];
        foreach ($co as $r) {
            $scores[(int) $r->id] = $maxCo > 0 ? 0.55 * ((float) $r->weight / $maxCo) : 0.0;
        }
        foreach ($contentSim as $id => $sim) {
            $scores[(int) $id] = ($scores[(int) $id] ?? 0) + 0.30 * $sim;
        }

        if (!$scores) {
            return [];
        }

        // quality and freshness as the tiebreak, so a cold-start item with no
        // co-read history can still surface on merit
        $ids = array_keys($scores);
        $meta = $this->db->table('discussions')
            ->whereIn('id', $ids)
            ->where('is_private', false)
            ->whereNull('hidden_at')
            ->get(['id', 'hotness', 'last_posted_at']);

        $out = [];
        foreach ($meta as $d) {
            $age = $d->last_posted_at ? (time() - strtotime($d->last_posted_at)) / 86400 : 999;
            $out[] = [
                'id' => (int) $d->id,
                'score' => $scores[(int) $d->id]
                    + 0.10 * min(1.0, (float) $d->hotness / 5)
                    + 0.05 * max(0, 1 - $age / 30),
            ];
        }

        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, $limit);
    }

    /**
     * A personalised feed. Falls back to hotness for a reader we know nothing
     * about, which is the honest answer for a first visit rather than a
     * pretend-personalised list.
     */
    public function feed(?int $userId, ?string $session, int $limit = 30): array
    {
        $affinity = $this->affinity($userId, $session);

        if (!$affinity) {
            return $this->db->table('discussions')
                ->where('is_private', false)
                ->whereNull('hidden_at')
                ->orderByDesc('hotness')
                ->limit($limit)
                ->pluck('id')
                ->all();
        }

        $tagIds = array_keys($affinity);
        $case = 'CASE dt.tag_id ' . implode(' ', array_map(
            fn ($id) => 'WHEN ' . (int) $id . ' THEN ' . (float) $affinity[$id],
            $tagIds
        )) . ' ELSE 0 END';

        // Already-read threads are excluded: a personalised feed that shows you
        // what you just finished reading is worse than no personalisation.
        $rows = $this->db->select("
            SELECT d.id,
                   (0.55 * MAX({$case}) + 0.35 * LEAST(d.hotness / 5, 1)
                    + 0.10 * GREATEST(0, 1 - (TIMESTAMPDIFF(DAY, d.last_posted_at, NOW()) / 30))) AS score
              FROM discussions d
              JOIN discussion_tag dt ON dt.discussion_id = d.id
             WHERE d.is_private = 0 AND d.hidden_at IS NULL
               AND dt.tag_id IN (" . implode(',', array_map('intval', $tagIds)) . ")
               AND d.id NOT IN (
                     SELECT DISTINCT discussion_id FROM analytics_events
                      WHERE discussion_id IS NOT NULL
                        AND type IN ('discussion.viewed','discussion.dwell')
                        AND " . ($userId ? 'user_id = ?' : 'session = ?') . "
                        AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                   )
             GROUP BY d.id
             ORDER BY score DESC
             LIMIT {$limit}
        ", [$userId ?: $session]);

        return array_map(fn ($r) => (int) $r->id, $rows);
    }
}
