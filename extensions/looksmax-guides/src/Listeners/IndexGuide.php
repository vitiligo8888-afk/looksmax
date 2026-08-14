<?php

namespace Local\Guides\Listeners;

use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Local\Guides\Guide\Indexer;
use Psr\Log\LoggerInterface;

/**
 * Keeps guide_meta in step with the post it describes.
 *
 * Wrapped in a catch because this runs inside the post-save transaction: a bug
 * in extraction must degrade the guide metadata, never stop a member posting.
 * The log line is the evidence trail — silently swallowing would mean the
 * metadata quietly stops updating and nobody finds out for weeks.
 */
class IndexGuide
{
    public function __construct(
        protected Indexer $indexer,
        protected LoggerInterface $log
    ) {
    }

    public function handlePosted(Posted $event): void
    {
        $this->run(fn () => $this->indexer->indexPost($event->post), $event->post->id);
    }

    public function handleRevised(Revised $event): void
    {
        $this->run(fn () => $this->indexer->indexPost($event->post), $event->post->id);
    }

    public function handleDeleted(Deleted $event): void
    {
        if ((int) $event->post->number !== 1) {
            return;
        }

        $this->run(
            fn () => $this->indexer->clear((int) $event->post->discussion_id),
            $event->post->id
        );
    }

    /**
     * Deleting a discussion does not dispatch a post-deleted event per post,
     * so without this the satellite rows outlive the thing they describe.
     *
     * Found the honest way: after a verification run tore down its fixture
     * discussions, `select count(*) from guide_meta` still returned 4 and
     * guide_claims 24. Orphans in a satellite table are quiet — nothing
     * renders them, nothing errors — right up until the guide library joins
     * them back to `discussions` and starts listing threads that no longer
     * exist.
     */
    public function handleDiscussionDeleted(DiscussionDeleted $event): void
    {
        $this->run(
            fn () => $this->indexer->clear((int) $event->discussion->id),
            'discussion:' . $event->discussion->id
        );
    }

    private function run(callable $fn, $postId): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->log->warning('[guides] indexing failed for post ' . $postId . ': ' . $e->getMessage());
        }
    }
}
