<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Models\SavedSearch;
use Local\Search\Search\Engine;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Saved searches — list, create, delete, and "what is new".
 *
 * One endpoint rather than four routes because the whole resource is four
 * fields; a REST surface here would be more ceremony than substance.
 *
 * The `newCount` on a list response is the feature: it runs each saved query
 * with `id > last_seen_id` and reports how many results have appeared since the
 * user last opened it. That is what turns a saved search into a subscription to
 * a topic no tag covers — "limb lengthening in the Turkish section with more
 * than five reactions" is not a forum, but it is a thing people follow.
 */
class SavedSearchController implements RequestHandlerInterface
{
    private const MAX_PER_USER = 40;

    public function __construct(private Engine $engine)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            return new JsonResponse(['error' => 'not-authenticated'], 401);
        }

        $method = $request->getMethod();

        if ($method === 'DELETE') {
            $id = (int) ($request->getQueryParams()['id'] ?? 0);
            SavedSearch::where('user_id', $actor->id)->where('id', $id)->delete();

            return new JsonResponse(['ok' => true]);
        }

        if ($method === 'POST') {
            $body = (array) $request->getParsedBody();
            $query = trim((string) ($body['query'] ?? ''));
            if ($query === '') {
                return new JsonResponse(['error' => 'empty-query'], 400);
            }
            if (SavedSearch::where('user_id', $actor->id)->count() >= self::MAX_PER_USER) {
                return new JsonResponse(['error' => 'limit-reached', 'limit' => self::MAX_PER_USER], 400);
            }

            $name = trim((string) ($body['name'] ?? '')) ?: mb_substr($query, 0, 60);
            $row = SavedSearch::updateOrCreate(
                ['user_id' => $actor->id, 'name' => mb_substr($name, 0, 120)],
                [
                    'query' => mb_substr($query, 0, 500),
                    'type' => in_array($body['type'] ?? 'all', ['all', 'discussions', 'posts', 'users'], true) ? $body['type'] : 'all',
                    'notify' => (bool) ($body['notify'] ?? false),
                    'last_seen_id' => $this->highestId($query, $actor, (string) ($body['type'] ?? 'all')),
                    'created_at' => date('Y-m-d H:i:s'),
                    'checked_at' => date('Y-m-d H:i:s'),
                ]
            );

            return new JsonResponse(['ok' => true, 'saved' => $this->shape($row, 0)]);
        }

        $rows = SavedSearch::where('user_id', $actor->id)->orderBy('name')->get();
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->shape($row, $this->newCount($row, $actor));
        }

        return new JsonResponse(['saved' => $out]);
    }

    private function shape(SavedSearch $row, int $newCount): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'query' => $row->query,
            'type' => $row->type,
            'notify' => (bool) $row->notify,
            'newCount' => $newCount,
            'lastSeenId' => (int) $row->last_seen_id,
        ];
    }

    /**
     * Results newer than the watermark. Ordered by id descending and capped:
     * "9+" is as useful as an exact 3,412 and costs a bounded query.
     */
    private function newCount(SavedSearch $row, $actor): int
    {
        try {
            $r = $this->engine->search($row->query . ' sort:new', $actor, [
                'type' => $row->type === 'all' ? 'all' : $row->type,
                'limit' => 20, 'facets' => false,
            ]);
            $n = 0;
            foreach ($r['results'] as $hit) {
                if ((int) ($hit['id'] ?? 0) > (int) $row->last_seen_id) {
                    $n++;
                }
            }

            return $n;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function highestId(string $query, $actor, string $type): int
    {
        try {
            $r = $this->engine->search($query . ' sort:new', $actor, [
                'type' => $type === 'all' ? 'all' : $type, 'limit' => 1, 'facets' => false,
            ]);

            return (int) ($r['results'][0]['id'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
