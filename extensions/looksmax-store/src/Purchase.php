<?php

namespace Local\Store;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\Store\Grants\Registry;
use Local\Store\Payments\MockCardProvider;
use Local\Store\Payments\PointsProvider;

/**
 * The purchase path. Everything else in this extension is around it.
 *
 * The defect this file is written against, stated once: a transaction that
 * leaves the credits gone and nothing granted. Every decision below follows
 * from refusing to allow that state to exist.
 *
 *   1. The ORDER is written first, and committed, before any money moves. So a
 *      request that dies anywhere later still leaves a row saying what was
 *      being attempted, by whom, for how much. `pending` orders are findable.
 *
 *   2. Stock, payment and grant happen inside ONE database transaction on ONE
 *      connection. The ledger debit is a row in that same transaction, so a
 *      grant that throws takes the debit with it. There is no compensating
 *      refund to get wrong, because there is no window in which the money has
 *      moved and the item has not.
 *
 *   3. The item row is locked with SELECT … FOR UPDATE before stock is read.
 *      Two people buying the last of a limited drop serialise on that lock;
 *      the loser reads stock_sold = stock_total and is refused before paying.
 *
 *   4. The buyer's balance is locked by Ledger::spend() the same way, so two
 *      tabs cannot spend the same balance twice.
 *
 *   5. Idempotency is a unique index on (user_id, idempotency_key). A
 *      double-submitted form is one order; the second request reads the first
 *      one back and reports it, rather than charging again.
 *
 *   6. Prices are recomputed here from the catalogue row and the buyer's tier.
 *      Nothing the client sends is trusted except which item and how it is
 *      being paid for.
 *
 * The one thing that CAN still go wrong is the process dying between the
 * commit and the response, which loses the reply but not the state — the order
 * is `granted` and the client's retry with the same key finds it. Anything
 * left `pending` after that is what Console\ReconcileCommand sweeps.
 */
class Purchase
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Catalogue $catalogue,
        protected Entitlements $entitlements,
        protected Registry $grants,
        protected PointsProvider $points,
        protected MockCardProvider $card
    ) {
    }

    /**
     * @param array $opts key, recipient (User|null), provider, card, fault
     * @return array{ok:bool, status:int, order?:array, error?:string, code?:string}
     */
    public function buy(User $buyer, string $sku, array $opts = []): array
    {
        $item = $this->catalogue->find($sku);
        if (!$item) {
            return $this->fail(404, 'no_such_item', resolve('translator')->trans('local-looksmax-store.forum.error.no_such_item'));
        }

        $recipient = $opts['recipient'] ?? $buyer;
        $gift = (int) $recipient->id !== (int) $buyer->id;

        if ($gift && !$item->giftable) {
            return $this->fail(403, 'not_giftable', resolve('translator')->trans('local-looksmax-store.forum.error.not_giftable'));
        }
        if ($gift && $recipient->isGuest()) {
            return $this->fail(404, 'no_such_user', resolve('translator')->trans('local-looksmax-store.forum.error.no_such_user'));
        }

        // The gates are evaluated against whoever RECEIVES the item — a gift of
        // an animated colour to somebody who cannot equip it is a wasted
        // purchase, and telling the buyer that before they pay is the point.
        $tierOfRecipient = $this->catalogue->tierOf($recipient);
        $locked = $this->catalogue->lockReason(
            $item,
            $recipient,
            $tierOfRecipient,
            (int) ($recipient->lifetime_points ?? 0),
            $this->catalogue->ownedSkus((int) $recipient->id),
            $this->catalogue->purchaseCounts((int) $recipient->id),
            $item->stock_total === null ? null : max(0, (int) $item->stock_total - (int) $item->stock_sold)
        );

        if ($locked !== null) {
            // The sentence is for the buyer; the code is for the client, which
            // has to tell "sold out" (stop offering it) from "you already own
            // this" (show it as owned) from "not for your tier" (offer the
            // upgrade).
            //
            // The sentence is a translation now, so the code can no longer be
            // sniffed out of English prose. Three of the four arms compare
            // against the same key Catalogue::lockReason() returned, which is
            // language-proof. The fourth cannot: the tier gate's sentence
            // carries a {tier} parameter, so there is no constant to compare
            // to. That is why lock.min_tier is still hardcoded in
            // Catalogue.php, and why `deferred` proposes lockReason() return a
            // code instead of a sentence — which would delete this whole
            // block.
            $t = resolve('translator');
            $code = match (true) {
                $locked === $t->trans('local-looksmax-store.forum.lock.sold_out') => 'sold_out',
                $locked === $t->trans('local-looksmax-store.forum.lock.owned') => 'owned',
                str_contains($locked, 'or above') => 'tier_required',
                $locked === $t->trans('local-looksmax-store.forum.lock.guest') => 'guest',
                default => 'locked',
            };

            return $this->fail(409, $code, $gift ? str_replace($t->trans('local-looksmax-store.forum.lock.owned'), $t->trans('local-looksmax-store.forum.lock.owned_gift'), $locked) : $locked);
        }

        $provider = ($opts['provider'] ?? null) === 'card' || $item->kind === 'credits' ? $this->card : $this->points;
        $price = $provider->key() === 'card'
            ? (int) ((json_decode((string) $item->payload, true) ?: [])['money'] ?? 0)
            : $this->catalogue->priceFor($item, $this->catalogue->tierOf($buyer));

        $key = substr((string) ($opts['key'] ?? ''), 0, 64) ?: bin2hex(random_bytes(8));

        // ------------------------------------------------------------ order
        $existing = $this->db->table('store_orders')
            ->where('user_id', $buyer->id)->where('idempotency_key', $key)->first();

        if ($existing) {
            return [
                'ok' => $existing->state === 'granted',
                'status' => $existing->state === 'granted' ? 200 : 409,
                'duplicate' => true,
                'order' => $this->orderPayload($existing),
                'error' => $existing->state === 'granted' ? null : ($existing->error ?: resolve('translator')->trans('local-looksmax-store.forum.error.duplicate_submitted')),
                'code' => $existing->state === 'granted' ? null : 'duplicate',
            ];
        }

        try {
            $orderId = (int) $this->db->table('store_orders')->insertGetId([
                'user_id' => (int) $buyer->id,
                'recipient_id' => (int) $recipient->id,
                'item_id' => (int) $item->id,
                'sku' => $item->sku,
                'unit_price' => (int) $item->price,
                'discount' => max(0, (int) $item->price - $price),
                'total' => $price,
                'currency' => $provider->currency(),
                'state' => 'pending',
                'idempotency_key' => $key,
                'provider' => $provider->key(),
                'created_at' => date('Y-m-d H:i:s'),
                'meta' => json_encode(['gift' => $gift]),
            ]);
        } catch (\Throwable $e) {
            // Lost the race with our own double submit: the other request
            // inserted first. Read its order rather than making a second one.
            $other = $this->db->table('store_orders')
                ->where('user_id', $buyer->id)->where('idempotency_key', $key)->first();

            return [
                'ok' => false, 'status' => 409, 'duplicate' => true,
                'order' => $other ? $this->orderPayload($other) : null,
                'error' => resolve('translator')->trans('local-looksmax-store.forum.error.duplicate_processing'), 'code' => 'duplicate',
            ];
        }

        // ------------------------------------------------- money and delivery
        try {
            $result = $this->db->transaction(function () use ($item, $buyer, $recipient, $price, $provider, $orderId, $opts) {
                // Re-read under a row lock. Everything checked before this point
                // was checked outside the lock and is therefore a hint.
                $locked = $this->db->table('store_items')->where('id', $item->id)->lockForUpdate()->first();

                if (!$locked->active) {
                    throw new PurchaseRefused('not_on_sale', resolve('translator')->trans('local-looksmax-store.forum.error.not_on_sale'));
                }
                if ($locked->available_until && strtotime((string) $locked->available_until) < time()) {
                    throw new PurchaseRefused('closed', resolve('translator')->trans('local-looksmax-store.forum.error.closed'));
                }
                if ($locked->stock_total !== null && (int) $locked->stock_sold >= (int) $locked->stock_total) {
                    throw new PurchaseRefused('sold_out', resolve('translator')->trans('local-looksmax-store.forum.error.sold_out'));
                }

                // The max_per_user check Catalogue::lockReason() runs to build
                // the CARD's error message happens BEFORE this transaction
                // even opens, against a plain (unlocked) count — it is a hint
                // for the UI, not a guarantee. Two requests for the same
                // max_per_user=1 SKU that both read that count as zero both
                // reach here; the `lockForUpdate()` on store_items above
                // already SERIALISES them (the second transaction blocks
                // until the first commits or rolls back), but serialised is
                // not the same as re-checked — without asking again down
                // here, the second request wakes up from the lock, sees
                // stock/active/window are still fine, and completes the
                // purchase anyway. Confirmed exploitable before this check
                // existed: two concurrent POST /store/purchase calls for a
                // max_per_user=1 SKU (e.g. tier-vip-lifetime or any `style-*`/
                // `frame-*` cosmetic) both returned 200.
                //
                // `lockForUpdate()` here too, not a plain count: InnoDB's
                // REPEATABLE READ view is otherwise established at this
                // transaction's first read and could still be stale by the
                // time this line runs even though execution is serialised —
                // a locking read is the only one guaranteed to see the other
                // transaction's just-committed row.
                if ((int) $locked->max_per_user > 0) {
                    $already = (int) $this->db->table('store_orders')
                        ->where('recipient_id', $recipient->id)
                        ->where('sku', $locked->sku)
                        ->whereIn('state', ['granted', 'redeemed'])
                        ->lockForUpdate()
                        ->count();

                    if ($already >= (int) $locked->max_per_user) {
                        throw new PurchaseRefused('max_per_user', resolve('translator')->trans('local-looksmax-store.forum.error.max_per_user'));
                    }
                }

                $this->faultPoint($opts, 'before_charge');

                $charge = $provider->charge((int) $buyer->id, $price, 'order:' . $orderId, [
                    'card' => $opts['card'] ?? null,
                ]);

                if (!$charge->ok) {
                    throw new PurchaseRefused($charge->code, $charge->error, $charge->shortfall);
                }

                $this->faultPoint($opts, 'after_charge');

                if ($locked->stock_total !== null) {
                    $this->db->table('store_items')->where('id', $item->id)
                        ->update(['stock_sold' => $this->db->raw('stock_sold + 1')]);
                }

                $payload = json_decode((string) $locked->payload, true) ?: [];
                $granted = $this->grants->for($locked->kind)->apply((int) $recipient->id, $locked, $payload, $orderId);

                $this->faultPoint($opts, 'after_grant');

                $this->db->table('store_orders')->where('id', $orderId)->update([
                    'state' => 'granted',
                    'provider_ref' => $charge->reference,
                    'completed_at' => date('Y-m-d H:i:s'),
                    'meta' => json_encode(['gift' => (int) $recipient->id !== (int) $buyer->id, 'granted' => $granted]),
                ]);

                return $granted;
            });
        } catch (PurchaseRefused $e) {
            // A refusal is not a failure: no money moved, nothing was granted,
            // and the row records why so the same complaint twice is one query.
            $this->db->table('store_orders')->where('id', $orderId)
                ->update(['state' => 'refused', 'error' => substr($e->getMessage(), 0, 255)]);

            return $this->fail($e->reason === 'insufficient_funds' ? 402 : 409, $e->reason, $e->getMessage(), $e->shortfall)
                + ['order' => $this->orderPayload($this->db->table('store_orders')->find($orderId))];
        } catch (\Throwable $e) {
            // The transaction rolled back, so the debit rolled back with it.
            // The order stays as evidence that it was attempted.
            $this->db->table('store_orders')->where('id', $orderId)
                ->update(['state' => 'failed', 'error' => substr($e->getMessage(), 0, 255)]);

            return $this->fail(500, 'failed', $this->humanise($e->getMessage()))
                + ['order' => $this->orderPayload($this->db->table('store_orders')->find($orderId))];
        }

        $order = $this->db->table('store_orders')->find($orderId);

        return [
            'ok' => true,
            'status' => 200,
            'order' => $this->orderPayload($order),
            'granted' => $result,
            'balance' => (int) $this->db->table('users')->where('id', $buyer->id)->value('points'),
            'recipientBalance' => (int) $this->db->table('users')->where('id', $recipient->id)->value('points'),
        ];
    }

    /**
     * Refund an order: money back, entitlements revoked, stock returned.
     *
     * Self-service inside the item's refund window, staff at any time. Refused
     * outright once a charge has been spent — a pin that has already pinned a
     * thread for a day has been consumed, and "use it then refund it" is the
     * first thing anybody tries.
     */
    public function refund(object $order, User $actor, string $note = ''): array
    {
        $staff = $actor->isAdmin() || $actor->hasPermission('store.admin');

        if ($order->state !== 'granted') {
            return $this->fail(409, 'not_refundable', resolve('translator')->trans('local-looksmax-store.forum.error.order_state', ['state' => resolve('translator')->trans('local-looksmax-store.forum.state.' . $order->state)]));
        }
        if (!$staff && (int) $order->user_id !== (int) $actor->id) {
            return $this->fail(403, 'not_yours', resolve('translator')->trans('local-looksmax-store.forum.error.not_your_order'));
        }

        $item = $this->catalogue->find($order->sku);
        $window = (int) ($item->refund_minutes ?? 0);

        if (!$staff) {
            if ($window <= 0) {
                return $this->fail(403, 'no_window', resolve('translator')->trans('local-looksmax-store.forum.error.no_refund_window'));
            }
            if (strtotime((string) $order->completed_at) + $window * 60 < time()) {
                return $this->fail(403, 'window_closed', resolve('translator')->trans('local-looksmax-store.forum.error.refund_window_closed'));
            }
        }

        if ($this->entitlements->partlyUsed((int) $order->id)) {
            return $this->fail(409, 'already_used', resolve('translator')->trans('local-looksmax-store.forum.error.already_used'));
        }

        try {
            $this->db->transaction(function () use ($order, $actor, $note) {
                $rows = $this->db->table('store_entitlements')
                    ->where('order_id', $order->id)->whereNull('revoked_at')->get();

                foreach ($rows as $row) {
                    $this->grants->for($row->kind)->revoke((int) $order->recipient_id, $row);
                }

                $this->entitlements->revokeByOrder((int) $order->id, 'refund');

                $provider = $order->provider === 'card' ? $this->card : $this->points;
                $charge = $provider->refund(
                    (int) $order->user_id,
                    (int) $order->total,
                    'order:' . $order->id,
                    (string) $order->provider_ref
                );

                if (!$charge->ok) {
                    throw new PurchaseRefused($charge->code, $charge->error);
                }

                if ($order->item_id) {
                    $this->db->table('store_items')->where('id', $order->item_id)
                        ->where('stock_sold', '>', 0)
                        ->update(['stock_sold' => $this->db->raw('stock_sold - 1')]);
                }

                $this->db->table('store_orders')->where('id', $order->id)->update([
                    'state' => 'refunded',
                    'refunded_at' => date('Y-m-d H:i:s'),
                ]);

                $this->db->table('store_audit')->insert([
                    'actor_id' => (int) $actor->id,
                    'action' => 'refund',
                    'target_user_id' => (int) $order->user_id,
                    'subject' => 'order:' . $order->id,
                    'before' => json_encode(['state' => $order->state, 'total' => (int) $order->total]),
                    'after' => json_encode(['state' => 'refunded']),
                    'note' => substr($note, 0, 255),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            });
        } catch (PurchaseRefused $e) {
            return $this->fail(409, $e->reason, $e->getMessage());
        }

        return [
            'ok' => true,
            'status' => 200,
            'order' => $this->orderPayload($this->db->table('store_orders')->find($order->id)),
            'balance' => (int) $this->db->table('users')->where('id', $actor->id)->value('points'),
        ];
    }

    public function orderPayload(?object $order): ?array
    {
        if (!$order) {
            return null;
        }

        $item = $this->db->table('store_items')->where('sku', $order->sku)->first();
        $meta = json_decode((string) $order->meta, true) ?: [];

        return [
            'id' => (int) $order->id,
            'sku' => $order->sku,
            'name' => $item->name ?? $order->sku,
            'icon' => $item->icon ?? 'ph:tag-fill',
            'category' => $item->category ?? null,
            'userId' => (int) $order->user_id,
            'recipientId' => (int) $order->recipient_id,
            'gift' => (int) $order->recipient_id !== (int) $order->user_id,
            'listPrice' => (int) $order->unit_price,
            'discount' => (int) $order->discount,
            'total' => (int) $order->total,
            'currency' => $order->currency,
            'state' => $order->state,
            'provider' => $order->provider,
            'error' => $order->error,
            'createdAt' => $order->created_at,
            'completedAt' => $order->completed_at,
            'refundedAt' => $order->refunded_at,
            'granted' => $meta['granted'] ?? null,
            'refundableUntil' => $order->completed_at && ($item->refund_minutes ?? 0) > 0
                ? date('c', strtotime((string) $order->completed_at) + (int) $item->refund_minutes * 60)
                : null,
        ];
    }

    /**
     * Deliberate failure injection, so the rollback path can be proven rather
     * than asserted. Only an admin can trigger it, and it is the only way any
     * of this code throws on purpose.
     */
    private function faultPoint(array $opts, string $point): void
    {
        if (($opts['fault'] ?? null) === $point) {
            throw new \RuntimeException('injected fault at ' . $point);
        }
    }

    private function humanise(string $message): string
    {
        // The str_contains() probes stay in English on purpose: they match
        // internal RuntimeException text thrown by the grant handlers, which is
        // never shown to anybody. Only the RETURN values are user-visible.
        if (str_contains($message, 'already owned')) {
            return resolve('translator')->trans('local-looksmax-store.forum.error.already_owned');
        }
        if (str_contains($message, 'already own everything')) {
            return resolve('translator')->trans(str_contains($message, 'bundle')
                ? 'local-looksmax-store.forum.error.own_everything_bundle'
                : 'local-looksmax-store.forum.error.own_everything_box');
        }
        if (str_contains($message, 'injected fault')) {
            return resolve('translator')->trans('local-looksmax-store.forum.error.injected_fault', ['detail' => $message]);
        }

        return resolve('translator')->trans('local-looksmax-store.forum.error.generic_rollback');
    }

    private function fail(int $status, string $code, string $error, int $shortfall = 0): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'error' => $error, 'shortfall' => $shortfall];
    }
}
