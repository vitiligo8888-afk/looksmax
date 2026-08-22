<?php

namespace Local\Analytics\Listeners;

use Flarum\Discussion\Event\Renamed;
use Flarum\Post\Event\Hidden;
use Local\Analytics\Models\Event;

/**
 * Moderator actions: a post hidden, a discussion renamed.
 *
 * ── Why this class exists ───────────────────────────────────────────────────
 *
 * extend.php:61-62 has wired BOTH events to this class since the extension was
 * written, but the file was never created. Flarum resolves a listener out of
 * the container at DISPATCH time, not at boot, so nothing complained on
 * startup — the forum booted clean and the fault was invisible until a
 * moderator actually hid a post or renamed a thread, at which point the
 * request fatals with a class-not-found. Both are routine moderation actions
 * on a board this size, so this was a live crash, not latent dead code.
 *
 * ── One class, two unrelated events ─────────────────────────────────────────
 *
 * `handle()` therefore takes no type hint and branches on the concrete class.
 * That is deliberate and matches how extend.php registers it; splitting into
 * two listeners would be tidier PHP but would mean editing the registration,
 * and the registration is the part that is already correct.
 *
 * `actor` is nullable on both core events (a rename can come from a background
 * task, not a person), so every read goes through `?->` and the row simply
 * carries a null user_id rather than being dropped.
 */
class CaptureModeration
{
    public function handle(object $event): void
    {
        if ($event instanceof Hidden) {
            $this->hidden($event);

            return;
        }

        if ($event instanceof Renamed) {
            $this->renamed($event);
        }
    }

    private function hidden(Hidden $event): void
    {
        $post = $event->post ?? null;

        Event::record('post.hidden', [
            'user_id' => $event->actor->id ?? null,
            'discussion_id' => $post->discussion_id ?? null,
            'post_id' => $post->id ?? null,
            'props' => json_encode(array_filter([
                // Who wrote it, as distinct from who hid it — the pair is the
                // only thing that makes this row useful for moderation review.
                'author_id' => $post->user_id ?? null,
                'number' => $post->number ?? null,
            ], fn ($v) => $v !== null)),
        ]);
    }

    private function renamed(Renamed $event): void
    {
        $discussion = $event->discussion ?? null;

        Event::record('discussion.renamed', [
            'user_id' => $event->actor->id ?? null,
            'discussion_id' => $discussion->id ?? null,
            'props' => json_encode(array_filter([
                'old_title' => $event->oldTitle ?? null,
                'new_title' => $discussion->title ?? null,
            ], fn ($v) => $v !== null)),
        ]);
    }
}
