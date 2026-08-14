<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Models\SearchQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Attribute a click to the search that produced it.
 *
 * This is the half of search quality that result counts cannot see. A query
 * that returns 400 results and gets no click is a failure, and it is invisible
 * to every metric except this one. Together with `result_count` it separates
 * three different problems that all look like "search is bad":
 *
 *   result_count = 0                  -> we do not have the content
 *   result_count > 0, no click        -> we have it and did not rank it
 *   result_count > 0, click at pos 9  -> we have it and ranked it badly
 *
 * The position is what makes the third case measurable, so it is required.
 */
class ClickController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $body = (array) $request->getParsedBody();

        $logId = (int) ($body['logId'] ?? 0);
        if (!$logId) {
            return new JsonResponse(['ok' => false], 400);
        }

        try {
            $row = SearchQuery::find($logId);
            // Only the searcher may attribute a click to their own search, and
            // only once — otherwise the click-through rate is writable by
            // anyone who can guess an integer.
            if (!$row || $row->clicked_at !== null) {
                return new JsonResponse(['ok' => false]);
            }
            $ownSession = substr(hash('sha256', ($request->getServerParams()['REMOTE_ADDR'] ?? '') . date('Y-m-d')), 0, 32);
            $isOwner = $actor->isGuest()
                ? $row->session === $ownSession
                : (int) $row->user_id === (int) $actor->id;
            if (!$isOwner) {
                return new JsonResponse(['ok' => false]);
            }

            $row->clicked_result_id = (int) ($body['resultId'] ?? 0) ?: null;
            $row->clicked_type = mb_substr((string) ($body['resultType'] ?? ''), 0, 16) ?: null;
            $row->clicked_position = min(65535, max(0, (int) ($body['position'] ?? 0)));
            $row->clicked_at = date('Y-m-d H:i:s');
            $row->save();
        } catch (\Throwable) {
            return new JsonResponse(['ok' => false]);
        }

        return new JsonResponse(['ok' => true]);
    }
}
