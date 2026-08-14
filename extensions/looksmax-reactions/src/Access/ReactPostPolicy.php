<?php

namespace Local\Reactions\Access;

use Flarum\Post\Post;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;

class ReactPostPolicy extends AbstractPolicy
{
    public function __construct(private \Flarum\Settings\SettingsRepositoryInterface $settings)
    {
    }

    public function react(User $actor, Post $post)
    {
        if ($actor->isGuest() || !$actor->hasPermission('lmxreactions.react')) {
            return $this->deny();
        }

        // A hidden or private post has no visible footer to react in; allowing
        // it anyway means rows accumulate against content nobody can see.
        if ($post->hidden_at !== null || $post->is_private) {
            return $this->deny();
        }

        if ((int) $post->user_id === (int) $actor->id
            && !$this->settings->get('lmxreactions.selfReact')) {
            return $this->deny();
        }

        return $this->allow();
    }
}
