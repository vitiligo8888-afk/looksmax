<?php

namespace Local\Store\Grants;

use Illuminate\Database\ConnectionInterface;
use Local\Ranks\Catalog;
use Local\Ranks\Standing;
use Local\Store\Entitlements;
use Local\Store\PurchaseRefused;

/**
 * Memberships.
 *
 * Renewal extends from the current expiry rather than from now, so buying a
 * second month on day 20 gives you 40 days, not 30. Standing::grantTier
 * already does that; this handler exists to record the store side and to hold
 * the one rule the identity layer cannot know about — you may not buy a tier
 * BELOW the one you are on, because the group projection keeps the higher tier
 * and the buyer would see nothing change for their points.
 *
 * What happens when a tier lapses is deliberately not "you lose what you
 * bought": the cosmetics stay in inventory and simply stop rendering, so
 * renewing restores them instantly. That rule lives in Standing::effectiveStyle
 * and is the reason a lapse is a downgrade rather than a confiscation.
 */
class TierGrant implements Grant
{
    public function __construct(
        protected Standing $standing,
        protected Entitlements $entitlements,
        protected ConnectionInterface $db
    ) {
    }

    public function kinds(): array
    {
        return ['tier'];
    }

    public function apply(int $userId, object $item, array $payload, int $orderId): array
    {
        $slug = (string) ($payload['tier'] ?? '');
        $days = (int) ($payload['days'] ?? 30);
        $tier = Catalog::tier($slug);

        if ($tier['slug'] !== $slug) {
            throw new \RuntimeException('no such membership');
        }

        $current = $this->db->table('users')->where('id', $userId)->first(['tier_slug', 'tier_expires_at']);
        $currentTier = Catalog::tier($current->tier_slug ?? null);
        $lapsed = $current && $current->tier_expires_at && strtotime((string) $current->tier_expires_at) < time();

        if (!$lapsed && (int) $currentTier['rank'] > (int) $tier['rank']) {
            // A refusal, not a failure. It used to be a plain RuntimeException,
            // which the purchase path treats as a bug: the order was marked
            // `failed`, the buyer was shown "something went wrong and nothing
            // was charged", and the actual reason — that they are already on a
            // better membership — was buried in a column. Nothing was ever
            // charged either way, but a store that says "something went wrong"
            // when it means "you already have better" is a store people stop
            // trusting.
            throw new PurchaseRefused(
                'tier_downgrade',
                resolve('translator')->trans('local-looksmax-store.forum.lock.tier_downgrade', ['current' => $currentTier['name'], 'target' => $tier['name']])
            );
        }

        $this->standing->grantTier($userId, $slug, 'purchase', (int) $item->price, $days);

        $expires = $this->trueExpiry($userId);

        $this->entitlements->grant(
            $userId,
            $item->sku,
            'tier',
            ['tier' => $slug],
            $expires ? (string) $expires : null,
            null,
            $orderId
        );

        return ['tier' => $slug, 'name' => $tier['name'], 'expiresAt' => $expires];
    }

    /**
     * The expiry the member actually has, and a repair for the cached column.
     *
     * Standing::refreshTier walks the active membership rows and keeps the
     * expiry of the first row it sees at the highest rank, not the latest one.
     * With one row per purchase — which is how that table records history — a
     * third renewal therefore leaves `users.tier_expires_at` pinned to the
     * FIRST month's end date, and the store's own renewal reads as a purchase
     * that did nothing. Measured on user 3015: rows ending 2026-09-12,
     * 2026-10-12 and 2026-11-11, cached column 2026-09-12.
     *
     * The fix belongs in refreshTier (see HANDOFF-STORE.md), which this
     * extension may not edit. Until it lands, the store writes the true value
     * back after every membership purchase, so a renewal is honest even though
     * the identity layer would have understated it.
     */
    private function trueExpiry(int $userId): ?string
    {
        $now = date('Y-m-d H:i:s');

        $slug = $this->db->table('users')->where('id', $userId)->value('tier_slug');
        if (!$slug || $slug === 'standard') {
            return null;
        }

        $max = $this->db->table('identity_memberships')
            ->where('user_id', $userId)
            ->where('tier', $slug)
            ->where('active', 1)
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->max('expires_at');

        $permanent = $this->db->table('identity_memberships')
            ->where('user_id', $userId)->where('tier', $slug)->where('active', 1)
            ->whereNull('expires_at')->exists();

        $value = $permanent ? null : $max;

        $this->db->table('users')->where('id', $userId)->update(['tier_expires_at' => $value]);

        return $value;
    }

    public function revoke(int $userId, object $entitlement): void
    {
        $payload = json_decode((string) $entitlement->payload, true) ?: [];
        $slug = (string) ($payload['tier'] ?? '');

        if ($slug === '') {
            return;
        }

        // Deactivate the membership row this order created, then recompute.
        // Recomputing rather than assuming "back to standard" is what keeps a
        // second, still-valid membership (a gift, a staff grant) intact.
        $this->db->table('identity_memberships')
            ->where('user_id', $userId)
            ->where('tier', $slug)
            ->where('active', 1)
            ->orderByDesc('id')->limit(1)
            ->update(['active' => 0]);

        $this->standing->refreshTier($userId);
    }
}
