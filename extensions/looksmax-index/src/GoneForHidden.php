<?php

namespace Local\Index;

use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Devuelve 410 Gone, no 404, cuando el hilo pedido existe pero está oculto.
 *
 * ── Por qué ────────────────────────────────────────────────────────────────
 *
 * La purga de idiomas ocultó 62.381 hilos, y los rastreadores siguen teniendo
 * esas URLs indexadas. Medido en el access.log: 2.568 de las últimas 20.000
 * peticiones son 404 de `/d/<id>` — el 13% del tráfico.
 *
 * 404 significa "no lo encuentro ahora", y un rastreador responde a eso
 * volviendo semanas después a comprobar. 410 significa "esto se fue y no
 * vuelve", y es la señal que acorta la desindexación. La diferencia no es
 * cosmética: mientras esas URLs sigan en el índice, el sitio se anuncia con
 * miles de páginas muertas.
 *
 * ── Coste ──────────────────────────────────────────────────────────────────
 *
 * Una consulta, y SOLO cuando ya se iba a devolver un 404 sobre una ruta con
 * forma `/d/<numero>`. Una petición normal no toca la base de datos por esto,
 * y una petición a una URL inventada tampoco: el id tiene que existir.
 *
 * No distingue "oculto" de "borrado de verdad" porque no puede — una fila
 * borrada no deja rastro. Ese caso sigue devolviendo 404, que es correcto.
 */
class GoneForHidden implements MiddlewareInterface
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->getStatusCode() !== 404) {
            return $response;
        }

        $path = $request->getUri()->getPath();

        if (! preg_match('#^/d/(\d+)#', $path, $m)) {
            return $response;
        }

        try {
            $hidden = $this->db->table('discussions')
                ->where('id', (int) $m[1])
                ->whereNotNull('hidden_at')
                ->exists();
        } catch (\Throwable $e) {
            // Un fallo aquí no puede cambiar lo que ya se iba a responder.
            return $response;
        }

        return $hidden ? $response->withStatus(410) : $response;
    }
}
