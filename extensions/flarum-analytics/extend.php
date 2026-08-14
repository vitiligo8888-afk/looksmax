<?php
/**
 * Analytics — event-sourced instrumentation for Flarum.
 *
 * Design notes (why this shape):
 *  - Events are STORED, not just counted. michaelbelgium/flarum-discussion-views
 *    stores one row per view rather than incrementing a counter, and that is the
 *    right call: a counter answers one question, an event stream answers every
 *    later question (unique vs total, dwell, cohort, funnel) without a schema
 *    change. Everything here follows that.
 *  - Capture is SERVER-SIDE first. fof/analytics injects a client script and
 *    stops there, which means ad-blocked users vanish from the numbers. Domain
 *    events (post created, reaction added, discussion viewed) are captured in
 *    PHP where nothing can block them; the client only adds what the server
 *    cannot see (scroll depth, dwell, composer abandonment).
 *  - PostHog receives everything asynchronously via the local table as a spool,
 *    so a PostHog outage degrades to a backlog, never to lost events or a
 *    slow request.
 *  - The same stream feeds ranking/filtering in-forum, so analytics is not a
 *    write-only side channel.
 */

use Flarum\Extend;
use Flarum\Discussion\Discussion;
use Flarum\Database\AbstractModel;
use Local\Analytics\Models\Event;
use Local\Analytics\Listeners;
use Local\Analytics\Console\FlushCommand;
use Local\Analytics\Console\RecomputeRankCommand;

return [
    // Frontend bundles are attached only once built (js/dist/*). The PHP side —
    // server-side capture, spool, forwarding, ranking — is fully functional
    // without them, so a missing bundle degrades rather than fatals.
    (function () {
        $f = (new Extend\Frontend('forum'))->content(Listeners\InjectClientConfig::class);
        if (file_exists(__DIR__ . '/js/dist/forum.js')) {
            $f = $f->js(__DIR__ . '/js/dist/forum.js');
        }
        if (file_exists(__DIR__ . '/less/forum.less')) {
            $f = $f->css(__DIR__ . '/less/forum.less');
        }
        return $f;
    })(),

    // --- storage -----------------------------------------------------------
    (new Extend\Model(Discussion::class))
        ->relationship('analyticsEvents', fn (AbstractModel $model) =>
            $model->hasMany(Event::class, 'discussion_id'))
        // hotness is a materialised column so sorting never scans the event
        // table; RecomputeRankCommand refreshes it on a schedule.
        ->cast('hotness', 'float'),

    // --- server-side capture ----------------------------------------------
    (new Extend\Event())
        ->listen(\Flarum\Post\Event\Posted::class, Listeners\CapturePosted::class)
        ->listen(\Flarum\Discussion\Event\Started::class, Listeners\CaptureStarted::class)
        ->listen(\Flarum\Discussion\Event\Deleted::class, Listeners\CaptureDeleted::class)
        ->listen(\Flarum\User\Event\LoggedIn::class, Listeners\CaptureLoggedIn::class)
        ->listen(\Flarum\User\Event\Registered::class, Listeners\CaptureRegistered::class)
        ->listen(\Flarum\Post\Event\Hidden::class, Listeners\CaptureModeration::class)
        ->listen(\Flarum\Discussion\Event\Renamed::class, Listeners\CaptureModeration::class),

    // Views + reads are middleware, not events: they happen on GET, which
    // dispatches no domain event.
    (new Extend\Middleware('forum'))
        ->add(Listeners\CaptureRequest::class),

    // --- api ---------------------------------------------------------------
    (new Extend\Routes('api'))
        ->post('/analytics/events', 'analytics.events.ingest', Api\IngestController::class)
        ->get('/analytics/feed', 'analytics.feed', Api\FeedController::class),

    (new Extend\ApiSerializer(\Flarum\Api\Serializer\DiscussionSerializer::class))
        ->attributes(Listeners\AddDiscussionAttributes::class),

    // sort discussions by the computed hotness score
    (new Extend\ApiController(\Flarum\Api\Controller\ListDiscussionsController::class))
        ->addSortField('hotness'),

    // --- ops ---------------------------------------------------------------
    (new Extend\Console())
        ->command(FlushCommand::class)          // spool -> PostHog
        ->command(RecomputeRankCommand::class), // events -> hotness

    (new Extend\Settings())
        ->serializeToForum('analytics.posthog.host', 'analytics.posthog.host')
        ->serializeToForum('analytics.posthog.key', 'analytics.posthog.key')
        ->serializeToForum('analytics.client.enabled', 'analytics.client.enabled', 'boolval', true)
        ->serializeToForum('analytics.consent.mode', 'analytics.consent.mode', null, 'implicit'),
];
