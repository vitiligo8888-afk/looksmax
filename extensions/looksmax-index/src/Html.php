<?php

namespace Local\Index;

/**
 * The three things every block does to a string, in one place.
 *
 * Escaping is not optional anywhere in this extension: the whole index is
 * server-rendered from user-supplied titles and usernames and then injected into
 * the document. One unescaped title is a stored XSS on the front page.
 */
class Html
{
    public static function esc(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** Escaped, trimmed to width, with a real ellipsis rather than "...". */
    public static function trim(?string $s, int $width): string
    {
        return self::esc(mb_strimwidth(trim((string) $s), 0, $width, '…'));
    }

    /**
     * Accepts a FontAwesome class ("fas fa-globe") or an Iconify name
     * ("ph:globe-fill"), told apart by the colon no FA class contains.
     * looksmax-icons swaps `<i class="fa-*">` for `<iconify-icon>` in the DOM but
     * only for names it has a mapping for; emitting the custom element directly
     * is exact and skips that map.
     */
    public static function icon(?string $name, string $class = ''): string
    {
        $name = $name ?: 'ph:chats-circle-fill';
        $c = $class ? ' class="' . self::esc($class) . '"' : '';

        return str_contains($name, ':')
            ? '<iconify-icon icon="' . self::esc($name) . '"' . $c . ' aria-hidden="true"></iconify-icon>'
            : '<i class="' . self::esc($name) . ($class ? ' ' . self::esc($class) : '') . '"></i>';
    }

    /** Flarum's canonical discussion URL. */
    public static function discussionUrl(int $id, ?string $slug): string
    {
        return '/d/' . $id . ($slug ? '-' . self::esc($slug) : '');
    }

    /**
     * A first post reduced to one readable line.
     *
     * The bodies are s9e XML with the original bbcode preserved inside `<s>`/
     * `<e>` pairs and images, mentions and emotes as elements. Stripping tags
     * alone leaves the duplicated bbcode source in the text, which is why an
     * early version of the news block rendered excerpts that began
     * "[img alt=…]https://i.looksmax.org/…". Those pairs go first.
     */
    public static function excerpt(?string $xml, int $width): string
    {
        $s = (string) $xml;
        $s = preg_replace('#<s>.*?</s>|<e>.*?</e>|<CODE>.*?</CODE>|<QUOTE>.*?</QUOTE>#su', ' ', $s) ?? $s;
        $s = preg_replace('#<IMG[^>]*>.*?</IMG>|<IMG[^>]*/?>#su', ' ', $s) ?? $s;
        $s = preg_replace('#<USERMENTION[^>]*>(.*?)</USERMENTION>#su', '$1', $s) ?? $s;
        $s = preg_replace('#<[^>]+>#u', ' ', $s) ?? $s;
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('#https?://\S+#u', ' ', $s) ?? $s;
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);

        return self::trim($s, $width);
    }

    /**
     * A relative time the browser will re-render.
     *
     * The string is server-rendered so it is right without JavaScript, and
     * carries a `datetime` so the SPA's own humanizer can take over. Rendering an
     * absolute date here would have been wrong for a different reason: the server
     * is UTC and the reader is not.
     */
    public static function time(?string $iso): string
    {
        if (! $iso) {
            return '';
        }

        $ts = strtotime($iso);
        if (! $ts) {
            return '';
        }

        $d = max(0, time() - $ts);
        $s = match (true) {
            $d < 90 => 'now',
            $d < 3600 => floor($d / 60) . 'm',
            $d < 86400 => floor($d / 3600) . 'h',
            $d < 2592000 => floor($d / 86400) . 'd',
            $d < 31536000 => floor($d / 2592000) . 'mo',
            default => floor($d / 31536000) . 'y',
        };

        return '<time datetime="' . self::esc(gmdate('c', $ts)) . '">' . self::esc($s) . '</time>';
    }

    /**
     * A figure grouped the way THIS reader's locale groups it.
     *
     * Not `number_format()`, which is hardwired to the English 1,234 — and not
     * an ICU `{n, number}` message either, because ICU is handed Flarum's locale
     * code `es`, and generic Spanish groups differently from Mexican Spanish.
     * Measured against CLDR (this finding is the i18n lane's, from
     * `locale/i18n-map.json`, and is adopted here rather than re-derived):
     *
     *     es -> 12.431      es-MX -> 12,431      es-419 -> 12,431      en -> 12,431
     *
     * The audience is Mexican and `window.lmxI18n` already maps `es -> es-MX`
     * for everything it formats on the client
     * (looksmax-i18n/js/dist/forum.js:44). Without the same mapping on this
     * side, this server-rendered index would print 12.431 next to a
     * client-rendered search result printing 12,431 on the same page.
     *
     * Rendered with `font-variant-numeric: tabular-nums` so columns of these
     * line up.
     */
    public static function num(int|float|null $n, ?string $locale = null): string
    {
        $v = (float) $n;

        if ($locale === null) {
            try {
                $locale = resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->getLocale();
            } catch (\Throwable $e) {
                $locale = 'en';
            }
        }

        if ($locale === 'es') {
            $locale = 'es-MX';
        }

        if (class_exists(\NumberFormatter::class)) {
            $formatted = (new \NumberFormatter($locale, \NumberFormatter::DECIMAL))->format($v);
            if ($formatted !== false) {
                return self::esc($formatted);
            }
        }

        return number_format($v);
    }
}
