<?php

namespace Local\I18n;

use Flarum\Api\Serializer\ForumSerializer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Make the forum's own settings speak the reader's language.
 *
 * ── The problem ─────────────────────────────────────────────────────────────
 *
 * `welcome_title`, `welcome_message` and `forum_description` are Flarum
 * SETTINGS: one row in the settings table, one value, shown to everybody. They
 * are not translation keys and Flarum has no notion of a per-locale setting.
 * The welcome banner is the first thing on the front page and the description
 * is the meta description and the og:description, so leaving them as a single
 * string means one of the two audiences reads the wrong language on the most
 * visible surface there is.
 *
 * ── The shape ───────────────────────────────────────────────────────────────
 *
 * The setting holds the SPANISH, because Spanish is the default and a setting
 * is what a visitor gets when everything else fails. This class then replaces
 * the serialized value with the translation for the locale actually being
 * served.
 *
 * That ordering matters: disable this extension and the forum degrades to
 * Spanish on a Spanish-first board, rather than to English or to blank. The
 * failure mode of the fallback should be the common case, not the rare one.
 *
 * A setting an admin has edited to something other than our shipped Spanish is
 * left alone. Otherwise editing the welcome message in the admin panel would
 * appear to work and then silently have no effect, which is a worse bug than
 * the one being fixed.
 */
class ForumStrings
{
    public function __construct(
        protected TranslatorInterface $translator
    ) {
    }

    public function __invoke(ForumSerializer $serializer, $model, array $attributes): array
    {
        $map = [
            'welcomeTitle' => 'local-looksmax-i18n.forum.welcome.title',
            'welcomeMessage' => 'local-looksmax-i18n.forum.welcome.message',
            'description' => 'local-looksmax-i18n.forum.meta.description',
        ];

        foreach ($map as $attribute => $key) {
            if (! array_key_exists($attribute, $attributes)) {
                continue;
            }

            $translated = $this->translator->trans($key);

            // A missing key comes back as the key itself. Never ship that.
            if ($translated === $key || $translated === '') {
                continue;
            }

            $current = (string) $attributes[$attribute];

            // Only replace what we recognise as ours: the value we ship in
            // either language. Anything else is an admin's own words.
            if ($current !== '' && ! $this->isOneOfOurs($key, $current)) {
                continue;
            }

            $attributes[$attribute] = $translated;
        }

        return $attributes;
    }

    /** Is this string the shipped value for this key in any installed locale? */
    private function isOneOfOurs(string $key, string $value): bool
    {
        foreach (['es', 'en'] as $locale) {
            if (trim($this->translator->trans($key, [], null, $locale)) === trim($value)) {
                return true;
            }
        }

        return false;
    }
}
