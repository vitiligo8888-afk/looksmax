<?php

use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Extend;
use Flarum\Http\Middleware\SetLocale;
use Local\I18n\ForumStrings;
use Local\I18n\Head;
use Local\I18n\InjectScript;
use Local\I18n\LocaleResourceGuard;
use Local\I18n\LocaleSettingsProvider;
use Local\I18n\NegotiateLocale;

/**
 * Internationalisation for Looksmax.lat.
 *
 * Spanish is the primary language and English is a first-class second. Neither
 * is the "source" language in the code: every user-visible string in every
 * extension is a key, and both locales are shipped as data next to it.
 *
 * ── What lives where ────────────────────────────────────────────────────────
 *
 *  core + the bundled flarum/* extensions   flarum-lang/spanish 1.13.2
 *      Sourced, not written. 93.7% of core's 589 keys and 98.9% of the 278
 *      bundled-extension keys, all of it human Spanish rather than machine
 *      output, in its "informal" (tú) register. The 37 core keys it is missing
 *      are 1.8.16–1.8.18 additions and all but two are admin-only; the two
 *      that a visitor can see are filled in locale/es.yml here.
 *
 *  our own fourteen extensions              each extension's own locale/ dir
 *      Written here. Adding a locale directory and one Extend\Locales line is
 *      a one-line touch to an extension another agent is editing; rewriting
 *      its source is not, so which of those has happened is recorded in
 *      HANDOFF-I18N.md rather than assumed.
 *
 *  this extension                           locale/{en,es}.yml
 *      The switcher's own strings, plus the handful of core keys the sourced
 *      pack has not caught up with. Later-registered resources win in Symfony
 *      Translation, so an override here beats the pack for the same key.
 *
 * ── The one thing that must never regress ───────────────────────────────────
 *
 * LocaleResourceGuard. `php flarum info` — which e2e/deploy.sh and three
 * lanes' health checks all run — writes an EMPTY English message catalogue to
 * storage/locale and every subsequent request serves it, so the whole forum
 * renders raw translation keys at HTTP 200 with nothing in the logs. The
 * reproduction and the cause are in that file. tools/gate.ts fails a deploy
 * that leaves the catalogue in that state.
 */
return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__.'/less/forum.less')
        ->content(Head::class)
        ->content(InjectScript::class),

    // The admin panel needs the script too, not just the stylesheet. It was
    // CSS-only at first, which meant `window.lmxI18n` did not exist there at
    // all — so any lane routing an admin-side count or date through it got a
    // ReferenceError and an unrendered settings pane. Somebody has to run this
    // forum, and they read numbers too.
    (new Extend\Frontend('admin'))
        ->css(__DIR__.'/less/forum.less')
        ->content(InjectScript::class),

    (new Extend\Locales(__DIR__.'/locale')),

    // The welcome banner and the meta description are settings, not keys.
    // ForumStrings translates them per request without taking the setting away
    // from the admin who edits it.
    (new Extend\ApiSerializer(ForumSerializer::class))
        ->attributes(ForumStrings::class),

    (new Extend\ServiceProvider())
        ->register(LocaleResourceGuard::class)
        ->register(LocaleSettingsProvider::class),

    // AFTER core's SetLocale, never before. Core reads the `locale` cookie for
    // guests only; a signed-in member with no stored preference never reaches
    // that branch, so anything this writes before core runs is discarded for
    // exactly the users who are hardest to notice. The reasoning, and the
    // browser evidence that caught it, are in NegotiateLocale.
    (new Extend\Middleware('forum'))
        ->insertAfter(SetLocale::class, NegotiateLocale::class),

    (new Extend\Middleware('api'))
        ->insertAfter(SetLocale::class, NegotiateLocale::class),
];
