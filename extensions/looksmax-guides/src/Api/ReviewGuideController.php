<?php

namespace Local\Guides\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Mark a guide as reviewed, resetting its staleness clock.
 *
 * Reviewing is deliberately a separate act from editing. On the source board
 * nothing ever expires, so a 2019 protocol recommending a withdrawn compound
 * outranks a checked one forever; if editing silently counted as reviewing, a
 * typo fix would launder a stale guide back to fresh and the whole lifecycle
 * would be theatre.
 *
 * Route parameters arrive merged into the query params by
 * RouteHandlerFactory::toController — that is the 1.x contract, verified in
 * framework/core/src/Http/RouteHandlerFactory.php.
 */
class ReviewGuideController implements RequestHandlerInterface
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('guides.review');

        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        $meta = $this->db->table('guide_meta')->where('discussion_id', $id)->first();

        if (!$meta) {
            return new JsonResponse(['errors' => [['status' => '404', 'code' => 'not_found']]], 404);
        }

        $interval = max(1, (int) $meta->review_interval_d);
        $now = time();

        $this->db->table('guide_meta')->where('discussion_id', $id)->update([
            'reviewed_at' => date('Y-m-d H:i:s', $now),
            'reviewed_by' => $actor->id,
            'review_due_at' => date('Y-m-d H:i:s', $now + $interval * 86400),
            'status' => 'published',
            'updated_at' => date('Y-m-d H:i:s', $now),
        ]);

        return new JsonResponse([
            'data' => [
                'id' => $id,
                'reviewedAt' => date('c', $now),
                'reviewDueAt' => date('c', $now + $interval * 86400),
                'reviewedBy' => (int) $actor->id,
                'intervalDays' => $interval,
            ],
        ]);
    }
}
