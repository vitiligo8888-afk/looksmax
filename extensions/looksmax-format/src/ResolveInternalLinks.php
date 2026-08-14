<?php

namespace Local\Format;

use Flarum\Http\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use s9e\TextFormatter\Renderer;
use s9e\TextFormatter\Utils;

/**
 * Point looksmax.org thread/post links at the local copy when we have one.
 *
 * -------------------------------------------------------------------------
 * Why
 * -------------------------------------------------------------------------
 * The corpus survey counted 129,730 anchors the authors actually wrote across
 * 35,012 posts, and a large share of them are internal: people cite other
 * threads on the same board constantly, and a guide is largely a hub of links
 * to other guides. Left alone, every one of those sends the reader off this
 * forum and onto looksmax.org — which is both a worse experience and a live
 * dependency on the board being mirrored.
 *
 * -------------------------------------------------------------------------
 * Why at render time rather than in the converter
 * -------------------------------------------------------------------------
 * Same reason as quote backlinks: forward references. A post frequently links
 * to a thread that has not been imported yet — imports walk the thread table,
 * and the corpus is being imported continuously alongside an active crawler.
 * Rewriting at conversion time would resolve against whatever happened to
 * exist at that moment and freeze the miss into the stored XML.
 *
 * Doing it here means a link starts external and silently becomes internal the
 * moment its target is imported, with no reconvert of 400k posts.
 *
 * -------------------------------------------------------------------------
 * What it deliberately does not do
 * -------------------------------------------------------------------------
 * A link whose target is NOT imported is left pointing at looksmax.org rather
 * than being turned into a dead local link or stripped. It is marked so the
 * theme can show that it leaves the site; a broken internal link would be
 * strictly worse than a working external one.
 */
class ResolveInternalLinks
{
    /** source thread id => local discussion path, or null when not imported. */
    private array $threads = [];

    /** source post id => local post path, or null. */
    private array $posts = [];

    private ?string $base = null;

    public function __construct(
        private ConnectionInterface $db,
        private UrlGenerator $url
    ) {
    }

    public function __invoke(Renderer $renderer, $context, string $xml): string
    {
        if (! str_contains($xml, 'looksmax.org')) {
            return $xml;
        }

        return Utils::replaceAttributes($xml, 'URL', function (array $attributes) {
            $href = (string) ($attributes['url'] ?? '');
            if ($href === '' || ! str_contains($href, 'looksmax.org')) {
                return $attributes;
            }

            $local = $this->localFor($href);
            if ($local !== null) {
                $attributes['url'] = $local;
                $attributes['data-lmx-internal'] = '1';
            } else {
                $attributes['data-lmx-offsite'] = '1';
            }

            return $attributes;
        });
    }

    /**
     * Recognise the two shapes the board uses and resolve them.
     *
     *   https://looksmax.org/threads/some-slug.1912092/
     *   https://looksmax.org/threads/some-slug.1912092/post-27851234
     *   https://looksmax.org/goto/post?id=27851234
     *
     * The trailing id in the slug is XenForo's thread id; `post-N` and the
     * goto form carry a post id. A post id is more precise, so it wins.
     */
    private function localFor(string $href): ?string
    {
        if (preg_match('#/goto/post\?id=(\d+)#', $href, $m)) {
            return $this->post((int) $m[1]);
        }

        if (preg_match('#/threads/[^/]*?\.(\d+)/?(?:post-(\d+))?#', $href, $m)) {
            if (! empty($m[2])) {
                $byPost = $this->post((int) $m[2]);
                if ($byPost !== null) {
                    return $byPost;
                }
            }

            return $this->thread((int) $m[1]);
        }

        if (preg_match('#/posts/(\d+)#', $href, $m)) {
            return $this->post((int) $m[1]);
        }

        return null;
    }

    private function thread(int $sourceId): ?string
    {
        if (array_key_exists($sourceId, $this->threads)) {
            return $this->threads[$sourceId];
        }

        try {
            $row = $this->db->table('discussions')
                ->where('imported_id', $sourceId)
                ->first(['id', 'slug']);
        } catch (\Throwable $e) {
            return $this->threads[$sourceId] = null;
        }

        return $this->threads[$sourceId] = $row
            ? $this->basePath().'/d/'.$row->id.($row->slug ? '-'.$row->slug : '')
            : null;
    }

    private function post(int $sourceId): ?string
    {
        if (array_key_exists($sourceId, $this->posts)) {
            return $this->posts[$sourceId];
        }

        try {
            $row = $this->db->table('posts')
                ->where('imported_id', $sourceId)
                ->first(['discussion_id', 'number']);
        } catch (\Throwable $e) {
            return $this->posts[$sourceId] = null;
        }

        return $this->posts[$sourceId] = $row
            ? $this->basePath().'/d/'.$row->discussion_id.'/'.$row->number
            : null;
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
