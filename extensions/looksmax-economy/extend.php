<?php

use Flarum\Extend;
use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Local\Economy\Api\QuestsActionController;
use Local\Economy\Api\QuestsController;
use Local\Economy\Api\SummaryController;
use Local\Economy\Config;
use Local\Economy\InjectAdminScript;
use Local\Economy\InjectInviteWidget;
use Local\Economy\InjectRefCapture;
use Local\Economy\InjectScript;
use Local\Economy\Ledger;
use Local\Economy\Listeners;
use Illuminate\Database\ConnectionInterface;

return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        // The streak/progress widget. See InjectScript.php for why it ships
        // as its own <script> rather than inside the shared bundle.
        ->content(InjectScript::class)
        // Referral ?ref capture — a few guarded bytes, emitted only when the
        // program is on. See InjectRefCapture.php.
        ->content(InjectRefCapture::class)
        // The dismissible "Invita y gana" card for logged-in members; also
        // emitted only when referral is on. See InjectInviteWidget.php.
        ->content(InjectInviteWidget::class),

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
    // covers the next-rank progress fields (see Ledger::progressFor()):
    // looksmax-ranks' own `identity` attribute already carries a richer
    // `nextRank` object (Standing.php), so this is only ever the answer when
    // that extension is absent OR simply has not run yet on this user (the
    // `isset($attributes['identity'])` check below depends on extension load
    // order, which this file does not control — see the next paragraph for
    // why that matters).
    //
    // PERFORMANCE NOTE, learned the expensive way: this callback runs once
    // per user on any page that serializes more than one through
    // UserSerializer (member list, leaderboard, chat, mentions) — and because
    // the `identity` short-circuit above cannot be relied on to always fire
    // first, the branch below is NOT dead code just because looksmax-ranks is
    // installed. An earlier version of this file called `resolve(Ledger::class)`
    // and an instance method here, which meant constructing a Ledger (and
    // resolving its ConnectionInterface + SettingsRepositoryInterface
    // dependencies through the container) once per row on every such page.
    // `Ledger::progressFor()` is a static, pure function over a ladder that is
    // memoized once per worker (`Ledger::ladder()`) specifically so this
    // callback never touches the container, the database, or an object
    // construction at all — see Ledger.php's comments on ladder()/progressFor().
    (new Extend\ApiSerializer(UserSerializer::class))
        ->attributes(function (UserSerializer $serializer, User $user, array $attributes) {
            // Oro (the paid balance) is emitted on every user payload regardless
            // of whether looksmax-ranks' richer `identity` attribute has already
            // run — the header's oro chip reads it the same way the theme reads
            // `points` for username colouring, so it must not sit behind the
            // identity short-circuit below.
            $attributes['oro'] = (int) ($user->oro ?? 0);

            if (isset($attributes['identity'])) {
                return $attributes;
            }

            $lifetime = (int) ($user->lifetime_points ?? 0);

            $attributes['points'] = (int) ($user->points ?? 0);
            $attributes['lifetimePoints'] = $lifetime;
            $attributes['rankSlug'] = $user->rank_slug ?? 'greycel';

            try {
                $progress = Ledger::progressFor($lifetime);
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

    // Referral panel data — the actor's OWN invite code and tallies, so a
    // profile/settings surface can render "share looksmax.lat/?ref=<code>,
    // N joined, M qualified" with no extra request. Actor-scoped for the same
    // reason looksmax-welcome's survey answers are: private, self-only, and
    // registered on UserSerializer (not Basic) so the two queries run at most
    // once per page — the viewer's own row — never per post author. Both
    // queries are guarded; a throw degrades to no panel, never a 500.
    (new Extend\ApiSerializer(UserSerializer::class))
        ->attributes(function (UserSerializer $serializer, User $user, array $attributes) {
            $actor = $serializer->getActor();
            if (!$actor || $actor->isGuest() || (int) $actor->id !== (int) $user->id) {
                return $attributes;
            }

            /** @var SettingsRepositoryInterface $settings */
            $settings = resolve(SettingsRepositoryInterface::class);
            if (! (bool) Config::get($settings, 'referral.enabled')) {
                return $attributes;
            }

            try {
                /** @var ConnectionInterface $db */
                $db = resolve(ConnectionInterface::class);
                $attributes['lmxReferral'] = [
                    'code' => (int) $user->id,
                    'joined' => (int) $db->table('lmx_referrals')->where('referrer_id', $user->id)->count(),
                    'qualified' => (int) $db->table('lmx_referrals')->where('referrer_id', $user->id)->whereNotNull('qualified_at')->count(),
                ];
            } catch (\Throwable $e) {
                $attributes['lmxReferral'] = null;
            }

            return $attributes;
        }),

    (new Extend\Routes('api'))
        ->get('/economy/summary', 'economy.summary', SummaryController::class)
        // Daily/weekly quests — see src/Quests.php. Read/write split, same
        // shape as looksmax-cosmetics' equip endpoint and looksmax-ranks'
        // identity endpoints.
        ->get('/economy/quests', 'economy.quests', QuestsController::class)
        ->post('/economy/quests/claim', 'economy.quests.claim', QuestsActionController::class),

    (new Extend\Event())
        ->listen(\Flarum\Post\Event\Posted::class, Listeners\AwardPost::class)
        ->listen(\Flarum\Discussion\Event\Started::class, Listeners\AwardDiscussion::class)
        ->listen(\Flarum\Post\Event\Deleted::class, Listeners\RevokePost::class)

        // Founding Member: the relaunch's first N registrants get the Fundador
        // group + a one-off bonus. No-op until an operator opens the window —
        // see Listeners/FoundingMember.php and Config's founding.* keys.
        ->listen(\Flarum\User\Event\Registered::class, Listeners\FoundingMember::class)

        // Referral loop: record who brought a new account in and pay the join
        // bonus (ReferralCapture), then pay the qualify bonus once the referee
        // is demonstrably real (ReferralQualify). Both no-op until enabled.
        ->listen(\Flarum\User\Event\Registered::class, Listeners\ReferralCapture::class)
        ->listen(\Flarum\Post\Event\Posted::class, Listeners\ReferralQualify::class)

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
