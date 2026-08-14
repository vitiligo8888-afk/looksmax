<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Search\Semantic;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /api/looksmax/search/related?discussion=123`
 *
 * Related topics for one thread. Returns three separate lists rather than one
 * merged one, because they mean different things to a reader and a UI should
 * be free to render them differently or not at all:
 *
 *   `related`    — read this next. Semantic neighbours, quality-re-ranked.
 *   `duplicates` — this has been asked before. Near-identical threads, which
 *                  belong above the fold or in a dismissible notice, never
 *                  mixed into "read next".
 *   `alsoRead`   — behavioural. People who posted here also posted there.
 *                  Empty (not faked) when the thread has too few participants.
 *
 * `strategy` says which mechanism produced the answer, so a caller can tell a
 * genuinely semantic result from the tag-based fallback that runs when the
 * embedder is off or the thread has no vector yet.
 *
 * Cached `private` because the result is permission-filtered per actor: a
 * shared cache here would serve one reader's visible set to another, which is
 * exactly the leak a recommendation strip is prone to.
 */
class RelatedController implements RequestHandlerInterface
{
    public function __construct(private Semantic $semantic)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();

        $discussion = (int) ($params['discussion'] ?? $params['id'] ?? 0);
        if ($discussion <= 0) {
            return new JsonResponse([
                'error' => 'discussion id required',
                'usage' => 'GET /api/looksmax/search/related?discussion=<id>&limit=8&alsoRead=1',
            ], 422);
        }

        $out = $this->semantic->related($discussion, $actor, (int) ($params['limit'] ?? 8), [
            'alsoRead' => ($params['alsoRead'] ?? '1') !== '0',
        ]);
        $out['available'] = $this->semantic->available();

        return new JsonResponse($out, 200, ['Cache-Control' => 'private, max-age=120']);
    }
}
