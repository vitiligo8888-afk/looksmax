<?php

namespace Local\Cosmetics\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Cosmetics\Defs;
use Symfony\Component\Console\Input\InputOption;

/**
 * Put the shipped definitions into `cosmetic_defs`.
 *
 * Symfony configure()/setName(), NOT a $signature property — a $signature on a
 * Flarum console command throws "cannot have an empty name" at container boot
 * and takes the ENTIRE CLI down with it, migrate included. That has happened on
 * this install.
 *
 * Rows whose `source` is not `shipped` are never touched, so a frame added by
 * hand in SQL survives every deploy. This is the one thing `store:sync` gets
 * wrong: looksmax-store/src/Catalogue.php:76 UPDATEs every descriptive column
 * of every seeded row unconditionally, which is why the visual definitions
 * cannot live in `store_items.payload`.
 */
class SyncCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('cosmetics:sync')
            ->setDescription('Write the shipped cosmetic definitions into cosmetic_defs')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'deactivate shipped rows no longer defined');
    }

    protected function fire()
    {
        $now = date('Y-m-d H:i:s');
        $seen = [];
        $added = $updated = 0;

        foreach (Defs::all() as $row) {
            $key = $row['kind'] . '/' . $row['slug'];
            $seen[$key] = true;

            $record = [
                'sku' => $row['sku'],
                'spec' => json_encode($row['spec'], JSON_UNESCAPED_SLASHES),
                'sort' => (int) $row['sort'],
                'active' => 1,
                'source' => 'shipped',
                'updated_at' => $now,
            ];

            $existing = $this->db->table('cosmetic_defs')
                ->where('kind', $row['kind'])->where('slug', $row['slug'])->first();

            if ($existing === null) {
                $this->db->table('cosmetic_defs')->insert($record + ['kind' => $row['kind'], 'slug' => $row['slug']]);
                $added++;
                continue;
            }

            if ($existing->source !== 'shipped') {
                $this->info('  keep  ' . $key . ' (source=' . $existing->source . ')');
                continue;
            }

            $this->db->table('cosmetic_defs')->where('id', $existing->id)->update($record);
            $updated++;
        }

        $this->info('definitions: ' . $added . ' added, ' . $updated . ' refreshed');

        if ($this->input->getOption('prune')) {
            $n = 0;
            foreach ($this->db->table('cosmetic_defs')->where('source', 'shipped')->get(['id', 'kind', 'slug']) as $r) {
                if (!isset($seen[$r->kind . '/' . $r->slug])) {
                    $this->db->table('cosmetic_defs')->where('id', $r->id)->update(['active' => 0]);
                    $n++;
                }
            }
            $this->info('deactivated: ' . $n);
        }

        // A definition that nobody can wear is worth reporting at sync time
        // rather than discovering as a silent absence on the page.
        $rows = $this->db->table('cosmetic_defs')->where('active', 1)->count();
        $wearers = $this->db->table('cosmetic_loadout')
            ->where(function ($q) {
                $q->whereNotNull('frame')->orWhereNotNull('banner');
            })->count();
        $this->info('active definitions: ' . $rows . ', accounts wearing something: ' . $wearers);
    }
}
