<?php

namespace Local\Ranks\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Economy\Ledger;
use Local\Ranks\Catalog;
use Local\Ranks\Standing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Read side of the identity layer.
 *
 * Plain PSR-15 handlers returning JsonResponse rather than JSON:API resources:
 * these are view-models assembled for specific panels (the store grid, the
 * trophy case, the name-colour map the DOM decorator needs), not resources with
 * relationships, and forcing them through JSON:API would mean three round trips
 * to draw one card.
 *
 * `app('flarum.forum')` is not a binding — that was tried and it is not. This
 * is the shape that works.
 */
class IdentityController implements RequestHandlerInterface
{
    public function __construct(
        protected Standing $standing,
        protected Ledger $ledger,
        protected ConnectionInterface $db
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $what = $request->getAttribute('routeParameters')['what'] ?? 'me';

        return match ($what) {
            'catalog' => new JsonResponse($this->catalog($actor)),
            'names' => $this->names(),
            'ladder' => new JsonResponse($this->ladder()),
            'leaderboard' => new JsonResponse($this->leaderboard($request)),
            default => new JsonResponse($this->me($actor)),
        };
    }

    /** Everything the signed-in user needs to render their own panels. */
    private function me($actor): array
    {
        if ($actor->isGuest()) {
            return ['guest' => true, 'ladder' => $this->ladder()];
        }

        $payload = $this->standing->payload($actor);
        $inv = $this->standing->inventory((int) $actor->id);
        $owned = [];
        foreach ($inv as $row) {
            $owned[$row['type']][] = $row['item'];
        }

        return [
            'guest' => false,
            'id' => (int) $actor->id,
            'username' => $actor->username,
            'standing' => $payload,
            'owned' => $owned,
            'badges' => $this->standing->badges((int) $actor->id),
            'nearestBadge' => $this->standing->nearestMeasurableBadge((int) $actor->id),
            'history' => $this->ledger->history((int) $actor->id, 25),
            'ladder' => $this->ladder(),
        ];
    }

    /**
     * The store catalogue, priced for THIS user (tier discount applied) and
     * annotated with why each item is or is not available.
     *
     * The reason string matters more than the boolean: "VIP or above can equip
     * gradient styles" tells someone what to do next, "locked" does not.
     */
    private function catalog($actor): array
    {
        $tier = $actor->isGuest() ? Catalog::tier('standard') : $this->standing->activeTier($actor);
        $owned = [];
        if (!$actor->isGuest()) {
            foreach ($this->standing->inventory((int) $actor->id) as $row) {
                $owned[$row['type'] . ':' . $row['item']] = true;
            }
        }

        $items = [];
        foreach (Catalog::shopItems() as $item) {
            $isFrame = $item['type'] === 'frame';
            $need = $isFrame ? Catalog::tier($item['tier']) : Catalog::tierForStyleKind($item['kind']);
            $allowed = $isFrame
                ? Catalog::tier($item['tier'])['rank'] <= $tier['rank']
                : in_array($item['kind'], $tier['styles'], true);

            $price = (int) floor($item['price'] * (1 - $tier['discount']));

            $items[] = [
                'type' => $item['type'],
                'slug' => $item['slug'],
                'name' => $item['name'],
                'kind' => $item['kind'],
                'blurb' => $item['blurb'],
                'rarity' => $item['rarity'],
                'rarityColor' => Catalog::RARITIES[$item['rarity']] ?? '#9aa4b2',
                'class' => $item['class'],
                'listPrice' => (int) $item['price'],
                'price' => $price,
                'owned' => isset($owned[$item['type'] . ':' . $item['slug']]),
                'purchasable' => $item['price'] > 0,
                'equippable' => $allowed,
                'requires' => $allowed ? null : ($need['name'] ?? Catalog::trans('forum.store.requires_invite')),
            ];
        }

        return [
            'items' => $items,
            'tiers' => $this->tiers($actor, $tier),
            'balance' => $actor->isGuest() ? 0 : (int) $actor->points,
            'discount' => $tier['discount'],
            'tier' => $tier['slug'],
            'rarities' => Catalog::RARITIES,
        ];
    }

    private function tiers($actor, array $current): array
    {
        $out = [];
        foreach (Catalog::tiers() as $t) {
            $out[] = [
                'slug' => $t['slug'],
                'name' => $t['name'],
                'price' => $t['price'],
                'days' => $t['days'],
                'color' => $t['color'],
                'icon' => $t['icon'],
                'headline' => $t['headline'],
                'earn' => $t['earn'],
                'discount' => $t['discount'],
                'capBoost' => $t['capBoost'],
                'titleLen' => $t['titleLen'],
                'showcase' => $t['showcase'],
                'styles' => $t['styles'],
                'banner' => $t['banner'],
                'accent' => $t['accent'],
                'current' => $t['slug'] === $current['slug'],
                'grantOnly' => $t['price'] === 0 && $t['slug'] !== 'standard',
                'expiresAt' => $t['slug'] === $current['slug'] && !$actor->isGuest() ? ($actor->tier_expires_at ? (string) $actor->tier_expires_at : null) : null,
            ];
        }

        return $out;
    }

    private function ladder(): array
    {
        $counts = $this->db->table('users')->groupBy('rank_slug')->selectRaw('rank_slug, COUNT(*) c')->pluck('c', 'rank_slug');
        $total = max(1, (int) $this->db->table('users')->count());

        $out = [];
        foreach (Catalog::ranks() as $i => $r) {
            $n = (int) ($counts[$r['slug']] ?? 0);
            $out[] = $r + [
                'index' => $i,
                'holders' => $n,
                'share' => round(100 * $n / $total, 2),
            ];
        }

        return $out;
    }

    /**
     * The username -> styling map used by the DOM decorator.
     *
     * Only users who actually have non-default styling are included, which on
     * the current population is a few hundred rows rather than 1,413, and the
     * response is cacheable for a minute because standing does not move faster
     * than that. `badge_count > 0` was added to that filter for the trophy
     * marks below — an account with nothing but badges (no rank above
     * Greycel, no bought style) used to be invisible to this endpoint
     * entirely, which meant the showcase it pinned on /settings could never
     * actually render next to its name anywhere.
     */
    private function names(): ResponseInterface
    {
        $rows = $this->db->table('users')
            ->where(function ($q) {
                $q->whereNotNull('name_style')
                  ->orWhereNotNull('custom_title')
                  ->orWhereNotNull('avatar_frame')
                  ->orWhere('badge_count', '>', 0)
                  ->orWhere(function ($q2) {
                      $q2->whereNotNull('rank_slug')->where('rank_slug', '!=', 'greycel');
                  });
            })
            ->get(['id', 'username', 'rank_slug', 'tier_slug', 'tier_expires_at', 'name_style', 'avatar_frame', 'custom_title', 'title_color', 'badge_count']);

        // Showcased badges for every account this response mentions, in one
        // query rather than one per row — same shape as
        // Ownership::entitlements() in looksmax-cosmetics.
        $ids = $rows->pluck('id')->all();
        $showcase = [];
        if ($ids) {
            foreach ($this->db->table('identity_badges')
                ->whereIn('user_id', $ids)->where('showcased', 1)
                ->orderBy('user_id')->orderBy('slot')
                ->get(['user_id', 'badge']) as $r) {
                if (count($showcase[$r->user_id] ?? []) >= 3) {
                    continue; // the inline mark caps at three; see less/forum.less .lmx-badges
                }
                $b = Catalog::badge((string) $r->badge);
                if ($b) {
                    $showcase[$r->user_id][] = ['n' => $b['name'], 'i' => $b['icon'], 't' => $b['tier']];
                }
            }
        }

        $now = time();
        $map = [];
        foreach ($rows as $u) {
            $tier = Catalog::tier(
                ($u->tier_expires_at && strtotime((string) $u->tier_expires_at) < $now) ? 'standard' : $u->tier_slug
            );

            $style = Catalog::style($u->name_style);
            if ($style && !in_array($style['kind'], $tier['styles'], true)) {
                $style = null;
            }
            $frame = Catalog::frame($u->avatar_frame);
            if ($frame && Catalog::tier($frame['tier'])['rank'] > $tier['rank']) {
                $frame = null;
            }

            $rank = Catalog::rank($u->rank_slug ?: 'greycel');

            $map[$u->username] = [
                'r' => $rank['slug'],
                'ri' => $rank['index'],
                't' => $tier['slug'],
                's' => $style['class'] ?? null,
                'f' => $frame['class'] ?? null,
                'ti' => $u->custom_title,
                'tc' => $u->title_color,
                'b' => (int) $u->badge_count,
                'sb' => $showcase[$u->id] ?? [],
            ];
        }

        return new JsonResponse(
            ['names' => $map, 'ranks' => array_column(Catalog::ranks(), null, 'slug'), 'tiers' => array_column(Catalog::tiers(), null, 'slug')],
            200,
            ['Cache-Control' => 'public, max-age=60']
        );
    }

    /** Top of the ladder, for the front page card and the /ranks page. */
    private function leaderboard(ServerRequestInterface $request): array
    {
        $window = $request->getQueryParams()['window'] ?? 'all';

        if ($window === 'week') {
            $rows = $this->db->table('economy_transactions AS t')
                ->join('users AS u', 'u.id', '=', 't.user_id')
                ->where('t.created_at', '>', date('Y-m-d H:i:s', time() - 7 * 86400))
                ->where('t.delta', '>', 0)
                ->groupBy('u.id', 'u.username', 'u.rank_slug', 'u.name_style', 'u.tier_slug', 'u.avatar_url')
                ->selectRaw('u.id, u.username, u.rank_slug, u.name_style, u.tier_slug, u.avatar_url, SUM(t.delta) AS score')
                ->orderByDesc('score')->limit(15)->get();
        } else {
            $rows = $this->db->table('users')
                ->orderByDesc('lifetime_points')->limit(15)
                ->get(['id', 'username', 'rank_slug', 'name_style', 'tier_slug', 'avatar_url', 'lifetime_points AS score']);
        }

        return [
            'window' => $window,
            'entries' => $rows->map(function ($u) {
                $rank = Catalog::rank($u->rank_slug ?: 'greycel');
                $style = Catalog::style($u->name_style);

                return [
                    'id' => (int) $u->id,
                    'username' => $u->username,
                    'avatarUrl' => self::avatarUrl($u->avatar_url),
                    'score' => (int) $u->score,
                    'rank' => $rank['name'],
                    'rankColor' => $rank['color'],
                    'rankIcon' => $rank['icon'],
                    'nameClass' => $style['class'] ?? null,
                    'tier' => $u->tier_slug,
                ];
            })->all(),
        ];
    }

    /**
     * Turn the raw `users.avatar_url` column into something a browser can load.
     *
     * The column holds a BARE FILENAME ("3311.jpg"), not a URL. These endpoints
     * read it through the query builder, which returns stdClass rows and so
     * never runs Eloquent's `getAvatarUrlAttribute` accessor — the value went
     * straight into JSON and straight into an <img src>, where the browser
     * resolved it against the current path. Measured live: GET /3311.jpg -> 404
     * text/plain, GET /assets/avatars/3311.jpg -> 200 image/jpeg. Every avatar
     * on the standing card was a broken image.
     *
     * Resolved through the same filesystem disk core's accessor uses, so a
     * future move to S3 or a CDN cannot make these two disagree.
     */
    public static function avatarUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (str_contains($value, '://')) {
            return $value;
        }

        return resolve(FilesystemFactory::class)->disk('flarum-avatars')->url($value);
    }
}
