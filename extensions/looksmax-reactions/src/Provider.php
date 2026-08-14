<?php

namespace Local\Reactions;

use Flarum\Foundation\AbstractServiceProvider;

class Provider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Singleton, so "request-scoped" is literal: Flarum builds a fresh
        // container per request and the warmed counts die with it. Anything
        // longer-lived would serve one user's `mine` array to the next user.
        $this->container->singleton(Counts::class, function ($app) {
            return new Counts($app->make(\Illuminate\Database\ConnectionInterface::class));
        });
    }
}
