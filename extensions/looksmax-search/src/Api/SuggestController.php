<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Meili\MeiliException;
use Local\Search\Search\Engine;
use Local\Search\Search\QueryParser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Autocomplete.
 *
 * Held to a budget rather than a feature list: everything here exists because
 * it fits inside the ~120 ms a keystroke can spend without the dropdown feeling
 * laggy, and anything that does not fit lives on the results page instead.
 *
 * It is deliberately NOT logged as a search. Recording every keystroke prefix
 * would bury the actual demand signal — `j`, `ja`, `jaw`, `jawl` are not four
 * searches for four things, and a zero-result report full of one-letter
 * prefixes is a zero-result report nobody reads. Only submitted searches and
 * palette navigations are recorded.
 */
class SuggestController implements RequestHandlerInterface
{
    public function __construct(private Engine $engine)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $limit = max(1, min(10, (int) ($params['limit'] ?? 5)));

        if (mb_strlen($q) < 2) {
            return new JsonResponse(['query' => $q, 'groups' => [], 'grammar' => QueryParser::grammar()]);
        }

        try {
            $out = $this->engine->suggest($q, $actor, $limit);
        } catch (MeiliException $e) {
            // A failing dropdown must not throw a red banner over the page the
            // reader is on. It returns nothing and says why, and the results
            // page (which has a database fallback) still works.
            return new JsonResponse([
                'query' => $q, 'groups' => [], 'degraded' => 'engine-unavailable',
            ]);
        }

        $out['grammar'] = QueryParser::grammar();

        return new JsonResponse($out, 200, [
            // Private: results are permission-filtered per actor, so a shared
            // cache would serve one reader's visible set to another.
            'Cache-Control' => 'private, max-age=10',
        ]);
    }
}
