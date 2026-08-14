<?php

namespace Local\Cosmetics\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Cosmetics\Loadout;
use Local\Cosmetics\Ownership;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Write side: equip.
 *
 * One action, because there is one thing a user can do to a cosmetic they own.
 * Buying happens in the store, earning happens by posting, and neither is this
 * extension's business.
 */
class CosmeticsActionController implements RequestHandlerInterface
{
    public function __construct(
        protected Loadout $loadout,
        protected Ownership $ownership,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            return new JsonResponse(['error' => $this->t('error.guest')], 401);
        }

        $action = (string) ($request->getAttribute('routeParameters')['action'] ?? '');
        $body = (array) $request->getParsedBody();

        if ($action !== 'equip') {
            return new JsonResponse(['error' => $this->t('error.unknown_action')], 404);
        }

        $kind = (string) ($body['kind'] ?? '');
        $item = $body['item'] ?? null;
        $item = is_string($item) ? $item : null;

        try {
            $error = $this->loadout->equip($actor, $kind, $item);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $this->t('error.failed'), 'detail' => $e->getMessage()], 500);
        }

        if ($error !== null) {
            return new JsonResponse(['error' => $this->t($error)], 403);
        }

        $tier = $actor->tier_slug;
        $expires = $actor->tier_expires_at ? (string) $actor->tier_expires_at : null;

        // Answer with the state the client should now be in, rather than `ok`.
        // The equip screen redraws from this, so a successful POST and a reload
        // cannot show different things — which is exactly the bug an `ok: true`
        // response hides.
        return new JsonResponse([
            'ok' => true,
            'equipped' => $this->ownership->loadout((int) $actor->id),
            'live' => $this->ownership->live((int) $actor->id, $tier, $expires),
            // no legacy fallback here on purpose: equip() has just written a
            // row, so the row is now the answer and the column is a mirror.
        ]);
    }

    private function t(string $key): string
    {
        return $this->translator->trans('local-looksmax-cosmetics.forum.' . $key);
    }
}
