<?php

namespace Local\Analytics\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Analytics\Models\Event;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Client event intake. Everything here is untrusted: the browser can claim any
 * type, any discussion, any dwell. So the type is allowlisted, numbers are
 * clamped, and identity comes from the session — never from the payload.
 */
class IngestController implements RequestHandlerInterface
{
    private const ALLOWED = [
        'discussion.dwell', 'composer.opened', 'composer.submitted', 'composer.abandoned',
        'search.performed', 'tag.filtered', 'link.clicked',
    ];

    private const MAX_PER_REQUEST = 50;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $body = $request->getParsedBody();
        $events = array_slice((array) ($body['events'] ?? []), 0, self::MAX_PER_REQUEST);

        foreach ($events as $e) {
            $type = (string) ($e['type'] ?? '');
            if (!in_array($type, self::ALLOWED, true)) {
                continue;
            }

            $props = (array) ($e['props'] ?? []);
            // clamp: a client claiming a 9-hour dwell must not skew any average
            if (isset($props['dwell_ms'])) {
                $props['dwell_ms'] = min(max((int) $props['dwell_ms'], 0), 3600_000);
            }
            if (isset($props['read_pct'])) {
                $props['read_pct'] = min(max((int) $props['read_pct'], 0), 100);
            }

            Event::record($type, [
                'user_id' => $actor->isGuest() ? null : $actor->id,
                'discussion_id' => isset($props['discussion_id']) ? (int) $props['discussion_id'] : null,
                'session' => substr(hash('sha256', $request->getServerParams()['REMOTE_ADDR'] . date('Y-m-d')), 0, 32),
                'props' => json_encode($props),
            ]);
        }

        return new JsonResponse(['accepted' => count($events)]);
    }
}
