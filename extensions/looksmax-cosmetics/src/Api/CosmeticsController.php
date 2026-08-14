<?php

namespace Local\Cosmetics\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Cosmetics\Definitions;
use Local\Cosmetics\Ownership;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Read side. Two views, on new routes so there is no conflict surface with any
 * other extension — the same shape looksmax-ranks and looksmax-store use.
 *
 *   GET /api/cosmetics/me    the wardrobe: everything that exists, what you
 *                            hold, what you are wearing, and for the rest,
 *                            exactly what it would take. A locked item with no
 *                            stated requirement is the whole reason cosmetic
 *                            economies feel arbitrary.
 *   GET /api/cosmetics/map   username -> live cosmetics, for the surfaces that
 *                            are raw DOM rather than a serialized user (the
 *                            shoutbox, the index last-poster). Everything else
 *                            comes off the user payload the SPA already has, so
 *                            the decorator normally fetches nothing at all.
 */
class CosmeticsController implements RequestHandlerInterface
{
    public function __construct(
        protected Definitions $defs,
        protected Ownership $ownership,
        protected ConnectionInterface $db
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $what = (string) ($request->getAttribute('routeParameters')['what'] ?? '');

        return match ($what) {
            'me' => $this->me($request),
            'map' => $this->map(),
            default => new JsonResponse(['error' => 'unknown_view'], 404),
        };
    }

    private function me(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            // A guest still gets the catalogue — seeing what exists is the
            // reason to make an account — but nothing is owned and nothing is
            // equipped.
            return new JsonResponse([
                'guest' => true,
                'equipped' => ['frame' => null, 'banner' => null],
                'items' => $this->catalogue(0, null, null),
            ]);
        }

        $uid = (int) $actor->id;
        $tier = $actor->tier_slug;
        $expires = $actor->tier_expires_at ? (string) $actor->tier_expires_at : null;

        $legacy = $actor->avatar_frame ?: null;

        return new JsonResponse([
            'guest' => false,
            'tier' => $tier,
            'equipped' => $this->ownership->loadout($uid, $legacy),
            'live' => $this->ownership->live($uid, $tier, $expires, $legacy),
            'items' => $this->catalogue($uid, $tier, $expires),
        ]);
    }

    /** @return array<int,array> every definition, annotated for this viewer */
    private function catalogue(int $uid, ?string $tier, ?string $expires): array
    {
        $out = [];

        foreach ($this->defs->all() as $kind => $defs) {
            foreach ($defs as $def) {
                $why = $uid ? $this->ownership->reason($uid, $def, $tier, $expires) : null;
                $holders = $this->defs->holderCount($def['obtain']);

                $out[] = [
                    'kind' => $kind,
                    'slug' => $def['slug'],
                    'name' => $def['name'],
                    'blurb' => $def['blurb'],
                    'rarity' => $def['rarity'],
                    'icon' => $def['icon'],
                    'render' => $def['render'],
                    'css' => $def['css'],
                    'owned' => $why !== null,
                    'reason' => $why,
                    'sku' => $def['sku'],
                    'price' => $def['price'],
                    'sellable' => $def['sellable'],
                    'holders' => $holders,
                    // How to get it, as structured data. The sentence is built
                    // in the client from a translation key, never here — Spanish
                    // is the source language and a hardcoded English string in
                    // an API response is a string nobody can translate.
                    'unlock' => $this->unlock($def),
                ];
            }
        }

        return $out;
    }

    private function unlock(array $def): array
    {
        $obtain = $def['obtain'];
        $type = (string) ($obtain['type'] ?? 'never');

        return match ($type) {
            'sku' => [
                'type' => 'buy',
                'sku' => $def['sku'],
                'price' => $def['price'],
                'minTier' => $def['minTier'],
                'url' => '/store',
            ],
            'tier' => ['type' => 'tier', 'tier' => $obtain['tier'] ?? null, 'url' => '/store'],
            'badge' => [
                'type' => 'badge',
                'badge' => $obtain['badge'] ?? null,
                // The badge's own display name, already translated by
                // looksmax-ranks. Showing a slug in a lock line is how a
                // cosmetic economy reads as unfinished. Guarded because this
                // extension must work with ranks disabled.
                'badgeName' => $this->badgeName((string) ($obtain['badge'] ?? '')),
            ],
            default => ['type' => 'award'],
        };
    }

    private function badgeName(string $slug): ?string
    {
        if ($slug === '' || !class_exists(\Local\Ranks\Catalog::class)) {
            return null;
        }

        try {
            $badge = \Local\Ranks\Catalog::badge($slug);
        } catch (\Throwable $e) {
            return null;
        }

        return $badge['name'] ?? null;
    }

    /**
     * The DOM fallback map.
     *
     * Only accounts that are wearing something appear, so the payload is the
     * size of the wardrobe in use rather than the size of the forum. Capped
     * regardless, because an unbounded list endpoint is how a page gets slow a
     * year after it ships.
     */
    private function map(): ResponseInterface
    {
        // LEFT join from users, not from the loadout table: somebody who bought
        // a frame in the store and has never opened the wardrobe has an
        // `avatar_frame` and no loadout row at all, and they still wear it.
        $rows = $this->db->table('users')
            ->leftJoin('cosmetic_loadout', 'users.id', '=', 'cosmetic_loadout.user_id')
            ->where(function ($q) {
                $q->whereNotNull('cosmetic_loadout.frame')
                  ->orWhereNotNull('cosmetic_loadout.banner')
                  ->orWhereNotNull('users.avatar_frame');
            })
            ->limit(2000)
            ->get(['users.id', 'users.username', 'users.tier_slug', 'users.tier_expires_at', 'users.avatar_frame']);

        $out = [];
        foreach ($rows as $r) {
            $live = $this->ownership->live(
                (int) $r->id,
                $r->tier_slug,
                $r->tier_expires_at ? (string) $r->tier_expires_at : null,
                $r->avatar_frame ?: null
            );
            if ($live) {
                $out[(string) $r->username] = $live;
            }
        }

        return new JsonResponse(['names' => $out, 'count' => count($out)]);
    }
}
