<?php

namespace Local\Analytics\Listeners;

use Flarum\Post\Event\Posted;
use Local\Analytics\Models\Event;

/**
 * Server-side capture of a reply. Carries the discussion's tags so PostHog can
 * segment by the board's taxonomy without a join — on the source forum the
 * prefix is the single most predictive property of engagement (Guide averages
 * 3,262 views vs JFL at 346), so it belongs on every event.
 */
class CapturePosted
{
    public function handle(Posted $event): void
    {
        $post = $event->post;
        $discussion = $post->discussion;

        Event::record('post.created', [
            'user_id' => $post->user_id,
            'discussion_id' => $post->discussion_id,
            'post_id' => $post->id,
            'props' => json_encode([
                'number' => $post->number,
                'length' => mb_strlen((string) $post->content),
                'tags' => $discussion ? $discussion->tags->pluck('slug')->all() : [],
                'is_first' => $post->number === 1,
                'reply_latency_s' => $discussion && $discussion->created_at
                    ? max(0, $post->created_at->getTimestamp() - $discussion->created_at->getTimestamp())
                    : null,
            ]),
        ]);
    }
}
