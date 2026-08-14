<?php

namespace Local\Store\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Ranks\Standing;
use Symfony\Component\Console\Input\InputOption;

/**
 * The clock.
 *
 * Everything the store sells with a duration ends here, and nowhere else:
 * memberships lapse, boosts stop counting, pinned threads unpin, highlights
 * come off. Reads are already time-filtered — a boost that ran out stops
 * multiplying the moment its timestamp passes, without this command — so this
 * exists for the effects that changed something OUTSIDE our own tables and
 * therefore have to be actively put back.
 *
 * Idempotent, and safe to run every minute:
 *
 *   * * * * * docker exec flarum-app php /flarum/app/flarum store:expire
 */
class ExpireCommand extends AbstractCommand
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Standing $standing
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('store:expire')
            ->setDescription('End expired memberships, pins, highlights and boosts')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'report without writing');
    }

    protected function fire()
    {
        $dry = (bool) $this->input->getOption('dry-run');
        $now = date('Y-m-d H:i:s');

        // ------------------------------------------------- thread effects
        $effects = $this->db->table('store_discussion_effects')
            ->whereNull('ended_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get();

        foreach ($effects as $e) {
            $restore = json_decode((string) $e->restore, true) ?: [];

            if (!$dry && $e->kind === 'sticky') {
                // Put back exactly what was there. If a moderator pinned it in
                // the meantime the restore value is still 0, so check the
                // current state before unpinning something they wanted pinned.
                $current = $this->db->table('discussions')->where('id', $e->discussion_id)->value('is_sticky');
                if ((int) $current === 1) {
                    $this->db->table('discussions')->where('id', $e->discussion_id)
                        ->update(['is_sticky' => (int) ($restore['is_sticky'] ?? 0)]);
                }
            }

            if (!$dry) {
                $this->db->table('store_discussion_effects')->where('id', $e->id)->update(['ended_at' => $now]);
            }
        }

        $this->info(($dry ? '[dry] ' : '') . 'thread effects ended: ' . count($effects));

        // --------------------------------------------------- memberships
        $lapsed = $this->db->table('identity_memberships')
            ->where('active', 1)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->pluck('user_id')->unique();

        foreach ($lapsed as $userId) {
            if (!$dry) {
                // refreshTier deactivates the row, recomputes the cached tier
                // and reconciles the Flarum group in one pass.
                $this->standing->refreshTier((int) $userId);
            }
        }

        $this->info(($dry ? '[dry] ' : '') . 'memberships lapsed: ' . count($lapsed));

        // ------------------------------------------------------- orders
        // An order whose entitlement has run out reads as `expired` in the
        // buyer's history, so "I paid for a month" has a visible end date
        // rather than silently becoming nothing.
        $expiredOrders = $this->db->table('store_entitlements')
            ->whereNotNull('expires_at')->where('expires_at', '<=', $now)
            ->whereNull('revoked_at')->whereNotNull('order_id')
            ->pluck('order_id')->unique();

        if (!$dry && count($expiredOrders)) {
            $this->db->table('store_orders')
                ->whereIn('id', $expiredOrders)->where('state', 'granted')
                ->update(['state' => 'expired']);
        }

        $this->info(($dry ? '[dry] ' : '') . 'orders marked expired: ' . count($expiredOrders));

        // -------------------------------------------------- timed drops
        $closed = $this->db->table('store_items')
            ->where('active', 1)->whereNotNull('available_until')
            ->where('available_until', '<=', $now)->count();

        if (!$dry && $closed) {
            $this->db->table('store_items')
                ->where('active', 1)->whereNotNull('available_until')
                ->where('available_until', '<=', $now)
                ->update(['active' => 0]);
        }

        $this->info(($dry ? '[dry] ' : '') . 'timed items closed: ' . $closed);
    }
}
