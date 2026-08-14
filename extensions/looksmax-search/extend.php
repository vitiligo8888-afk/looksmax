<?php

/**
 * Search.
 *
 * ## Why this is not "install a Meilisearch extension"
 *
 * Flarum 1.8 has exactly one seam into search: `Extend\SimpleFlarumSearch`
 * ->setFullTextGambit(). It is enough to change WHICH documents match and in
 * what order, and structurally incapable of anything else, because
 * `AbstractSearcher::search()` applies gambits first and only then applies
 * sort, offset and limit to the SQL builder. So through that seam there is:
 * no total count, no engine-side pagination, no facets, no highlighting, and
 * no way to return anything that is not a discussion row. `Extend\SearchDriver`
 * — which fixes all of that — is Flarum 2.0 only; it does not exist in this
 * tree (verified: `vendor/flarum/core/src/Extend/SearchDriver.php` is absent).
 *
 * So the extension has two planes, and both are wired here:
 *
 *   **compatibility plane** — the gambit. `/api/discussions?filter[q]=` and
 *   therefore every tag page, every third-party list and every API client gets
 *   engine results with no knowledge of this extension. Bounded, honest about
 *   its window.
 *
 *   **search plane** — our own routes. Facets, highlighting, real pagination,
 *   real totals, mixed result types, recovery from zero results. This is what
 *   the results page, the autocomplete and the command palette actually use.
 *
 * ## Why the JS is injected as its own element
 *
 * Flarum concatenates every extension's forum JS into one bundle: one
 * top-level throw stops every extension registered after it, and a malformed
 * export aborts `bootExtensions` and blanks the SPA. Both have happened in this
 * stack. `->content()` puts our script in its own element with its own error
 * boundary, so a bug in search breaks search and nothing else. This is the same
 * decision looksmax-icons and looksmax-index already made.
 */

use Flarum\Extend;
use Flarum\Discussion\Search\DiscussionSearcher;
use Local\Search\Api;
use Local\Search\Console;
use Local\Search\Listeners;
use Local\Search\Search\MeiliFulltextGambit;

return [
    // ------------------------------------------------------------------ locale
    // en.yml / es.yml, loaded into the `messages+intl-icu` domain so ICU
    // plurals work on both the PHP and the JS side.
    (new Extend\Locales(__DIR__ . '/locale')),

    // ---------------------------------------------------------------- frontend
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->content(Listeners\InjectSearch::class)
        // The results page is a real URL, not a modal. `/search?q=…` has to be
        // linkable, shareable, back-buttonable and server-rendered for a
        // crawler — a search you cannot send to someone is half a search.
        // Flarum 1.8 ships no /search route at all, so this creates one.
        ->route('/search', 'looksmax.search.page', Listeners\RenderSearchPage::class),

    (new Extend\Frontend('admin'))
        ->css(__DIR__ . '/less/admin.less')
        ->content(Listeners\InjectAdmin::class),

    // ------------------------------------------------------- compatibility plane
    (new Extend\SimpleFlarumSearch(DiscussionSearcher::class))
        ->setFullTextGambit(MeiliFulltextGambit::class),

    // ------------------------------------------------------------- search plane
    (new Extend\Routes('api'))
        ->get('/looksmax/search', 'looksmax.search', Api\SearchController::class)
        ->get('/looksmax/search/suggest', 'looksmax.search.suggest', Api\SuggestController::class)
        ->get('/looksmax/search/facet', 'looksmax.search.facet', Api\FacetController::class)
        ->post('/looksmax/search/click', 'looksmax.search.click', Api\ClickController::class)
        ->get('/looksmax/search/saved', 'looksmax.search.saved', Api\SavedSearchController::class)
        ->post('/looksmax/search/saved', 'looksmax.search.saved.create', Api\SavedSearchController::class)
        ->delete('/looksmax/search/saved', 'looksmax.search.saved.delete', Api\SavedSearchController::class)
        ->get('/looksmax/search/insights', 'looksmax.search.insights', Api\InsightsController::class)
        ->get('/looksmax/search/status', 'looksmax.search.status', Api\StatusController::class)

        // ------------------------------------------------------ discovery plane
        // Search is what you do when you already know you are looking for
        // something. These are the surfaces for the much larger case where you
        // do not: a related-topics strip under a thread, a "for you" list on the
        // index, what is moving right now, and a duplicate check in the composer.
        // All four are permission-filtered through the same two gates a search
        // uses (Engine::engineFilter + Engine::hydrateHits), because a strip
        // generated FOR a reader rather than requested BY one is where a
        // restricted tag leaks without anybody noticing.
        ->get('/looksmax/search/related', 'looksmax.search.related', Api\RelatedController::class)
        ->get('/looksmax/search/recommend', 'looksmax.search.recommend', Api\RecommendController::class)
        ->get('/looksmax/search/trending', 'looksmax.search.trending', Api\TrendingController::class)
        ->post('/looksmax/search/duplicates', 'looksmax.search.duplicates', Api\DuplicatesController::class),

    // ------------------------------------------------------ automatic indexing
    // Model events, not domain events: see QueueIndexChanges for why. This is
    // what makes new and edited content searchable with no manual reindex.
    (new Extend\Event())
        ->subscribe(Listeners\QueueIndexChanges::class),

    // Drains the outbox every minute. The daemon mode of the same command
    // exists for sub-second freshness; this is the floor, so freshness never
    // depends on someone having set up a supervisor.
    (new Extend\Console())
        ->command(Console\IndexCommand::class)
        ->command(Console\SyncCommand::class)
        ->command(Console\StatusCommand::class)
        ->command(Console\EmbedCommand::class)
        ->schedule(Console\SyncCommand::class, function (\Illuminate\Console\Scheduling\Event $event) {
            $event->everyMinute()->withoutOverlapping(5);
        })
        // rank_score carries a recency term that decays; without a refresh the
        // tie-break freezes at whatever it was when the document was written.
        ->schedule(Console\SyncCommand::class, function (\Illuminate\Console\Scheduling\Event $event) {
            $event->hourly()->withoutOverlapping(30);
        }, ['--scores' => true])
        // Post documents denormalise their discussion's title and tags.
        ->schedule(Console\SyncCommand::class, function (\Illuminate\Console\Scheduling\Event $event) {
            $event->hourly()->withoutOverlapping(30);
        }, ['--reconcile' => true]),

    // ---------------------------------------------------------------- settings
    (new Extend\Settings())
        ->serializeToForum('looksmax-search.enabled', 'looksmax-search.enabled', 'boolval', true)
        ->serializeToForum('looksmax-search.paletteEnabled', 'looksmax-search.paletteEnabled', 'boolval', true)
        ->serializeToForum('looksmax-search.minChars', 'looksmax-search.minChars', 'intval', 2)
        ->serializeToForum('looksmax-search.debounceMs', 'looksmax-search.debounceMs', 'intval', 120)
        ->serializeToForum('looksmax-search.prefix', 'looksmax-search.prefix', null, 'lmx')
        ->default('looksmax-search.host', 'http://flarum-meili:7700')
        ->default('looksmax-search.prefix', 'lmx')
        ->default('looksmax-search.timeout', 5)
        // Semantic search. Off by default and serialized to the forum payload
        // so the UI lane can render a "semantic" affordance only when there is
        // actually a vector index behind it.
        ->serializeToForum('looksmax-search.semantic', 'looksmax-search.embed.enabled', 'boolval', false)
        ->default('looksmax-search.embed.wire', 'tei')
        ->default('looksmax-search.embed.url', 'http://lmx-embed:80/embed')
        ->default('looksmax-search.embed.dimensions', 384)
        ->default('looksmax-search.embed.cap', 420)
        ->default('looksmax-search.embed.ratio', 0.35),

    (new Extend\ServiceProvider())
        ->register(Local\Search\Provider::class),
];
