<?php

namespace Local\Economy\Listeners;

use Flarum\Settings\SettingsRepositoryInterface;
use Local\Economy\Config;
use Flarum\Post\Event\Posted;
use Local\Economy\Ledger;

class AwardPost
{
    public function __construct(
        protected Ledger $ledger,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;
        if (!$post->user_id || $post->number === 1) {
            return; // first post is credited as a started discussion instead
        }

        // Effort weighting: a one word reply is worth less than a considered
        // one. Capped so a wall of text cannot be gamed either. The three
        // numbers here (floor, ceiling, characters-per-1.0x) were a hardcoded
        // 0.25 / 2.0 / 400 before Config.php existed; see 'post.*' there.
        $min = (float) Config::get($this->settings, 'post.minMultiplier');
        $max = (float) Config::get($this->settings, 'post.maxMultiplier');
        $divisor = max(1, (int) Config::get($this->settings, 'post.multiplierDivisor'));

        $len = mb_strlen(strip_tags((string) $post->content));
        $multiplier = max($min, min($max, $len / $divisor));

        $this->ledger->award($post->user_id, 'post.created', 'post:' . $post->id, null, $multiplier);
    }
}
