<?php

namespace Local\Economy\Api;

use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sirve la librería de la ruleta. GET /api/economy/wheel/lib.js
 *
 * ── Por qué una ruta y no el bundle ─────────────────────────────────────────
 *
 * Flarum CONCATENA el JS de las extensiones en un solo fichero. Un throw en
 * cualquier pieza mata todo lo que cargue después, y un export malformado
 * aborta bootExtensions y deja el foro en blanco — ya pasó en esta instalación
 * (ver InjectCosmetics.php). 28 KB de código de un tercero no tienen por qué
 * correr ese riesgo, ni cargarse en las páginas donde nadie va a girar nada.
 *
 * Desde aquí se pide una sola vez, cuando el usuario abre la ruleta, y queda
 * cacheada un año. El contenido nunca cambia sin que cambie el fichero, así
 * que `immutable` es honesto; si algún día se actualiza la librería, cambia el
 * ETag y el navegador la vuelve a pedir.
 */
class WheelLibController implements RequestHandlerInterface
{
    private const PATH = __DIR__ . '/../../js/vendor/spin-wheel-iife.js';

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = realpath(self::PATH);

        if ($path === false || ! is_readable($path)) {
            // Sin librería no hay ruleta, pero tampoco hay 500: se devuelve JS
            // válido que no define nada, y el cliente ya trata "no cargó" como
            // "no se puede girar ahora".
            return new TextResponse('/* spin-wheel no disponible */', 200, [
                'Content-Type' => 'application/javascript; charset=utf-8',
            ]);
        }

        $body = (string) file_get_contents($path);
        $etag = '"' . substr(sha1($body), 0, 16) . '"';

        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
            return new TextResponse('', 304, ['ETag' => $etag]);
        }

        return new TextResponse($body, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $etag,
        ]);
    }
}
