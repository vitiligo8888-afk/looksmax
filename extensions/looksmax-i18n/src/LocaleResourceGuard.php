<?php

namespace Local\I18n;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Locale\LocaleManager;
use Illuminate\Contracts\Container\Container;

/**
 * Stop `php flarum info` from silently deleting the English forum.
 *
 * ── The bug, measured on this box on 2026-08-13 ─────────────────────────────
 *
 * Reproduction, from a clean cache, inside flarum-app:
 *
 *     rm -f storage/locale/*
 *     curl -s -o /dev/null http://127.0.0.1/     → catalogue.en…php  79638 bytes  www-data
 *     rm -f storage/locale/*
 *     php flarum info                            → catalogue.en…php    133 bytes  root
 *
 * 133 bytes is `new MessageCatalogue('en', array())` — an empty catalogue with
 * an empty `.meta` (`a:0:{}`). Symfony's Translator caches per locale and, once
 * that file exists, every subsequent web request loads it instead of reading
 * the YAML. Every key in the forum then falls through to its own name, so a
 * visitor reads `core.forum.post_scrubber.original_post_link` where a sentence
 * should be. The site is HTTP 200 the whole time. `docker ps` is green.
 * `forum.css` is present. Nothing is logged.
 *
 * ── Why it happens ──────────────────────────────────────────────────────────
 *
 * Flarum\Foundation\ApplicationInfoProvider (line 85) type-hints
 * Flarum\Locale\Translator in its constructor and calls
 * $this->translator->trans('core.admin.dashboard.status.scheduler.*') at lines
 * 126–132. That resolves the `translator` singleton directly.
 *
 * But no `addTranslations()` call lives on the translator. Every one of them —
 * core's own core.yml and validation.yml in LocaleServiceProvider, every
 * Extend\Locales in every extension, every language pack — is registered
 * inside a `$container->resolving(LocaleManager::class, …)` callback. Resolving
 * the translator without ever touching LocaleManager therefore produces a
 * translator with ZERO resources, and the first trans() call dumps that
 * emptiness to disk as the authoritative cache.
 *
 * As root, because docker exec runs as root, so www-data cannot even overwrite
 * it afterwards. Which is the second half of the failure: the site cannot
 * self-heal on the next request.
 *
 * ── The fix ─────────────────────────────────────────────────────────────────
 *
 * Force LocaleManager to resolve whenever the translator does. The callback
 * cannot recurse: Laravel's container writes a shared instance into
 * $this->instances BEFORE firing resolving callbacks, so LocaleManager's own
 * `$container->make('translator')` gets the very object we are called about.
 * The re-entrancy flag is kept anyway — this runs on every request on a forum
 * that is being edited by six agents, and a stack overflow here is a white
 * page.
 *
 * This is a fix for a core defect applied from an extension, which is normally
 * the wrong shape. It is justified because the alternative is a rule that every
 * agent and every runbook must never run `flarum info` — an instruction that
 * has already been violated by e2e/deploy.sh, by three lanes' health checks and
 * by me, in the hour it took to find this.
 */
class LocaleResourceGuard extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->resolving('translator', function ($translator, Container $container) {
            static $inside = false;

            if ($inside) {
                return;
            }

            $inside = true;

            try {
                $container->make(LocaleManager::class);
            } finally {
                $inside = false;
            }
        });
    }
}
