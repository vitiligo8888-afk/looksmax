<?php

namespace Local\Search;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Settings\SettingsRepositoryInterface;
use Local\Search\Meili\Client;
use Local\Search\Meili\DocumentBuilder;
use Local\Search\Meili\Indexer;
use Local\Search\Search\Engine;
use Local\Search\Search\Fallback;
use Local\Search\Search\QueryParser;

/**
 * Everything is a singleton on purpose.
 *
 * The container would auto-wire all of these, but it would build a new one per
 * injection: a request that renders a page, runs the gambit and answers a
 * suggest call would construct three Guzzle clients and read the settings table
 * three times. More importantly, `Engine` memoises the actor's denied-tag set
 * for the request, and that memo is worthless if the object is rebuilt on every
 * resolution.
 */
class Provider extends AbstractServiceProvider
{
    public function register()
    {
        $this->container->singleton(QueryParser::class);

        $this->container->singleton(Client::class, fn ($app) => new Client(
            $app->make(SettingsRepositoryInterface::class),
            $app->make('log')
        ));

        $this->container->singleton(DocumentBuilder::class, function ($app) {
            $b = new DocumentBuilder($app->make('flarum.db'));
            // The embedding text cap is a throughput lever measured in
            // Embedder's docblock; it has to be the SAME value here (which
            // writes `embed_text`) and there (which sizes the Meilisearch
            // template limit), or documents get truncated twice at different
            // points and every one of them re-embeds on the next sync.
            $b->embedCap = $app->make(\Local\Search\Meili\Embedder::class)->textCap();

            return $b;
        });

        $this->container->singleton(\Local\Search\Meili\Embedder::class, fn ($app) => new \Local\Search\Meili\Embedder(
            $app->make(SettingsRepositoryInterface::class),
            $app->make('log')
        ));

        $this->container->singleton(\Local\Search\Search\SemanticPolicy::class);

        $this->container->singleton(Indexer::class, fn ($app) => new Indexer(
            $app->make(Client::class),
            $app->make(DocumentBuilder::class),
            $app->make('flarum.db'),
            $app->make(SettingsRepositoryInterface::class),
            $app->make('log')
        ));

        $this->container->singleton(Engine::class, fn ($app) => new Engine(
            $app->make(Client::class),
            $app->make(QueryParser::class),
            $app->make('flarum.db'),
            $app->make(SettingsRepositoryInterface::class),
            $app->make('log'),
            $app->make(\Local\Search\Meili\Embedder::class),
            $app->make(\Local\Search\Search\SemanticPolicy::class)
        ));

        $this->container->singleton(\Local\Search\Search\Semantic::class, fn ($app) => new \Local\Search\Search\Semantic(
            $app->make(Client::class),
            $app->make(\Local\Search\Meili\Embedder::class),
            $app->make(Engine::class),
            $app->make('flarum.db'),
            $app->make(SettingsRepositoryInterface::class),
            $app->make('log')
        ));

        $this->container->singleton(Fallback::class, fn ($app) => new Fallback(
            $app->make(QueryParser::class),
            $app->make('flarum.db')
        ));
    }
}
