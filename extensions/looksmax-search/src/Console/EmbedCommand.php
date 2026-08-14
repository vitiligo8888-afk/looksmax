<?php

namespace Local\Search\Console;

use Flarum\Settings\SettingsRepositoryInterface;
use Local\Search\Meili\Client;
use Local\Search\Meili\Embedder;
use Local\Search\Meili\Indexer;
use Local\Search\Meili\MeiliException;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php flarum search:embed` — everything about the vector side of the index.
 *
 * Uses Symfony's `configure()`/`setName()`, NOT a `$signature` property.
 * Flarum 1.8 builds its console application by instantiating every registered
 * command; a `$signature` on a Flarum `AbstractCommand` leaves the Symfony name
 * empty and the resulting "command cannot have an empty name" throw takes down
 * the ENTIRE CLI, not just this command. That has already happened once in this
 * stack.
 *
 * The backfill is not implemented here as a loop over documents, and that is
 * deliberate. With a `rest` embedder, Meilisearch owns embedding: it calls the
 * backend itself whenever a document's rendered template changes. So `--apply`
 * installs the embedder and Meilisearch does the backfill, the incremental
 * updates, and the retries — one mechanism instead of two that can disagree
 * about which documents are current. What this command adds is what
 * Meilisearch does not give you: a preflight that fails BEFORE you enqueue a
 * 48,000-document task, and a progress view while it runs.
 */
class EmbedCommand extends AbstractCommand
{
    public function __construct(
        protected Client $client,
        protected Indexer $indexer,
        protected Embedder $embedder,
        protected SettingsRepositoryInterface $settings
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('search:embed')
            ->setDescription('Configure, backfill and inspect the semantic (vector) index')
            ->addOption('status', null, InputOption::VALUE_NONE, 'report embedder configuration and vector coverage')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'install the embedder on the discussions index (triggers a full backfill)')
            ->addOption('remove', null, InputOption::VALUE_NONE, 'remove the embedder and every stored vector')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'turn semantic search on for queries')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'turn semantic search off for queries (vectors are kept)')
            ->addOption('watch', null, InputOption::VALUE_NONE, 'follow backfill progress until it finishes')
            ->addOption('probe', null, InputOption::VALUE_NONE, 'measure embedding throughput against the live backend')
            ->addOption('wire', null, InputOption::VALUE_REQUIRED, 'tei | openai')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'embedding endpoint URL')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'model id (openai wire only)')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'API key for the embedding endpoint')
            ->addOption('dimensions', null, InputOption::VALUE_REQUIRED, 'vector dimensions the model returns')
            ->addOption('cap', null, InputOption::VALUE_REQUIRED, 'characters of text per document to embed')
            ->addOption('ratio', null, InputOption::VALUE_REQUIRED, 'default semantic ratio for an ordinary query')
            ->addOption('json', null, InputOption::VALUE_NONE, 'machine-readable output');
    }

    protected function fire()
    {
        foreach (['wire', 'url', 'model', 'key', 'dimensions', 'cap', 'ratio'] as $opt) {
            $v = $this->input->getOption($opt);
            if ($v !== null && $v !== '') {
                $this->settings->set('looksmax-search.embed.' . $opt, $v);
                $this->info("set looksmax-search.embed.$opt = " . ($opt === 'key' ? str_repeat('*', 8) : $v));
            }
        }

        if ($this->input->getOption('enable')) {
            $this->settings->set('looksmax-search.embed.enabled', '1');
            $this->info('semantic search ENABLED for queries');
        }
        if ($this->input->getOption('disable')) {
            $this->settings->set('looksmax-search.embed.enabled', '');
            $this->info('semantic search DISABLED for queries (stored vectors kept)');
        }

        if ($this->input->getOption('probe')) {
            return $this->probe();
        }

        if ($this->input->getOption('remove')) {
            return $this->remove();
        }

        if ($this->input->getOption('apply')) {
            return $this->apply();
        }

        return $this->status();
    }

    /**
     * Measure, do not assume.
     *
     * Embedding throughput on a shared box is not a property of the model, it
     * is a property of the model AND whatever else is running. This reports
     * what is true right now, at three text lengths, because the cost is
     * strongly super-linear in length and the document cap is the single
     * biggest lever available.
     */
    private function probe(): int
    {
        $samples = [
            'short (~60 chars, a title)' => 60,
            'default (the configured cap)' => $this->embedder->textCap(),
            'long (2x the cap)' => $this->embedder->textCap() * 2,
        ];

        $base = 'como recuperarse de una rinoplastia y cuanto tiempo dura la inflamacion '
            . 'jaw surgery recovery timeline swelling week by week what to expect after '
            . 'double jaw surgery bimax orthognathic recovery diet and pain management '
            . 'plus mewing and hard chewing routines for gonial angle definition and more ';

        $this->info('embedding backend: ' . $this->embedder->url() . ' (' . $this->embedder->wire() . ')');

        $health = $this->embedder->health();
        if (!($health['ok'] ?? false)) {
            $this->error('backend unhealthy: ' . json_encode($health));

            return 1;
        }
        $this->info('  measured dimensions: ' . $health['measuredDimensions']
            . ' (configured ' . $health['configuredDimensions'] . ')');

        $rows = [];
        foreach ($samples as $label => $len) {
            $text = mb_substr(str_repeat($base, (int) ceil($len / mb_strlen($base)) + 1), 0, $len);
            foreach ([1, 32] as $batch) {
                $texts = array_fill(0, $batch, $text);
                $started = microtime(true);
                try {
                    $this->embedder->embed($texts);
                } catch (MeiliException $e) {
                    $this->error('  probe failed: ' . $e->getMessage());

                    return 1;
                }
                $elapsed = microtime(true) - $started;
                $rows[] = [$label, $len, $batch, round($batch / $elapsed, 1), round($elapsed * 1000, 1)];
            }
        }

        $this->info('');
        $this->info(sprintf('%-30s %6s %6s %10s %10s', 'text', 'chars', 'batch', 'docs/s', 'ms'));
        foreach ($rows as $r) {
            $this->info(sprintf('%-30s %6d %6d %10.1f %10.1f', $r[0], $r[1], $r[2], $r[3], $r[4]));
        }

        $status = $this->indexer->embedStatus();
        $docs = (int) ($status['documents'] ?? 0);
        $rate = 0.0;
        foreach ($rows as $r) {
            if ($r[1] === $this->embedder->textCap() && $r[2] === 32) {
                $rate = $r[3];
            }
        }
        if ($rate > 0 && $docs > 0) {
            $this->info('');
            $this->info(sprintf(
                'full backfill of %s discussions at %.1f docs/s ≈ %s',
                number_format($docs), $rate, $this->duration((int) ($docs / $rate))
            ));
            $this->info(sprintf(
                'vector storage ≈ %.1f MB (%d dims x 4 bytes x %s docs)',
                $docs * $this->embedder->dimensions() * 4 / 1048576,
                $this->embedder->dimensions(), number_format($docs)
            ));
        }

        return 0;
    }

    private function apply(): int
    {
        // Preflight. Enqueuing a 48,000-document embedding task against a
        // backend that is not answering, or that returns a different number of
        // dimensions than the index expects, wastes the whole run AND blocks
        // the post index behind it while it fails.
        $health = $this->embedder->health();
        if (!($health['ok'] ?? false)) {
            $this->error('refusing to apply: embedding backend is not healthy');
            $this->error('  ' . json_encode($health));

            return 1;
        }
        $this->info('preflight OK — ' . $health['measuredDimensions'] . ' dimensions from ' . $health['url']
            . ' in ' . $health['ms'] . 'ms');

        $before = $this->indexer->embedStatus();
        $docs = (int) ($before['documents'] ?? 0);
        $this->info(sprintf('applying embedder "%s" to %s (%s documents)',
            $this->embedder->name(), $before['index'], number_format($docs)));
        $this->info('NOTE: Meilisearch re-embeds every document whose rendered template changed.');
        $this->info('      Its scheduler runs one batch at a time, so post indexing waits behind this.');

        $res = $this->indexer->applyEmbedder(false);
        $this->info('enqueued task ' . $res['taskUid']);

        if ($this->input->getOption('watch')) {
            return $this->watch((int) $res['taskUid'], $docs);
        }

        $this->info('run with --watch to follow, or `php flarum search:embed --status`');

        return 0;
    }

    private function watch(int $taskUid, int $docs): int
    {
        $started = microtime(true);
        $lastEmbedded = 0;
        while (true) {
            sleep(10);
            try {
                $task = $this->client->task($taskUid);
                $status = $this->indexer->embedStatus();
            } catch (MeiliException $e) {
                $this->error('watch: ' . $e->getMessage());

                return 1;
            }

            $embedded = (int) ($status['embeddedDocuments'] ?? 0);
            $elapsed = microtime(true) - $started;
            $rate = $elapsed > 0 ? ($embedded - $lastEmbedded) / 10 : 0;
            $this->info(sprintf(
                '[%6.0fs] task=%s  embedded %s/%s (%.1f%%)  %.1f docs/s',
                $elapsed, $task['status'] ?? '?', number_format($embedded),
                number_format($docs), $status['coveragePct'] ?? 0, $rate
            ));
            $lastEmbedded = $embedded;

            if (in_array($task['status'] ?? '', ['succeeded', 'failed', 'canceled'], true)) {
                if ($task['status'] !== 'succeeded') {
                    $this->error('task ' . $task['status'] . ': ' . json_encode($task['error'] ?? null));

                    return 1;
                }
                $this->info(sprintf('done in %s — coverage %.2f%%',
                    $this->duration((int) $elapsed), $status['coveragePct'] ?? 0));

                return 0;
            }
        }
    }

    private function remove(): int
    {
        $res = $this->indexer->applyEmbedder(true);
        $this->info('removed embedder "' . $res['embedder'] . '" from ' . $res['index']
            . ' (task ' . $res['taskUid'] . ')');
        $this->info('every stored vector on that index is deleted; re-applying means a full re-embed.');

        return 0;
    }

    private function status(): int
    {
        $status = $this->indexer->embedStatus();

        if ($this->input->getOption('json')) {
            $this->info(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($status['backend']['ok'] ?? false) ? 0 : 1;
        }

        $this->info('embedder      : ' . $status['name'] . ' (' . ($status['enabled'] ? 'ENABLED' : 'disabled') . ' for queries)');
        $this->info('wire / url    : ' . $status['wire'] . ' ' . $status['url']);
        if ($status['model']) {
            $this->info('model         : ' . $status['model']);
        }
        $this->info('dimensions    : ' . $status['dimensions']);
        $this->info('text cap      : ' . $status['textCap'] . ' chars');
        $this->info('default ratio : ' . $status['defaultRatio']);
        $this->info('on index      : ' . implode(', ', $status['configured'] ?: ['(none)']));
        $this->info(sprintf('coverage      : %s / %s documents embedded (%.2f%%)',
            number_format($status['embeddedDocuments'] ?? 0),
            number_format($status['documents'] ?? 0),
            $status['coveragePct'] ?? 0));
        $this->info('indexing now  : ' . (($status['isIndexing'] ?? false) ? 'yes' : 'no'));

        $backend = $status['backend'];
        if ($backend['ok'] ?? false) {
            $this->info(sprintf('backend       : OK, %d dims in %.1fms', $backend['measuredDimensions'], $backend['ms']));
        } else {
            $this->error('backend       : FAILING — ' . ($backend['error'] ?? 'dimension mismatch'));

            return 1;
        }

        if (($status['enabled'] ?? false) && ($status['coveragePct'] ?? 0) < 99) {
            $this->error('WARNING: semantic search is on but ' . round(100 - ($status['coveragePct'] ?? 0), 2)
                . '% of discussions have no vector — those threads cannot be found semantically.');

            return 1;
        }

        return 0;
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 90) {
            return $seconds . 's';
        }
        if ($seconds < 5400) {
            return round($seconds / 60, 1) . ' min';
        }

        return round($seconds / 3600, 1) . ' h';
    }
}
