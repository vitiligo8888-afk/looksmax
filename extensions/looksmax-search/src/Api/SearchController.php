<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Meili\MeiliException;
use Local\Search\Models\SearchQuery;
use Local\Search\Search\Engine;
use Local\Search\Search\Fallback;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * The search endpoint.
 *
 * Not JSON:API. JSON:API's document shape cannot express what a results page
 * needs — facet distributions, per-hit highlight fragments, an estimated total,
 * engine timing — without inventing a meta vocabulary that no client would
 * recognise anyway. `app('flarum.forum')` is not a binding, so this implements
 * `RequestHandlerInterface` and returns a `JsonResponse` directly, which is the
 * documented shape for a non-JSON:API endpoint in Flarum 1.x.
 *
 * Behaviour when the engine is unavailable is deliberate and visible: the
 * request degrades to a database search and says so in `degraded`, rather than
 * returning an error page or, worse, an empty result set that looks like "no
 * matches". A reader must never be told a topic does not exist because a
 * container is restarting.
 */
class SearchController implements RequestHandlerInterface
{
    public function __construct(
        private Engine $engine,
        private Fallback $fallback,
        private LoggerInterface $log
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();

        $q = trim((string) ($params['q'] ?? ''));
        $type = (string) ($params['type'] ?? 'all');
        $limit = (int) ($params['limit'] ?? 20);
        $offset = (int) ($params['offset'] ?? 0);
        $source = (string) ($params['source'] ?? 'page');
        $wantRecovery = ($params['recover'] ?? '1') !== '0';

        if ($q === '' && empty($params['filters'])) {
            return new JsonResponse([
                'query' => '', 'results' => [], 'facets' => [],
                'estimatedTotalHits' => 0, 'empty' => true,
            ]);
        }

        $degraded = null;
        try {
            $result = $this->engine->search($q, $actor, [
                'type' => in_array($type, ['all', 'discussions', 'posts', 'users', 'tags'], true) ? $type : 'all',
                'limit' => $limit,
                'offset' => $offset,
                'facets' => true,
                // `semanticRatio` pins the keyword/vector blend for this one
                // query: 0 is byte-identical to the keyword-only engine, 1 is
                // pure vector search. Omitted means SemanticPolicy decides per
                // query, which is what production traffic should do — the
                // parameter exists so the UI can offer a control and so a
                // relevance comparison can hold one variable still.
                'semanticRatio' => isset($params['semanticRatio']) && $params['semanticRatio'] !== ''
                    ? (float) $params['semanticRatio']
                    : null,
            ]);
        } catch (MeiliException $e) {
            $this->log->error('search: falling back to database', $e->context() + ['q' => $q]);
            $result = $this->fallback->search($q, $actor, $limit, $offset);
            $degraded = 'engine-unavailable';
        }

        // A search that found nothing is the most useful thing a reader ever
        // tells us, so it is never a dead end and never unrecorded.
        if ($wantRecovery && $offset === 0 && count($result['results'] ?? []) === 0 && $degraded === null) {
            try {
                $result['recovery'] = $this->engine->recover($q, $actor);
            } catch (\Throwable $e) {
                $this->log->warning('search: recovery failed', ['error' => $e->getMessage()]);
            }
        }

        $result['degraded'] = $degraded;
        $result['logId'] = $this->record($request, $actor, $q, $result, $source, $offset);

        return new JsonResponse($result);
    }

    /**
     * One row per search. Returns its id so the client can attribute a click to
     * the search that produced it — which is the only way to distinguish a
     * search that worked from one that returned twenty results nobody wanted.
     */
    private function record(ServerRequestInterface $request, $actor, string $q, array $result, string $source, int $offset): ?int
    {
        if ($q === '') {
            return null;
        }
        try {
            $row = SearchQuery::create([
                'query' => mb_substr($q, 0, 500),
                'normalised' => SearchQuery::normalise($q),
                'user_id' => $actor->isGuest() ? null : $actor->id,
                'session' => substr(hash('sha256', ($request->getServerParams()['REMOTE_ADDR'] ?? '') . date('Y-m-d')), 0, 32),
                'type' => $result['type'] ?? 'all',
                'result_count' => (int) ($result['estimatedTotalHits'] ?? 0),
                'engine_ms' => (int) round((float) ($result['engineMs'] ?? 0)),
                'total_ms' => (int) round((float) ($result['totalMs'] ?? 0)),
                'offset' => min(65535, $offset),
                'filters' => json_encode(array_filter([
                    'tags' => $result['parsed']['tags'] ?? [],
                    'authors' => $result['parsed']['authors'] ?? [],
                    'prefixes' => $result['parsed']['prefixes'] ?? [],
                    'flags' => $result['parsed']['flags'] ?? [],
                    'sort' => $result['parsed']['sort'] ?? null,
                ])),
                'source' => in_array($source, ['page', 'suggest', 'palette'], true) ? $source : 'page',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Mirror into the forum's event stream so search sits in the same
            // funnel as views and posts rather than in a private silo. Guarded
            // because this extension does not depend on that one.
            if (class_exists(\Local\Analytics\Models\Event::class)) {
                \Local\Analytics\Models\Event::record(
                    ($result['estimatedTotalHits'] ?? 0) > 0 ? 'search.performed' : 'search.zero_results',
                    [
                        'user_id' => $actor->isGuest() ? null : $actor->id,
                        'props' => json_encode([
                            'q' => mb_substr($q, 0, 200),
                            'results' => $result['estimatedTotalHits'] ?? 0,
                            'ms' => $result['totalMs'] ?? null,
                            'source' => $source,
                        ]),
                    ]
                );
            }

            return $row->id;
        } catch (\Throwable $e) {
            $this->log->warning('search: could not record query', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
