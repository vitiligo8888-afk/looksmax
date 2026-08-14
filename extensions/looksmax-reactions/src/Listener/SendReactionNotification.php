<?php

namespace Local\Reactions\Listener;

use Flarum\Notification\NotificationSyncer;
use Local\Reactions\Events\PostWasReacted;
use Local\Reactions\Notification\PostReactedBlueprint;

class SendReactionNotification
{
    public function __construct(private NotificationSyncer $notifications)
    {
    }

    public function handle(PostWasReacted $event): void
    {
        $author = $event->post->user;

        // No self-notification, and nothing for a deleted author.
        if (!$author || (int) $author->id === (int) $event->actor->id) {
            return;
        }

        $this->notifications->sync(
            new PostReactedBlueprint($event->post, $event->actor, $event->reaction),
            [$author]
        );
    }
}
