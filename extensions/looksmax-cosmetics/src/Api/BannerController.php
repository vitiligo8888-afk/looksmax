<?php

namespace Local\Cosmetics\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Cosmetics\BannerUploads;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Read side of custom banner upload: the state a member's own wardrobe needs
 * (GET /api/cosmetics/banner/me) and the moderation queue
 * (GET /api/cosmetics/banner/queue), which is where "what happens to an
 * abusive image" actually gets a human in front of it — see
 * BannerUploads::queue() for the sort order and BannerActionController for
 * the reject action this feeds.
 */
class BannerController implements RequestHandlerInterface
{
    public function __construct(protected BannerUploads $uploads)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $what = (string) ($request->getAttribute('routeParameters')['what'] ?? '');

        return match ($what) {
            'me' => $this->me($request),
            'queue' => $this->queue($request),
            default => new JsonResponse(['error' => 'unknown_view'], 404),
        };
    }

    private function me(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            return new JsonResponse(['eligible' => false, 'banned' => false, 'upload' => null]);
        }

        $banned = $this->uploads->banned((int) $actor->id);
        $eligible = $this->uploads->eligible(
            $actor->tier_slug,
            $actor->tier_expires_at ? (string) $actor->tier_expires_at : null
        ) && !$banned;

        $row = $this->uploads->row((int) $actor->id);

        return new JsonResponse([
            'eligible' => $eligible,
            // Distinct from `!eligible` on purpose: a lapsed VIP with one old
            // rejection is `!eligible` (tier gate) but NOT banned, and the
            // wardrobe needs to say "reach VIP" to that account, not "removed
            // for repeated rejections" — see js/dist/forum.js
            // customBannerBlock(), which reads this instead of inferring the
            // reason from upload.status.
            'banned' => $banned,
            'upload' => $row ? [
                'status' => $row->status,
                'url' => $row->status === 'active' ? $this->uploads->url((int) $actor->id) : null,
                'uploadedAt' => (string) $row->uploaded_at,
                'rejectCount' => (int) $row->reject_count,
                'moderationReason' => $row->moderation_reason,
            ] : null,
        ]);
    }

    private function queue(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (!$actor->isAdmin() && !$actor->hasPermission('cosmetics.moderate')) {
            return new JsonResponse(['error' => 'not_allowed'], 403);
        }

        return new JsonResponse(['queue' => $this->uploads->queue()]);
    }
}
