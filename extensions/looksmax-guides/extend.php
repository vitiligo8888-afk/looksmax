<?php

use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\Extend;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Local\Guides\Api;
use Local\Guides\Console\ReindexCommand;
use Local\Guides\Formatter\Configure;
use Local\Guides\Listeners;
use Local\Guides\Models\GuideMeta;

/**
 * Long-form guides.
 *
 * The board's own numbers say why this exists: Best of the Best is 160 threads
 * carrying 40,000 posts — 250 posts per thread against Offtopic's 12.6 — and
 * Guide-prefixed threads average 2,339 views against Discussion's 520. The
 * scarce, demand-exceeding-supply asset on a board with 29.7M posts is the
 * long-form guide, and nothing in the 2,528-extension ecosystem addresses it.
 *
 * Design constraint, held throughout: ZERO override() calls, on either side.
 * The ecosystem sweep counts 90 method-level override conflicts, and the ones
 * this feature would otherwise walk into are the worst on the list — 32
 * extensions fight over IndexPage.hero, 10 over Post.contentHtml, 9 over
 * DiscussionPage.view, and all three table-of-contents extensions collide on
 * DiscussionPage.render. So: structure is produced server-side in the
 * formatter pipeline, new capabilities get new routes, and the frontend only
 * appends to ItemLists and decorates DOM the server already rendered.
 */
return [
    // The JS is injected as its own <script> element rather than added to the
    // shared forum.js bundle. See Listeners\InjectScript for the measurement
    // that forced this: flarum/markdown's s9e preview module calls
    // `new XSLTProcessor` at bundle top level, Chrome 149 has removed
    // XSLTProcessor, and the resulting uncaught throw aborts every extension
    // bundle concatenated after it — which is currently all of them.
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->content(Listeners\InjectScript::class),

    // --- content ------------------------------------------------------------
    // configure() has 175 users across the corpus and zero override conflicts,
    // which is why every guide element lives here rather than in a JS renderer.
    (new Extend\Formatter())
        ->configure(Configure::class),

    // --- data ---------------------------------------------------------------
    (new Extend\Model(Discussion::class))
        ->cast('is_guide', 'boolean')
        ->relationship('guideMeta', fn (AbstractModel $model) => $model->hasOne(GuideMeta::class, 'discussion_id')),

    (new Extend\ApiSerializer(DiscussionSerializer::class))
        ->attributes(Listeners\AddDiscussionAttributes::class),

    // Sorting the library by freshness or evidence needs the satellite, but
    // sorting the ordinary discussion list by "is this a guide" only needs the
    // column — which is exactly why the column exists.
    // Eager-load on the LIST too, not just the single discussion.
    //
    // The serializer falls back to GuideMeta::find() when the relation is not
    // loaded, which is correct but is an N+1: a 20-row page becomes 20 extra
    // point queries. That is invisible on a test forum and is exactly the
    // class of defect that only shows up under load — the extension audit
    // found the same shape shipped in fof/reactions (3 queries per post) and
    // v17development/flarum-seo (a likes()->count() per post). One eager load
    // makes it one query for the page regardless of row count.
    (new Extend\ApiController(\Flarum\Api\Controller\ListDiscussionsController::class))
        ->addSortField('is_guide')
        ->load('guideMeta'),

    (new Extend\ApiController(\Flarum\Api\Controller\ShowDiscussionController::class))
        ->load('guideMeta'),

    // --- lifecycle ----------------------------------------------------------
    (new Extend\Event())
        ->listen(Posted::class, [Listeners\IndexGuide::class, 'handlePosted'])
        ->listen(Revised::class, [Listeners\IndexGuide::class, 'handleRevised'])
        ->listen(Deleted::class, [Listeners\IndexGuide::class, 'handleDeleted'])
        // Deleting a discussion dispatches no per-post Deleted event, so
        // without this listener the satellite rows outlive the discussion and
        // the guide library starts listing threads that no longer exist.
        ->listen(DiscussionDeleted::class, [Listeners\IndexGuide::class, 'handleDiscussionDeleted']),

    // --- api ----------------------------------------------------------------
    // New routes, not decorated existing ones. A new route has no conflict
    // surface at all, which is the whole reason the frontend can stay clean.
    (new Extend\Routes('api'))
        ->get('/guides', 'guides.index', Api\ListGuidesController::class)
        ->post('/guides/{id:[0-9]+}/review', 'guides.review', Api\ReviewGuideController::class),

    // --- ops ----------------------------------------------------------------
    (new Extend\Console())
        ->command(ReindexCommand::class),

    (new Extend\Settings())
        ->serializeToForum('guides.libraryEnabled', 'guides.libraryEnabled', 'boolval', true)
        ->serializeToForum('guides.minTierDefault', 'guides.minTierDefault', 'intval', 0),

    // --- i18n ---------------------------------------------------------------
    // locale/en.yml + locale/es.yml. The pack also carries keys for the
    // formatter labels (callouts, spec sheet, regimen table, evidence tiers),
    // which are baked into the cached XSLT and therefore still English on the
    // page — see the `deferred` block of locale/i18n-map.json for the
    // mechanism that would connect them.
    (new Extend\Locales(__DIR__ . '/locale')),
];
