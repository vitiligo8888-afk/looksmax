<?php

namespace Local\Cosmetics;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Equipping. The write side, and the only place this extension changes state.
 *
 * Server-side validation is total. The equip screen greys out what you cannot
 * wear and that is a hint, not a boundary — a POST is a POST, so every call
 * re-derives ownership from the four tables in Ownership before it writes.
 *
 * ── the mirror, and why it exists ──────────────────────────────────────────
 * `users.avatar_frame` predates this extension and looksmax-ranks renders from
 * it in its own DOM decorator (looksmax-ranks/js/dist/forum.js:253). If that
 * column and `cosmetic_loadout.frame` ever disagreed, two renderers would draw
 * two different rings on the same avatar. So every write here sets BOTH, with
 * one rule:
 *
 *   mirror the slug into users.avatar_frame only when looksmax-ranks would
 *   accept it — that is, when Catalog::frame() knows the slug AND the account
 *   has the matching identity_inventory row. Otherwise write NULL there.
 *
 * The second half of that condition is not caution, it is measured behaviour:
 * `identity:sync` NULLs avatar_frame for any slug the account does not own in
 * identity_inventory (looksmax-ranks/src/Console/SyncCommand.php:133-138), so
 * writing a badge-earned frame into that column would be undone on the next
 * sync and the two systems would silently disagree until then.
 *
 * Result: for the eight frames both systems know, they always agree. For the
 * five this extension adds, ranks holds NULL and never draws — which is what it
 * would do anyway — and this renderer covers every avatar on the forum, so
 * nothing is lost.
 */
class Loadout
{
    public const KINDS = ['frame', 'banner'];

    public function __construct(
        protected ConnectionInterface $db,
        protected Definitions $defs,
        protected Ownership $ownership
    ) {
    }

    /**
     * Equip or clear one slot. Returns a translation key on refusal, null on OK.
     */
    public function equip(User $user, string $kind, ?string $slug): ?string
    {
        if (!in_array($kind, self::KINDS, true)) {
            return 'error.unknown_kind';
        }

        $slug = ($slug === null || $slug === '' || $slug === 'none') ? null : $slug;

        if ($slug !== null) {
            $def = $this->defs->find($kind, $slug);
            if ($def === null) {
                return 'error.no_such_item';
            }
            if ($this->ownership->reason(
                (int) $user->id,
                $def,
                $user->tier_slug,
                $user->tier_expires_at ? (string) $user->tier_expires_at : null
            ) === null) {
                return 'error.not_owned';
            }
        }

        $this->write((int) $user->id, $kind, $slug);

        if ($kind === 'frame') {
            $this->mirrorFrame((int) $user->id, $slug);
        }

        Ownership::flush();

        return null;
    }

    /** Upsert one column of one row without disturbing the other slot. */
    private function write(int $userId, string $kind, ?string $slug): void
    {
        $now = date('Y-m-d H:i:s');
        $exists = $this->db->table('cosmetic_loadout')->where('user_id', $userId)->exists();

        if ($exists) {
            $this->db->table('cosmetic_loadout')->where('user_id', $userId)
                ->update([$kind => $slug, 'updated_at' => $now]);

            return;
        }

        // First row for this account. Seed the OTHER slot from `users.avatar_frame`
        // so that somebody whose frame came from a store purchase does not lose
        // it the first time they pick a banner — before this row existed, that
        // column was what Ownership::loadout() read.
        $legacy = $kind === 'frame' ? null : ($this->db->table('users')->where('id', $userId)->value('avatar_frame') ?: null);

        $this->db->table('cosmetic_loadout')->insert([
            'user_id' => $userId,
            'frame' => $kind === 'frame' ? $slug : $legacy,
            'banner' => $kind === 'banner' ? $slug : null,
            'updated_at' => $now,
        ]);
    }

    private function mirrorFrame(int $userId, ?string $slug): void
    {
        $mirror = null;

        if ($slug !== null && class_exists(\Local\Ranks\Catalog::class)) {
            try {
                $known = \Local\Ranks\Catalog::frame($slug) !== null;
            } catch (\Throwable $e) {
                $known = false;
            }

            if ($known) {
                $inInventory = $this->db->table('identity_inventory')
                    ->where('user_id', $userId)->where('type', 'frame')->where('item', $slug)
                    ->exists();
                if ($inInventory) {
                    $mirror = $slug;
                }
            }
        }

        // Reading looksmax-ranks' column, writing looksmax-ranks' column — but
        // never its TABLES. identity_inventory and identity_badges are read-only
        // from here, by instruction and because ownership is not ours to invent.
        $this->db->table('users')->where('id', $userId)->update(['avatar_frame' => $mirror]);
    }
}
