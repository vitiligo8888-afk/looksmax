<?php

namespace Local\Economy\Listeners;

use Flarum\Discussion\Event\Started;
use Local\Economy\Ledger;

class AwardDiscussion
{
    public function __construct(protected Ledger $ledger)
    {
    }

    public function handle(Started $event): void
    {
        $d = $event->discussion;
        if (!$d->user_id) {
            return;
        }

        // Posting into a curated tag is worth more, matching how the source
        // board's Guide threads outperform everything else on engagement.
        $tags = $d->tags->pluck('slug')->all();
        $reason = array_intersect($tags, ['guide', 'method', 'best-of-the-best']) ? 'guide.published' : 'discussion.started';

        $this->ledger->award($d->user_id, $reason, 'discussion:' . $d->id);
    }
}
