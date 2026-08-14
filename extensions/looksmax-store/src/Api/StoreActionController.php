<?php

namespace Local\Store\Api;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Economy\Ledger;
use Local\Store\Catalogue;
use Local\Store\Purchase;
use Local\Store\Redeem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Write side.
 *
 * Nothing here trusts a number from the client. The price, the discount, the
 * recipient's eligibility and the stock are all re-derived server-side; the
 * body says which SKU and, for a gift, to whom.
 *
 * The one client-supplied value that matters is `key`, the idempotency key. A
 * browser that submits twice — double click, retried fetch, flaky connection
 * — sends the same key twice and gets one purchase and one answer.
 */
class StoreActionController implements RequestHandlerInterface
{
    public function __construct(
        protected Purchase $purchase,
        protected Redeem $redeem,
        protected Catalogue $catalogue,
        protected Ledger $ledger,
        protected ConnectionInterface $db
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $action = (string) ($request->getAttribute('routeParameters')['action'] ?? '');
        $body = (array) $request->getParsedBody();

        if ($actor->isGuest()) {
            return new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.error.sign_in')], 401);
        }

        try {
            return match ($action) {
                'purchase' => $this->buy($actor, $body),
                'refund' => $this->refundOrder($actor, $body),
                'redeem' => $this->redeemItem($actor, $body),
                'admin' => $this->admin($actor, $body),
                default => new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.error.unknown_action')], 404),
            };
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => resolve('translator')->trans('local-looksmax-store.forum.error.generic'),
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    private function buy(User $actor, array $body): ResponseInterface
    {
        $sku = (string) ($body['sku'] ?? '');
        $recipient = $actor;

        if (!empty($body['gift'])) {
            $name = trim((string) $body['gift']);
            $found = User::where('username', $name)->first();
            if (!$found) {
                return new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.error.no_account_named', ['name' => $name]), 'code' => 'no_such_user'], 404);
            }
            $recipient = $found;
        }

        $fault = null;
        if (!empty($body['fault']) && $actor->isAdmin()) {
            // Deliberate rollback, admin only. This is how the "charged for
            // nothing" branch is proven to be impossible rather than asserted.
            $fault = (string) $body['fault'];
        }

        $result = $this->purchase->buy($actor, $sku, [
            'key' => (string) ($body['key'] ?? ''),
            'recipient' => $recipient,
            'provider' => $body['provider'] ?? null,
            'card' => $body['card'] ?? null,
            'fault' => $fault,
        ]);

        $status = $result['status'];
        unset($result['status']);

        return new JsonResponse($result, $status);
    }

    private function refundOrder(User $actor, array $body): ResponseInterface
    {
        $order = $this->db->table('store_orders')->where('id', (int) ($body['order'] ?? 0))->first();
        if (!$order) {
            return new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.error.no_such_order')], 404);
        }

        $result = $this->purchase->refund($order, $actor, (string) ($body['note'] ?? ''));
        $status = $result['status'];
        unset($result['status']);

        return new JsonResponse($result, $status);
    }

    private function redeemItem(User $actor, array $body): ResponseInterface
    {
        $kind = (string) ($body['kind'] ?? '');
        $result = match ($kind) {
            'highlight' => $this->redeem->highlight($actor, (int) ($body['discussion'] ?? 0)),
            'sticky' => $this->redeem->sticky($actor, (int) ($body['discussion'] ?? 0)),
            'bump' => $this->redeem->bump($actor, (int) ($body['discussion'] ?? 0)),
            'rename' => $this->redeem->rename($actor, (string) ($body['username'] ?? '')),
            default => ['ok' => false, 'status' => 404, 'error' => resolve('translator')->trans('local-looksmax-store.forum.error.nothing_to_redeem')],
        };

        $status = $result['status'];
        unset($result['status']);

        return new JsonResponse($result, $status);
    }

    // ------------------------------------------------------------------ admin

    /**
     * Catalogue and balance administration.
     *
     * Every mutation writes a store_audit row with the before and after value.
     * An economy where staff can move balances without a trail is an economy
     * where the first accusation of favouritism cannot be answered.
     */
    private function admin(User $actor, array $body): ResponseInterface
    {
        if (!$actor->isAdmin() && !$actor->hasPermission('store.admin')) {
            return new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.error.not_allowed')], 403);
        }

        $op = (string) ($body['op'] ?? '');

        return match ($op) {
            'item.save' => $this->saveItem($actor, $body),
            'item.toggle' => $this->toggleItem($actor, $body),
            'item.delete' => $this->deleteItem($actor, $body),
            'balance.adjust' => $this->adjustBalance($actor, $body),
            'sync' => $this->syncCatalogue($actor),
            default => new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.error.unknown_operation')], 404),
        };
    }

    private function saveItem(User $actor, array $body): ResponseInterface
    {
        $sku = trim((string) ($body['sku'] ?? ''));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,58}[a-z0-9]$/', $sku)) {
            return new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.admin.sku_rules')], 422);
        }

        $before = $this->db->table('store_items')->where('sku', $sku)->first();

        $fields = [
            'name' => substr(trim((string) ($body['name'] ?? $sku)), 0, 120),
            'blurb' => substr(trim((string) ($body['blurb'] ?? '')), 0, 255),
            'category' => substr((string) ($body['category'] ?? 'misc'), 0, 30),
            'kind' => substr((string) ($body['kind'] ?? 'boost'), 0, 24),
            'payload' => json_encode(is_array($body['payload'] ?? null) ? $body['payload'] : (json_decode((string) ($body['payload'] ?? '{}'), true) ?: [])),
            'price' => max(0, (int) ($body['price'] ?? 0)),
            'rarity' => substr((string) ($body['rarity'] ?? 'common'), 0, 16),
            'icon' => substr((string) ($body['icon'] ?? 'ph:tag-fill'), 0, 60),
            'color' => $body['color'] ? substr((string) $body['color'], 0, 16) : null,
            'sort' => (int) ($body['sort'] ?? 100),
            'active' => !empty($body['active']),
            'giftable' => !empty($body['giftable']),
            'discountable' => !empty($body['discountable']),
            'max_per_user' => max(0, (int) ($body['max_per_user'] ?? 0)),
            'stock_total' => ($body['stock_total'] ?? '') === '' || $body['stock_total'] === null ? null : max(0, (int) $body['stock_total']),
            'min_tier' => $body['min_tier'] ?: null,
            'min_rank' => $body['min_rank'] ?: null,
            'requires_sku' => $body['requires_sku'] ?: null,
            'available_from' => $body['available_from'] ?: null,
            'available_until' => $body['available_until'] ?: null,
            'duration_days' => ($body['duration_days'] ?? '') === '' || $body['duration_days'] === null ? null : (int) $body['duration_days'],
            'uses' => ($body['uses'] ?? '') === '' || $body['uses'] === null ? null : (int) $body['uses'],
            'refund_minutes' => max(0, (int) ($body['refund_minutes'] ?? 0)),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($before) {
            $this->db->table('store_items')->where('sku', $sku)->update($fields);
        } else {
            $this->db->table('store_items')->insert($fields + ['sku' => $sku, 'created_at' => date('Y-m-d H:i:s')]);
        }

        $after = $this->db->table('store_items')->where('sku', $sku)->first();
        $this->audit($actor, $before ? 'item.update' : 'item.create', null, 'item:' . $sku, (array) $before, (array) $after);

        return new JsonResponse(['ok' => true, 'item' => (array) $after]);
    }

    private function toggleItem(User $actor, array $body): ResponseInterface
    {
        $sku = (string) ($body['sku'] ?? '');
        $row = $this->db->table('store_items')->where('sku', $sku)->first();
        if (!$row) {
            return new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.admin.no_such_item')], 404);
        }

        $active = !$row->active;
        $this->db->table('store_items')->where('sku', $sku)->update(['active' => $active, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->audit($actor, 'item.toggle', null, 'item:' . $sku, ['active' => (bool) $row->active], ['active' => $active]);

        return new JsonResponse(['ok' => true, 'active' => $active]);
    }

    /**
     * Retiring, not deleting.
     *
     * A catalogue row that orders point at cannot be removed without orphaning
     * a purchase history, so "delete" deactivates and renames the SKU out of
     * the way only when nothing has ever been sold.
     */
    private function deleteItem(User $actor, array $body): ResponseInterface
    {
        $sku = (string) ($body['sku'] ?? '');
        $sold = $this->db->table('store_orders')->where('sku', $sku)->exists();

        if ($sold) {
            $this->db->table('store_items')->where('sku', $sku)->update(['active' => false]);
            $this->audit($actor, 'item.retire', null, 'item:' . $sku, [], []);

            return new JsonResponse(['ok' => true, 'retired' => true, 'note' => resolve('translator')->trans('local-looksmax-store.forum.admin.retired_note')]);
        }

        $this->db->table('store_items')->where('sku', $sku)->delete();
        $this->audit($actor, 'item.delete', null, 'item:' . $sku, [], []);

        return new JsonResponse(['ok' => true, 'deleted' => true]);
    }

    private function adjustBalance(User $actor, array $body): ResponseInterface
    {
        $name = trim((string) ($body['username'] ?? ''));
        $delta = (int) ($body['delta'] ?? 0);
        $note = substr(trim((string) ($body['note'] ?? '')), 0, 255);

        if ($delta === 0) {
            return new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.admin.nothing_to_adjust')], 422);
        }
        if ($note === '') {
            return new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.admin.reason_required')], 422);
        }

        $target = User::where('username', $name)->first();
        if (!$target) {
            return new JsonResponse(['error' => resolve('translator')->trans('local-looksmax-store.forum.error.no_account_named', ['name' => $name])], 404);
        }

        $before = (int) $target->points;

        // countsForRank false: staff must not be able to hand somebody a rank.
        // The ladder is the one number in this system that cannot be given.
        $this->ledger->credit(
            (int) $target->id,
            $delta,
            'admin.adjust',
            'adj:' . $actor->id . ':' . microtime(true),
            false
        );

        $after = (int) $this->db->table('users')->where('id', $target->id)->value('points');

        $this->audit($actor, 'balance.adjust', (int) $target->id, 'user:' . $target->username,
            ['points' => $before], ['points' => $after, 'delta' => $delta], $note);

        return new JsonResponse(['ok' => true, 'username' => $target->username, 'before' => $before, 'after' => $after]);
    }

    private function syncCatalogue(User $actor): ResponseInterface
    {
        $result = $this->catalogue->sync();
        $this->audit($actor, 'catalogue.sync', null, null, [], $result);

        return new JsonResponse(['ok' => true] + $result);
    }

    private function audit(User $actor, string $action, ?int $target, ?string $subject, array $before, array $after, string $note = ''): void
    {
        $this->db->table('store_audit')->insert([
            'actor_id' => (int) $actor->id,
            'action' => $action,
            'target_user_id' => $target,
            'subject' => $subject,
            'before' => json_encode($before),
            'after' => json_encode($after),
            'note' => $note,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
