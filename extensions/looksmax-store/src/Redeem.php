<?php

namespace Local\Store;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Spending a consumable.
 *
 * Buying a pin and using a pin are separate acts, because the buyer has to
 * pick a thread and might not have written it yet. That separation is also
 * what makes the perk safe: the charge is spent here, under a conditional SQL
 * decrement, and the effect it produces carries its own expiry and the value
 * it has to put back when it ends.
 *
 * Every effect is written to store_discussion_effects with a `restore` blob.
 * Un-pinning after 24 hours must not un-pin a thread a moderator had already
 * pinned for their own reasons, and the only way to know the difference is to
 * have recorded what the column said before we touched it.
 */
class Redeem
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Entitlements $entitlements
    ) {
    }

    public function highlight(User $user, int $discussionId): array
    {
        $d = $this->ownThread($user, $discussionId);
        if (isset($d['error'])) {
            return $d;
        }

        $ent = $this->entitlements->consume((int) $user->id, 'highlight');
        if (!$ent) {
            return ['ok' => false, 'status' => 403, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.no_highlight')];
        }

        $days = (int) ($ent->payload['days'] ?? 3);
        $variant = (string) ($ent->payload['variant'] ?? 'gold');
        $expires = date('Y-m-d H:i:s', time() + $days * 86400);

        $this->db->table('store_discussion_effects')->insert([
            'discussion_id' => $discussionId,
            'user_id' => (int) $user->id,
            'order_id' => $ent->order_id,
            'kind' => 'highlight',
            'variant' => $variant,
            'restore' => null,
            'started_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expires,
        ]);

        return ['ok' => true, 'status' => 200, 'kind' => 'highlight', 'expiresAt' => $expires, 'discussionId' => $discussionId];
    }

    public function sticky(User $user, int $discussionId): array
    {
        $d = $this->ownThread($user, $discussionId);
        if (isset($d['error'])) {
            return $d;
        }

        $row = $this->db->table('discussions')->where('id', $discussionId)->first();
        if ((int) ($row->is_sticky ?? 0) === 1) {
            return ['ok' => false, 'status' => 409, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.already_pinned')];
        }

        $ent = $this->entitlements->consume((int) $user->id, 'sticky');
        if (!$ent) {
            return ['ok' => false, 'status' => 403, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.no_pin')];
        }

        $hours = (int) ($ent->payload['hours'] ?? 24);
        $expires = date('Y-m-d H:i:s', time() + $hours * 3600);

        $this->db->transaction(function () use ($discussionId, $user, $ent, $expires, $row) {
            $this->db->table('discussions')->where('id', $discussionId)->update(['is_sticky' => 1]);
            $this->db->table('store_discussion_effects')->insert([
                'discussion_id' => $discussionId,
                'user_id' => (int) $user->id,
                'order_id' => $ent->order_id,
                'kind' => 'sticky',
                'restore' => json_encode(['is_sticky' => (int) ($row->is_sticky ?? 0)]),
                'started_at' => date('Y-m-d H:i:s'),
                'expires_at' => $expires,
            ]);
        });

        return ['ok' => true, 'status' => 200, 'kind' => 'sticky', 'expiresAt' => $expires, 'discussionId' => $discussionId];
    }

    public function bump(User $user, int $discussionId): array
    {
        $d = $this->ownThread($user, $discussionId);
        if (isset($d['error'])) {
            return $d;
        }

        $row = $this->db->table('discussions')->where('id', $discussionId)->first();

        // One bump per thread per week, regardless of how many were bought.
        // Without it the perk is "buy the front page", which is the failure
        // mode every board with a paid bump has hit.
        $recent = $this->db->table('store_discussion_effects')
            ->where('discussion_id', $discussionId)->where('kind', 'bump')
            ->where('started_at', '>', date('Y-m-d H:i:s', time() - 7 * 86400))
            ->exists();

        if ($recent) {
            return ['ok' => false, 'status' => 429, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.bumped_this_week')];
        }

        $ent = $this->entitlements->consume((int) $user->id, 'bump');
        if (!$ent) {
            return ['ok' => false, 'status' => 403, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.no_bump')];
        }

        $this->db->transaction(function () use ($discussionId, $user, $ent, $row) {
            $this->db->table('discussions')->where('id', $discussionId)
                ->update(['last_posted_at' => date('Y-m-d H:i:s')]);

            $this->db->table('store_discussion_effects')->insert([
                'discussion_id' => $discussionId,
                'user_id' => (int) $user->id,
                'order_id' => $ent->order_id,
                'kind' => 'bump',
                'restore' => json_encode(['last_posted_at' => $row->last_posted_at ?? null]),
                'started_at' => date('Y-m-d H:i:s'),
                'expires_at' => null,
                'ended_at' => date('Y-m-d H:i:s'),
            ]);
        });

        return ['ok' => true, 'status' => 200, 'kind' => 'bump', 'discussionId' => $discussionId];
    }

    public function rename(User $user, string $newName): array
    {
        $newName = trim($newName);

        if (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $newName)) {
            return ['ok' => false, 'status' => 422, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.name_rules')];
        }
        if (strcasecmp($newName, (string) $user->username) === 0) {
            return ['ok' => false, 'status' => 422, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.same_name')];
        }
        if ($this->db->table('users')->where('username', $newName)->exists()) {
            return ['ok' => false, 'status' => 409, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.name_taken')];
        }

        $ent = $this->entitlements->consume((int) $user->id, 'rename');
        if (!$ent) {
            return ['ok' => false, 'status' => 403, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.no_rename')];
        }

        $old = (string) $user->username;

        $this->db->transaction(function () use ($user, $newName, $old, $ent) {
            $this->db->table('users')->where('id', $user->id)->update(['username' => $newName]);
            $this->db->table('store_audit')->insert([
                'actor_id' => (int) $user->id,
                'action' => 'rename',
                'target_user_id' => (int) $user->id,
                'subject' => 'entitlement:' . $ent->id,
                'before' => json_encode(['username' => $old]),
                'after' => json_encode(['username' => $newName]),
                'note' => 'bought username change',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        });

        return ['ok' => true, 'status' => 200, 'kind' => 'rename', 'username' => $newName, 'previous' => $old];
    }

    /** Effects currently live on a set of discussions, for the listing. */
    public function effectsFor(array $discussionIds): array
    {
        if (!$discussionIds) {
            return [];
        }

        $rows = $this->db->table('store_discussion_effects')
            ->whereIn('discussion_id', $discussionIds)
            ->whereNull('ended_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->discussion_id][] = ['kind' => $r->kind, 'variant' => $r->variant, 'expiresAt' => $r->expires_at];
        }

        return $out;
    }

    private function ownThread(User $user, int $discussionId): array
    {
        $d = $this->db->table('discussions')->where('id', $discussionId)->first();

        if (!$d) {
            return ['ok' => false, 'status' => 404, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.no_such_thread')];
        }
        if ((int) $d->user_id !== (int) $user->id && !$user->isAdmin()) {
            return ['ok' => false, 'status' => 403, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.not_your_thread')];
        }
        if ($d->hidden_at) {
            return ['ok' => false, 'status' => 409, 'error' => resolve('translator')->trans('local-looksmax-store.forum.redeem.thread_deleted')];
        }

        return ['ok' => true];
    }
}
