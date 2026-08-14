<?php

namespace Local\Economy\Listeners;

use Flarum\Post\Event\Posted;
use Local\Economy\Ledger;

class AwardPost
{
    public function __construct(protected Ledger $ledger)
    {
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;
        if (!$post->user_id || $post->number === 1) {
            return; // first post is credited as a started discussion instead
        }

        // Effort weighting: a one word reply is worth less than a considered
        // one. Capped so a wall of text cannot be gamed either.
        $len = mb_strlen(strip_tags((string) $post->content));
        $multiplier = max(0.25, min(2.0, $len / 400));

        $this->ledger->award($post->user_id, 'post.created', 'post:' . $post->id, null, $multiplier);
    }
}
