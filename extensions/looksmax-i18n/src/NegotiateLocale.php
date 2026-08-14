<?php

namespace Local\I18n;

use Flarum\Http\RequestUtil;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Pick a locale for a visitor who has never picked one.
 *
 * Core's Flarum\Http\Middleware\SetLocale already handles the two cases where
 * somebody HAS chosen: a signed-in account's `locale` preference, and a guest's
 * `locale` cookie. What it does nothing about is the first request, which for
 * this forum is the important one — the audience is Mexican, the default_locale
 * is `es`, and an English-speaking visitor arriving from a search result should
 * not have to find a dropdown before the page is readable.
 *
 * So this runs immediately BEFORE core's middleware and answers exactly one
 * question: when nothing has been chosen, what does the browser say it wants?
 *
 * ── Why it runs AFTER core's middleware, not before ─────────────────────────
 * The first version ran before SetLocale and wrote the negotiated code into
 * the request's cookie params, so that core's own hasLocale() guard made the
 * decision. That is tidier and it is wrong, and the browser gate caught it:
 *
 *     SetLocale.php:  if ($actor->exists) { $locale = $actor->getPreference('locale'); }
 *                     else                { $locale = cookie('locale'); }
 *
 * The cookie is read for GUESTS ONLY. For a signed-in member with no stored
 * preference — which is every member on this forum, since nobody has set one —
 * core reads the preference, gets null, and stops. The injected cookie was
 * never looked at, so `?lang=es` did nothing at all once you logged in, and
 * `<html lang>` stayed `en` while the gate asked for `es`.
 *
 * Running after core means core applies whatever the member actually chose,
 * and this only speaks when core had nothing to go on — or when the URL says
 * something explicit, which outranks a stored preference by definition.
 *
 * ── What it does not do ─────────────────────────────────────────────────────
 * It does not write the member's `locale` preference. `?lang=es` on a GET is a
 * request to read this page in Spanish, not a request to change an account
 * setting, and a GET that mutates state is a GET that a crawler mutates state
 * with. The switcher saves the preference through the API, which is the path
 * that should own that write.
 *
 * ── ?lang= is deliberate, and it is not just a convenience ───────────────────
 * A cookie-selected locale gives a crawler exactly one version of the site, so
 * the Spanish and English forums are indistinguishable to it and hreflang has
 * nothing to point at. `?lang=es` gives every page a stable, linkable,
 * crawlable address per language, which is what Head.php emits alternates for.
 * For a human it also survives being pasted into a chat.
 *
 * Anything not in the installed-locale list is ignored rather than rejected: a
 * junk ?lang= should render the site, not a 400.
 */
class NegotiateLocale implements Middleware
{
    /** Nothing is stored for longer than a browser would keep a session. */
    private const COOKIE = 'locale';

    public function __construct(
        protected LocaleManager $locales,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $available = array_keys($this->locales->getLocales());
        $explicit = $this->fromQuery($request, $available);
        $chosen = null;

        if ($explicit !== null) {
            // An explicit URL beats a stored preference: the member is asking
            // for this page, now, in this language.
            $chosen = $explicit;
        } else {
            $actor = RequestUtil::getActor($request);
            $hasPreference = $actor->exists && $actor->getPreference('locale');
            $hasCookie = (bool) Arr::get($request->getCookieParams(), self::COOKIE);

            // A signed-in member whose account carries no preference is exactly
            // as undecided as a guest with no cookie, and core treats them
            // differently only because it never reads the cookie for them.
            if (! $hasPreference && ! $hasCookie) {
                $chosen = $this->fromAcceptLanguage($request, $available);
            }
        }

        if ($chosen !== null && $this->locales->hasLocale($chosen)) {
            $this->locales->setLocale($chosen);
            // Core set this attribute from its own decision a moment ago;
            // anything downstream that reads it must see the same locale the
            // translator is now using.
            $request = $request->withAttribute('locale', $chosen);
        }

        $response = $handler->handle($request);

        // Only an explicit ?lang= is persisted. Writing a cookie for a guess
        // would freeze that guess forever, including for the shared machines
        // and the "open in a translated tab" case, and it would make the
        // Accept-Language path untestable after the first request.
        if ($explicit !== null) {
            $response = $response->withAddedHeader(
                'Set-Cookie',
                self::COOKIE.'='.$explicit.'; Path=/; Max-Age=31536000; SameSite=Lax'
            );
        }

        // Two different Accept-Language headers can produce two different
        // documents from one URL. Say so, or a shared cache will serve the
        // first visitor's language to everyone behind it.
        return $response->withAddedHeader('Vary', 'Accept-Language');
    }

    private function fromQuery(Request $request, array $available): ?string
    {
        $lang = Arr::get($request->getQueryParams(), 'lang');

        if (! is_string($lang) || $lang === '') {
            return null;
        }

        return $this->match([$lang => 1.0], $available);
    }

    private function fromAcceptLanguage(Request $request, array $available): ?string
    {
        $header = $request->getHeaderLine('Accept-Language');

        if ($header === '') {
            return null;
        }

        return $this->match($this->parse($header), $available);
    }

    /**
     * `es-419,es;q=0.9,en-US;q=0.8` → ['es-419' => 1.0, 'es' => 0.9, 'en-us' => 0.8]
     *
     * Deliberately tolerant. A malformed q-value makes that entry lowest
     * priority rather than discarding the header, because a browser that sends
     * a header at all is telling us something even when it sends it badly.
     *
     * @return array<string, float>
     */
    private function parse(string $header): array
    {
        $out = [];

        foreach (explode(',', $header) as $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $param, $m)) {
                    $q = (float) $m[1];
                }
            }

            $out[$tag] = max($out[$tag] ?? 0.0, $q);
        }

        arsort($out);

        return $out;
    }

    /**
     * Match requested tags against installed locales, most-wanted first.
     *
     * Three passes per tag, in this order, because `es-419` (Latin American
     * Spanish — what a Mexican Chrome actually sends) must resolve to an
     * installed `es`, and `pt-BR` must NOT resolve to anything at all:
     *
     *   1. exact, case-insensitively: es-419 → es-419
     *   2. requested tag's base language: es-419 → es
     *   3. an installed regional variant of the base: es → es-419
     *
     * @param array<string, float> $wanted
     * @param string[] $available
     */
    private function match(array $wanted, array $available): ?string
    {
        $lower = [];
        foreach ($available as $code) {
            $lower[strtolower($code)] = $code;
        }

        foreach (array_keys($wanted) as $tag) {
            if (isset($lower[$tag])) {
                return $lower[$tag];
            }

            $base = explode('-', $tag)[0];

            if (isset($lower[$base])) {
                return $lower[$base];
            }

            foreach ($lower as $code => $original) {
                if (explode('-', $code)[0] === $base) {
                    return $original;
                }
            }
        }

        return null;
    }
}
