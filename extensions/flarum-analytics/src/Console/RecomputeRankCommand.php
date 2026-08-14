<?php

namespace Local\Analytics\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Turn the event stream into a ranking signal.
 *
 * Why a materialised score rather than sorting on a live query: with 2.2M
 * discussions and tens of millions of events, ORDER BY over a join is not a
 * thing you do per request. This recomputes `discussions.hotness` in bulk and
 * the API sorts on an indexed column.
 *
 * The score is deliberately explainable rather than clever:
 *
 *   hotness = log10(1 + weighted_engagement) + recency_bonus - staleness
 *
 * Weights come from what the source board actually rewards. Its own numbers:
 * `Guide` threads average 3,262 views / 24 replies while `JFL` averages 346 /
 * 13 — so raw view count alone would rank shitposts above guides. Replies and
 * reactions are weighted above views for exactly that reason, and unique views
 * above repeat views.
 */
class RecomputeRankCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('analytics:rank')
            ->setDescription('Recompute discussion hotness from the analytics event stream')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'days of events to weigh', 7);
    }

    protected function fire()
    {
        $since = (int) $this->input->getOption('since');
        $this->info("recomputing hotness from the last {$since}d of events…");

        // 1. roll the raw event stream into per-discussion aggregates
        $this->db->statement("DROP TEMPORARY TABLE IF EXISTS _agg");
        $this->db->statement("
            CREATE TEMPORARY TABLE _agg AS
            SELECT discussion_id,
                   SUM(type = 'discussion.viewed')                    AS views,
                   COUNT(DISTINCT CASE WHEN type = 'discussion.viewed'
                         THEN COALESCE(CONCAT('u', user_id), session) END) AS uniques,
                   SUM(type = 'post.created')                          AS replies,
                   SUM(type = 'post.reacted')                          AS reactions,
                   AVG(CASE WHEN type = 'discussion.dwell'
                       THEN CAST(JSON_EXTRACT(props, '$.dwell_ms') AS UNSIGNED) END) AS avg_dwell,
                   MAX(created_at)                                     AS last_event
              FROM analytics_events
             WHERE discussion_id IS NOT NULL
               AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY discussion_id
        ", [$since]);

        // 2. weighted score. Reactions and replies dominate views on purpose;
        //    dwell separates "opened and left" from "actually read".
        $affected = $this->db->update("
            UPDATE discussions d
              JOIN _agg a ON a.discussion_id = d.id
               SET d.view_count   = d.view_count + a.views,
                   d.unique_views = GREATEST(d.unique_views, a.uniques),
                   d.hotness =
                     LOG10(1
                       + (a.uniques   * 1.0)
                       + (a.replies   * 6.0)
                       + (a.reactions * 3.0)
                       + (LEAST(COALESCE(a.avg_dwell, 0) / 1000, 300) * 0.25)
                     )
                     -- recency: full credit for today, decaying over a week
                     + GREATEST(0, 1 - (TIMESTAMPDIFF(HOUR, a.last_event, NOW()) / 168))
                     -- staleness penalty so a 2019 thread with old engagement
                     -- cannot outrank live discussion
                     - (TIMESTAMPDIFF(DAY, d.last_posted_at, NOW()) / 365)
        ");

        $this->info("updated {$affected} discussions");

        // 3. decay everything not touched this window, so the front page moves
        $this->db->update("
            UPDATE discussions
               SET hotness = hotness * 0.9
             WHERE id NOT IN (SELECT discussion_id FROM _agg)
               AND hotness > 0.01
        ");
        $this->db->statement("DROP TEMPORARY TABLE IF EXISTS _agg");
        $this->info('done');
    }
}
