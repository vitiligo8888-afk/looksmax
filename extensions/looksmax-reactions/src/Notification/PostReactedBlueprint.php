<?php

namespace Local\Reactions\Notification;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Post\Post;
use Flarum\User\User;
use Local\Reactions\Reaction;

/**
 * "X reacted 🤣 to your post."
 *
 * Deliberately NOT mailable: reactions are the highest-frequency event on a
 * forum and an email per reaction is a way to get the domain blocked. Alert
 * channel only.
 */
class PostReactedBlueprint implements BlueprintInterface
{
    public function __construct(
        private Post $post,
        private User $actor,
        private Reaction $reaction,
    ) {
    }

    public function getSubject()
    {
        return $this->post;
    }

    public function getFromUser()
    {
        return $this->actor;
    }

    public function getData()
    {
        return ['reactionSlug' => $this->reaction->slug, 'reactionId' => (int) $this->reaction->id];
    }

    public static function getType()
    {
        return 'lmxPostReacted';
    }

    public static function getSubjectModel()
    {
        return Post::class;
    }
}
