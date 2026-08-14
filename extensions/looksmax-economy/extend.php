<?php

use Flarum\Extend;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\User\User;
use Local\Economy\Listeners;

return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less'),

    // Nothing here renders text, but the ledger's `reason` vocabulary is read
    // by members through the store's movements table. The labels ship from the
    // extension that owns the codes. See locale/i18n-map.json.
    (new Extend\Locales(__DIR__ . '/locale')),

    // Standing is public: it belongs on every user payload so the theme can
    // colour usernames without a second request.
    //
    // looksmax-ranks now owns the richer `identity` attribute and emits
    // points/lifetimePoints/rankSlug alongside it, computed from the same
    // columns. This extender stays as the fallback for when the identity
    // extension is disabled, and defers to it when it is not — two extenders
    // writing the same key is a load-order coin flip, and the ladder must not
    // depend on which one lands second.
    (new Extend\ApiSerializer(UserSerializer::class))
        ->attributes(function (UserSerializer $serializer, User $user, array $attributes) {
            if (isset($attributes['identity'])) {
                return $attributes;
            }

            $attributes['points'] = (int) ($user->points ?? 0);
            $attributes['lifetimePoints'] = (int) ($user->lifetime_points ?? 0);
            $attributes['rankSlug'] = $user->rank_slug ?? 'greycel';

            return $attributes;
        }),

    (new Extend\Event())
        ->listen(\Flarum\Post\Event\Posted::class, Listeners\AwardPost::class)
        ->listen(\Flarum\Discussion\Event\Started::class, Listeners\AwardDiscussion::class)
        ->listen(\Flarum\Post\Event\Deleted::class, Listeners\RevokePost::class)

        // Turning up on distinct days, which is the only earning rule that
        // volume cannot game.
        ->listen(\Flarum\Post\Event\Posted::class, Listeners\TrackStreak::class)

        // Reactions have had a rate in the ledger since it was written and
        // nothing ever fired it: every `reaction.received` row on this install
        // came from the import. Live, being liked paid nothing, so the only way
        // to earn was to post — the exact volume-over-value incentive this
        // economy exists to avoid.
        ->listen(\Flarum\Likes\Event\PostWasLiked::class, [Listeners\AwardReaction::class, 'liked'])
        ->listen(\Flarum\Likes\Event\PostWasUnliked::class, [Listeners\AwardReaction::class, 'unliked']),

    (new Extend\Console())
        ->command(\Local\Economy\Console\RecomputeCommand::class),
];
