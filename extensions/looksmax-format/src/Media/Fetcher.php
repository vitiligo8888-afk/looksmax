<?php

namespace Local\Format\Media;

/**
 * The only place this application makes an outbound request to the source board.
 *
 * -------------------------------------------------------------------------
 * The operational-security rules, and why each one is here
 * -------------------------------------------------------------------------
 *  - NO Referer, ever. The forum currently sends `Referrer-Policy: same-origin`
 *    so readers' browsers already send none, but that is one config change away
 *    from being lost and it does nothing for server-side fetches. A Referer
 *    naming this forum on a request to their image host is the single most
 *    direct way to be noticed.
 *  - A neutral User-Agent. A default PHP/cURL UA is just as identifying as a
 *    custom one: nothing else on their traffic looks like it, so it clusters
 *    perfectly.
 *  - An optional upstream proxy. Without one every fetch comes from this
 *    forum's own IP, which correlates our public address with the reading
 *    pattern of our users. `LMX_MEDIA_PROXY` takes an http/socks proxy URL, and
 *    the acquisition lane already owns a working pool — see HANDOFF-FORMAT.md.
 *    Absent it, fetches still work, still send no Referer, and are logged as
 *    direct so the exposure is visible rather than assumed.
 *  - A size cap and a timeout. A proxy that will stream an unbounded body is a
 *    memory exhaustion bug waiting for someone to point it at a large file.
 *  - Content-type allowlist. This proxy serves media. Anything else coming back
 *    is either a mistake or an attempt to have us host something, and it must
 *    not be cached and re-served under our own origin.
 */
class Fetcher
{
    private const MAX_BYTES = 25 * 1024 * 1024;
    private const TIMEOUT = 15;

    /** Deliberately a common, boring browser string with no build details. */
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        .'(KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    private const TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
        'image/svg+xml', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon',
        'video/mp4', 'video/webm', 'video/quicktime',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/mp4',
    ];

    public function __construct(private ?string $proxy = null)
    {
        $this->proxy = $proxy ?: (getenv('LMX_MEDIA_PROXY') ?: null);
    }

    public function usingProxy(): bool
    {
        return $this->proxy !== null && $this->proxy !== '';
    }

    /**
     * @return array{ok:bool,status:int,type:string,body:string,error:string}
     */
    public function fetch(string $url): array
    {
        $fail = fn (int $status, string $error) => [
            'ok' => false, 'status' => $status, 'type' => '', 'body' => '', 'error' => $error,
        ];

        if (! function_exists('curl_init')) {
            return $fail(0, 'ext-curl is not available');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_USERAGENT => self::UA,
            // AUTOREFERER would re-introduce a Referer across redirects.
            CURLOPT_AUTOREFERER => false,
            CURLOPT_REFERER => '',
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/apng,image/*,video/*,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                // Explicitly blank rather than absent: some stacks synthesise one.
                'Referer:',
            ],
            // Refuse anything that is not plain http(s), including after a redirect.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Abort as soon as the body goes past the cap rather than buffering it all.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) {
                return ($dlTotal > self::MAX_BYTES || $dlNow > self::MAX_BYTES) ? 1 : 0;
            },
        ]);

        if ($this->usingProxy()) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return $fail($status, $err ?: 'transfer failed');
        }
        if ($status < 200 || $status >= 300) {
            return $fail($status, 'http '.$status);
        }
        if (strlen($body) > self::MAX_BYTES) {
            return $fail($status, 'body over cap');
        }
        if ($type === '' || ! in_array($type, self::TYPES, true)) {
            return $fail($status, 'content-type not media: '.($type ?: 'none'));
        }

        return ['ok' => true, 'status' => $status, 'type' => $type, 'body' => $body, 'error' => ''];
    }
}
