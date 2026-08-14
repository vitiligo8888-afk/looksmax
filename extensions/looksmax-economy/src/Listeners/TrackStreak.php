<?php

namespace Local\Economy\Listeners;

use Flarum\Post\Event\Posted;
use Local\Economy\Streaks;

/**
 * A streak is held by writing something, not by loading the page.
 *
 * Deliberately not a login streak: a login streak rewards opening a tab, which
 * is worth nothing to anybody else on the forum. The length floor is the same
 * idea — a streak that can be held with "based" is a streak that measures
 * nothing.
 */
class TrackStreak
{
    public function __construct(protected Streaks $streaks)
    {
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;

        if (!$post || !$post->user_id) {
            return;
        }

        $length = mb_strlen(trim(strip_tags((string) $post->content)));

        // Read through Streaks::minLength(), not the MIN_LENGTH constant
        // directly, so this and the settings-driven admin control (and
        // RevokeStreak's own qualifying check) can never disagree about the
        // threshold.
        if ($length < $this->streaks->minLength()) {
            return;
        }

        $this->streaks->touch((int) $post->user_id, null, (int) $post->id);
    }
}
