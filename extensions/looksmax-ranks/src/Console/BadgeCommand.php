<?php

namespace Local\Ranks\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Economy\Ledger;
use Local\Ranks\Badges\Engine;
use Local\Ranks\Catalog;
use Symfony\Component\Console\Input\InputOption;

/**
 * Evaluate every badge against the whole population.
 *
 * The design constraint that shapes this: a badge must be recomputable. Every
 * criterion is a pure function of stored data, awards are idempotent, and
 * `--rebuild` wipes and re-derives to exactly the same set. That is what makes
 * a trophy something you can point at rather than something to argue about, and
 * it is why nothing here is hand-granted.
 *
 *   php flarum identity:badges           # award anything newly satisfied
 *   php flarum identity:badges --rebuild # wipe and re-derive from scratch
 *   php flarum identity:badges --dry-run # count what would be awarded
 */
class BadgeCommand extends AbstractCommand
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Engine $engine,
        protected Ledger $ledger
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('identity:badges')
            ->setDescription('Award badges from measured criteria and refresh their rarity')
            ->addOption('rebuild', null, InputOption::VALUE_NONE, 'delete every badge and re-derive')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'count what would be awarded, write nothing')
            ->addOption('showcase', null, InputOption::VALUE_NONE, 'auto-fill empty trophy cases with the rarest badges held');
    }

    protected function fire()
    {
        $dry = (bool) $this->input->getOption('dry-run');

        if ($this->input->getOption('rebuild') && !$dry) {
            $n = $this->db->table('identity_badges')->count();
            $this->db->table('identity_badges')->delete();
            // the ledger rows stay: a rebuild must not double-pay, and
            // badge.earned is idempotent on (user, reason, ref) anyway
            $this->info("cleared {$n} badge rows (ledger credits left in place; they are idempotent)");
        }

        $credited = 0;
        $credit = function (int $userId, string $slug, int $points) use ($dry, &$credited): int {
            if ($dry) {
                return $points;
            }
            $written = $this->ledger->credit($userId, $points, 'badge.earned', 'badge:' . $slug);
            $credited += $written;

            return $written;
        };

        if ($dry) {
            // count without writing, by running the same checks the engine does
            [$awarded, $points] = $this->countOnly();
            $this->info("would award {$awarded} badges worth " . number_format($points) . ' points');

            return 0;
        }

        $t0 = microtime(true);
        [$awarded, $points] = $this->engine->run($credit, function (string $slug, int $n) {
            $this->output->write('.');
        });
        $this->output->writeln('');

        $rarities = $this->engine->rarities();
        $this->db->table('settings')->updateOrInsert(
            ['key' => 'identity.badge_rarity'],
            ['value' => json_encode($rarities)]
        );

        $this->info(sprintf('awarded %d badges in %.1fs, credited %s points',
            $awarded, microtime(true) - $t0, number_format($credited)));

        if ($this->input->getOption('showcase')) {
            $this->autoShowcase($rarities);
        }

        $this->report($rarities);

        return 0;
    }

    /**
     * Fill empty trophy cases with the three rarest badges a user actually
     * holds. An empty showcase on a profile reads as "this feature is broken",
     * and nobody with 1,400 imported accounts is going to curate by hand.
     * Anyone who sets their own showcase overrides this and is never touched
     * again, because this only ever writes where nothing is showcased.
     */
    private function autoShowcase(array $rarities): void
    {
        $filled = 0;
        $has = $this->db->table('identity_badges')->where('showcased', 1)->distinct()->pluck('user_id')->all();
        $flip = array_flip($has);

        $rows = $this->db->table('identity_badges')->get(['user_id', 'badge']);
        $byUser = [];
        foreach ($rows as $r) {
            if (isset($flip[$r->user_id])) {
                continue;
            }
            $byUser[$r->user_id][] = $r->badge;
        }

        foreach ($byUser as $uid => $badges) {
            usort($badges, fn ($a, $b) => ($rarities[$a] ?? 100) <=> ($rarities[$b] ?? 100));
            foreach (array_slice($badges, 0, 3) as $i => $slug) {
                $this->db->table('identity_badges')
                    ->where('user_id', $uid)->where('badge', $slug)
                    ->update(['showcased' => 1, 'slot' => $i]);
            }
            $filled++;
        }

        $this->info("auto-filled {$filled} empty trophy cases");
    }

    private function report(array $rarities): void
    {
        $counts = $this->db->table('identity_badges')->groupBy('badge')
            ->selectRaw('badge, COUNT(*) c')->pluck('c', 'badge');

        $this->info('');
        $this->info(sprintf('%-16s %-20s %7s %8s', 'slug', 'name', 'held', 'rarity'));
        foreach (Catalog::BADGES as $b) {
            $this->info(sprintf('%-16s %-20s %7d %7.2f%%',
                $b['slug'], $b['name'], (int) ($counts[$b['slug']] ?? 0), $rarities[$b['slug']] ?? 0));
        }

        $total = (int) $this->db->table('identity_badges')->count();
        $holders = (int) $this->db->table('identity_badges')->distinct()->count('user_id');
        $this->info('');
        $this->info("{$total} badges held by {$holders} accounts");
    }

    private function countOnly(): array
    {
        $existing = [];
        foreach ($this->db->table('identity_badges')->get(['user_id', 'badge']) as $r) {
            $existing[$r->user_id . '|' . $r->badge] = true;
        }

        $banners = $this->engine->bannerFlags();
        $awarded = 0;
        $points = 0;

        foreach (Catalog::BADGES as $badge) {
            if ($badge['check'] === 'banner') {
                $values = [];
                foreach ($banners as $uid => $flags) {
                    if (in_array($badge['arg'], $flags, true)) {
                        $values[$uid] = 1;
                    }
                }
                $threshold = 1;
            } else {
                $values = method_exists($this->engine, $badge['check']) ? $this->engine->{$badge['check']}() : [];
                $threshold = max(1, (int) $badge['arg']);
            }

            foreach ($values as $uid => $v) {
                if ($v >= $threshold && !isset($existing[$uid . '|' . $badge['slug']])) {
                    $awarded++;
                    $points += (int) $badge['points'];
                }
            }
        }

        return [$awarded, $points];
    }
}
