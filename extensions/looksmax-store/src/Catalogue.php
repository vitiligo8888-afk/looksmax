<?php

namespace Local\Store;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\Ranks\Catalog;

/**
 * Reading side of the store.
 *
 * Everything a card shows is computed here, once, for the whole catalogue:
 * the price this specific person pays, whether they already own it, and — when
 * they cannot buy it — the one sentence that says why. That last part is the
 * reason this is not done in the browser. "Buy" buttons that are simply absent
 * teach nobody anything; "VIP or above can equip animated styles" tells a
 * member what to do next, and it has to come from the same rules the purchase
 * endpoint enforces or the two will drift.
 */
class Catalogue
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Entitlements $entitlements
    ) {
    }

    /** Insert missing catalogue rows; never overwrite an admin's price. */
    public function sync(bool $refreshCopy = true): array
    {
        $existing = $this->db->table('store_items')->pluck('id', 'sku')->all();
        $added = 0;
        $updated = 0;

        foreach (Seed::all() as $row) {
            $record = [
                'sku' => $row['sku'],
                'name' => $row['name'],
                'blurb' => $row['blurb'] ?? '',
                'category' => $row['category'],
                'kind' => $row['kind'],
                'payload' => json_encode($row['payload'] ?? []),
                'rarity' => $row['rarity'] ?? 'common',
                'icon' => $row['icon'] ?? 'ph:tag-fill',
                'color' => $row['color'] ?? null,
                'sort' => $row['sort'] ?? 100,
                'giftable' => $row['giftable'] ?? true,
                'discountable' => $row['discountable'] ?? true,
                'max_per_user' => $row['max_per_user'] ?? 0,
                'stock_total' => $row['stock_total'] ?? null,
                'min_rank' => $row['min_rank'] ?? null,
                'min_tier' => $row['min_tier'] ?? null,
                'requires_sku' => $row['requires_sku'] ?? null,
                'duration_days' => $row['duration_days'] ?? null,
                'uses' => $row['uses'] ?? null,
                'refund_minutes' => $row['refund_minutes'] ?? 0,
                'managed' => $row['managed'] ?? false,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if (!isset($existing[$row['sku']])) {
                $record['price'] = (int) ($row['price'] ?? 0);
                // Currency is commercial, like price: seeded once, then owned by
                // the admin screen. A redeploy must not flip an item an admin
                // re-priced into the other currency.
                $record['currency'] = $row['currency'] ?? 'points';
                $record['active'] = true;
                $record['created_at'] = date('Y-m-d H:i:s');
                $this->db->table('store_items')->insert($record);
                $added++;
                continue;
            }

            if ($refreshCopy) {
                // Descriptive columns only. Price, active and stock belong to
                // whoever last touched the admin screen — a redeploy must not
                // silently undo a repricing done because something was being
                // farmed.
                unset($record['sku']);
                $this->db->table('store_items')->where('sku', $row['sku'])->update($record);
                $updated++;
            }
        }

        return ['added' => $added, 'updated' => $updated];
    }

    /** @return array<int,object> */
    public function items(bool $includeInactive = false): array
    {
        $q = $this->db->table('store_items');
        if (!$includeInactive) {
            $q->where('active', 1);
        }

        return $q->orderBy('category')->orderBy('sort')->orderBy('price')->get()->all();
    }

    public function find(string $sku): ?object
    {
        return $this->db->table('store_items')->where('sku', $sku)->first();
    }

    /**
     * The catalogue as one person sees it.
     *
     * `locked` is a sentence or null. `price` is what they will actually be
     * charged, which is the number the confirm dialog must repeat back.
     */
    public function forUser(?User $user, bool $includeInactive = false): array
    {
        $rows = $this->items($includeInactive);
        $userId = $user && !$user->isGuest() ? (int) $user->id : 0;

        $owned = $userId ? $this->ownedSkus($userId) : [];
        $counts = $userId ? $this->purchaseCounts($userId) : [];
        $holders = $this->holderCounts();
        $tier = $this->tierOf($user);
        $lifetime = $user ? (int) ($user->lifetime_points ?? 0) : 0;
        $balance = $user ? (int) ($user->points ?? 0) : 0;
        $oro = $user ? (int) ($user->oro ?? 0) : 0;

        $out = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $price = $this->priceFor($row, $tier);
            $stockLeft = $row->stock_total === null ? null : max(0, (int) $row->stock_total - (int) $row->stock_sold);

            // A money-in pack (kind 'oro' or legacy 'credits') is bought with
            // real money; anything else is priced in a forum currency, which is
            // 'oro' if the item says so and 'points' otherwise.
            $moneyPack = $row->kind === 'oro' || $row->kind === 'credits';
            $itemCurrency = $moneyPack ? 'money' : (($row->currency ?? 'points') === 'oro' ? 'oro' : 'points');
            $affordable = $moneyPack ? true : ($itemCurrency === 'oro' ? $oro >= $price : $balance >= $price);

            $out[] = [
                'sku' => $row->sku,
                'name' => $row->name,
                'blurb' => $row->blurb,
                'category' => $row->category,
                'kind' => $row->kind,
                'payload' => $payload,
                'icon' => $row->icon,
                'color' => $row->color,
                'rarity' => $row->rarity,
                'listPrice' => (int) $row->price,
                'price' => $price,
                'saving' => (int) $row->price - $price,
                'currency' => $itemCurrency,
                'money' => $moneyPack ? ($payload['money'] ?? null) : null,
                'active' => (bool) $row->active,
                'giftable' => (bool) $row->giftable,
                'durationDays' => $row->duration_days === null ? null : (int) $row->duration_days,
                'uses' => $row->uses === null ? null : (int) $row->uses,
                'stockLeft' => $stockLeft,
                'stockTotal' => $row->stock_total === null ? null : (int) $row->stock_total,
                'availableUntil' => $row->available_until,
                'minTier' => $row->min_tier,
                // The card said "plus" and "vip" — the slug, not the name
                // anybody sees anywhere else on the forum. A gate is only a
                // hint if the member cannot tell what it is asking for.
                'minTierName' => $row->min_tier && class_exists(Catalog::class)
                    ? Catalog::tier($row->min_tier)['name'] : null,
                'minRank' => $row->min_rank,
                // How many members hold it. The source board ran a public
                // count of purchased upgrades and it is the cheapest social
                // proof there is; it also quietly tells somebody whether the
                // colour they are about to buy is one everybody already has.
                'holders' => (int) ($holders[$row->sku] ?? 0),
                'owned' => in_array($row->sku, $owned, true),
                'ownedCount' => (int) ($counts[$row->sku] ?? 0),
                'affordable' => $affordable,
                'locked' => $this->lockReason($row, $user, $tier, $lifetime, $owned, $counts, $stockLeft),
                'preview' => $this->preview($row, $payload),
            ];
        }

        return $out;
    }

    /** Price after the buyer's membership discount. */
    public function priceFor(object $row, array $tier): int
    {
        $price = (int) $row->price;
        if (!$row->discountable || $price === 0) {
            return $price;
        }

        return (int) floor($price * (1 - (float) ($tier['discount'] ?? 0)));
    }

    public function tierOf(?User $user): array
    {
        if (!$user || $user->isGuest() || !class_exists(Catalog::class)) {
            return ['slug' => 'standard', 'rank' => 0, 'discount' => 0.0, 'name' => resolve('translator')->trans('local-looksmax-store.forum.tier.standard')];
        }

        $slug = $user->tier_slug;
        $expiry = $user->tier_expires_at;
        if (!$slug || ($expiry && strtotime((string) $expiry) < time())) {
            $slug = 'standard';
        }

        return Catalog::tier($slug);
    }

    /**
     * Why this person cannot buy this, in one sentence, or null.
     *
     * Order matters: the most actionable reason wins. Telling somebody they
     * are 400 points short is more useful than telling them the item needs
     * VIP, if both are true — they can fix the first one today.
     */
    public function lockReason(object $row, ?User $user, array $tier, int $lifetime, array $owned, array $counts, ?int $stockLeft): ?string
    {
        if (!$user || $user->isGuest()) {
            return resolve('translator')->trans('local-looksmax-store.forum.lock.guest');
        }
        if (!$row->active) {
            return resolve('translator')->trans('local-looksmax-store.forum.lock.inactive');
        }
        if ($row->available_from && strtotime((string) $row->available_from) > time()) {
            return resolve('translator')->trans('local-looksmax-store.forum.lock.opens', ['when' => \IntlDateFormatter::formatObject(new \DateTimeImmutable((string) $row->available_from), 'd MMM HH:mm', resolve('translator')->getLocale())]);
        }
        if ($row->available_until && strtotime((string) $row->available_until) < time()) {
            return resolve('translator')->trans('local-looksmax-store.forum.lock.closed');
        }
        if ($stockLeft !== null && $stockLeft <= 0) {
            return resolve('translator')->trans('local-looksmax-store.forum.lock.sold_out');
        }

        $count = (int) ($counts[$row->sku] ?? 0);
        if ((int) $row->max_per_user > 0 && $count >= (int) $row->max_per_user) {
            return (int) $row->max_per_user === 1 ? resolve('translator')->trans('local-looksmax-store.forum.lock.owned') : resolve('translator')->trans('local-looksmax-store.forum.lock.max_reached');
        }

        // A membership below the one already held: refuse it on the card, not
        // after the click. The purchase path refuses it too — the UI is a hint
        // and the endpoint is the rule — but a member should not have to click
        // to find out.
        if ($row->kind === 'tier' && class_exists(Catalog::class)) {
            $payload = json_decode((string) $row->payload, true) ?: [];
            $target = Catalog::tier($payload['tier'] ?? '');
            if ((int) ($tier['rank'] ?? 0) > (int) $target['rank']) {
                return resolve('translator')->trans('local-looksmax-store.forum.lock.tier_downgrade', ['current' => $tier['name'], 'target' => $target['name']]);
            }
        }

        if ($row->min_tier && class_exists(Catalog::class)) {
            $need = Catalog::tier($row->min_tier);
            if ((int) $need['rank'] > (int) ($tier['rank'] ?? 0)) {
                return $need['name'] . ' or above can use this.';
            }
        }

        if ($row->min_rank && class_exists(Catalog::class)) {
            $need = Catalog::rank($row->min_rank);
            if ($lifetime < (int) $need['min']) {
                return resolve('translator')->trans('local-looksmax-store.forum.lock.rank_required', ['rank' => $need['name'], 'count' => (int) $need['min'] - $lifetime]);
            }
        }

        if ($row->requires_sku && !in_array($row->requires_sku, $owned, true)) {
            $req = $this->find($row->requires_sku);
            return $req ? resolve('translator')->trans('local-looksmax-store.forum.lock.requires', ['item' => $req->name]) : null;
        }

        return null;
    }

    /** SKUs this user holds a live entitlement for. */
    public function ownedSkus(int $userId): array
    {
        return $this->db->table('store_entitlements')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->distinct()->pluck('sku')->all();
    }

    /**
     * How many distinct members hold each SKU right now.
     *
     * One grouped query for the whole catalogue, not one per card. Cached for
     * a minute in memory because the storefront renders it on every item and
     * the number does not need to be to-the-second accurate.
     */
    public function holderCounts(): array
    {
        static $cache = null;
        static $at = 0;

        if ($cache !== null && time() - $at < 60) {
            return $cache;
        }

        $at = time();

        return $cache = $this->db->table('store_entitlements')
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->selectRaw('sku, count(distinct user_id) as n')
            ->groupBy('sku')
            ->pluck('n', 'sku')->all();
    }

    /** Lifetime purchase counts per SKU, which is what max_per_user bounds. */
    public function purchaseCounts(int $userId): array
    {
        return $this->db->table('store_orders')
            ->where('recipient_id', $userId)
            ->whereIn('state', ['granted', 'redeemed'])
            ->selectRaw('sku, count(*) as n')
            ->groupBy('sku')->pluck('n', 'sku')->all();
    }

    /**
     * Enough of the identity catalogue to draw the item without a second
     * request: the CSS class that paints a name style, the ring class for a
     * frame, the tier colour.
     */
    private function preview(object $row, array $payload): array
    {
        if (!class_exists(Catalog::class)) {
            return [];
        }

        if ($row->kind === 'style' && isset($payload['item'])) {
            $s = Catalog::style($payload['item']);
            return $s ? ['type' => 'style', 'class' => $s['class'], 'kind' => $s['kind']] : [];
        }
        if ($row->kind === 'frame' && isset($payload['item'])) {
            $f = Catalog::frame($payload['item']);
            return $f ? ['type' => 'frame', 'class' => $f['class']] : [];
        }
        if ($row->kind === 'tier' && isset($payload['tier'])) {
            $t = Catalog::tier($payload['tier']);
            return ['type' => 'tier', 'color' => $t['color'], 'icon' => $t['icon'], 'headline' => $t['headline'], 'earn' => $t['earn'], 'discount' => $t['discount']];
        }

        return [];
    }
}
