<?php

namespace Local\Economy\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Economy\Quests;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Write side: claim one quest's reward. POST /api/economy/quests/claim.
 *
 * One action, because there is one thing a member can do to a quest —
 * `Quests::claim()` recomputes eligibility from the live ledger before paying,
 * so this endpoint is a thin transport and every real rule (met? already
 * claimed? enabled at all?) is enforced server-side regardless of what the
 * widget's cached state believes.
 */
class QuestsActionController implements RequestHandlerInterface
{
    public function __construct(protected Quests $quests, protected TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            return new JsonResponse(['error' => $this->t('error.guest')], 401);
        }

        $body = (array) $request->getParsedBody();
        $key = (string) ($body['key'] ?? '');

        $reward = 0;
        try {
            $reward = $this->quests->claim((int) $actor->id, $key);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $this->t('error.failed')], 500);
        }

        if ($reward <= 0) {
            return new JsonResponse(['error' => $this->t('error.not_claimable')], 403);
        }

        return new JsonResponse([
            'ok' => true,
            'reward' => $reward,
            'quests' => $this->quests->state((int) $actor->id),
        ]);
    }

    private function t(string $key): string
    {
        return $this->translator->trans('local-looksmax-economy.forum.quests.' . $key);
    }
}
