<?php

namespace Local\Format\Media;

/**
 * On-disk cache for proxied media, with a negative cache and a size cap.
 *
 * -------------------------------------------------------------------------
 * Layout
 * -------------------------------------------------------------------------
 *   <root>/ab/abcdef… .bin    the bytes
 *   <root>/ab/abcdef… .json   {"type": "image/jpeg", "len": 12345, "at": ts}
 *
 * Two-character fanout because a single directory with hundreds of thousands of
 * entries is slow to stat on ext4 and miserable to inspect by hand.
 *
 * -------------------------------------------------------------------------
 * Negative caching
 * -------------------------------------------------------------------------
 * The source board 404s a lot — attachments expire, users delete images, and a
 * recent acquisition run saw ~594 dead URLs. Without a negative cache, every
 * page view of a post containing a dead image is another outbound request to
 * their host: the exact repeated, correlated traffic pattern this proxy exists
 * to avoid, and it would be *worse* than hotlinking because it never stops.
 *
 * A miss is therefore recorded with a TTL and re-tried only after it expires,
 * so a genuinely temporary outage still heals without hammering anyone.
 */
class Store
{
    /** How long a failed fetch is remembered before it is retried. */
    private const NEGATIVE_TTL = 86400 * 3;

    public function __construct(
        private string $root,
        /** Soft cap in bytes; eviction runs when the cache exceeds it. */
        private int $maxBytes = 40 * 1024 * 1024 * 1024
    ) {
    }

    /** @return array{path:string,type:string}|null */
    public function get(string $url): ?array
    {
        $base = $this->base($url);
        $meta = $this->meta($base);

        if ($meta === null || ! empty($meta['dead'])) {
            return null;
        }
        if (! is_file($base.'.bin')) {
            return null;
        }

        // Cheap LRU signal. Only bumped once a day so a hot file does not cause
        // a write on every single request.
        if (($meta['seen'] ?? 0) < time() - 86400) {
            $meta['seen'] = time();
            @file_put_contents($base.'.json', json_encode($meta));
        }

        return ['path' => $base.'.bin', 'type' => $meta['type'] ?? 'application/octet-stream'];
    }

    /** True when this url failed recently and must not be re-requested yet. */
    public function isDead(string $url): bool
    {
        $meta = $this->meta($this->base($url));

        return $meta !== null
            && ! empty($meta['dead'])
            && ($meta['at'] ?? 0) > time() - self::NEGATIVE_TTL;
    }

    public function put(string $url, string $bytes, string $type): void
    {
        $base = $this->base($url);
        @mkdir(dirname($base), 0o755, true);

        // Write through a temp file and rename: a concurrent reader must never
        // see a half-written image, and two requests for the same cold URL race
        // here constantly.
        $tmp = $base.'.'.getmypid().'.tmp';
        if (@file_put_contents($tmp, $bytes) === false) {
            return;
        }
        @rename($tmp, $base.'.bin');
        @file_put_contents($base.'.json', json_encode([
            'type' => $type,
            'len' => strlen($bytes),
            'at' => time(),
            'seen' => time(),
            'url' => $url,
        ]));
    }

    public function putDead(string $url, int $status): void
    {
        $base = $this->base($url);
        @mkdir(dirname($base), 0o755, true);
        @file_put_contents($base.'.json', json_encode([
            'dead' => true,
            'status' => $status,
            'at' => time(),
            'url' => $url,
        ]));
    }

    public function has(string $url): bool
    {
        return is_file($this->base($url).'.bin');
    }

    /**
     * Evict least-recently-seen entries until the cache is back under the cap.
     *
     * Deliberately NOT run on the request path: walking the whole tree is far
     * too slow to do while a reader waits. The warm command calls it, so
     * eviction happens on the same schedule as growth.
     *
     * @return array{scanned:int,bytes:int,evicted:int,freed:int}
     */
    public function evict(): array
    {
        $entries = [];
        $total = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.bin')) {
                continue;
            }
            $size = $file->getSize();
            $total += $size;
            $base = substr($file->getPathname(), 0, -4);
            $meta = $this->meta($base);
            $entries[] = [$base, $size, $meta['seen'] ?? $meta['at'] ?? 0];
        }

        $scanned = count($entries);
        if ($total <= $this->maxBytes) {
            return ['scanned' => $scanned, 'bytes' => $total, 'evicted' => 0, 'freed' => 0];
        }

        usort($entries, fn ($a, $b) => $a[2] <=> $b[2]);

        $freed = 0;
        $evicted = 0;
        foreach ($entries as [$base, $size, $_]) {
            if ($total - $freed <= $this->maxBytes * 0.9) {
                break;
            }
            @unlink($base.'.bin');
            @unlink($base.'.json');
            $freed += $size;
            $evicted++;
        }

        return ['scanned' => $scanned, 'bytes' => $total, 'evicted' => $evicted, 'freed' => $freed];
    }

    /** @return array{files:int,bytes:int,dead:int} */
    public function stats(): array
    {
        $files = 0;
        $bytes = 0;
        $dead = 0;

        if (! is_dir($this->root)) {
            return ['files' => 0, 'bytes' => 0, 'dead' => 0];
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if (str_ends_with($file->getFilename(), '.bin')) {
                $files++;
                $bytes += $file->getSize();
            } elseif (str_ends_with($file->getFilename(), '.json')) {
                $meta = json_decode((string) @file_get_contents($file->getPathname()), true);
                if (is_array($meta) && ! empty($meta['dead'])) {
                    $dead++;
                }
            }
        }

        return ['files' => $files, 'bytes' => $bytes, 'dead' => $dead];
    }

    private function base(string $url): string
    {
        $h = hash('sha256', $url);

        return $this->root.'/'.substr($h, 0, 2).'/'.$h;
    }

    private function meta(string $base): ?array
    {
        $raw = @file_get_contents($base.'.json');
        if ($raw === false) {
            return null;
        }
        $meta = json_decode($raw, true);

        return is_array($meta) ? $meta : null;
    }
}
