<?php

namespace Local\Economy\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Economy\Wheel;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lado lectura de la ruleta. GET /api/economy/wheel.
 *
 * Devuelve los segmentos aunque seas invitado, para que la ruleta se pueda
 * DIBUJAR sin sesión y el visitante vea qué se puede ganar; lo que no devuelve
 * nunca es permiso para girar (`canSpin` solo es true con sesión y sin giro
 * hoy). Enseñar el premio es marketing; concederlo es del lado escritura.
 */
class WheelController implements RequestHandlerInterface
{
    public function __construct(protected Wheel $wheel)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id = $actor->isGuest() ? 0 : (int) $actor->id;

        try {
            $data = $this->wheel->state($id);
        } catch (\Throwable $e) {
            return new JsonResponse(['data' => ['enabled' => false, 'prizes' => [], 'canSpin' => false]]);
        }

        $data['guest'] = $id === 0;

        return new JsonResponse(['data' => $data]);
    }
}
