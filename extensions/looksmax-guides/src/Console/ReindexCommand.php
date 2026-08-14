<?php

namespace Local\Guides\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use Local\Guides\Guide\Indexer;
use Symfony\Component\Console\Input\InputOption;

/**
 * Rebuild guide metadata from post content.
 *
 * Needed for two real cases: the imported corpus, where guides arrive as
 * already-written posts that never fired a Posted event, and any change to the
 * extractor, which must be able to re-derive every guide without asking 8,273
 * authors to re-save.
 *
 * Chunked by primary key rather than paginated with OFFSET: at 29.7M posts an
 * OFFSET scan degrades quadratically, and the run has to be resumable from a
 * known id when it is interrupted.
 *
 * NOTE: Flarum 1.x console commands configure themselves the Symfony way, with
 * setName() inside configure(). A Laravel-style $signature property produces
 * "command cannot have an empty name" and takes the whole CLI down with it.
 */
class ReindexCommand extends AbstractCommand
{
    public function __construct(
        protected Indexer $indexer,
        protected ConnectionInterface $db
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('guides:reindex')
            ->setDescription('Rebuild guide_meta and guide_claims from stored post content')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Resume from this post id', '0')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Rows per batch', '500')
            ->addOption('discussion', null, InputOption::VALUE_REQUIRED, 'Reindex one discussion only')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Delete satellite rows whose discussion is gone');
    }

    protected function fire()
    {
        if ($this->input->getOption('prune')) {
            return $this->prune();
        }

        $chunk = max(1, (int) $this->input->getOption('chunk'));
        $lastId = (int) $this->input->getOption('from');
        $only = $this->input->getOption('discussion');

        $scanned = 0;
        $indexed = 0;
        $failed = 0;
        $started = microtime(true);

        while (true) {
            $query = Post::query()
                ->where('type', 'comment')
                ->where('number', 1)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk);

            if ($only) {
                $query->where('discussion_id', (int) $only);
            }

            $posts = $query->get();

            if ($posts->isEmpty()) {
                break;
            }

            foreach ($posts as $post) {
                $lastId = (int) $post->id;
                $scanned++;

                try {
                    if ($this->indexer->indexPost($post)) {
                        $indexed++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error('post ' . $post->id . ': ' . $e->getMessage());
                }
            }

            // Progress on one line rather than a bar: this is run over ssh
            // inside a container where a redrawing bar is just noise in a log.
            $this->info(sprintf(
                'scanned %d, guides %d, failed %d, last id %d, %.1fs',
                $scanned, $indexed, $failed, $lastId, microtime(true) - $started
            ));

            if ($only) {
                break;
            }
        }

        $this->info(sprintf(
            'done: %d posts scanned, %d guides indexed, %d failed in %.1fs',
            $scanned, $indexed, $failed, microtime(true) - $started
        ));

        return $failed > 0 ? 1 : 0;
    }

    /**
     * Drop satellite rows whose discussion no longer exists.
     *
     * The event listeners keep this from happening going forward, but a
     * satellite table with no foreign key will still accumulate orphans from
     * any path that bypasses the event system — a bulk import, a direct SQL
     * delete, a restore from a partial backup. Orphans here are silent: they
     * render nowhere and error nowhere, until the guide library joins them
     * back to `discussions` and lists threads that are gone.
     *
     * Run it periodically. It is cheap and it is the difference between
     * "consistent" and "consistent as far as anyone has checked".
     */
    private function prune(): int
    {
        $meta = $this->db->table('guide_meta')
            ->whereNotExists(function ($q) {
                $q->select($q->raw(1))->from('discussions')
                    ->whereColumn('discussions.id', 'guide_meta.discussion_id');
            })->delete();

        $claims = $this->db->table('guide_claims')
            ->whereNotExists(function ($q) {
                $q->select($q->raw(1))->from('discussions')
                    ->whereColumn('discussions.id', 'guide_claims.discussion_id');
            })->delete();

        $this->info(sprintf('pruned %d orphaned guide_meta rows and %d orphaned guide_claims rows', $meta, $claims));

        return 0;
    }
}
