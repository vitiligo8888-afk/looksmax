<?php

namespace Local\Economy\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Economy\Ledger;
use Symfony\Component\Console\Input\InputOption;

/**
 * Rebuild balances from the ledger.
 *
 * The cached points column on users is an optimisation; the transactions table
 * is the truth. After an import, a bulk revoke, or any bug in an award path,
 * this reconciles the two rather than leaving people to argue about numbers.
 */
class RecomputeCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db, protected Ledger $ledger)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('economy:recompute')
            ->setDescription('Rebuild user points and ranks from the transaction ledger')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'report drift without writing');
    }

    protected function fire()
    {
        $dry = (bool) $this->input->getOption('dry-run');

        $totals = $this->db->table('economy_transactions')
            ->selectRaw('user_id, SUM(delta) AS points, SUM(GREATEST(delta,0)) AS lifetime')
            ->groupBy('user_id')
            ->get();

        $drift = 0;
        foreach ($totals as $row) {
            $current = (int) $this->db->table('users')->where('id', $row->user_id)->value('points');
            if ($current !== (int) $row->points) {
                $drift++;
            }
            if ($dry) {
                continue;
            }
            $this->db->table('users')->where('id', $row->user_id)->update([
                'points' => (int) $row->points,
                'lifetime_points' => (int) $row->lifetime,
                // rank follows the lifetime record, not the spendable balance
                'rank_slug' => $this->ledger->rankFor((int) $row->lifetime)['slug'],
            ]);
        }

        // anyone with no ledger rows must read as zero, not as a stale balance
        if (!$dry) {
            $this->db->table('users')
                ->whereNotIn('id', $totals->pluck('user_id')->all() ?: [0])
                ->update(['points' => 0, 'lifetime_points' => 0, 'rank_slug' => $this->ledger->ranks()[0]['slug']]);
        }

        $this->info(($dry ? 'would fix ' : 'reconciled ') . $drift . ' of ' . $totals->count() . ' accounts');
        return 0;
    }
}
