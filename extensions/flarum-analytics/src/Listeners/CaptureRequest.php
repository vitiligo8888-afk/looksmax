<?php

namespace Local\Analytics\Listeners;

use Local\Analytics\Models\Event;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * View capture.
 *
 * Views are a GET, and Flarum dispatches no domain event for them, so this is
 * middleware. Server-side by design: an ad blocker cannot suppress it, which is
 * the whole reason the numbers here will disagree with (and beat) a pure
 * client-side pixel.
 *
 * Two things that make view data useless if you skip them:
 *  - bot traffic. The source board reports 668 average views per thread; a
 *    naive counter mostly measures crawlers, and we are literally one of them.
 *  - repeat views from the same reader inside one session, which inflate
 *    engagement and poison any ranking built on top.
 */
class CaptureRequest implements MiddlewareInterface
{
    /** Throttle window for a repeat view of the same discussion, seconds. */
    private const DEDUPE_WINDOW = 900;

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|headless|python-requests|curl|wget|scrapy|facebookexternalhit/i';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        try {
            $this->record($request, $response);
        } catch (\Throwable $e) {
            // Instrumentation must never break the forum. Swallow, don't rethrow.
        }

        return $response;
    }

    private function record(ServerRequestInterface $request, ResponseInterface $response): void
    {
        if ($request->getMethod() !== 'GET' || $response->getStatusCode() !== 200) {
            return;
        }

        $path = $request->getUri()->getPath();
        if (!preg_match('#^/d/(\d+)#', $path, $m)) {
            return;
        }
        $discussionId = (int) $m[1];

        $ua = $request->getHeaderLine('User-Agent');
        if ($ua === '' || preg_match(self::BOT_PATTERN, $ua)) {
            return;
        }

        $actor = $request->getAttribute('actor');
        $userId = ($actor && $actor->id) ? (int) $actor->id : null;
        $session = $this->sessionId($request, $userId);

        // dedupe: same reader + same discussion inside the window counts once
        $recent = Event::query()
            ->where('type', 'discussion.viewed')
            ->where('discussion_id', $discussionId)
            ->where($userId ? 'user_id' : 'session', $userId ?: $session)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - self::DEDUPE_WINDOW))
            ->exists();

        if ($recent) {
            return;
        }

        Event::create([
            'type' => 'discussion.viewed',
            'user_id' => $userId,
            'discussion_id' => $discussionId,
            'session' => $session,
            'path' => $path,
            'props' => json_encode([
                'referrer' => $request->getHeaderLine('Referer') ?: null,
                'authed' => $userId !== null,
            ]),
        ]);
    }

    /**
     * Stable per-visitor id that is not personally identifying: a salted hash of
     * IP + UA, rotated daily so it cannot be used to track across days.
     */
    private function sessionId(ServerRequestInterface $request, ?int $userId): string
    {
        if ($userId) {
            return 'u' . $userId;
        }
        $server = $request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? '';
        $ua = $request->getHeaderLine('User-Agent');

        return substr(hash('sha256', $ip . '|' . $ua . '|' . date('Y-m-d')), 0, 32);
    }
}
