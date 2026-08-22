<?php

namespace Local\Index\Api;

use Flarum\Http\RequestUtil;
use Flarum\User\Guest;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Index\RenderIndex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/lmx-index/fragment — the front page, for a client-side navigation.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * RenderIndex used to inject its ~66KB <template> into the head of every page
 * on the forum, including the millions of discussion views that never read it,
 * and paid several DB queries per page to build it. It now injects the template
 * only on the paths that paint it immediately.
 *
 * That leaves one case: Flarum is an SPA, so clicking the logo from a
 * discussion reaches `/` with no document request and therefore no template.
 * This route serves exactly the same HTML for that case, from the same builder,
 * so the two paths cannot drift.
 *
 * ── Per-actor, therefore not cacheable ──────────────────────────────────────
 *
 * The fragment carries the member greeting and unread counts, so it is
 * per-user by construction. It is marked no-store rather than being left to a
 * default: a shared cache holding this would serve one member's greeting and
 * unread count to another.
 */
class FragmentController implements RequestHandlerInterface
{
    public function __construct(protected RenderIndex $index)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor = $actor instanceof User ? $actor : new Guest();

        try {
            $html = $this->index->fragment($actor);
        } catch (\Throwable $e) {
            // Same posture as RenderIndex::__invoke: a broken block degrades to
            // no index, never to an error the reader has to look at.
            $html = '';
        }

        return new JsonResponse(
            ['html' => $html],
            200,
            ['Cache-Control' => 'private, no-store']
        );
    }
}
