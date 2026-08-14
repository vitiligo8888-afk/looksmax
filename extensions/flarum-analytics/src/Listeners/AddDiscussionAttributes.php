<?php

namespace Local\Analytics\Listeners;

use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;

/** Expose the computed signals so the frontend can sort and badge on them. */
class AddDiscussionAttributes
{
    public function __invoke(DiscussionSerializer $serializer, Discussion $discussion, array $attributes): array
    {
        $attributes['viewCount'] = (int) ($discussion->view_count ?? 0);
        $attributes['uniqueViews'] = (int) ($discussion->unique_views ?? 0);
        $attributes['hotness'] = round((float) ($discussion->hotness ?? 0), 4);

        return $attributes;
    }
}
