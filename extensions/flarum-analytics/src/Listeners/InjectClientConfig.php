<?php

namespace Local\Analytics\Listeners;

use Flarum\Frontend\Document;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The PostHog browser snippet is intentionally NOT injected here.
 *
 * Client events go to our own endpoint and are forwarded server-side, so the
 * forum never ships a third-party script, nothing breaks when the vendor is
 * blocked, and there is exactly one definition of an event.
 */
class InjectClientConfig
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $document->payload['analyticsIngest'] = '/api/analytics/events';
    }
}
