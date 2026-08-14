<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Search\Semantic;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /api/looksmax/search/recommend`
 *
 * "For you". Works for a logged-out visitor — which on a public forum is most
 * of the traffic — by degrading to what is genuinely moving right now rather
 * than to an empty array or to "newest first" dressed up as a recommendation.
 *
 * `strategy` is always present and is one of:
 *   `personalised` — built from this reader's own posting history
 *   `seeded`       — built from tags or discussions the CALLER supplied
 *   `cold-start`   — trending + best-of, diversified across sections
 *
 * `personalised` is a boolean shortcut for the common UI decision ("do I title
 * this 'For you' or 'Popular right now'?"). Rendering "For you" over a
 * cold-start list is the single fastest way to make a recommendation strip feel
 * fake, so the distinction is in the payload rather than left to be guessed.
 *
 * Query parameters, all optional:
 *   limit=12         1..50
 *   tags=a,b         restrict to these tag slugs
 *   seed=1,2,3       discussion ids to recommend FROM (e.g. the thread being
 *                    read right now) — this is what makes the endpoint useful
 *                    for a logged-out reader on a thread page
 *   exclude=4,5      discussion ids to keep out (e.g. already on screen)
 *   perTag=3         max results from any one section; 0 disables diversity
 *   windowHours=72   trending window used by the cold-start path
 */
class RecommendController implements RequestHandlerInterface
{
    public function __construct(private Semantic $semantic)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();

        $out = $this->semantic->recommend($actor, (int) ($params['limit'] ?? 12), [
            'tags' => $this->csv($params['tags'] ?? ''),
            'seedDiscussions' => array_map('intval', $this->csv($params['seed'] ?? '')),
            'exclude' => array_map('intval', $this->csv($params['exclude'] ?? '')),
            'perTag' => isset($params['perTag']) ? (int) $params['perTag'] : 3,
            'windowHours' => (int) ($params['windowHours'] ?? 72),
        ]);

        $out['available'] = $this->semantic->available();
        $out['actor'] = $actor->isGuest() ? 'guest' : 'user';

        return new JsonResponse($out, 200, [
            // Short, and private: a guest cold-start list is identical for
            // everyone but a personalised one is not, and one Cache-Control
            // header has to be correct for both.
            'Cache-Control' => 'private, max-age=60',
        ]);
    }

    /** @return string[] */
    private function csv($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), fn ($v) => $v !== ''));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }
}
