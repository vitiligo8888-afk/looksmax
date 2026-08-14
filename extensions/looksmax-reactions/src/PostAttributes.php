<?php

namespace Local\Reactions;

use Flarum\Api\Serializer\BasicPostSerializer;
use Flarum\Post\Post;

/**
 * What every post carries about reactions. Four scalars and two maps, no
 * relationship: the reaction CATALOGUE rides on the forum payload once per
 * page load, so a post only has to say which ids it drew and how many.
 */
class PostAttributes
{
    public function __construct(private Counts $counts)
    {
    }

    public function __invoke(BasicPostSerializer $serializer, Post $post, array $attributes): array
    {
        $actor = $serializer->getActor();
        $attributes['reactions'] = $this->counts->forPost((int) $post->id, $actor->id ?: null);
        $attributes['canReact'] = $actor->can('react', $post);
        $attributes['canSeeReactors'] = $actor->hasPermission('lmxreactions.seeReactors')
            || $actor->hasPermission('discussion.hidePosts');

        return $attributes;
    }
}
