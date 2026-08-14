<?php

namespace Local\Reactions\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;

/**
 * Report the state of the reaction tables, and check the invariants that the
 * schema cannot.
 *
 *   php flarum lmx:reactions:status
 *
 * Exists because "the deploy worked" and "the data is right" are different
 * claims, and a reaction system whose counts have quietly drifted looks
 * perfectly healthy from the outside.
 */
class RecountCommand extends AbstractCommand
{
    protected $signature = 'lmx:reactions:status';

    public function __construct(private ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('lmx:reactions:status')
            ->setDescription('Report reaction table state and check invariants');
    }

    protected function fire(): int
    {
        $t = fn (string $x) => (int) $this->db->table($x)->count();

        $this->info(sprintf(
            "reactions            %d (%d enabled)\n" .
            "post_reactions       %d native, over %d posts, by %d users\n" .
            "legacy_post_reactions %d rows (%d with an exact count)\n" .
            "legacy_post_totals   %d posts, %d total imported score\n" .
            "post_likes           %d (flarum/likes, disabled; left in place for looksmax-userinfo)",
            $t('reactions'),
            (int) $this->db->table('reactions')->where('enabled', true)->count(),
            $t('post_reactions'),
            (int) $this->db->table('post_reactions')->distinct()->count('post_id'),
            (int) $this->db->table('post_reactions')->distinct()->count('user_id'),
            $t('legacy_post_reactions'),
            (int) $this->db->table('legacy_post_reactions')->where('exact', true)->count(),
            $t('legacy_post_totals'),
            (int) $this->db->table('legacy_post_totals')->sum('score'),
            $this->db->getSchemaBuilder()->hasTable('post_likes') ? $t('post_likes') : -1
        ));

        $this->output->writeln("\n  per reaction:");
        $rows = $this->db->table('reactions as r')
            ->leftJoin('post_reactions as pr', 'pr.reaction_id', '=', 'r.id')
            ->leftJoin('legacy_post_reactions as l', 'l.reaction_id', '=', 'r.id')
            ->groupBy('r.id', 'r.slug', 'r.grp', 'r.enabled', 'r.position')
            ->orderBy('r.position')
            ->select('r.slug', 'r.grp', 'r.enabled', 'r.position')
            ->selectRaw('COUNT(DISTINCT pr.id) as native')
            ->selectRaw('COUNT(DISTINCT l.post_id) as legacy_posts')
            ->get();
        foreach ($rows as $r) {
            $this->output->writeln(sprintf(
                '    %-14s %-9s pos %-4d %s  native %-7d legacy-posts %d',
                $r->slug, $r->grp, $r->position, $r->enabled ? 'on ' : 'OFF',
                $r->native, $r->legacy_posts
            ));
        }

        // Invariants the schema cannot state.
        $bad = 0;

        $orphanLegacy = (int) $this->db->table('legacy_post_reactions as l')
            ->leftJoin('legacy_post_totals as t', 't.post_id', '=', 'l.post_id')
            ->whereNull('t.post_id')->count();
        if ($orphanLegacy) {
            $this->error("  ! $orphanLegacy legacy type rows on posts with no recorded total");
            $bad++;
        }

        $exactMismatch = (int) $this->db->table('legacy_post_reactions as l')
            ->join('legacy_post_totals as t', 't.post_id', '=', 'l.post_id')
            ->where('l.exact', true)
            ->whereColumn('l.count', '!=', 't.score')
            ->count();
        if ($exactMismatch) {
            $this->error("  ! $exactMismatch rows claim an exact count that differs from the post total");
            $bad++;
        }

        $multiExact = (int) $this->db->table('legacy_post_reactions')
            ->select('post_id')->where('exact', true)
            ->groupBy('post_id')->havingRaw('COUNT(*) > 1')->get()->count();
        if ($multiExact) {
            $this->error("  ! $multiExact posts have more than one 'exact' type — "
                . 'exactness is only derivable when a post drew exactly one type');
            $bad++;
        }

        $this->output->writeln($bad ? "\n  $bad invariant(s) violated" : "\n  invariants ok");

        return $bad ? 1 : 0;
    }
}
