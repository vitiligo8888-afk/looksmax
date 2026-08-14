<?php

namespace Local\Analytics\Listeners;

use Local\Analytics\Models\Event;

class CaptureStarted
{
    public function handle(\Flarum\Discussion\Event\Started $event): void
    {
        $actor = $event->actor ?? null;
        $discussion = $event->discussion ?? null;
        Event::record('discussion.started', [
            'user_id' => $actor->id ?? ($event->user->id ?? null),
            'discussion_id' => $discussion->id ?? null,
            'props' => json_encode(array_filter([
                'title' => $discussion->title ?? null,
                'tags' => $discussion ? $discussion->tags->pluck('slug')->all() : null,
            ], fn ($v) => $v !== null)),
        ]);
    }
}
