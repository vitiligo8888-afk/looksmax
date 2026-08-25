<?php

namespace Local\Store\Api;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Ranks\Catalog;
use Local\Store\Catalogue;
use Local\Store\Entitlements;
use Local\Store\Purchase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Read side. One route, three views, because the store page needs all three
 * within a click of each other and three round trips to paint one screen is
 * three chances to show a half-drawn page.
 *
 *   /api/store/catalogue   items, priced for you, with the reason you cannot
 *                          buy each one you cannot buy
 *   /api/store/orders      your purchase history, gifts in and out
 *   /api/store/admin       the catalogue including retired rows, recent
 *                          orders across everybody, the audit trail
 */
class StoreController implements RequestHandlerInterface
{
    public function __construct(
        protected Catalogue $catalogue,
        protected Entitlements $entitlements,
        protected Purchase $purchase,
        protected \Local\Store\Grants\Registry $grants,
        protected ConnectionInterface $db
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $what = (string) ($request->getAttribute('routeParameters')['what'] ?? 'catalogue');
        $query = $request->getQueryParams();

        return match ($what) {
            'catalogue' => new JsonResponse($this->catalogueView($actor)),
            'oro' => new JsonResponse($this->oroView($actor)),
            'orders' => new JsonResponse($this->ordersView($actor, $query)),
            'admin' => $this->adminView($actor, $query),
            default => new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.error.unknown_view')], 404),
        };
    }

    private function catalogueView(User $actor): array
    {
        $guest = $actor->isGuest();
        $userId = $guest ? 0 : (int) $actor->id;

        // The store now runs on Oro: every spend item is priced in oro and the
        // balance bar shows the oro balance, so oro items are shown here (no
        // longer filtered out). The dist JS renders the price number + the
        // currency unit string, which locale now sets to "Oro"; the server is
        // authoritative on which balance is charged (Catalogue currency='oro'
        // routes Purchase to OroProvider).
        $items = $this->catalogue->forUser($guest ? null : $actor);
        $tier = $this->catalogue->tierOf($guest ? null : $actor);

        $held = $userId ? $this->heldByUser($userId) : [];

        return [
            'items' => $items,
            'categories' => $this->categories($items),
            'me' => [
                'guest' => $guest,
                'id' => $userId,
                'username' => $guest ? null : $actor->username,
                // Back to points. The store spends the EARNED balance again now
                // that the paid currency is parked; `balance` is the number the
                // buy dialog checks against, so it has to be the one the items
                // are actually priced in or every card lies about affordability.
                'balance' => $guest ? 0 : (int) $actor->points,
                'oro' => $guest ? 0 : (int) ($actor->oro ?? 0),
                'points' => $guest ? 0 : (int) $actor->points,
                'lifetime' => $guest ? 0 : (int) $actor->lifetime_points,
                'tier' => $tier['slug'] ?? 'standard',
                'tierName' => $tier['name'] ?? resolve('translator')->trans('local-looksmax-store.forum.tier.standard'),
                'tierColor' => $tier['color'] ?? null,
                'tierExpiresAt' => $guest ? null : $actor->tier_expires_at,
                'discount' => (float) ($tier['discount'] ?? 0),
                'earn' => (float) ($tier['earn'] ?? 1),
                'boosts' => $userId ? $this->entitlements->boosts($userId) : ['earn' => 1.0, 'capBoost' => 1.0],
                'canAdmin' => !$guest && ($actor->isAdmin() || $actor->hasPermission('store.admin')),
            ],
            'held' => $held,
            'threads' => $userId ? $this->myThreads($userId) : [],
        ];
    }

    /**
     * The Oro surface: what you can buy WITH money (packs) and what you can buy
     * with the oro you hold (items), plus both balances. One request paints the
     * whole paid-currency panel. Prices, ownership and lock reasons are already
     * computed by forUser(), so this only splits its output in two.
     */
    private function oroView(User $actor): array
    {
        $guest = $actor->isGuest();
        $all = $this->catalogue->forUser($guest ? null : $actor);

        return [
            'packs' => array_values(array_filter($all, fn ($i) => ($i['kind'] ?? '') === 'oro')),
            'items' => array_values(array_filter($all, fn ($i) => ($i['currency'] ?? 'points') === 'oro')),
            'me' => [
                'guest' => $guest,
                'id' => $guest ? 0 : (int) $actor->id,
                'username' => $guest ? null : $actor->username,
                'oro' => $guest ? 0 : (int) ($actor->oro ?? 0),
                'points' => $guest ? 0 : (int) ($actor->points ?? 0),
            ],
        ];
    }

    /**
     * What this member holds, in words rather than in SKUs.
     *
     * The first cut of this sent the raw `sku` and the page rendered
     * "style-vaporwave", "box-colour" eight times over, and
     * "e2e-limited-dqo1i" — a list that is technically the truth and tells the
     * owner nothing. What somebody wants to see is the name of the thing, and
     * for a box, the name of what came OUT of it, which is not the same row.
     */
    private function heldByUser(int $userId): array
    {
        $items = $this->db->table('store_items')->get()->keyBy('sku');
        $out = [];

        foreach ($this->entitlements->active($userId) as $row) {
            $item = $items[$row->sku] ?? null;
            $payload = is_array($row->payload) ? $row->payload : [];

            $name = $item->name ?? $row->sku;
            $preview = null;

            // A cosmetic entitlement carries the granted slug, which is the
            // interesting name — a box entitlement is not "Colour box", it is
            // the colour it produced.
            if (in_array($row->kind, ['style', 'frame'], true) && isset($payload['item']) && class_exists(Catalog::class)) {
                $cosmetic = $row->kind === 'frame'
                    ? Catalog::frame($payload['item'])
                    : Catalog::style($payload['item']);

                if ($cosmetic) {
                    $name = $cosmetic['name'];
                    $preview = ['type' => $row->kind, 'class' => $cosmetic['class']];
                }
            }

            $out[] = [
                'id' => (int) $row->id,
                'sku' => $row->sku,
                'kind' => $row->kind,
                'name' => $name,
                'from' => $item->name ?? null,
                'icon' => $item->icon ?? 'ph:tag-fill',
                'color' => $item->color ?? null,
                'rarity' => $item->rarity ?? 'common',
                'payload' => $payload,
                'preview' => $preview,
                'grantedAt' => $row->granted_at,
                'expiresAt' => $row->expires_at,
                'usesLeft' => $row->uses_left === null ? null : (int) $row->uses_left,
            ];
        }

        return $out;
    }

    /**
     * Section tabs, in a deliberate order: what the board's own threads show
     * people actually buy first (a membership, then a colour), then the things
     * that need a membership to be worth having.
     */
    private function categories(array $items): array
    {
        $t = resolve('translator');
        $meta = [
            'membership' => [$t->trans('local-looksmax-store.forum.category.membership'), 'solar:crown-bold'],
            'colours' => [$t->trans('local-looksmax-store.forum.category.colours'), 'ph:paint-brush-fill'],
            'frames' => [$t->trans('local-looksmax-store.forum.category.frames'), 'ph:circle-half-tilt-bold'],
            'boosts' => [$t->trans('local-looksmax-store.forum.category.boosts'), 'ph:lightning-fill'],
            'utility' => [$t->trans('local-looksmax-store.forum.category.utility'), 'ph:wrench-fill'],
            'mystery' => [$t->trans('local-looksmax-store.forum.category.mystery'), 'ph:package-fill'],
            'bundles' => [$t->trans('local-looksmax-store.forum.category.bundles'), 'ph:shopping-bag-open-fill'],
            'credits' => [$t->trans('local-looksmax-store.forum.category.credits'), 'ph:coins-fill'],
        ];

        $counts = [];
        foreach ($items as $item) {
            $counts[$item['category']] = ($counts[$item['category']] ?? 0) + 1;
        }

        $out = [];
        foreach ($meta as $key => [$label, $icon]) {
            if (!isset($counts[$key])) {
                continue;
            }
            $out[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'count' => $counts[$key]];
        }

        // Anything an admin invented a new category for still gets a tab.
        foreach ($counts as $key => $n) {
            if (!isset($meta[$key])) {
                $out[] = ['key' => $key, 'label' => ucfirst($key), 'icon' => 'ph:tag-fill', 'count' => $n];
            }
        }

        return $out;
    }

    /**
     * The buyer's own threads, so redeeming a pin or a highlight is a picker
     * rather than "go and find the ID".
     */
    private function myThreads(int $userId): array
    {
        return $this->db->table('discussions')
            ->where('user_id', $userId)
            ->whereNull('hidden_at')
            ->orderByDesc('last_posted_at')
            ->limit(25)
            ->get(['id', 'title', 'comment_count', 'last_posted_at', 'is_sticky'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'title' => $r->title,
                'comments' => (int) $r->comment_count,
                'lastPostedAt' => $r->last_posted_at,
                'sticky' => (bool) $r->is_sticky,
            ])->all();
    }

    private function ordersView(User $actor, array $query): array
    {
        if ($actor->isGuest()) {
            return ['orders' => [], 'gifts' => []];
        }

        $limit = min(100, max(5, (int) ($query['limit'] ?? 50)));

        $mine = $this->db->table('store_orders')
            ->where('user_id', $actor->id)
            ->orderByDesc('id')->limit($limit)->get();

        $gifts = $this->db->table('store_orders')
            ->where('recipient_id', $actor->id)
            ->whereColumn('recipient_id', '!=', 'user_id')
            ->where('state', 'granted')
            ->orderByDesc('id')->limit($limit)->get();

        $names = $this->names(array_merge(
            $mine->pluck('recipient_id')->all(),
            $gifts->pluck('user_id')->all()
        ));

        return [
            'orders' => $mine->map(fn ($o) => $this->purchase->orderPayload($o) + [
                'recipientName' => $names[(int) $o->recipient_id] ?? null,
            ])->all(),
            'gifts' => $gifts->map(fn ($o) => $this->purchase->orderPayload($o) + [
                'fromName' => $names[(int) $o->user_id] ?? null,
            ])->all(),
            'ledger' => $this->ledgerLines((int) $actor->id),
        ];
    }

    /**
     * The store side of the ledger, so a purchase history and a balance
     * history can be read against each other. If these two ever disagree the
     * economy has a bug, and the only way anybody notices is by being able to
     * see both.
     */
    private function ledgerLines(int $userId): array
    {
        return $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->whereIn('reason', ['store.purchase', 'store.refund', 'store.credits', 'store.clawback', 'tier.purchase', 'admin.adjust'])
            ->orderByDesc('id')->limit(60)
            ->get(['delta', 'reason', 'ref', 'created_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    private function adminView(User $actor, array $query): ResponseInterface
    {
        if (!$actor->isAdmin() && !$actor->hasPermission('store.admin')) {
            return new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.error.not_allowed')], 403);
        }

        $items = $this->catalogue->items(true);
        $orders = $this->db->table('store_orders')->orderByDesc('id')->limit(100)->get();
        $names = $this->names(array_merge($orders->pluck('user_id')->all(), $orders->pluck('recipient_id')->all()));

        $revenue = $this->db->table('store_orders')->where('state', 'granted')
            ->where('currency', 'points')->sum('total');

        $refunded = $this->db->table('store_orders')->where('state', 'refunded')->sum('total');

        $top = $this->db->table('store_orders')
            ->where('state', 'granted')
            ->selectRaw('sku, count(*) as n, sum(total) as revenue')
            ->groupBy('sku')->orderByDesc('n')->limit(15)->get();

        return new JsonResponse([
            'items' => array_map(function ($i) {
                $i = (array) $i;
                $i['payload'] = json_decode((string) $i['payload'], true) ?: [];
                return $i;
            }, $items),
            'orders' => $orders->map(fn ($o) => $this->purchase->orderPayload($o) + [
                'userName' => $names[(int) $o->user_id] ?? null,
                'recipientName' => $names[(int) $o->recipient_id] ?? null,
            ])->all(),
            'audit' => $this->db->table('store_audit')->orderByDesc('id')->limit(60)->get()->map(function ($r) {
                $r->before = json_decode((string) $r->before, true);
                $r->after = json_decode((string) $r->after, true);
                return (array) $r;
            })->all(),
            'stats' => [
                'items' => count($items),
                'orders' => (int) $this->db->table('store_orders')->count(),
                'granted' => (int) $this->db->table('store_orders')->where('state', 'granted')->count(),
                'refused' => (int) $this->db->table('store_orders')->where('state', 'refused')->count(),
                'failed' => (int) $this->db->table('store_orders')->where('state', 'failed')->count(),
                'refunded' => (int) $this->db->table('store_orders')->where('state', 'refunded')->count(),
                'spent' => (int) $revenue,
                'refundedPoints' => (int) $refunded,
                'circulating' => (int) $this->db->table('users')->sum('points'),
                'entitlements' => (int) $this->db->table('store_entitlements')->whereNull('revoked_at')->count(),
            ],
            'top' => $top->map(fn ($r) => (array) $r)->all(),
            'kinds' => $this->grants->kinds(),
        ]);
    }

    private function names(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }

        return $this->db->table('users')->whereIn('id', $ids)->pluck('username', 'id')->all();
    }
}
