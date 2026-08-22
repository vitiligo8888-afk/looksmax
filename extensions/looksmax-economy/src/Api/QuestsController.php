<?php

namespace Local\Economy\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Economy\Quests;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Read side of the quest board: GET /api/economy/quests.
 *
 * Actor-only, same rule as SummaryController — a quest checklist is a "your
 * own dashboard" view, not a profile surface, so there is no `?id=` and no
 * visibility check that could be gotten wrong.
 */
class QuestsController implements RequestHandlerInterface
{
    public function __construct(protected Quests $quests)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            return new JsonResponse(['data' => ['enabled' => false, 'quests' => []]]);
        }

        return new JsonResponse([
            'data' => [
                'enabled' => $this->quests->enabled(),
                'quests' => $this->quests->state((int) $actor->id),
            ],
        ]);
    }
}
