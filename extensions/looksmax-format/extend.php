<?php

use Flarum\Extend;
use Flarum\Foundation\Paths;
use Illuminate\Contracts\Container\Container;
use Local\Format\BalanceTags;
use Local\Format\Configure;
use Local\Format\Console\WarmMediaCommand;
use Local\Format\InjectScript;
use Local\Format\Media\Fetcher;
use Local\Format\Media\ProxyController;
use Local\Format\Media\Signer;
use Local\Format\Media\Store;
use Local\Format\ResolveInternalLinks;
use Local\Format\ResolveQuoteLinks;
use Local\Format\RewriteMedia;
use Local\Format\SecurityHeaders;

/**
 * Post content formatting.
 *
 * Everything here hangs off Extend\Formatter, which is the only supported way
 * to reach the s9e/TextFormatter configurator Flarum builds and caches. That
 * cache is keyed on the configuration, so `php flarum cache:clear` is required
 * after changing anything in Configure — a stale formatter cache silently
 * keeps rendering the old vocabulary.
 *
 * ORDER OF THE RENDER HOOKS MATTERS. They run in the order registered:
 *
 *   1. ResolveQuoteLinks     source post/member id -> local url, avatar, name
 *   2. ResolveInternalLinks  looksmax.org thread links -> local discussion
 *   3. RewriteMedia          every remaining third-party media url -> our proxy
 *
 * RewriteMedia is deliberately LAST: the two hooks before it can introduce new
 * absolute URLs (a resolved avatar, a source permalink fallback), and the whole
 * point of RewriteMedia is that nothing gets past it.
 */
return [
    (new Extend\Formatter())
        ->configure(Configure::class)
        // Runs on the text before s9e sees it. Strips closing tags with no
        // opener, which s9e would otherwise (correctly, by its own rules) hand
        // to the reader as literal "[/spoiler]".
        ->parse(BalanceTags::class)
        ->render(ResolveQuoteLinks::class)
        ->render(ResolveInternalLinks::class)
        ->render(RewriteMedia::class),

    /*
     * Referrer-Policy: no-referrer + a report-only img-src CSP.
     *
     * REPLACE, not add. Flarum core's ReferrerPolicyHeader uses
     * `withAddedHeader`, so simply adding our own middleware produced TWO
     * Referrer-Policy headers — `no-referrer` and core's `same-origin` — and
     * per spec the last valid value wins, which is core's. Measured with
     * `curl -sI`: both headers were present and the weaker one was effective.
     * Swapping core's middleware for this one is the only way to end up with a
     * single, correct value.
     */
    (new Extend\Middleware('forum'))
        ->replace(\Flarum\Http\Middleware\ReferrerPolicyHeader::class, SecurityHeaders::class),

    /*
     * The media proxy.
     *
     * `payload` is base64url of the source URL and can be long, so the route
     * pattern is greedy on that segment. `sig` and `hash` are fixed-width.
     */
    (new Extend\Routes('forum'))
        ->get('/media/p/{sig}/{hash}/{payload}', 'lmx.media.proxy', ProxyController::class),

    (new Extend\Console())->command(WarmMediaCommand::class),

    (new Extend\ServiceProvider())->register(MediaProvider::class),

    (new Extend\Frontend('forum'))
        ->css(__DIR__.'/less/forum.less')
        // Shipped as its own <script> element, never appended to the shared
        // bundle: a bare IIFE in the bundle aborts bootExtensions and blanks
        // the whole SPA (this stack has hit that exact failure).
        ->content(InjectScript::class),

    (new Extend\Frontend('admin'))
        ->css(__DIR__.'/less/forum.less'),

    // The chrome this extension renders inside post content — the spoiler
    // label, "said:", the post-label chips, the copy button — in en and es.
    (new Extend\Locales(__DIR__.'/locale')),
];

/**
 * Wiring for the media proxy's three collaborators.
 *
 * A provider rather than closures inline so the same Signer instance (and
 * therefore the same key) is shared by the render hook that MINTS urls and the
 * controller that VERIFIES them. Two instances with different keys would mean
 * every image 404s, which is a miserable thing to debug.
 */
class MediaProvider extends \Flarum\Foundation\AbstractServiceProvider
{
    public function register()
    {
        $this->container->singleton(Signer::class, function (Container $container) {
            $config = $container->make('flarum.config');

            // Flarum's install-time secret. Per-install, never in the repo.
            $key = (string) ($config['api_key'] ?? $config['database']['password'] ?? '');
            if ($key === '') {
                // Never fall back to a constant: an empty or shared key makes
                // the signature worthless and the proxy an open relay.
                $key = hash('sha256', (string) $container->make(Paths::class)->base);
            }

            return new Signer('lmx-media:'.$key);
        });

        $this->container->singleton(Store::class, function (Container $container) {
            $storage = $container->make(Paths::class)->storage;

            return new Store($storage.'/media-cache');
        });

        $this->container->singleton(Fetcher::class, fn () => new Fetcher());
    }
}
