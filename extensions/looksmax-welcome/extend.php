<?php

use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Api\Serializer\UserSerializer;
use Flarum\Extend;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Registered;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\Welcome\Api\StatsController;
use Local\Welcome\Api\SurveyController;
use Local\Welcome\Config;
use Local\Welcome\InjectAdminScript;
use Local\Welcome\InjectScript;
use Local\Welcome\Listeners\AutoConfirmEmail;
use Local\Welcome\Listeners\SeedSurveyState;
use Local\Welcome\Survey;

/**
 * First-run identity: signup and login polish, a welcoming first session, and
 * a "why did you join" survey that is stored — properly, keyed by user, with
 * timestamps — for personalisation, not thrown away after the confetti.
 *
 * This extension owns THREE things and nothing else:
 *
 *   1. Additive DOM decoration of core's own SignUpModal / LogInModal —
 *      icons, hints, a password-strength meter — never an override of either
 *      component. See js/dist/forum.js `decorateAuthModals()` for exactly
 *      which DOM it touches and how defensively.
 *   2. A first-run overlay, built entirely from scratch (not decorating
 *      unknown core markup, so none of the fragility above applies to it):
 *      a welcome moment plus the survey, staged in, multi-select, skippable,
 *      never a wall.
 *   3. Storage and retrieval of the survey's answers, in its own two-table
 *      schema (see migrations/), reachable per-user off the payload every
 *      page already has and in aggregate from an admin-only endpoint.
 *
 * Extension points, no overrides — the same discipline
 * looksmax-userinfo/extend.php documents and the ecosystem sweep in
 * looksmax-guides measured as the actually-safe pattern on this install:
 *
 *   Flarum\User\Event\Registered   the ONE moment a survey row is created.
 *                                  Not login, not "first page view" — see
 *                                  Listeners\SeedSurveyState and the
 *                                  migration's docblock for why that timing
 *                                  is load-bearing.
 *   UserSerializer                 the actor's OWN answers, self-scoped —
 *                                  see the closure below for the exact check.
 *   ForumSerializer                the survey catalogue and the enabled flag,
 *                                  once, on the payload every page has —
 *                                  same shape as `lmxUserInfo` / `lmxEconomy`.
 *   Frontend@content                the JS, as its own <script>. See
 *                                  InjectScript.
 */
return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->content(InjectScript::class),

    (new Extend\Frontend('admin'))
        ->content(InjectAdminScript::class),

    (new Extend\Event())
        ->listen(Registered::class, SeedSurveyState::class)
        // No-op unless `welcome.autoConfirm` is on. It exists because outbound
        // mail on this install cannot be delivered, so the confirmation link
        // never arrives and an unconfirmed account can never post. See
        // Listeners/AutoConfirmEmail and the setting's comment in Config.
        ->listen(Registered::class, AutoConfirmEmail::class),

    // Actor-only, on purpose. `email`, `preferences` and every other private
    // field on core's own UserSerializer follow exactly this shape — a value
    // attached to the SUBJECT's resource but only populated when the actor
    // asking IS the subject. UserSerializer (not BasicUserSerializer): this
    // is private data that belongs on a full user fetch and on the
    // session-embedded actor, not on every author byline a post stream
    // renders — registering here means the two extra queries below run at
    // most once per page (the viewer's own row), never once per post author.
    (new Extend\ApiSerializer(UserSerializer::class))
        ->attributes(function (UserSerializer $serializer, User $user, array $attributes) {
            $actor = $serializer->getActor();

            if (!$actor || $actor->isGuest() || (int) $actor->id !== (int) $user->id) {
                return $attributes;
            }

            try {
                /** @var ConnectionInterface $db */
                $db = resolve(ConnectionInterface::class);

                $state = $db->table('lmx_welcome_survey_state')->where('user_id', $user->id)->first();

                if (!$state) {
                    // No row = this account predates the feature (or existed
                    // before it was enabled) — see the migration's docblock.
                    // `null`, not omitted: an omitted key and an explicit
                    // "not applicable" look identical over JSON, but the
                    // client's gate (js/dist/forum.js `maybeOpen()`) treats
                    // "key present but null" and "key absent" the same way
                    // regardless, so this is belt-and-braces rather than
                    // load-bearing.
                    $attributes['lmxJoinSurvey'] = null;

                    return $attributes;
                }

                $answers = $db->table('lmx_welcome_survey_answers')
                    ->where('user_id', $user->id)
                    ->pluck('option_key');

                $attributes['lmxJoinSurvey'] = [
                    'status' => $state->status,
                    'answers' => array_values($answers->all()),
                    'respondedAt' => $state->responded_at,
                ];
            } catch (\Throwable $e) {
                // A serializer that throws returns a 500 for the WHOLE page.
                // The survey is worth less than the forum, so it degrades to
                // "never shown" rather than taking every page down with it.
                $attributes['lmxJoinSurvey'] = null;
            }

            return $attributes;
        }),

    // The catalogue and the kill switch, once, on the payload every page
    // already has — same shape as looksmax-userinfo's `lmxUserInfo` /
    // looksmax-economy's `lmxEconomy`. `Survey::forClient()` is NOT a
    // setting — the six sections and six intents are the operator's own
    // words and are not admin-editable text (see Config.php's docblock) —
    // but it rides the same attribute so the client has exactly one place to
    // read "is this on, and what does it look like".
    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attributes(function (ForumSerializer $serializer, $model, array $attributes) {
            /** @var SettingsRepositoryInterface $settings */
            $settings = resolve(SettingsRepositoryInterface::class);

            try {
                $cfg = Config::all($settings);
                $attributes['lmxWelcome'] = [
                    'enabled' => (bool) $cfg['enabled'],
                    'catalog' => Survey::forClient(),
                ];
            } catch (\Throwable $e) {
                $attributes['lmxWelcome'] = null;
            }

            return $attributes;
        }),

    (new Extend\Routes('api'))
        // Write-only pair, actor-scoped — see SurveyController's class header
        // for the full security model.
        ->post('/lmx-welcome/survey', 'lmxwelcome.survey.save', SurveyController::class)
        ->post('/lmx-welcome/survey/skip', 'lmxwelcome.survey.skip', SurveyController::class)
        // Admin-only aggregate — see StatsController's class header for what
        // it does and, as importantly, what it deliberately does not expose.
        ->get('/lmx-welcome/stats', 'lmxwelcome.stats', StatsController::class),

    // Every string this surface renders is a key in locale/en.yml +
    // locale/es.yml. Spanish is the forum's DEFAULT locale and the SOURCE
    // language — see HANDOFF-UI.md §8 — so es.yml carries the authored copy
    // and en.yml is the translation, never the reverse.
    (new Extend\Locales(__DIR__ . '/locale')),
];
