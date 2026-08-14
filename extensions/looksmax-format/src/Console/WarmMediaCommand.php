<?php

namespace Local\Format\Console;

use Flarum\Console\AbstractCommand;
use Local\Format\Media\Fetcher;
use Local\Format\Media\Store;
use Symfony\Component\Console\Input\InputOption;

/**
 * Pre-warm the media cache, most-read content first.
 *
 * -------------------------------------------------------------------------
 * Why warm at all
 * -------------------------------------------------------------------------
 * A cold proxy means the first reader of a popular thread waits on one
 * outbound fetch per image — slow for them, and a burst of requests to the
 * source board at exactly the moment a real person is reading, which is the
 * correlated pattern the proxy exists to avoid. Warming ahead of demand
 * decouples our fetches from our readers entirely.
 *
 * -------------------------------------------------------------------------
 * Ordering is taken from data, not guessed
 * -------------------------------------------------------------------------
 * The scrape DB records what the source board's own readers did. Threads are
 * scored on the axes that actually predict being opened here:
 *
 *   views                      how much attention it got, the dominant signal
 *   sticky                     pinned threads are permanently on the front page
 *   first_post_reaction_score  how good the community thought the OP was
 *   replies                    depth of discussion
 *   last_post_ts               recency, so live threads are not starved by
 *                              years-old giants
 *
 * Images are then warmed thread by thread in that order, so stopping the run
 * at any point still leaves the most-read content covered — the cut-off is a
 * budget, not a correctness boundary.
 *
 * -------------------------------------------------------------------------
 * It does not re-fetch what already exists
 * -------------------------------------------------------------------------
 * 21GB of images are already downloaded by the acquisition lane and hardlinked
 * into the container at public/media/. Every candidate is checked against the
 * media manifest (source url -> local path) and against the proxy's own cache
 * before any request is made, so this is a set difference and not a second
 * downloader competing with the first for the same proxy pool.
 *
 *   php flarum lmx:warm-media --limit=5000 --media-manifest=/path/to.tsv
 */
class WarmMediaCommand extends AbstractCommand
{
    public function __construct(
        private Store $store,
        private Fetcher $fetcher
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('lmx:warm-media')
            ->setDescription('Pre-fetch and cache media for the most-read threads')
            ->addOption('db', null, InputOption::VALUE_REQUIRED,
                'path to the scrape sqlite file', '/flarum/app/data/looksmax-import.db')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED,
                'max images to fetch this run', 2000)
            ->addOption('threads', null, InputOption::VALUE_REQUIRED,
                'how many top threads to consider', 3000)
            ->addOption('media-manifest', null, InputOption::VALUE_REQUIRED,
                'tsv of "source url<TAB>local path" already downloaded; those are skipped')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'report what would be fetched without fetching')
            ->addOption('no-evict', null, InputOption::VALUE_NONE,
                'skip the cache size check at the end');
    }

    protected function fire()
    {
        $path = (string) $this->input->getOption('db');
        if (! file_exists($path)) {
            $this->error("scrape db not found at {$path}");

            return 1;
        }

        $limit = (int) $this->input->getOption('limit');
        $threadCount = (int) $this->input->getOption('threads');
        $dry = (bool) $this->input->getOption('dry-run');

        $have = $this->manifest();

        $src = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $src->exec('PRAGMA query_only = 1');

        $this->info($this->fetcher->usingProxy()
            ? 'outbound: via LMX_MEDIA_PROXY'
            : 'outbound: DIRECT from this host — set LMX_MEDIA_PROXY to route through the pool');

        /*
         * The score is a weighted sum on log-scaled counts. Log, because views
         * span six orders of magnitude and a single 2M-view megathread would
         * otherwise be the entire ordering; the weights are then meaningful
         * against each other instead of being drowned.
         */
        $sql = '
            SELECT t.id, t.title, t.views, t.replies, t.sticky
            FROM threads t
            WHERE EXISTS (SELECT 1 FROM post_images pi
                          JOIN posts p ON p.id = pi.post_id
                          WHERE p.thread_id = t.id)
            ORDER BY (
                  4.0 * (CASE WHEN t.sticky THEN 1 ELSE 0 END)
                + 1.0 * log(1 + COALESCE(t.views, 0))
                + 0.6 * log(1 + COALESCE(t.first_post_reaction_score, 0))
                + 0.4 * log(1 + COALESCE(t.replies, 0))
                + 0.8 * (COALESCE(t.last_post_ts, 0) / 1.0e9)
            ) DESC
            LIMIT '.$threadCount;

        $threads = $src->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        $this->info('considering '.count($threads).' threads by views/sticky/reactions/replies/recency');

        $imgStmt = $src->prepare(
            'SELECT DISTINCT pi.url FROM post_images pi
               JOIN posts p ON p.id = pi.post_id
              WHERE p.thread_id = ?'
        );

        $fetched = 0;
        $skippedHave = 0;
        $skippedManifest = 0;
        $skippedDead = 0;
        $failed = 0;
        $bytes = 0;
        $seen = [];
        $threadsTouched = 0;

        foreach ($threads as $t) {
            if ($fetched >= $limit) {
                break;
            }
            $threadsTouched++;

            $imgStmt->execute([(int) $t['id']]);
            foreach ($imgStmt->fetchAll(\PDO::FETCH_COLUMN) as $url) {
                if ($fetched >= $limit) {
                    break;
                }

                $url = trim((string) $url);
                if ($url === '' || isset($seen[$url]) || ! preg_match('#^https?://#i', $url)) {
                    continue;
                }
                $seen[$url] = true;

                if (isset($have[$url])) {
                    $skippedManifest++;
                    continue;
                }
                if ($this->store->has($url)) {
                    $skippedHave++;
                    continue;
                }
                if ($this->store->isDead($url)) {
                    $skippedDead++;
                    continue;
                }
                if ($dry) {
                    $fetched++;
                    continue;
                }

                $r = $this->fetcher->fetch($url);
                if ($r['ok']) {
                    $this->store->put($url, $r['body'], $r['type']);
                    $bytes += strlen($r['body']);
                    $fetched++;
                } else {
                    // Negative-cached, so a dead image is not re-requested on
                    // every page view or on every warm run.
                    $this->store->putDead($url, $r['status']);
                    $failed++;
                }

                if (($fetched + $failed) % 100 === 0) {
                    $this->info(sprintf(
                        '  %d fetched, %d dead, %s',
                        $fetched, $failed, $this->human($bytes)
                    ));
                }
            }
        }

        $this->info(sprintf(
            'warm: %d fetched (%s) over %d threads, %d already cached, %d already downloaded, '
            .'%d known-dead skipped, %d newly dead, %d distinct urls considered',
            $fetched, $this->human($bytes), $threadsTouched,
            $skippedHave, $skippedManifest, $skippedDead, $failed, count($seen)
        ));

        if (! $this->input->getOption('no-evict') && ! $dry) {
            $e = $this->store->evict();
            $this->info(sprintf(
                'cache: %d files, %s on disk, %d evicted (%s freed)',
                $e['scanned'], $this->human($e['bytes']), $e['evicted'], $this->human($e['freed'])
            ));
        }

        return 0;
    }

    /** Images the acquisition lane has already downloaded; never re-fetch them. */
    private function manifest(): array
    {
        $path = $this->input->getOption('media-manifest');
        if (! $path) {
            $this->info('no --media-manifest: cannot tell which of the 21GB already on disk '
                .'covers these urls, so some may be re-fetched (see HANDOFF-FORMAT.md)');

            return [];
        }
        if (! is_readable((string) $path)) {
            $this->error("media manifest not readable at {$path}");

            return [];
        }

        $have = [];
        $fh = fopen((string) $path, 'r');
        while (($line = fgets($fh)) !== false) {
            $parts = explode("\t", rtrim($line, "\r\n"), 2);
            if (count($parts) === 2) {
                $have[$parts[0]] = true;
            }
        }
        fclose($fh);
        $this->info('media manifest: '.count($have).' urls already downloaded, will be skipped');

        return $have;
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, 1).$units[$i];
    }
}
