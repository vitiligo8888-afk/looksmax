<?php

use Flarum\Api\Controller\ListPostsController;
use Flarum\Api\Controller\ShowDiscussionController;
use Flarum\Api\Controller\ShowPostController;
use Flarum\Api\Serializer\BasicPostSerializer;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Extend;
use Flarum\Post\Post;
use Local\Reactions\Access\ReactPostPolicy;
use Local\Reactions\Api;
use Local\Reactions\Counts;
use Local\Reactions\Events\PostWasReacted;
use Local\Reactions\ForumAttributes;
use Local\Reactions\InjectScript;
use Local\Reactions\Listener\SendReactionNotification;
use Local\Reactions\Notification\PostReactedBlueprint;
use Local\Reactions\PostAttributes;

/**
 * Multi-reaction system for Looksmax.lat.
 *
 * ── Why not flarum/likes, and why not fof/reactions ─────────────────────────
 *
 * flarum/likes is a single binary like on a (post_id, user_id) pivot. There is
 * no reaction TYPE in its schema at all, so "extend it" would mean adding the
 * type column, the catalogue table, the ordering, the picker and the whole API
 * — i.e. replacing it while keeping its table name. It is left installed but
 * DISABLED, and its `post_likes` table is left in place untouched, because
 * looksmax-userinfo's reception metric reads it (see HANDOFF-REACTIONS.md).
 * Running both would put two competing reaction UIs in the same post footer.
 *
 * fof/reactions is the ecosystem incumbent and the only serious one — the only
 * other candidates in a 2,528-extension corpus are reflar/reactions (abandoned,
 * beta.8) and two dead 2016 repos. It was read closely and NOT adopted, for one
 * disqualifying reason and three expensive ones:
 *
 *   * It is not multi-reaction. SaveReactionsToDatabase looks up the single row
 *     for (user_id, post_id) and OVERWRITES reaction_id in place; the picker is
 *     hidden entirely once you have reacted. The upstream request for multiple
 *     reactions is issue #64, open and untouched since 2023-03-23. That is the
 *     one requirement here, so adoption means forking on day one.
 *   * No ordering column, on either branch. Reordering the picker means delete
 *     and recreate, and the FK is ON DELETE CASCADE — reordering destroys the
 *     history of that reaction. Not survivable with 381k imported rows.
 *   * N+1 on every post serialization; upstream fixed it on 2.x only (PR #115).
 *   * No custom image reactions. Its emoji path builds URLs from a
 *     `[codepoint]` template, so filenames must be emoji codepoints and 13
 *     named PNGs cannot be expressed at all.
 *
 * What IS taken from it is its schema. `reactions` and `post_reactions` here
 * use the same table names and the same core columns, so this is a drop-in for
 * a forum that ever ran it, and so the two can never be installed side by side.
 *
 * ── The order of extenders below is load-bearing in exactly one place ────────
 * The three ApiController warmers must exist for the serializer attribute to be
 * cheap, but the attribute is correct without them (Counts falls back to a lazy
 * per-post read). So a mistake there is slow, not wrong.
 */
return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->content(InjectScript::class),

    (new Extend\Frontend('admin'))
        ->css(__DIR__ . '/less/admin.less')
        ->content(Local\Reactions\InjectAdminScript::class),

    (new Extend\Locales(__DIR__ . '/locale')),

    // ---------------------------------------------------------------- API
    (new Extend\Routes('api'))
        ->post('/lmx/posts/{id}/react', 'lmxr.react', Api\ReactController::class)
        ->get('/lmx/posts/{id}/reactors', 'lmxr.reactors', Api\ReactorsController::class)
        ->get('/lmx/discussions/{id}/reactions', 'lmxr.discussion', Api\DiscussionReactionsController::class)
        ->get('/lmx/reactions/leaderboard', 'lmxr.leaderboard', Api\LeaderboardController::class)
        ->get('/lmx/reactions', 'lmxr.admin.list', Api\AdminController::class)
        ->patch('/lmx/reactions/{id}', 'lmxr.admin.update', Api\AdminController::class)
        ->delete('/lmx/reactions/{id}', 'lmxr.admin.delete', Api\AdminController::class)
        ->post('/lmx/reactions/order', 'lmxr.admin.order', Api\AdminController::class),

    // ------------------------------------------------------- serialization
    (new Extend\ApiSerializer(BasicPostSerializer::class))
        ->attributes(PostAttributes::class),

    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attributes(ForumAttributes::class),

    // Warm the count cache from the three controllers that know the whole
    // batch, which is what keeps the serializer from going N+1. See Counts.
    (new Extend\ApiController(ShowDiscussionController::class))
        ->prepareDataForSerialization(function ($controller, &$data, $request) {
            resolve(Counts::class)->warm(
                collect($data->posts ?? [])->pluck('id')->all(),
                \Flarum\Http\RequestUtil::getActor($request)->id ?: null
            );
        }),
    (new Extend\ApiController(ListPostsController::class))
        ->prepareDataForSerialization(function ($controller, &$data, $request) {
            resolve(Counts::class)->warm(
                collect($data)->pluck('id')->all(),
                \Flarum\Http\RequestUtil::getActor($request)->id ?: null
            );
        }),
    (new Extend\ApiController(ShowPostController::class))
        ->prepareDataForSerialization(function ($controller, &$data, $request) {
            resolve(Counts::class)->warm(
                [$data->id ?? null],
                \Flarum\Http\RequestUtil::getActor($request)->id ?: null
            );
        }),

    // ----------------------------------------------------------- behaviour
    (new Extend\Policy())
        ->modelPolicy(Post::class, ReactPostPolicy::class),

    (new Extend\Event())
        ->listen(PostWasReacted::class, SendReactionNotification::class),

    (new Extend\Notification())
        ->type(PostReactedBlueprint::class, BasicPostSerializer::class, ['alert']),

    (new Extend\ServiceProvider())
        ->register(Local\Reactions\Provider::class),

    (new Extend\Settings())
        ->default('lmxreactions.selfReact', false)
        ->default('lmxreactions.maxPerPost', 6)
        ->default('lmxreactions.rateBurst', 20)
        ->default('lmxreactions.rateHourly', 300)
        ->default('lmxreactions.stripSize', 24)
        ->serializeToForum('lmxReactionsMaxPerPost', 'lmxreactions.maxPerPost', 'intval')
        ->serializeToForum('lmxReactionsStripSize', 'lmxreactions.stripSize', 'intval')
        ->serializeToForum('lmxReactionsSelfReact', 'lmxreactions.selfReact', 'boolval'),

    (new Extend\Console())
        ->command(Local\Reactions\Console\BackfillCommand::class)
        ->command(Local\Reactions\Console\RecountCommand::class)
        ->command(Local\Reactions\Console\ChrisCommand::class),
];
