<?php

namespace Local\I18n;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Return the forum description in the language of the request.
 *
 * ── Why a decorator and not another content hook ────────────────────────────
 *
 * Head.php already rewrites `$document->meta['description']`, and it did not
 * work. Measured on `?lang=en`: `og:locale` came out `en_US` — so this lane's
 * hook definitely ran, with the English locale — and the description was still
 * Spanish. The reason is that `local-looksmax-brand`'s Head runs AFTER this
 * one, re-reads `forum_description` from the settings table, and writes it
 * three times: into `$document->meta['description']`, and as raw
 * `og:description` and `twitter:description` entries in `$document->head`,
 * which a meta-map overwrite cannot reach at all.
 *
 * I could have won that race by registering later, except that content-hook
 * order is extension boot order, which is not mine to choose and would change
 * the first time somebody adds an extension. Racing another lane's hook is not
 * a fix, it is a bet.
 *
 * Decorating the read fixes it at the source instead: core's
 * Frontend\Content\Meta, the brand lane's Head, the API serializer and anything
 * added later all ask the settings repository the same question and now get the
 * same, correct answer. No lane has to know this exists.
 *
 * ── Scope, deliberately tiny ────────────────────────────────────────────────
 *
 * Exactly one key, and only when the stored value is one this lane shipped. An
 * admin who types their own description gets their own description back —
 * otherwise editing that field in the admin panel would appear to work and then
 * silently do nothing, which is a worse bug than the one being fixed.
 *
 * `set()`, `delete()` and `all()` pass straight through untouched, so the
 * stored value is never rewritten by reading it.
 */
class LocalisedSettings implements SettingsRepositoryInterface
{
    private const KEYS = [
        'forum_description' => 'local-looksmax-i18n.forum.meta.description',
        'welcome_message' => 'local-looksmax-i18n.forum.welcome.message',
    ];

    /**
     * Resolving the translator asks the settings repository for
     * `default_locale`, which arrives back here. That cannot recurse — the
     * guarded keys are not `default_locale` — but this runs on every request on
     * a forum six agents are editing, and an infinite loop here is a white
     * page, so the flag stays.
     */
    private bool $inside = false;

    public function __construct(
        private SettingsRepositoryInterface $inner,
        private Container $container
    ) {
    }

    public function get($key, $default = null)
    {
        $value = $this->inner->get($key, $default);

        if (! isset(self::KEYS[$key]) || $this->inside) {
            return $value;
        }

        $this->inside = true;

        try {
            return $this->localise(self::KEYS[$key], (string) $value) ?? $value;
        } catch (\Throwable $e) {
            // A settings read must never be the thing that takes the forum
            // down. Untranslated is a blemish; a 500 is an outage.
            return $value;
        } finally {
            $this->inside = false;
        }
    }

    private function localise(string $key, string $stored): ?string
    {
        $translator = $this->container->make('translator');

        $translated = $translator->trans($key);

        // A missing key comes back as the key itself.
        if ($translated === $key || $translated === '') {
            return null;
        }

        if ($stored !== '' && ! $this->isShipped($translator, $key, $stored)) {
            return null;
        }

        return $translated;
    }

    /** Is the stored text the value this lane ships, in any installed locale? */
    private function isShipped($translator, string $key, string $stored): bool
    {
        foreach (array_keys($this->container->make('flarum.locales')->getLocales()) as $locale) {
            if (trim($translator->trans($key, [], null, $locale)) === trim($stored)) {
                return true;
            }
        }

        return false;
    }

    public function all(): array
    {
        return $this->inner->all();
    }

    public function set($key, $value)
    {
        $this->inner->set($key, $value);
    }

    public function delete($keyLike)
    {
        $this->inner->delete($keyLike);
    }
}
