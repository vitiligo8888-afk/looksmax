<?php

namespace Local\Format\Media;

/**
 * Sign the URLs the media proxy will fetch.
 *
 * -------------------------------------------------------------------------
 * Why a signature and not just a URL parameter
 * -------------------------------------------------------------------------
 * `/media/p?url=<anything>` is an open proxy. Anyone on the internet could
 * point it at any host and make our server fetch it — a trivially abusable SSRF
 * and bandwidth amplifier, and exactly the kind of thing that gets an IP
 * blocked. The signature means the proxy will only ever fetch URLs that THIS
 * forum minted, at render time, from content already in our own database.
 *
 * The key is Flarum's own `api_key`/salt from config.php, so it is per-install
 * and never in the repo. If it rotates, previously-rendered pages simply
 * re-render (post HTML is generated per request, not cached as HTML), so there
 * is no migration to do.
 *
 * -------------------------------------------------------------------------
 * Path shape
 * -------------------------------------------------------------------------
 *   /media/p/{sig}/{contentHash}/{payload}
 *
 * `payload` is the base64url of the source URL, so the proxy is stateless — no
 * database round-trip to find out what a request is for.
 *
 * `contentHash` is in the path, not a query string, deliberately: it makes the
 * URL content-addressed, so it can be served `immutable` with a one-year
 * max-age and any CDN or browser in front of us stops asking. It is derived
 * from the source URL rather than the bytes (we do not have the bytes at render
 * time); if a source URL ever serves different content, that is the source
 * board changing what a URL means, which is precisely the substitution attack
 * this whole exercise exists to stop.
 */
class Signer
{
    public function __construct(private string $key)
    {
    }

    /** @return string path relative to the forum root, no leading slash */
    public function path(string $url): string
    {
        $payload = self::b64($url);

        return 'media/p/'.$this->sign($payload).'/'.substr(hash('sha256', $url), 0, 16).'/'.$payload;
    }

    /** @return string|null the source url, or null when the signature is wrong */
    public function verify(string $sig, string $payload): ?string
    {
        // hash_equals, not ===: a timing-safe comparison is the whole point of
        // signing, and a plain === leaks the correct prefix byte by byte.
        if (! hash_equals($this->sign($payload), $sig)) {
            return null;
        }

        $url = self::unb64($payload);
        if ($url === null) {
            return null;
        }

        return $this->allowed($url) ? $url : null;
    }

    private function sign(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, $this->key), 0, 32);
    }

    /**
     * Second gate, after the signature.
     *
     * Belt and braces: even a validly signed URL must be an ordinary http(s)
     * request to a public host. Without this, a bug anywhere in the rewrite
     * path that let `file://` or `http://169.254.169.254/` be signed would turn
     * into cloud-metadata exfiltration.
     */
    private function allowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        // Never let the proxy reach a private or loopback address.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return ! in_array($host, ['localhost', 'localhost.localdomain'], true);
    }

    public static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64(string $s): ?string
    {
        $out = base64_decode(strtr($s, '-_', '+/'), true);

        return $out === false ? null : $out;
    }
}
