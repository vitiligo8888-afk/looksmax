<?php

use Flarum\Extend;
use Local\Index\Console\PaletteCommand;
use Local\Index\Console\SectionsCommand;
use Local\Index\Console\SitemapCommand;
use Local\Index\Console\TagColourCommand;
use Local\Index\RenderIndex;
// Fully qualified, not `Api\FragmentController::class`. This file declares no
// namespace, so a partially-qualified ::class resolves to the literal string
// "Api\FragmentController" and the route 500s from the container at dispatch
// time — a live bug found in flarum-analytics, where both API routes had been
// broken this way since the extension was written and nothing said so.
use Local\Index\Api\FragmentController;

/**
 * A purpose-built forum front page.
 *
 * Flarum's tags page is a tag cloud with tiles. A front page is a different
 * object: what this forum is, what is new, six places to go, and the board's
 * pulse — composed from configurable blocks across a left rail, a main column
 * and a right rail, rather than one 1100px column with 43% of the viewport
 * sitting empty beside it.
 *
 * Rendered server-side and injected into the document, then mounted by a small
 * script. That avoids overriding IndexPage, which 88 extensions already do and
 * which silently clobbers whichever of them loads second. See RenderIndex.
 */
return [
    // en.yml / es.yml, loaded into the `messages+intl-icu` domain. Spanish is
    // the forum's default locale, so es.yml is the SOURCE and en.yml is the
    // translation. Nothing on this page is a hardcoded string; the six section
    // names live in `forum.section.*.title` and are the operator's own words,
    // identical in both files.
    (new Extend\Locales(__DIR__ . '/locale')),

    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->content(RenderIndex::class),

    // Serves the same front-page markup to a client-side navigation, which no
    // longer carries the template in its document. See FragmentController.
    (new Extend\Routes('api'))
        ->get('/lmx-index/fragment', 'lmx-index.fragment', FragmentController::class),

    /*
     * The layout switch, persisted per user.
     *
     * A preference and not localStorage alone, because "cards or list" is a
     * decision about how somebody reads the forum and it should follow them to
     * their phone. localStorage carries it too — that is what makes the FIRST
     * paint correct for a guest, and for a member before the session payload has
     * been parsed. RenderIndex reads the preference server-side so a returning
     * reader's choice is in the first paint rather than a flash of the other
     * layout.
     *
     * `lmxNewsSeen` is the highest announcement id the reader has dismissed, so
     * a NEWER announcement re-opens the band by itself: the point is that a
     * daily reader stops seeing the same three items, not that they stop seeing
     * announcements.
     */
    (new Extend\User())
        ->registerPreference('lmxIndexView', fn ($v) => in_array($v, ['cards', 'list'], true) ? $v : 'cards', 'cards')
        ->registerPreference('lmxNewsSeen', fn ($v) => (int) $v, 0)
        ->registerPreference('lmxOnboardingDone', fn ($v) => (bool) $v, false),

    /*
     * Rail placement, the news source and the ad creative are settings rather
     * than constants, so an admin can move, retarget or switch off a block
     * without a deploy. Defaults live on the blocks themselves, which is why
     * `looksmax-index.rails` may be absent entirely and a block added later
     * still appears without this setting being rewritten.
     */
    (new Extend\Settings())
        ->serializeToForum('lmxIndexRails', 'looksmax-index.rails')
        ->serializeToForum('lmxIndexDefaultView', 'looksmax-index.default_view', null, 'cards')
        ->serializeToForum('lmxIndexNewsTag', 'looksmax-index.news_tag', null, 'f-11')
        ->serializeToForum('lmxIndexNewsLimit', 'looksmax-index.news_limit', 'intval', 4),

    (new Extend\Console())
        // Mirrors src/Palette.php into tags.color / tags.icon, so CORE surfaces —
        // the discussion list, the hero, the tag picker — get the same colours the
        // index renders. See PaletteCommand for why both paths exist.
        ->command(PaletteCommand::class)
        // Creates the six section tags and files the board into them. Additive
        // and idempotent; the class docblock says exactly what it will and will
        // not touch.
        ->command(SectionsCommand::class)
        // Gives every OTHER tag a measured colour. Before it ran, 40 of 47 tags
        // shared one blue, so "per category colour" rendered as no colour at
        // all. Reversible: --restore.
        ->command(TagColourCommand::class)
        // Rebuilds public/sitemap.xml from what is visible. It used to be a
        // static file that nothing regenerated, so it drifted into advertising
        // URLs that had become 404s. See SitemapCommand.
        ->command(SitemapCommand::class)
        ->schedule(SitemapCommand::class, function (\Illuminate\Console\Scheduling\Event $event) {
            // Daily is the right cadence: the sitemap only has to be true, not
            // instant, and rewriting it on every post would churn a file the
            // crawler reads a few times a week at most.
            $event->dailyAt('04:10');
        }),
];
