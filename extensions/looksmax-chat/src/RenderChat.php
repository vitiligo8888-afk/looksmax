<?php

namespace Local\Chat;

use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Puts the shoutbox on the page.
 *
 * Two elements: the state the box starts with, serialised from the same
 * `Payload` the API returns, and the client itself. Bootstrapping the state
 * server-side means the card paints with its messages in the first frame
 * instead of showing an empty log for a poll interval, and it gives the client
 * a cursor that is already correct — the previous version started at cursor 0,
 * fired two overlapping requests for the same range, and rendered every message
 * twice.
 *
 * The client is read from js/chat.js rather than kept in a heredoc so it is a
 * real file that an editor and a linter can see.
 */
class RenderChat
{
    public function __construct(protected Payload $payload)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        // The box only mounts where looksmax-index builds its sidebar, so the
        // three queries behind the bootstrap are wasted on every discussion,
        // profile and settings page — and those are most page views. Arriving
        // at the index through the SPA needs no bootstrap: the client already
        // has its log, and a cold one fills from its first poll.
        $path = $request->getUri()->getPath();
        $mounts = $path === '/' || $path === '' || rtrim($path, '/') === '/tags';

        try {
            $state = $mounts ? $this->payload->state(RequestUtil::getActor($request), 0, false) : null;
        } catch (\Throwable $e) {
            $state = null; // never let the shoutbox stop the page from rendering
        }

        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $document->head[] = '<script data-lmx-chat-boot>window.__lmxChatBoot=' . ($json ?: 'null') . ';</script>';
        $document->head[] = '<script data-lmx-chat>' . $this->client() . '</script>';
    }

    private function client(): string
    {
        $js = @file_get_contents(__DIR__ . '/../js/chat.js');

        if ($js === false) {
            return '/* looksmax-chat: client missing */';
        }

        // A literal </script> inside the source would close this element early.
        // There is none, but the client is edited often and the failure mode is
        // a blank forum, so it is neutralised rather than trusted.
        return str_replace('</script', '<\/script', $js);
    }
}
