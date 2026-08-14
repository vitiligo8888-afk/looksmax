<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Meili\Indexer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The content-gap report.
 *
 * Search logs are usually kept for capacity planning and then never read. The
 * useful reading is the opposite direction: a ranked list of what readers asked
 * for and did not get is a content plan written by the audience, in their own
 * words, at the moment they wanted it.
 *
 * Four questions, each answerable from one indexed query:
 *
 *   gaps        — demand with no supply: repeated queries returning nothing.
 *                 `askers` (distinct people) is ranked above raw volume,
 *                 because forty searches from one determined person is a
 *                 person, and four searches from four people is a topic.
 *   abandoned   — demand with unusable supply: results existed, nobody clicked.
 *                 Invisible to any result-count metric, and the single best
 *                 signal that ranking rather than content is the problem.
 *   deep clicks — the answer existed but was ranked badly. Average click
 *                 position is the ranking quality number.
 *   latency     — p50/p95 measured from real traffic, not from a benchmark.
 *
 * Restricted to users who can administer the forum: raw queries are personal
 * data, and this endpoint is the only place they are exposed.
 */
class InsightsController implements RequestHandlerInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private Indexer $indexer
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (!$actor->isAdmin()) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }

        $days = max(1, min(365, (int) ($request->getQueryParams()['days'] ?? 30)));
        $since = date('Y-m-d H:i:s', time() - $days * 86400);

        $gaps = $this->db->table('search_queries')
            ->where('created_at', '>=', $since)
            ->where('result_count', 0)
            ->whereRaw("normalised <> ''")
            ->groupBy('normalised')
            ->orderByRaw('COUNT(DISTINCT COALESCE(user_id, session)) DESC, COUNT(*) DESC')
            ->limit(50)
            ->get([
                'normalised',
                $this->db->raw('COUNT(*) as searches'),
                $this->db->raw('COUNT(DISTINCT COALESCE(user_id, session)) as askers'),
                $this->db->raw('MAX(query) as example'),
                $this->db->raw('MAX(created_at) as last_seen'),
            ]);

        $abandoned = $this->db->table('search_queries')
            ->where('created_at', '>=', $since)
            ->where('result_count', '>', 0)
            ->whereRaw("normalised <> ''")
            ->groupBy('normalised')
            ->havingRaw('SUM(clicked_at IS NOT NULL) = 0')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(50)
            ->get([
                'normalised',
                $this->db->raw('COUNT(*) as searches'),
                $this->db->raw('COUNT(DISTINCT COALESCE(user_id, session)) as askers'),
                $this->db->raw('ROUND(AVG(result_count)) as avg_results'),
                $this->db->raw('MAX(query) as example'),
            ]);

        $deepClicks = $this->db->table('search_queries')
            ->where('created_at', '>=', $since)
            ->whereNotNull('clicked_at')
            ->where('clicked_position', '>=', 3)
            ->groupBy('normalised')
            ->orderByRaw('AVG(clicked_position) DESC, COUNT(*) DESC')
            ->limit(30)
            ->get([
                'normalised',
                $this->db->raw('COUNT(*) as clicks'),
                $this->db->raw('ROUND(AVG(clicked_position), 1) as avg_position'),
                $this->db->raw('MAX(query) as example'),
            ]);

        $totals = $this->db->table('search_queries')
            ->where('created_at', '>=', $since)
            ->first([
                $this->db->raw('COUNT(*) as searches'),
                $this->db->raw('SUM(result_count = 0) as zero'),
                $this->db->raw('SUM(clicked_at IS NOT NULL) as clicked'),
                $this->db->raw('COUNT(DISTINCT COALESCE(user_id, session)) as searchers'),
                $this->db->raw('ROUND(AVG(total_ms)) as avg_ms'),
                $this->db->raw('MAX(total_ms) as max_ms'),
            ]);

        // p50/p95 from real traffic. Computed in PHP over an ordered, bounded
        // sample rather than with a window function, so it works identically on
        // MariaDB and MySQL 5.7 and cannot fail on a version difference.
        $sample = $this->db->table('search_queries')
            ->where('created_at', '>=', $since)
            ->orderBy('total_ms')
            ->limit(20000)
            ->pluck('total_ms')
            ->all();
        $p = fn (float $q) => $sample ? (int) $sample[min(count($sample) - 1, (int) floor(count($sample) * $q))] : 0;

        $popular = $this->db->table('search_queries')
            ->where('created_at', '>=', $since)
            ->whereRaw("normalised <> ''")
            ->groupBy('normalised')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(30)
            ->get([
                'normalised',
                $this->db->raw('COUNT(*) as searches'),
                $this->db->raw('ROUND(100 * SUM(clicked_at IS NOT NULL) / COUNT(*)) as ctr'),
                $this->db->raw('ROUND(AVG(result_count)) as avg_results'),
            ]);

        $queueDepth = (int) $this->db->table('search_index_queue')->count();
        $queueOldest = $this->db->table('search_index_queue')->min('created_at');
        $stuck = (int) $this->db->table('search_index_queue')->where('attempts', '>', 0)->count();

        return new JsonResponse([
            'days' => $days,
            'totals' => [
                'searches' => (int) ($totals->searches ?? 0),
                'searchers' => (int) ($totals->searchers ?? 0),
                'zeroResults' => (int) ($totals->zero ?? 0),
                'zeroRate' => $totals->searches ? round(100 * $totals->zero / $totals->searches, 1) : 0,
                'clickThroughRate' => $totals->searches ? round(100 * $totals->clicked / $totals->searches, 1) : 0,
                'avgMs' => (int) ($totals->avg_ms ?? 0),
                'maxMs' => (int) ($totals->max_ms ?? 0),
                'p50Ms' => $p(0.50),
                'p95Ms' => $p(0.95),
                'p99Ms' => $p(0.99),
            ],
            'gaps' => $gaps,
            'abandoned' => $abandoned,
            'deepClicks' => $deepClicks,
            'popular' => $popular,
            'sync' => [
                'queueDepth' => $queueDepth,
                'oldestPending' => $queueOldest,
                'retrying' => $stuck,
            ],
            'engine' => $this->indexer->stats(),
        ]);
    }
}
