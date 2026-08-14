<?php

namespace Local\Search\Console;

use Flarum\Console\AbstractCommand;
use Local\Search\Meili\Client;
use Local\Search\Meili\Indexer;
use Local\Search\Meili\MeiliException;
use Symfony\Component\Console\Input\InputOption;

/**
 * Full and partial reindex.
 *
 * `--from` makes a reindex resumable: a run that dies at discussion 1.4M is
 * restarted from there rather than from zero, which at this corpus size is the
 * difference between a retry and a lost afternoon. The id watermark is printed
 * on every progress line for exactly that reason.
 */
class IndexCommand extends AbstractCommand
{
    public function __construct(
        protected Indexer $indexer,
        protected Client $client
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('search:index')
            ->setDescription('Build the search index from the database')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'discussions|posts|users|tags (comma separated)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'resume from this id', 0)
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'rows per database chunk', 2000)
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'delete the indexes first')
            ->addOption('settings-only', null, InputOption::VALUE_NONE, 'create indexes and push settings, index nothing');
    }

    protected function fire()
    {
        try {
            $health = $this->client->health();
        } catch (MeiliException $e) {
            $this->error('engine unreachable at ' . $this->client->host() . ': ' . $e->getMessage());
            $this->error(json_encode($e->context(), JSON_PRETTY_PRINT));

            return 1;
        }
        $this->info('engine ' . $this->client->host() . ' — ' . ($health['status'] ?? '?'));
        if (!$this->client->hasKey()) {
            $this->error('no master key configured; refusing to index into an unauthenticated engine');

            return 1;
        }

        $only = $this->input->getOption('only');
        $kinds = $only ? array_map('trim', explode(',', $only)) : ['tags', 'users', 'discussions', 'posts'];
        $chunk = max(100, (int) $this->input->getOption('chunk'));
        $from = (int) $this->input->getOption('from');

        if ($this->input->getOption('fresh')) {
            foreach ($kinds as $kind) {
                $uid = $this->indexer->index($kind);
                if ($this->client->indexExists($uid)) {
                    $this->info("dropping $uid");
                    $task = $this->client->deleteIndex($uid);
                    $this->client->waitForTask((int) $task['taskUid']);
                }
            }
        }

        foreach ($this->indexer->ensureIndexes() as $uid => $state) {
            $this->info("  $uid: $state");
        }

        if ($this->input->getOption('settings-only')) {
            return 0;
        }

        $startedAll = microtime(true);
        foreach ($kinds as $kind) {
            $started = microtime(true);
            $last = 0;
            $progress = function (int $done, int $lastId, int $n) use (&$last, $kind, $started) {
                $last = $lastId;
                $elapsed = microtime(true) - $started;
                if ($elapsed > 0) {
                    $this->output->write(sprintf(
                        "\r  %-12s %9d docs  id<=%-10d %7.0f docs/s  %5.0fs",
                        $kind, $done, $lastId, $done / max(0.001, $elapsed), $elapsed
                    ));
                }
            };

            try {
                $n = match ($kind) {
                    'discussions' => $this->indexer->reindexDiscussions($progress, $chunk, $from ?: null),
                    'posts' => $this->indexer->reindexPosts($progress, $chunk, $from ?: null),
                    'users' => $this->indexer->reindexUsers($progress, $chunk, $from ?: null),
                    'tags' => $this->indexer->reindexTags(),
                    default => throw new \InvalidArgumentException("unknown kind: $kind"),
                };
                // A 202 from the engine is not an index. This is what turns
                // "accepted" into "searchable", and it is where a failed batch
                // surfaces instead of being reported as success.
                $this->indexer->waitAll();
                $this->output->writeln('');
                $this->info(sprintf(
                    '  %s: %d documents in %.1fs (%.0f docs/s)',
                    $kind, $n, microtime(true) - $started, $n / max(0.001, microtime(true) - $started)
                ));
            } catch (MeiliException $e) {
                $this->output->writeln('');
                $this->error("  $kind FAILED at id<=$last: " . $e->getMessage());
                $this->error('  resume with: php flarum search:index --only=' . $kind . ' --from=' . max(0, $last - $chunk));
                $this->error('  ' . json_encode($e->context()));

                return 1;
            }
        }

        $this->info(sprintf('done in %.1fs', microtime(true) - $startedAll));
        $stats = $this->indexer->stats();
        foreach ($stats['indexes'] as $uid => $s) {
            if ($s) {
                $this->info(sprintf(
                    '  %-20s %9d docs  indexing=%s',
                    $uid, $s['numberOfDocuments'] ?? 0, ($s['isIndexing'] ?? false) ? 'yes' : 'no'
                ));
            }
        }
        if (isset($stats['databaseSize'])) {
            $this->info(sprintf('  engine on disk: %.1f MB', $stats['databaseSize'] / 1048576));
        }

        return 0;
    }
}
