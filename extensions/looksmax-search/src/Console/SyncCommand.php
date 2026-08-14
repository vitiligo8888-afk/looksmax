<?php

namespace Local\Search\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Search\Meili\Client;
use Local\Search\Meili\Indexer;
use Local\Search\Meili\MeiliException;
use Symfony\Component\Console\Input\InputOption;

/**
 * Drain the outbox — the thing that makes search automatic.
 *
 * Runs from Flarum's scheduler every minute, and can also be run as a daemon
 * (`--daemon`) for sub-second freshness on a busy forum.
 *
 * Failure handling is the interesting part. A failed batch is NOT dropped and
 * NOT retried immediately: `attempts` increments and `available_at` moves out
 * on an exponential backoff, so a single poison document cannot spin the drain
 * at 100% CPU, and a transient engine outage recovers by itself without anyone
 * noticing. The row is only ever removed once the engine has confirmed the task
 * succeeded, which means an interrupted drain re-does work rather than skipping
 * it — the correct direction to be wrong in.
 *
 * `--reconcile` is the answer to the bounded fan-out in QueueIndexChanges: a
 * retitled or retagged discussion leaves its post documents carrying a stale
 * `discussion_title` and stale `tag_ids`. Enqueuing them synchronously would
 * mean a 900-row write on the request that renamed a thread, so instead the
 * reconcile pass sweeps discussions changed since the last run and re-indexes
 * their posts out of band.
 */
class SyncCommand extends AbstractCommand
{
    public function __construct(
        protected Indexer $indexer,
        protected Client $client,
        protected ConnectionInterface $db
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('search:sync')
            ->setDescription('Apply pending index changes')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'rows per pass', 500)
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'max rows per run (0 = drain fully)', 20000)
            ->addOption('daemon', null, InputOption::VALUE_NONE, 'keep running')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'daemon poll seconds', 2)
            ->addOption('reconcile', null, InputOption::VALUE_NONE, 're-index posts of recently changed discussions')
            ->addOption('scores', null, InputOption::VALUE_NONE, 'refresh rank_score on recent discussions');
    }

    protected function fire()
    {
        if ($this->input->getOption('scores')) {
            $n = $this->indexer->refreshScores();
            $this->info("refreshed rank_score on $n discussions");

            return 0;
        }

        if ($this->input->getOption('reconcile')) {
            return $this->reconcile();
        }

        $batch = max(1, (int) $this->input->getOption('batch'));
        $max = (int) $this->input->getOption('max');
        $daemon = (bool) $this->input->getOption('daemon');
        $interval = max(1, (int) $this->input->getOption('interval'));

        $done = 0;
        do {
            // `--max` bounds ONE pass in daemon mode, not the lifetime of the
            // process.
            //
            // Subtracting the running total worked for a one-shot run and was
            // silently fatal as a daemon: after the first 20,000 rows the
            // budget went negative, drain() returned 0 on every subsequent
            // call, and the loop settled into its idle sleep — still running,
            // still `active` to systemd, applying nothing. Measured: 18,486
            // rows applied in the first 60 seconds and then a flat outbox for
            // 20 minutes while the importer kept adding to it.
            //
            // That is the worst shape a failure can take here, because every
            // external signal says healthy. `search:status` caught it only
            // because it reports the AGE of the oldest queued row rather than
            // the queue depth.
            $budget = $max > 0 ? ($daemon ? $max : $max - $done) : PHP_INT_MAX;

            $moved = $this->drain($batch, $budget);
            $done += $moved;
            if ($daemon && $moved === 0) {
                sleep($interval);
            }
        } while ($daemon || ($moved > 0 && ($max === 0 || $done < $max)));

        if ($done) {
            $this->info("applied $done queued change(s)");
        }

        return 0;
    }

    private function drain(int $batch, int $budget): int
    {
        if ($budget <= 0) {
            return 0;
        }

        $rows = $this->db->table('search_index_queue')
            ->where('available_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit(min($batch, $budget))
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        // The discussion-deleted marker is not an object kind; expand it here
        // where a real query is cheap, not on the request that deleted it.
        $expanded = [];
        $markerIds = [];
        foreach ($rows as $r) {
            if ($r->kind === 'posts_of_discussion') {
                $markerIds[] = $r->id;
                $postIds = $this->db->table('posts')->where('discussion_id', $r->object_id)->pluck('id')->all();
                foreach ($postIds as $pid) {
                    $expanded[] = (object) ['id' => null, 'kind' => 'posts', 'object_id' => $pid, 'action' => 'delete'];
                }
                // Posts rows may already be gone from the database (cascade),
                // so also delete by id range is impossible — the engine keeps
                // documents whose rows vanished. This is why the marker exists.
                continue;
            }
            $expanded[] = $r;
        }

        $ids = $rows->pluck('id')->filter()->all();

        try {
            $stats = $this->indexer->applyQueue($expanded);
            $this->db->table('search_index_queue')->whereIn('id', $ids)->delete();
            if ($markerIds) {
                $this->db->table('search_index_queue')->whereIn('id', $markerIds)->delete();
            }
            if ($this->output->isVerbose() && $stats) {
                $this->info('  ' . json_encode($stats));
            }
        } catch (MeiliException $e) {
            // Backoff, do not drop. 2^attempts seconds, capped at 5 minutes.
            $this->db->table('search_index_queue')
                ->whereIn('id', $ids)
                ->update([
                    'attempts' => $this->db->raw('attempts + 1'),
                    'last_error' => mb_substr($e->getMessage(), 0, 900),
                    'available_at' => $this->db->raw("DATE_ADD(NOW(), INTERVAL LEAST(300, POW(2, LEAST(attempts, 8))) SECOND)"),
                ]);
            $this->error('batch failed, backing off: ' . $e->getMessage());

            return 0;
        }

        return $rows->count();
    }

    /**
     * Post documents denormalise their discussion's title and tags. When those
     * change, the posts are stale until this runs.
     */
    private function reconcile(): int
    {
        $since = date('Y-m-d H:i:s', time() - 3600);
        $discussionIds = $this->db->table('discussions')
            ->where('last_posted_at', '>=', $since)
            ->orWhere('created_at', '>=', $since)
            ->pluck('id')
            ->all();

        if (!$discussionIds) {
            $this->info('nothing to reconcile');

            return 0;
        }

        $n = 0;
        foreach (array_chunk($discussionIds, 200) as $chunk) {
            $postIds = $this->db->table('posts')
                ->whereIn('discussion_id', $chunk)
                ->where('type', 'comment')
                ->pluck('id')
                ->all();
            foreach (array_chunk($postIds, 2000) as $pids) {
                $this->indexer->applyQueue(array_map(
                    fn ($id) => (object) ['kind' => 'posts', 'object_id' => $id, 'action' => 'upsert'],
                    $pids
                ));
                $n += count($pids);
            }
        }

        $this->info("reconciled $n post documents across " . count($discussionIds) . ' discussions');

        return 0;
    }
}
