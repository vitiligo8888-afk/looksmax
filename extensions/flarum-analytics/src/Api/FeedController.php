<?php

namespace Local\Analytics\Api;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Analytics\Recommend\Engine;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/analytics/feed — the personalised discussion feed.
 *
 * ── Why this file was missing ───────────────────────────────────────────────
 *
 * extend.php:72 has registered this route since the extension was written and
 * the class never existed, so the endpoint fatalled with class-not-found on
 * every call. That also left Recommend\Engine — a complete, working
 * recommender — unreachable: this was its only intended caller.
 *
 * ── The permission bug this controller has to close ─────────────────────────
 *
 * Engine::feed() is a scoring query. It filters `is_private` and `hidden_at`
 * and NOTHING ELSE — it has no idea which tags the actor is allowed to read.
 * Returning its ids straight to the client would leak the existence, titles and
 * ordering of discussions in restricted tags to anyone who called the route.
 *
 * So the ids are re-read through `Discussion::whereVisibleTo($actor)`, which is
 * the same gate the discussion list itself uses. The recommender proposes; the
 * visibility scope disposes. Any id the actor may not see is dropped rather
 * than replaced, so a restricted feed is short rather than padded with
 * something the scorer never picked.
 *
 * Guests are allowed. `feed()` takes a nullable user id and falls back to a
 * plain hotness ordering when there is no affinity, which is exactly the right
 * behaviour for a logged-out visitor, and the visibility scope still applies.
 */
class FeedController implements RequestHandlerInterface
{
    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT = 100;

    public function __construct(protected Engine $engine)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $query = $request->getQueryParams();

        $limit = min(self::MAX_LIMIT, max(1, (int) ($query['limit'] ?? self::DEFAULT_LIMIT)));

        // Identity comes from the session, never the query string: `session` is
        // what ties a guest's reading history together, and letting a caller
        // pass someone else's would hand them that person's affinity profile.
        $userId = $actor->isGuest() ? null : (int) $actor->id;
        $session = $userId === null ? $this->sessionKey($request) : null;

        $ids = $this->engine->feed($userId, $session, $limit);

        if (! $ids) {
            return new JsonResponse(['data' => [], 'meta' => ['limit' => $limit]]);
        }

        // Re-read under the actor's visibility scope. orderByRaw(FIELD(...))
        // preserves the recommender's ranking, which an `whereIn` would
        // otherwise discard in favour of primary-key order.
        $ordered = implode(',', array_map('intval', $ids));

        $rows = Discussion::whereVisibleTo($actor)
            ->whereIn('id', $ids)
            ->orderByRaw("FIELD(id, {$ordered})")
            ->get(['id', 'title', 'slug', 'comment_count', 'last_posted_at']);

        return new JsonResponse([
            'data' => $rows->map(fn ($d) => [
                'id' => (int) $d->id,
                'title' => $d->title,
                'slug' => $d->slug,
                'commentCount' => (int) $d->comment_count,
                'lastPostedAt' => optional($d->last_posted_at)->toIso8601String(),
            ])->all(),
            'meta' => [
                'limit' => $limit,
                // Deliberately exposed: `scored` above `count(data)` means the
                // visibility scope dropped rows, which is the first thing to
                // check when a feed comes back shorter than requested.
                'scored' => count($ids),
            ],
        ]);
    }

    /**
     * The anonymous reading-history key.
     *
     * This MUST stay byte-identical to CaptureRequest::sessionId() — that
     * listener writes the `discussion.viewed` rows that Engine::affinity()
     * scores against, so a key that differs by one separator yields a feed
     * built on an empty history and silently degrades to plain hotness for
     * every guest, with nothing in any log to say so.
     *
     * Note this is NOT the same derivation IngestController uses (`$ip . date`,
     * no UA, no separators) — a third spelling of "anonymous session key" in
     * the same extension. Worth unifying; not worth doing silently here.
     */
    private function sessionKey(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? '';
        $ua = $request->getHeaderLine('User-Agent');

        return substr(hash('sha256', $ip . '|' . $ua . '|' . date('Y-m-d')), 0, 32);
    }
}
