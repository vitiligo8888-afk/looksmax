<?php

namespace Local\I18n;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Wrap the settings repository in LocalisedSettings.
 *
 * `extend()` rather than a fresh binding, so this composes with whatever any
 * other extension has already done to the repository instead of replacing it —
 * the store lane and the brand lane both read settings, and neither should have
 * to know this exists.
 *
 * Registered in `register()`, not `boot()`: core resolves the settings
 * repository while building the translator (it needs `default_locale`), which
 * happens well before boot.
 */
class LocaleSettingsProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->extend(
            SettingsRepositoryInterface::class,
            function (SettingsRepositoryInterface $inner, Container $container) {
                // Already wrapped — extend() can fire more than once when the
                // binding is rebound during a console command's lifecycle.
                if ($inner instanceof LocalisedSettings) {
                    return $inner;
                }

                return new LocalisedSettings($inner, $container);
            }
        );
    }
}
