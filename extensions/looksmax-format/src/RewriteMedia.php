<?php

namespace Local\Format;

use Flarum\Http\UrlGenerator;
use Local\Format\Media\Signer;
use s9e\TextFormatter\Renderer;
use s9e\TextFormatter\Utils;

/**
 * Guarantee that no third-party media URL ever reaches a rendered page.
 *
 * -------------------------------------------------------------------------
 * The exposure this closes
 * -------------------------------------------------------------------------
 * Measured on one live thread (/d/329): 48 references to looksmax.org, 8 of
 * them to i.looksmax.org, their image host. Every one of those was fetched by
 * the READER's browser, directly, which hands the source board our traffic
 * volume, our users' IP addresses and user agents, and the exact posts being
 * read — and lets them break or substitute every image on this forum whenever
 * they choose.
 *
 * The forum does currently send `Referrer-Policy: same-origin`, so no Referer
 * is leaking today. That is not a defence worth relying on: it is one config
 * change, one `<meta>` tag or one extension setting away from being lost, and
 * it would be lost silently. Not making the request at all is the property we
 * want, and it is the only one that cannot regress by accident.
 *
 * -------------------------------------------------------------------------
 * Why at render time
 * -------------------------------------------------------------------------
 * This is the catch-all, and it has to be, because content is already in the
 * database — 111,800 posts of it — and more arrives continuously from an
 * importer running against a live crawler. A converter-side rewrite alone
 * would leave every existing post exposed until a full reconvert, and would
 * silently miss anything written by a future import path. Here, every post is
 * covered the moment the extension is enabled, including posts imported five
 * minutes from now.
 *
 * Local paths (`/media/...`, `/assets/...`) are left alone: they are already
 * our own origin, and routing them through the proxy would be a pointless hop.
 */
class RewriteMedia
{
    /**
     * Tag => attributes on it that hold a media URL.
     *
     * Every tag this extension defines that can carry one. `UNFURL.url` is
     * deliberately absent: that is the link the card points AT, a navigation
     * target the reader chooses to follow, not a subresource their browser
     * fetches automatically.
     */
    private const TARGETS = [
        'IMG' => ['src'],
        'UNFURL' => ['image', 'icon'],
        'EMBED' => ['thumb'],
        'QUOTE' => ['avatar'],
        'VIDEO' => ['src', 'poster'],
        'AUDIO' => ['src'],
    ];

    private ?string $base = null;

    public function __construct(
        private Signer $signer,
        private UrlGenerator $url
    ) {
    }

    public function __invoke(Renderer $renderer, $context, string $xml): string
    {
        // Cheap bail-out: a post with no absolute URL in it cannot leak one.
        if (! str_contains($xml, 'http')) {
            return $xml;
        }

        foreach (self::TARGETS as $tag => $attributes) {
            if (! str_contains($xml, '<'.$tag)) {
                continue;
            }

            $xml = Utils::replaceAttributes(
                $xml,
                $tag,
                function (array $attrs) use ($attributes) {
                    foreach ($attributes as $name) {
                        if (! isset($attrs[$name])) {
                            continue;
                        }
                        $rewritten = $this->proxy((string) $attrs[$name]);
                        if ($rewritten !== null) {
                            $attrs[$name] = $rewritten;
                        }
                    }

                    return $attrs;
                }
            );
        }

        return $xml;
    }

    /** @return string|null the proxied url, or null to leave the value alone */
    private function proxy(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Already ours, or not a fetchable absolute url.
        if (! preg_match('#^https?://#i', $value)) {
            return null;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        if ($host === '' || $this->isOwnHost($host)) {
            return null;
        }

        return $this->basePath().'/'.$this->signer->path($value);
    }

    /**
     * Our own origin, whatever it currently is.
     *
     * Read from Flarum's configured base url rather than hardcoded, because
     * this install is reached through a cloudflared quick tunnel whose hostname
     * changes every time it restarts. Hardcoding it would mean the proxy
     * started rewriting our OWN images through itself after a tunnel restart.
     */
    private function isOwnHost(string $host): bool
    {
        static $own = null;

        if ($own === null) {
            $own = [];
            try {
                $configured = parse_url($this->url->to('forum')->base(), PHP_URL_HOST);
                if ($configured) {
                    $own[] = strtolower($configured);
                }
            } catch (\Throwable $e) {
                // fall through to the loopback defaults
            }
            $own[] = 'localhost';
            $own[] = '127.0.0.1';
        }

        return in_array($host, $own, true);
    }

    private function basePath(): string
    {
        if ($this->base === null) {
            try {
                $this->base = rtrim((string) parse_url($this->url->to('forum')->base(), PHP_URL_PATH), '/');
            } catch (\Throwable $e) {
                $this->base = '';
            }
        }

        return $this->base;
    }
}
