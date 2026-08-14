<?php

namespace Local\Search\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Search\Meili\Client;
use Local\Search\Meili\Indexer;
use Local\Search\Meili\IndexSettings;
use Local\Search\Meili\MeiliException;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum search:status` — the same picture as the admin panel, from a
 * shell, with a non-zero exit code when something is wrong.
 *
 * The exit code is the point. This is what a health check, a deploy gate or a
 * cron alert can call: a human reading a dashboard notices an engine that is
 * down, and nobody at all notices an index that is 6% short or a filterable
 * attribute that silently never got applied.
 *
 * `--diff` prints the settings drift in full rather than as a count, because
 * "3 settings differ" is not actionable and "filterableAttributes: live is
 * missing `has_best_answer`" is.
 */
class StatusCommand extends AbstractCommand
{
    public function __construct(
        protected Client $client,
        protected Indexer $indexer,
        protected ConnectionInterface $db
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('search:status')
            ->setDescription('Report engine health, index coverage, queue backlog and settings drift')
            ->addOption('diff', null, InputOption::VALUE_NONE, 'print full settings drift')
            ->addOption('json', null, InputOption::VALUE_NONE, 'machine-readable output')
            ->addOption('min-coverage', null, InputOption::VALUE_REQUIRED,
                'fail if any index is below this percent of its table', 95)
            ->addOption('max-backlog-age', null, InputOption::VALUE_REQUIRED,
                'fail if the oldest queued row is older than this many seconds', 300);
    }

    protected function fire()
    {
        $problems = [];
        $report = ['host' => $this->client->host(), 'indexes' => [], 'problems' => []];

        try {
            $health = $this->client->health();
            $version = $this->client->version();
        } catch (MeiliException $e) {
            $this->error('engine UNREACHABLE at ' . $this->client->host());
            $this->error('  ' . $e->getMessage());
            $this->error('  ' . json_encode($e->context()));

            return 2;
        }

        $this->info(sprintf(
            'engine %s — %s, meilisearch %s',
            $this->client->host(), $health['status'] ?? '?', $version['pkgVersion'] ?? '?'
        ));
        if (!$this->client->hasKey()) {
            $problems[] = 'no master key configured (engine is unauthenticated)';
        }

        $stats = $this->client->stats();
        $rows = [
            'discussions' => (int) $this->db->table('discussions')->count(),
            'posts' => (int) $this->db->table('posts')->where('type', 'comment')->count(),
            'users' => (int) $this->db->table('users')->count(),
            'tags' => (int) $this->db->table('tags')->count(),
        ];
        $minCoverage = (float) $this->input->getOption('min-coverage');

        foreach (IndexSettings::KINDS as $kind) {
            $uid = $this->indexer->index($kind);
            $s = $stats['indexes'][$uid] ?? null;
            $docs = (int) ($s['numberOfDocuments'] ?? 0);
            $n = $rows[$kind] ?? 0;
            $cov = $n > 0 ? $docs / $n * 100 : null;

            $this->info(sprintf(
                '  %-18s %10s docs / %10s rows  %6s  %s',
                $uid,
                number_format($docs),
                number_format($n),
                $cov === null ? '   —  ' : sprintf('%5.1f%%', $cov),
                ($s['isIndexing'] ?? false) ? 'INDEXING' : ''
            ));

            $report['indexes'][$uid] = ['documents' => $docs, 'rows' => $n, 'coverage' => $cov];

            if ($s === null) {
                $problems[] = "$uid does not exist — run: php flarum search:index";
            } elseif ($cov !== null && $cov < $minCoverage) {
                $problems[] = sprintf('%s coverage %.1f%% (%d of %d) — reindex needed', $uid, $cov, $docs, $n);
            }
        }

        if (isset($stats['databaseSize'])) {
            $this->info(sprintf(
                '  engine on disk: %.1f MB used of %.1f MB allocated',
                ($stats['usedDatabaseSize'] ?? 0) / 1048576,
                $stats['databaseSize'] / 1048576
            ));
        }

        // ------------------------------------------------------------- queue
        try {
            $pending = (int) $this->db->table('search_index_queue')->count();
            $oldest = $this->db->table('search_index_queue')->min('created_at');
            $age = $oldest ? max(0, time() - strtotime((string) $oldest)) : 0;
            $this->info(sprintf('  outbox: %d pending, oldest %s',
                $pending, $oldest ? $age . 's old' : '—'));
            $report['queue'] = ['pending' => $pending, 'oldestAgeSeconds' => $age];

            $maxAge = (int) $this->input->getOption('max-backlog-age');
            if ($pending > 0 && $age > $maxAge) {
                // A backlog COUNT is not a symptom; a backlog that is not
                // draining is. The age is what distinguishes them.
                $problems[] = sprintf(
                    'outbox oldest row is %ds old (limit %ds) — the sync worker is not draining',
                    $age, $maxAge
                );
            }
        } catch (\Throwable $e) {
            $problems[] = 'outbox unreadable: ' . $e->getMessage() . ' (migrations run?)';
        }

        // ---------------------------------------------------------- failures
        $failed = $this->client->tasks(['statuses' => 'failed', 'limit' => 5]);
        if (($failed['total'] ?? 0) > 0) {
            $problems[] = ($failed['total']) . ' failed engine task(s)';
            foreach ($failed['results'] ?? [] as $t) {
                $this->error(sprintf('  task %s (%s on %s): %s',
                    $t['uid'] ?? '?', $t['type'] ?? '?', $t['indexUid'] ?? '?',
                    $t['error']['message'] ?? '?'));
            }
        }

        // ------------------------------------------------------------- drift
        $diff = $this->indexer->settingsDiff();
        if ($diff) {
            $problems[] = 'settings drift on ' . implode(', ', array_keys($diff));
            if ($this->input->getOption('diff')) {
                $this->output->writeln(json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                foreach ($diff as $uid => $keys) {
                    $this->error("  $uid: " . implode(', ', array_keys($keys)));
                }
                $this->error('  run with --diff for values, or `search:index --settings-only` to reapply');
            }
        }
        $report['problems'] = $problems;

        if ($this->input->getOption('json')) {
            $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT));
        }

        if ($problems) {
            $this->output->writeln('');
            foreach ($problems as $p) {
                $this->error('PROBLEM: ' . $p);
            }

            return 1;
        }

        $this->info('all green');

        return 0;
    }
}
