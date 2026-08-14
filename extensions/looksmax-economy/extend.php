<?php

use Flarum\Extend;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Local\Economy\Api\SummaryController;
use Local\Economy\Config;
use Local\Economy\InjectAdminScript;
use Local\Economy\InjectScript;
use Local\Economy\Ledger;
use Local\Economy\Listeners;

return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        // The streak/progress widget. See InjectScript.php for why it ships
        // as its own <script> rather than inside the shared bundle.
        ->content(InjectScript::class),

    // The settings screen. Every control here writes an `economy.*` setting
    // that Config.php reads — the direct fix for "every award amount, daily
    // cap, streak threshold and min-length is a hardcoded PHP constant with
    // no admin surface at all".
    (new Extend\Frontend('admin'))
        ->content(InjectAdminScript::class),

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
    // depend on which one lands second. The same fallback rule now also
    // covers the next-rank progress fields (see Ledger::progress()):
    // looksmax-ranks' own `identity` attribute already carries a richer
    // `nextRank` object (Standing.php), so this is only ever the answer when
    // that extension is absent.
    (new Extend\ApiSerializer(UserSerializer::class))
        ->attributes(function (UserSerializer $serializer, User $user, array $attributes) {
            if (isset($attributes['identity'])) {
                return $attributes;
            }

            $lifetime = (int) ($user->lifetime_points ?? 0);

            $attributes['points'] = (int) ($user->points ?? 0);
            $attributes['lifetimePoints'] = $lifetime;
            $attributes['rankSlug'] = $user->rank_slug ?? 'greycel';

            try {
                /** @var Ledger $ledger */
                $ledger = resolve(Ledger::class);
                $progress = $ledger->progress($lifetime);
                $attributes['nextRankSlug'] = $progress['nextRankSlug'];
                $attributes['pointsToNextRank'] = $progress['pointsToNextRank'];
                $attributes['rankProgressPct'] = $progress['rankProgressPct'];
            } catch (\Throwable $e) {
                // A broken progress calculation must not blank the whole
                // user payload — every post author panel reads this.
            }

            return $attributes;
        }),

    // The whole settings surface, once, on the payload every page already
    // has — same shape as looksmax-userinfo's `lmxUserInfo`. The forum
    // widget (js/dist/forum.js) does not currently read this (it gets its
    // numbers from /api/economy/summary, which is per-user), but every OTHER
    // future surface that wants to show "posts are worth 2 points" or "the
    // daily cap is 40" without a second request now has one place to read it
    // from, matching the pattern the rest of this codebase already commits to.
    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attributes(function (ForumSerializer $serializer, $model, array $attributes) {
            /** @var SettingsRepositoryInterface $settings */
            $settings = resolve(SettingsRepositoryInterface::class);
            try {
                $attributes['lmxEconomy'] = Config::all($settings);
            } catch (\Throwable $e) {
                $attributes['lmxEconomy'] = null;
            }

            return $attributes;
        }),

    (new Extend\Routes('api'))
        ->get('/economy/summary', 'economy.summary', SummaryController::class),

    (new Extend\Event())
        ->listen(\Flarum\Post\Event\Posted::class, Listeners\AwardPost::class)
        ->listen(\Flarum\Discussion\Event\Started::class, Listeners\AwardDiscussion::class)
        ->listen(\Flarum\Post\Event\Deleted::class, Listeners\RevokePost::class)

        // The farming-bug fix: reverse what RevokePost never covered. See
        // both listeners' own headers for the exact exploit each one closes.
        ->listen(\Flarum\Discussion\Event\Deleted::class, Listeners\RevokeDiscussion::class)
        ->listen(\Flarum\Post\Event\Deleted::class, Listeners\RevokeStreak::class)

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
