<?php

namespace Local\Search\Listeners;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;

/**
 * Model events become outbox rows.
 *
 * Subscribed to Eloquent's `saved`/`deleted` on the models themselves rather
 * than to Flarum's domain events. Domain events are the tidier API, but they
 * are incomplete for indexing purposes: `Flarum\Post\Event\Revised` fires for a
 * user edit and not for a moderator hiding a post, `Renamed` fires for a title
 * change and not for a tag change, and nothing at all fires when another
 * extension writes to a model directly — which is exactly what our own import
 * and analytics extensions do. Eloquent's model events fire for every write
 * that goes through a model, whoever made it, so the index cannot silently
 * miss a class of change.
 *
 * ## Fan-out, and why it is bounded
 *
 * A post changing invalidates its discussion's document too — the excerpt, the
 * comment count, the last-post time and the rank score all live there. But a
 * discussion changing does NOT invalidate its posts individually; the post
 * documents carry a denormalised `discussion_title` and `tag_ids`, and a
 * retitle of a 900-post thread must not enqueue 900 rows on the request that
 * renamed it. Those are reconciled by `search:sync --reconcile`, which is why
 * that mode exists.
 *
 * ## Never break a write
 *
 * Every path is wrapped. Failing to record an indexing intent must degrade
 * search, never fail the post the reader just spent ten minutes writing.
 */
class QueueIndexChanges
{
    public function __construct(
        private ConnectionInterface $db,
        private LoggerInterface $log
    ) {
    }

    public function subscribe($events): void
    {
        $events->listen(
            ['eloquent.saved: Flarum\Post\Post', 'eloquent.saved: Flarum\Post\CommentPost'],
            fn ($event, $models = null) => $this->onPost($models ?? $event)
        );
        $events->listen(
            ['eloquent.deleted: Flarum\Post\Post', 'eloquent.deleted: Flarum\Post\CommentPost'],
            fn ($event, $models = null) => $this->onPost($models ?? $event, true)
        );
        $events->listen(
            'eloquent.saved: Flarum\Discussion\Discussion',
            fn ($event, $models = null) => $this->onDiscussion($models ?? $event)
        );
        $events->listen(
            'eloquent.deleted: Flarum\Discussion\Discussion',
            fn ($event, $models = null) => $this->onDiscussion($models ?? $event, true)
        );
        $events->listen(
            'eloquent.saved: Flarum\User\User',
            fn ($event, $models = null) => $this->enqueueModel('users', $models ?? $event)
        );
        $events->listen(
            'eloquent.deleted: Flarum\User\User',
            fn ($event, $models = null) => $this->enqueueModel('users', $models ?? $event, true)
        );
        $events->listen(
            ['eloquent.saved: Flarum\Tags\Tag', 'eloquent.deleted: Flarum\Tags\Tag'],
            fn ($event, $models = null) => $this->enqueueModel('tags', $models ?? $event, str_contains((string) $event, 'deleted'))
        );

        // Tagging is a pivot write: no model event fires on the discussion, but
        // its document's tag facets are now wrong. Flarum's own domain event is
        // the only signal here.
        $events->listen(
            \Flarum\Discussion\Event\Renamed::class,
            fn ($event) => $this->enqueue('discussions', $event->discussion->id ?? null)
        );
        if (class_exists(\Flarum\Tags\Event\DiscussionWasTagged::class)) {
            $events->listen(
                \Flarum\Tags\Event\DiscussionWasTagged::class,
                fn ($event) => $this->enqueue('discussions', $event->discussion->id ?? null)
            );
        }

        // A reaction changes rank_score, and rank_score is the tie-break that
        // decides ordering between equally relevant threads. Cheap to enqueue,
        // and collapsed by the unique key when a post gets forty likes.
        $events->listen(
            ['eloquent.created: Flarum\Likes\*', 'eloquent.deleted: Flarum\Likes\*'],
            fn () => null
        );
        if (class_exists(\Flarum\Likes\Event\PostWasLiked::class)) {
            foreach ([\Flarum\Likes\Event\PostWasLiked::class, \Flarum\Likes\Event\PostWasUnliked::class] as $e) {
                $events->listen($e, function ($event) {
                    $post = $event->post ?? null;
                    if ($post) {
                        $this->enqueue('posts', $post->id);
                        $this->enqueue('discussions', $post->discussion_id);
                    }
                });
            }
        }
    }

    private function onPost($model, bool $deleted = false): void
    {
        try {
            $post = is_array($model) ? Arr::first($model) : $model;
            if (!is_object($post) || !isset($post->id)) {
                return;
            }
            // Only comments are indexed; a "user joined" event post is noise.
            if (($post->type ?? 'comment') !== 'comment' && !$deleted) {
                return;
            }
            $this->enqueue('posts', $post->id, $deleted ? 'delete' : 'upsert');
            if (isset($post->discussion_id)) {
                $this->enqueue('discussions', $post->discussion_id);
            }
        } catch (\Throwable $e) {
            $this->log->warning('search: failed to enqueue post', ['error' => $e->getMessage()]);
        }
    }

    private function onDiscussion($model, bool $deleted = false): void
    {
        try {
            $d = is_array($model) ? Arr::first($model) : $model;
            if (!is_object($d) || !isset($d->id)) {
                return;
            }
            $this->enqueue('discussions', $d->id, $deleted ? 'delete' : 'upsert');
            if ($deleted) {
                // Post documents outlive their discussion otherwise, and would
                // keep matching queries while pointing at a 404. Recorded as a
                // single reconcile marker instead of N delete rows.
                $this->enqueue('discussions', $d->id, 'delete');
                $this->db->table('search_index_queue')->insertOrIgnore([
                    'kind' => 'posts_of_discussion',
                    'object_id' => $d->id,
                    'action' => 'delete',
                    'created_at' => date('Y-m-d H:i:s'),
                    'available_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            $this->log->warning('search: failed to enqueue discussion', ['error' => $e->getMessage()]);
        }
    }

    private function enqueueModel(string $kind, $model, bool $deleted = false): void
    {
        try {
            $m = is_array($model) ? Arr::first($model) : $model;
            if (is_object($m) && isset($m->id)) {
                $this->enqueue($kind, $m->id, $deleted ? 'delete' : 'upsert');
            }
        } catch (\Throwable $e) {
            $this->log->warning('search: failed to enqueue ' . $kind, ['error' => $e->getMessage()]);
        }
    }

    public function enqueue(?string $kind, $id, string $action = 'upsert'): void
    {
        if (!$kind || !$id) {
            return;
        }
        try {
            $now = date('Y-m-d H:i:s');
            // insertOrIgnore + the unique key is the collapse: an edit storm on
            // one post is one pending row, not a thousand. `available_at` is
            // NOT bumped on a repeat, so a document that keeps being edited is
            // still indexed on the schedule of its FIRST change rather than
            // being starved forever by its own activity.
            $this->db->table('search_index_queue')->insertOrIgnore([
                'kind' => $kind,
                'object_id' => (int) $id,
                'action' => $action,
                'created_at' => $now,
                'available_at' => $now,
            ]);

            // A delete must win over a pending upsert for the same object,
            // otherwise a deleted post is re-indexed from a row that no longer
            // exists and the drain quietly does nothing while the stale
            // document stays searchable.
            if ($action === 'delete') {
                $this->db->table('search_index_queue')
                    ->where('kind', $kind)->where('object_id', (int) $id)
                    ->update(['action' => 'delete']);
            }
        } catch (\Throwable $e) {
            $this->log->warning('search: enqueue failed', ['kind' => $kind, 'id' => $id, 'error' => $e->getMessage()]);
        }
    }
}
