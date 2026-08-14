<?php

namespace Local\Economy\Listeners;

use Flarum\Post\Event\Deleted;
use Local\Economy\Ledger;

class RevokePost
{
    public function __construct(protected Ledger $ledger)
    {
    }

    public function handle(Deleted $event): void
    {
        $post = $event->post;
        if ($post->user_id) {
            $this->ledger->revoke($post->user_id, 'post.created', 'post:' . $post->id);
        }
    }
}
