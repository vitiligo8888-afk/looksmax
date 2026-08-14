<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Search\Semantic;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /api/looksmax/search/trending?windowHours=48&limit=10`
 *
 * Threads that are ACCELERATING, not threads that are big. See
 * `Semantic::trending()` for the score and for why it is computed from a
 * Meilisearch facet distribution rather than a `GROUP BY` against the posts
 * table the importer is writing 540 rows/s into.
 *
 * The response states its own ceiling (`facetCeiling`, `consideredThreads`):
 * the aggregate is taken over the 200 most active threads in the window, which
 * is right for a "what's hot" strip and wrong for analytics. A caller that
 * needs the true distribution should be told that here rather than discover it
 * from a number that looks complete and is not.
 *
 * Safe to cache publicly for a short time: unlike recommendations, this list
 * is not built from the reader's own history. It is still permission-filtered
 * per actor, so the cache stays `private` — a guest and a moderator do not see
 * the same threads.
 */
class TrendingController implements RequestHandlerInterface
{
    public function __construct(private Semantic $semantic)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();

        $out = $this->semantic->trending(
            $actor,
            (int) ($params['limit'] ?? 10),
            (int) ($params['windowHours'] ?? 48)
        );

        return new JsonResponse($out, 200, ['Cache-Control' => 'private, max-age=120']);
    }
}
