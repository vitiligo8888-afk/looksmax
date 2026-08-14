<?php

use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\Extend;
use Flarum\User\User;
use Local\Ranks\Api\IdentityActionController;
use Local\Ranks\Api\IdentityController;
use Local\Ranks\Console;
use Local\Ranks\InjectIdentity;

/**
 * Identity and progression.
 *
 * Three axes, deliberately independent: rank is earned from the ledger and
 * cannot be bought, a membership tier is bought or granted and cannot be
 * earned by posting, and cosmetics are owned and equipped. See
 * /root/identity-notes/DESIGN.md for why the source board's single
 * `style_class` column is not a model worth copying.
 *
 * The forum-facing JS is injected as its own <script> rather than joined into
 * the shared bundle. Flarum concatenates every extension's JS into one file, so
 * a top-level throw anywhere kills every extension after it and a malformed
 * export blanks the SPA. An inline element has its own error boundary.
 */
return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->css(__DIR__ . '/less/styles.less')
        ->css(__DIR__ . '/less/frames.less')
        ->content(InjectIdentity::class),

    (new Extend\Frontend('admin'))
        ->css(__DIR__ . '/less/forum.less'),

    (new Extend\Locales(__DIR__ . '/locale')),

    // BasicUserSerializer, NOT UserSerializer.
    //
    // Flarum serializes the author of a discussion or a post through
    // BasicUserSerializer — username, displayName, avatarUrl, slug and nothing
    // else. The full UserSerializer only runs for /api/users/{id}. Extending
    // the wrong one produced an `identity` attribute that was present on a
    // profile fetch and absent on every post in the stream, which is the exact
    // shape of bug that a 200 response hides. Mutators registered against a
    // parent serializer apply to its subclasses, so this covers both.
    //
    // Render::user() is query-free by construction, which is what makes it safe
    // to run on all ~50 users in a listing payload.
    (new Extend\ApiSerializer(BasicUserSerializer::class))
        ->attributes(function (BasicUserSerializer $serializer, User $user, array $attributes) {
            $attributes['identity'] = Local\Ranks\Render::user($user);
            $attributes['points'] = $attributes['identity']['points'];
            $attributes['lifetimePoints'] = $attributes['identity']['lifetimePoints'];
            $attributes['rankSlug'] = $attributes['identity']['rankSlug'];

            return $attributes;
        }),

    (new Extend\Routes('api'))
        ->get('/identity/{what:[a-z]+}', 'identity.read', IdentityController::class)
        ->post('/identity/{action:[a-z]+}', 'identity.write', IdentityActionController::class),

    (new Extend\Console())
        ->command(Console\SyncCommand::class)
        ->command(Console\BackfillCommand::class)
        ->command(Console\BadgeCommand::class)
        ->command(Console\ScoreCommand::class),
];
