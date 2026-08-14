<?php

namespace Local\Analytics\Listeners;

use Local\Analytics\Models\Event;

class CaptureLoggedIn
{
    public function handle(\Flarum\User\Event\LoggedIn $event): void
    {
        $actor = $event->actor ?? null;
        $discussion = $event->discussion ?? null;
        Event::record('user.logged_in', [
            'user_id' => $actor->id ?? ($event->user->id ?? null),
            'discussion_id' => $discussion->id ?? null,
            'props' => json_encode(array_filter([
                'title' => $discussion->title ?? null,
                'tags' => $discussion ? $discussion->tags->pluck('slug')->all() : null,
            ], fn ($v) => $v !== null)),
        ]);
    }
}
