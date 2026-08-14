<?php

use Flarum\Api\Controller;
use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Extend;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Local\UserInfo\Api\DmController;
use Local\UserInfo\Api\SignatureController;
use Local\UserInfo\Api\SummaryController;
use Local\UserInfo\Config;
use Local\UserInfo\Console\BackfillCommand;
use Local\UserInfo\InjectAdminScript;
use Local\UserInfo\InjectScript;
use Local\UserInfo\InjectSignature;
use Local\UserInfo\Presenter;
use Local\UserInfo\Profile;

/**
 * The user identity surface.
 *
 * Flarum's stock post puts a 32px avatar and a username above the body and
 * stops there. Every forum this board's users came from — XenForo, and vBulletin
 * and IPB before it — puts a panel BESIDE the post: large avatar, name in its
 * rank colour, user title, join date, post count, reaction score. That panel is
 * how a reader weighs a claim without clicking through, and its absence is the
 * single biggest legibility gap between this forum and the one it mirrors.
 *
 * This extension owns THREE things and nothing else:
 *
 *   1. the author rail beside a post,
 *   2. THE user hover card — one implementation, one style, mounted once from
 *      js/dist/forum.js and used at every site a username appears,
 *   3. direct messages, because "Message" on that card must not be a dead
 *      button and this install has no PM extension.
 *
 * Extension points, no overrides:
 *
 *   BasicUserSerializer  one `userInfo` attribute on the user payload the SPA
 *                        already fetches, so a post stream needs no extra
 *                        request to render twenty panels — and so does a
 *                        DISCUSSION LIST, which is the reason this is on
 *                        BasicUserSerializer and not UserSerializer. Measured:
 *                        GET /api/discussions serialises its authors through
 *                        BasicUserSerializer and carried no `userInfo` at all,
 *                        so every hover card on the index had to fetch the user
 *                        before it could draw. UserSerializer extends this one,
 *                        so one registration covers both.
 *   ForumSerializer      the single `lmxUserInfo` configuration object. Every
 *                        call site reads its behaviour from here; there is no
 *                        per-call-site option anywhere in the JS.
 *   ApiController@load   eager-loads the profile row and the groups so twenty
 *                        panels are two queries, not forty.
 *   Frontend@content     the JS as its own <script>. See InjectScript.
 *
 * Nothing here overrides a component method. The ecosystem sweep in
 * looksmax-guides counts CommentPost/DiscussionPage view() among the most
 * clobbered methods in Flarum; this decorates rendered DOM and appends to named
 * ItemLists instead.
 */
return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->content(InjectScript::class)
        // Separate script, separate failure domain. See InjectSignature.
        ->content(InjectSignature::class),

    (new Extend\Frontend('admin'))
        ->css(__DIR__ . '/less/forum.less')
        ->content(InjectAdminScript::class),

    // A real relation rather than a manual lookup, so the eager loads below
    // work and so a missing row is null instead of an exception.
    (new Extend\Model(User::class))
        ->relationship('userInfoProfile', fn (User $user) => $user->hasOne(Profile::class, 'user_id')),

    // BasicUserSerializer, NOT UserSerializer — see the header. Registering a
    // mutator on a parent serializer applies it to every subclass, so this one
    // registration covers the post stream, the discussion list, the member
    // list, notifications, search results and the profile page alike.
    (new Extend\ApiSerializer(BasicUserSerializer::class))
        ->attributes(function (BasicUserSerializer $serializer, User $user, array $attributes) {
            try {
                $attributes['userInfo'] = Presenter::user($user, $user->userInfoProfile, $serializer->getActor());
            } catch (\Throwable $e) {
                // A serializer that throws returns a 500 for the whole page. The
                // panel is worth less than the forum, so it degrades to absent.
                $attributes['userInfo'] = null;
            }

            return $attributes;
        }),

    // The configuration, once, on the payload every page already has.
    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attributes(function (ForumSerializer $serializer, $model, array $attributes) {
            /** @var SettingsRepositoryInterface $settings */
            $settings = resolve(SettingsRepositoryInterface::class);
            try {
                $attributes['lmxUserInfo'] = Config::all($settings);
            } catch (\Throwable $e) {
                $attributes['lmxUserInfo'] = null;
            }

            return $attributes;
        }),

    // Without these the panel is an N+1: one profile query and one groups query
    // per author per post. Measured on the seeded stream: 20 posts, 20 queries,
    // all primary-key lookups — cheap individually and pointless collectively.
    (new Extend\ApiController(Controller\ShowDiscussionController::class))
        ->load(['posts.user.userInfoProfile', 'posts.user.groups']),
    (new Extend\ApiController(Controller\ListPostsController::class))
        ->load(['user.userInfoProfile', 'user.groups']),
    (new Extend\ApiController(Controller\ListDiscussionsController::class))
        ->load(['user.userInfoProfile', 'user.groups', 'lastPostedUser.userInfoProfile', 'lastPostedUser.groups']),
    (new Extend\ApiController(Controller\ShowUserController::class))
        ->load(['userInfoProfile', 'groups']),
    (new Extend\ApiController(Controller\ListUsersController::class))
        ->load(['userInfoProfile', 'groups']),
    (new Extend\ApiController(Controller\CreatePostController::class))
        ->load(['user.userInfoProfile', 'user.groups']),
    (new Extend\ApiController(Controller\ListNotificationsController::class))
        ->load(['fromUser.userInfoProfile', 'fromUser.groups']),

    (new Extend\Routes('api'))
        ->get('/userinfo/summary', 'userinfo.summary', SummaryController::class)

        // ── direct messages ────────────────────────────────────────────────
        // Six routes, one handler; it dispatches on method + path suffix. See
        // the class header for why they are not six files.
        ->get('/lmx-dm/threads', 'lmxdm.threads', DmController::class)
        ->post('/lmx-dm/threads', 'lmxdm.threads.create', DmController::class)
        ->get('/lmx-dm/threads/{id}', 'lmxdm.thread', DmController::class)
        ->post('/lmx-dm/threads/{id}/messages', 'lmxdm.thread.reply', DmController::class)
        ->post('/lmx-dm/threads/{id}/read', 'lmxdm.thread.read', DmController::class)
        ->get('/lmx-dm/unread', 'lmxdm.unread', DmController::class)

        // ── signatures ─────────────────────────────────────────────────────
        // Write only. There is no GET: a signature is already on every user
        // payload the page holds (Presenter::user), so fetching one would be a
        // request for data the client demonstrably already has.
        ->post('/lmx-signature', 'lmx.signature.save', SignatureController::class),

    (new Extend\Console())
        ->command(BackfillCommand::class),

    // Every string this surface renders is a key in locale/en.yml +
    // locale/es.yml. It has to be here rather than nowhere: this is the surface
    // that renders beside EVERY post, so an untranslated word here is the
    // most-read untranslated word on the forum. See locale/i18n-map.json.
    (new Extend\Locales(__DIR__ . '/locale')),
];
