<?php

namespace Local\Format\Media;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serve proxied media from our own origin.
 *
 *   GET /media/p/{sig}/{hash}/{payload}
 *
 * Cache hit  -> stream from disk.
 * Cache miss -> fetch once (no Referer, neutral UA, optional upstream proxy),
 *               store, then serve. A failure is negatively cached so a dead
 *               image is not re-requested on every page view.
 *
 * The URL is content-addressed (see Signer), so responses are immutable and get
 * a one-year max-age: a browser or CDN in front of us asks exactly once.
 */
class ProxyController implements RequestHandlerInterface
{
    public function __construct(
        private Signer $signer,
        private Store $store,
        private Fetcher $fetcher
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sig = (string) ($request->getAttribute('routeParameters')['sig'] ?? '');
        $payload = (string) ($request->getAttribute('routeParameters')['payload'] ?? '');

        $url = $this->signer->verify($sig, $payload);
        if ($url === null) {
            // 404 rather than 403: an unsigned request should not be able to
            // distinguish "bad signature" from "no such thing".
            return new TextResponse('not found', 404, ['Cache-Control' => 'no-store']);
        }

        $hit = $this->store->get($url);

        if ($hit === null) {
            if ($this->store->isDead($url)) {
                return $this->gone();
            }

            $result = $this->fetcher->fetch($url);
            if (! $result['ok']) {
                $this->store->putDead($url, $result['status']);

                return $this->gone();
            }

            $this->store->put($url, $result['body'], $result['type']);
            $hit = $this->store->get($url) ?? [
                'path' => null,
                'type' => $result['type'],
            ];

            if ($hit['path'] === null) {
                return $this->body($result['body'], $result['type']);
            }
        }

        return $this->file($hit['path'], $hit['type']);
    }

    private function file(string $path, string $type): ResponseInterface
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return $this->gone();
        }

        return (new Response($stream, 200, $this->headers($type)))
            ->withHeader('Content-Length', (string) filesize($path));
    }

    private function body(string $bytes, string $type): ResponseInterface
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        return new Response($stream, 200, $this->headers($type));
    }

    /**
     * A transparent 1x1 GIF, not a 404.
     *
     * A 404 on an <img> paints the browser's broken-image glyph mid-paragraph,
     * which looks like the forum is broken rather than like the source board
     * deleted a file years ago. The CSS placeholder in forum.less handles the
     * visible affordance; this just needs to not be an error. Cached briefly so
     * a dead image is not re-requested on every scroll.
     */
    private function gone(): ResponseInterface
    {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $gif);
        rewind($stream);

        return new Response($stream, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'public, max-age=3600',
            'X-Lmx-Media' => 'unavailable',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function headers(string $type): array
    {
        return [
            'Content-Type' => $type,
            // Content-addressed path => safe to keep forever.
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Referrer-Policy' => 'no-referrer',
            // These bytes came from somewhere else; never let them be
            // interpreted as something active under our own origin.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }
}
