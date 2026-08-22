<?php

namespace Local\Cosmetics\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Cosmetics\BannerUploads;
use Local\Cosmetics\Ownership;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Write side of custom banner upload: upload, remove, report (any member),
 * moderate (admin/mod only). See BannerUploads.php for the validation and
 * moderation pipeline this is a thin transport over — every real rule lives
 * there, this controller only extracts the request and translates the
 * refusal key.
 */
class BannerActionController implements RequestHandlerInterface
{
    public function __construct(
        protected BannerUploads $uploads,
        protected ConnectionInterface $db,
        protected TranslatorInterface $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            return new JsonResponse(['error' => $this->t('error.guest')], 401);
        }

        $action = (string) ($request->getAttribute('routeParameters')['action'] ?? '');

        try {
            return match ($action) {
                'upload' => $this->upload($actor, $request),
                'remove' => $this->remove($actor),
                'report' => $this->report($actor, $request),
                'moderate' => $this->moderate($actor, $request),
                default => new JsonResponse(['error' => $this->t('error.unknown_action')], 404),
            };
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $this->t('error.failed')], 500);
        }
    }

    private function upload($actor, ServerRequestInterface $request): ResponseInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['banner'] ?? null;

        if (!($file instanceof UploadedFileInterface) || $file->getError() !== UPLOAD_ERR_OK) {
            return new JsonResponse(['error' => $this->t('error.banner_not_an_image')], 422);
        }
        if ($file->getSize() !== null && $file->getSize() > BannerUploads::MAX_INPUT_BYTES) {
            return new JsonResponse(['error' => $this->t('error.banner_too_large')], 422);
        }

        $bytes = (string) $file->getStream();

        $result = $this->uploads->store(
            (int) $actor->id,
            $bytes,
            $actor->tier_slug,
            $actor->tier_expires_at ? (string) $actor->tier_expires_at : null
        );

        if (is_string($result)) {
            return new JsonResponse(['error' => $this->t($result)], 422);
        }

        // Uploading puts it on — nobody uploads a banner to leave it in a
        // drawer, same reasoning the store's CosmeticGrant already applies to
        // a purchased frame. Writes cosmetic_loadout directly rather than
        // through Loadout::equip(), because that path validates against
        // Definitions (catalogue rows) and 'custom' is deliberately not one —
        // see Loadout.php's own special-case for why the equip screen still
        // reaches this same state through the ordinary equip action too.
        $this->equipCustom((int) $actor->id);

        return new JsonResponse([
            'ok' => true,
            'url' => $result['url'],
        ]);
    }

    private function remove($actor): ResponseInterface
    {
        $this->uploads->remove((int) $actor->id);
        Ownership::flush();

        return new JsonResponse(['ok' => true]);
    }

    private function report($actor, ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $targetId = (int) ($body['userId'] ?? 0);
        $reason = isset($body['reason']) ? (string) $body['reason'] : null;

        if ($targetId <= 0) {
            return new JsonResponse(['error' => $this->t('error.banner_no_target')], 404);
        }

        $ok = $this->uploads->report($targetId, (int) $actor->id, $reason);

        return new JsonResponse(['ok' => $ok]);
    }

    private function moderate($actor, ServerRequestInterface $request): ResponseInterface
    {
        if (!$actor->isAdmin() && !$actor->hasPermission('cosmetics.moderate')) {
            return new JsonResponse(['error' => $this->t('error.not_allowed')], 403);
        }

        $body = (array) $request->getParsedBody();
        $targetId = (int) ($body['userId'] ?? 0);
        $reason = isset($body['reason']) ? (string) $body['reason'] : null;

        if ($targetId <= 0) {
            return new JsonResponse(['error' => $this->t('error.banner_no_target')], 404);
        }

        $ok = $this->uploads->moderate($targetId, (int) $actor->id, $reason);
        Ownership::flush();

        return new JsonResponse(['ok' => $ok]);
    }

    private function equipCustom(int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = $this->db->table('cosmetic_loadout')->where('user_id', $userId)->exists();

        if ($exists) {
            $this->db->table('cosmetic_loadout')->where('user_id', $userId)
                ->update(['banner' => 'custom', 'updated_at' => $now]);
        } else {
            $legacy = $this->db->table('users')->where('id', $userId)->value('avatar_frame') ?: null;
            $this->db->table('cosmetic_loadout')->insert([
                'user_id' => $userId, 'frame' => $legacy, 'banner' => 'custom', 'updated_at' => $now,
            ]);
        }

        Ownership::flush();
    }

    private function t(string $key): string
    {
        return $this->translator->trans('local-looksmax-cosmetics.forum.' . $key);
    }
}
