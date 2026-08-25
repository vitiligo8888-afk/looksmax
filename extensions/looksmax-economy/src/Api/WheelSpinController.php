<?php

namespace Local\Economy\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Economy\Wheel;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Lado escritura. POST /api/economy/wheel/spin.
 *
 * No acepta ningún parámetro, a propósito: no hay nada que el cliente pueda
 * decir sobre su propio premio. `Wheel::spin()` sortea, cobra el candado del
 * día y devuelve el índice del segmento; la respuesta es lo único que la
 * animación puede usar para saber dónde parar.
 */
class WheelSpinController implements RequestHandlerInterface
{
    public function __construct(protected Wheel $wheel, protected TranslatorInterface $translator)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            return new JsonResponse(['error' => $this->t('guest')], 401);
        }

        try {
            $result = $this->wheel->spin((int) $actor->id);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $this->t('failed')], 500);
        }

        if ($result === null) {
            return new JsonResponse(['error' => $this->t('already')], 403);
        }

        return new JsonResponse([
            'ok' => true,
            'index' => $result['index'],
            'points' => $result['points'],
            'state' => $this->wheel->state((int) $actor->id),
        ]);
    }

    private function t(string $key): string
    {
        return $this->translator->trans('local-looksmax-economy.forum.wheel.error.' . $key);
    }
}
