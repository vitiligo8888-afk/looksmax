<?php

namespace Local\Format;

use Flarum\Foundation\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Defence in depth for the media-proxy work: make a regression fail LOUDLY.
 *
 * RewriteMedia guarantees no third-party media URL reaches a page. These
 * headers are the second line, and their job is to turn a future mistake into
 * a visible broken image plus a console error instead of a silent, undetected
 * hotlink that leaks reader traffic to the source board for months.
 *
 *  - `Referrer-Policy: no-referrer` — strictly stronger than the
 *    `same-origin` the stack sends today. Under `same-origin` a cross-origin
 *    request already sends nothing, but `no-referrer` also covers same-site
 *    navigations away from us and cannot be weakened by a downstream `<meta>`
 *    that only tightens.
 *
 *  - `Content-Security-Policy: img-src 'self' data: blob:` — the enforcement.
 *    If any code path ever emits a third-party image URL again, the browser
 *    refuses the request and logs a CSP violation. e2e/format-shots.ts fails on
 *    console errors, so that regression breaks the test suite rather than
 *    quietly shipping. `media-src` and `frame-src` are handled separately
 *    because the click-to-play facade deliberately loads a YouTube iframe, but
 *    only after the reader has clicked it.
 *
 * Deliberately NOT a full CSP: `script-src`/`style-src` belong to whoever owns
 * the app shell, and several other lanes are actively adding inline scripts and
 * third-party icon fonts. Constraining those from here would break their work.
 * Recorded in HANDOFF-FORMAT.md instead.
 */
class SecurityHeaders implements MiddlewareInterface
{
    public function __construct(protected Config $config)
    {
    }

    /**
     * The app's own asset origin, as an explicit CSP source.
     *
     * `'self'` is the document's origin, but Flarum builds every asset url from
     * the configured base url, so the two only coincide when the reader arrived
     * on that exact host. Reached on any other hostname — a tunnel, a port on
     * localhost, an IP, a staging name — every avatar, the logo, the icon fonts
     * and the favicon become cross-origin and the enforcing img-src blocks all
     * of them. Measured on a local render: 31 blocked requests, and, worse,
     * silently blocked in exactly the screenshots we rely on to verify the UI,
     * so the verification could not see what it was checking.
     *
     * Naming the origin keeps the anti-hotlink property intact — this is still
     * only our own assets — while making it independent of which hostname the
     * page was requested through.
     */
    private function assetOrigin(): string
    {
        try {
            $url = (string) $this->config->url();
        } catch (\Throwable $e) {
            return '';
        }

        $p = parse_url($url);
        if (empty($p['scheme']) || empty($p['host'])) {
            return '';
        }

        return $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // The media proxy sets its own, stricter, headers.
        if (str_contains($request->getUri()->getPath(), '/media/p/')) {
            return $response;
        }

        /*
         * ENFORCING, not report-only, and only for the fetch directives that
         * matter here.
         *
         * Report-only was the first version and it was not good enough. The
         * measurement that changed it: flarum/emoji rewrites every emoji into
         * a twemoji <img> pointing at cdn.jsdelivr.net, and it does so in the
         * BROWSER, from the compiled forum bundle — so no amount of server-side
         * template work stops the request. Measured over five real threads:
         * 13 requests to jsdelivr, one per distinct emoji, each one telling a
         * third party that somebody is reading a specific page here.
         *
         * An enforcing `img-src` stops the request before it is sent, which is
         * the only reliable answer when the offending code runs client-side and
         * is not ours. It is also exactly the "regression fails loudly" property
         * asked for: any future code path that hotlinks an image now produces a
         * visible broken image AND a CSP violation in the console, and
         * e2e/format-shots.ts fails on console errors.
         *
         * Deliberately limited to img-src / media-src / frame-src.
         * script-src and style-src are NOT constrained: several other lanes are
         * actively adding inline scripts and third-party icon fonts, and
         * breaking their work from here would be overreach. Recorded in
         * HANDOFF-FORMAT.md.
         */
        // `'self'` plus our configured asset origin: the same set of bytes, but
        // reachable no matter which hostname the page was served on.
        $own = trim("'self' ".$this->assetOrigin());

        $csp = implode('; ', [
            // Everything a reader's browser fetches automatically must be ours.
            "img-src $own data: blob:",
            "media-src $own data: blob:",
            // The click-to-play facade swaps in a real player, on demand only —
            // the reader has to click before any third party hears from them.
            'frame-src https://www.youtube-nocookie.com https://www.youtube.com '
                .'https://player.vimeo.com https://embed.music.apple.com',
        ]);

        return $response
            ->withHeader('Referrer-Policy', 'no-referrer')
            // Clickjacking: nothing on this forum is meant to be framed by a
            // third party. CSP frame-ancestors is the modern spelling and
            // X-Frame-Options the fallback for older engines; both say the same
            // thing so there is no daylight between them to exploit.
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            // HSTS. The site is HTTPS-only behind the Cloudflare tunnel, so a
            // plain-http request is always a mistake or an attack; telling the
            // browser to remember that for a year removes the first-request
            // downgrade window. No `preload` and no `includeSubDomains`: those
            // are commitments about hostnames this file does not own.
            ->withHeader('Strict-Transport-Security', 'max-age=31536000')
            ->withHeader('Content-Security-Policy', $csp . "; frame-ancestors 'self'");
    }
}
