<?php

namespace Local\Store\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Economy\Ledger;
use Symfony\Component\Console\Input\InputOption;

/**
 * The audit that assumes the design is wrong.
 *
 * The purchase path puts payment and grant in one transaction so that a debit
 * without a grant cannot exist. This command exists because "cannot exist" is
 * a claim, and a claim about money should be checked by something that does
 * not share the code it is checking. It walks the ledger and the orders from
 * opposite ends and reports every disagreement:
 *
 *   1. A ledger debit `store.purchase` with ref order:N where order N is not
 *      `granted` — money taken, nothing delivered. Refunded automatically
 *      unless --dry-run.
 *   2. A `granted` order with no matching debit — delivered, never paid for.
 *      Reported, never auto-corrected: taking points back from somebody by
 *      script is not a thing to do without a human reading the list first.
 *   3. Orders stuck `pending` for more than an hour — a process that died
 *      between writing the order and finishing the transaction.
 *   4. Live entitlements whose order is refunded — a revoke that did not run.
 *
 * Run it nightly. An economy that is never audited is one where the first
 * person to find the bug is the one exploiting it.
 */
class ReconcileCommand extends AbstractCommand
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Ledger $ledger
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('store:reconcile')
            ->setDescription('Check the order table against the ledger and repair paid-for-nothing')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'report without repairing');
    }

    protected function fire()
    {
        $dry = (bool) $this->input->getOption('dry-run');
        $problems = 0;

        // 1. debits without a granted order
        $debits = $this->db->table('economy_transactions')
            ->where('reason', 'store.purchase')
            ->where('ref', 'like', 'order:%')
            ->get(['id', 'user_id', 'delta', 'ref']);

        foreach ($debits as $tx) {
            $orderId = (int) substr((string) $tx->ref, 6);
            $order = $this->db->table('store_orders')->find($orderId);

            if ($order && in_array($order->state, ['granted', 'expired', 'refunded'], true)) {
                continue;
            }

            // Already compensated. The pair nets to zero, so the member is whole
            // whether or not the order row still exists — and it may not: an
            // order can be purged while its ledger rows, which are permanent by
            // design, stay. Without this the same four rows are reported as
            // broken every night forever, and a report that always says four is
            // a report nobody reads.
            $compensated = $this->db->table('economy_transactions')
                ->whereIn('reason', ['store.refund', 'store.clawback'])
                ->where('ref', $tx->ref)
                ->exists();

            if ($compensated) {
                continue;
            }

            $problems++;
            $this->error('paid but not granted: order ' . $orderId . ' user ' . $tx->user_id . ' ' . $tx->delta);

            if (!$dry) {
                $this->ledger->credit((int) $tx->user_id, (int) abs($tx->delta), 'store.refund', 'order:' . $orderId, false);
                $this->db->table('store_orders')->where('id', $orderId)
                    ->update(['state' => 'refunded', 'refunded_at' => date('Y-m-d H:i:s'), 'error' => 'auto-refunded by store:reconcile']);
                $this->info('  refunded ' . abs($tx->delta) . ' to user ' . $tx->user_id);
            }
        }

        // 2. granted orders with no debit
        $granted = $this->db->table('store_orders')
            ->where('state', 'granted')->where('currency', 'points')->where('total', '>', 0)
            ->get(['id', 'user_id', 'total']);

        foreach ($granted as $order) {
            $paid = $this->db->table('economy_transactions')
                ->where('reason', 'store.purchase')->where('ref', 'order:' . $order->id)->exists();

            if (!$paid) {
                $problems++;
                $this->error('granted but never paid: order ' . $order->id . ' user ' . $order->user_id . ' ' . $order->total);
            }
        }

        // 3. stuck pending
        $stuck = $this->db->table('store_orders')
            ->where('state', 'pending')
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - 3600))
            ->get(['id', 'user_id', 'sku']);

        foreach ($stuck as $order) {
            $problems++;
            $this->error('stuck pending: order ' . $order->id . ' ' . $order->sku);
            if (!$dry) {
                $this->db->table('store_orders')->where('id', $order->id)
                    ->update(['state' => 'failed', 'error' => 'abandoned; closed by store:reconcile']);
            }
        }

        // 4. entitlements alive under a refunded order
        $orphans = $this->db->table('store_entitlements')
            ->join('store_orders', 'store_orders.id', '=', 'store_entitlements.order_id')
            ->whereNull('store_entitlements.revoked_at')
            ->where('store_orders.state', 'refunded')
            ->get(['store_entitlements.id', 'store_entitlements.sku', 'store_orders.id as order_id']);

        foreach ($orphans as $row) {
            $problems++;
            $this->error('entitlement ' . $row->id . ' (' . $row->sku . ') still live under refunded order ' . $row->order_id);
            if (!$dry) {
                $this->db->table('store_entitlements')->where('id', $row->id)
                    ->update(['revoked_at' => date('Y-m-d H:i:s'), 'note' => 'store:reconcile']);
            }
        }

        $this->info($problems === 0 ? 'clean: orders and ledger agree' : ($problems . ' problems' . ($dry ? ' (dry run)' : ' handled')));
    }
}
