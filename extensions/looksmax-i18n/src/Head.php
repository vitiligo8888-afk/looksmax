<?php

namespace Local\I18n;

use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\LocaleManager;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UriInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Per-locale document metadata: hreflang alternates and Open Graph locale.
 *
 * The forum serves both languages from one set of URLs, chosen by cookie. To a
 * crawler that is one site in one language — whichever language the crawler's
 * own Accept-Language happened to negotiate — and the other language is
 * invisible. `?lang=` (handled in NegotiateLocale) gives every page a stable
 * address per language, and these tags are what tell a search engine that those
 * addresses are the same page rather than duplicates.
 *
 * Three rules, all of which are easy to get wrong:
 *
 *  - Alternates must be reciprocal and must include the page itself. A set that
 *    lists only the OTHER languages is ignored outright.
 *  - x-default points at the plain URL with no ?lang=, which is the one that
 *    negotiates. That is exactly what x-default means: the URL for a visitor
 *    whose language you do not know.
 *  - The canonical stays the plain URL. Pointing it at ?lang=es would tell a
 *    crawler the English page is a duplicate of the Spanish one and should be
 *    dropped from the index.
 *
 * Query strings other than `lang` are dropped from the alternates. Discussion
 * URLs on this forum carry ?page= and the search page carries ?q=, and building
 * an alternate set that varies per query parameter produces thousands of
 * near-duplicate declarations for no gain.
 */
class Head
{
    /**
     * Region hints for og:locale, which wants a full language_TERRITORY tag.
     * The audience is Mexican, so `es` is es_MX rather than es_ES.
     */
    private const TERRITORY = [
        'es' => 'es_MX',
        'en' => 'en_US',
    ];

    public function __construct(
        protected LocaleManager $locales,
        protected UrlGenerator $url,
        protected TranslatorInterface $translator
    ) {
    }

    public function __invoke(Document $document, Request $request): void
    {
        $this->localiseDescription($document);

        $installed = array_keys($this->locales->getLocales());

        // One installed language means there is nothing to alternate with, and
        // a self-referential hreflang set of size one is noise.
        if (count($installed) < 2) {
            return;
        }

        $current = $this->locales->getLocale();
        $base = $this->baseUrl($request);

        $document->head[] = sprintf(
            '<link rel="alternate" hreflang="x-default" href="%s">',
            htmlspecialchars($base, ENT_QUOTES)
        );

        foreach ($installed as $code) {
            $document->head[] = sprintf(
                '<link rel="alternate" hreflang="%s" href="%s">',
                htmlspecialchars($code, ENT_QUOTES),
                htmlspecialchars($this->withLang($base, $code), ENT_QUOTES)
            );
        }

        $document->meta['og:locale'] = self::TERRITORY[$current] ?? $current;

        foreach ($installed as $code) {
            if ($code === $current) {
                continue;
            }
            // Document::$meta is a map, so repeated og:locale:alternate values
            // cannot be expressed through it; emit them directly.
            $document->head[] = sprintf(
                '<meta property="og:locale:alternate" content="%s">',
                htmlspecialchars(self::TERRITORY[$code] ?? $code, ENT_QUOTES)
            );
        }
    }

    /**
     * The meta description, in the language of the page it describes.
     *
     * ForumStrings already localises the `description` attribute on the API
     * payload, which is what the SPA reads. The document's `<meta>` is a
     * separate path: core's Frontend\Content\Meta pulls `forum_description`
     * straight out of the settings table and never sees the translator, so
     * before this the Spanish description shipped on the English page — and
     * the meta description is exactly the string that a search result and a
     * pasted link show, i.e. the copy read by people who are not on the site
     * yet.
     *
     * The brand lane also writes `description`, `og:description` and
     * `twitter:description` from the same setting. This runs after it —
     * `local-looksmax-brand` sorts before `local-looksmax-i18n` — so all three
     * are corrected together rather than drifting apart.
     */
    private function localiseDescription(Document $document): void
    {
        $key = 'local-looksmax-i18n.forum.meta.description';
        $translated = $this->translator->trans($key);

        if ($translated === $key || $translated === '') {
            return;
        }

        foreach (['description', 'og:description', 'twitter:description'] as $name) {
            if (isset($document->meta[$name])) {
                $document->meta[$name] = $translated;
            }
        }

        // Core sets `description` unconditionally when the setting is
        // non-empty; if some path left it unset, the page still deserves one.
        $document->meta['description'] ??= $translated;
    }

    /** The current page, absolute, with every query parameter removed. */
    private function baseUrl(Request $request): string
    {
        $uri = $request->getUri();
        $root = rtrim($this->url->to('forum')->base(), '/');

        // Rebuild from the forum's configured base rather than from the request
        // host: this install answers on a cloudflared hostname and on
        // 127.0.0.1:8888, and an alternate pointing at either of those is a
        // crawler instruction to index the wrong domain.
        return $root.$this->path($uri);
    }

    private function path(UriInterface $uri): string
    {
        $path = $uri->getPath();

        return $path === '' ? '/' : $path;
    }

    private function withLang(string $url, string $code): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'lang='.rawurlencode($code);
    }
}
