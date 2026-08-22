<?php

namespace Local\I18n;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Locale\LocaleManager;

/**
 * Re-registers this extension's locale files LAST, so its overrides win.
 *
 * ── The problem ─────────────────────────────────────────────────────────────
 *
 * Half of locale/es.yml exists to REPLACE strings shipped by
 * flarum-lang/spanish — the dated interjections ("¡Nanay de la China!",
 * "¡Caramba!") that read as a grandparent's Spanish to this forum's Mexican
 * audience. Symfony's translator resolves a duplicate key by LAST REGISTRATION
 * WINS, and Flarum registers locale files in the order extensions boot.
 *
 * That order is not dependency-resolved. `ExtensionManager::getEnabledExtensions()`
 * simply walks the `extensions_enabled` setting — a plain JSON array — in the
 * order it happens to be stored (vendor/flarum/core/src/Extension/ExtensionManager.php:397-409).
 * This extension sat at index 15 and the Spanish pack at 29, so the pack was
 * registered afterwards and won every collision: every override in our es.yml
 * was silently dead, and the forum kept showing the pack's text.
 *
 * Declaring `optional-dependencies` on the pack does NOT fix this — that field
 * is read (Extension.php:252) but only guards disabling, it does not reorder
 * the enabled list.
 *
 * ── Why a service provider fixes it for good ────────────────────────────────
 *
 * Extenders all run during registration; a service provider's boot() runs
 * after that. Adding our files here therefore appends them after every
 * extension's — including any language pack, and including packs enabled in
 * future — so the override holds no matter what order the extensions are
 * stored in. Reordering the setting by hand fixes it once; this keeps it fixed.
 *
 * Registering the same file twice is harmless: the catalogue is a key→string
 * map, so the second pass overwrites its own identical values.
 */
class LastWordProvider extends AbstractServiceProvider
{
    public function boot(LocaleManager $locales): void
    {
        foreach (['en', 'es'] as $locale) {
            $file = __DIR__ . '/../locale/' . $locale . '.yml';

            if (is_file($file)) {
                $locales->addTranslations($locale, $file);
            }
        }
    }
}
