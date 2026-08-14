<?php

namespace Local\Reactions\Events;

use Flarum\Post\Post;
use Flarum\User\User;
use Local\Reactions\Reaction;

/**
 * Fired only when a reaction is ADDED, never when one is removed, because
 * every consumer so far (notifications, points) wants the positive edge and
 * would otherwise have to filter. Other lanes can listen to this without
 * depending on any of this extension's internals; see HANDOFF-REACTIONS.md.
 */
class PostWasReacted
{
    public function __construct(
        public Post $post,
        public User $actor,
        public Reaction $reaction,
    ) {
    }
}
