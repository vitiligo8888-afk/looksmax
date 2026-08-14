<?php

namespace Local\Format;

use Flarum\Http\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use s9e\TextFormatter\Renderer;
use s9e\TextFormatter\Utils;

/**
 * Turn the SOURCE post id carried on a quote into a link to the local copy.
 *
 * This has to happen at render time rather than at import time: a post can
 * quote a post that has not been imported yet (later thread, later page), so
 * at conversion time there is nothing to point at. The stable identifier is
 * the source id, and posts.imported_id is the join.
 *
 * Resolution is cached per process — a discussion page renders ~20 posts and
 * quote chains repeat the same targets — and misses are cached too, so an
 * un-imported quote costs one query per run, not one per post.
 */
class ResolveQuoteLinks
{
    /** @var array<int,?string> source post id => local url (null = not imported) */
    private array $cache = [];

    private ?string $base = null;

    public function __construct(
        private ConnectionInterface $db,
        private UrlGenerator $url
    ) {
    }

    public function __invoke(Renderer $renderer, $context, string $xml): string
    {
        if (! str_contains($xml, '<QUOTE')) {
            return $xml;
        }

        return Utils::replaceAttributes($xml, 'QUOTE', function (array $attributes) {
            $src = isset($attributes['post']) ? (int) $attributes['post'] : 0;

            if ($src > 0) {
                $url = $this->resolve($src);
                if ($url !== null) {
                    $attributes['url'] = $url;
                } elseif (! isset($attributes['url'])) {
                    // Nothing local to jump to. Keep the source permalink so the
                    // attribution is still actionable rather than a dead label.
                    $attributes['url'] = 'https://looksmax.org/goto/post?id='.$src;
                }
            }

            /*
             * The quoted member's avatar and profile link.
             *
             * The corpus survey found the source member id on 298,957 of
             * 300,179 quotes and nothing consuming it. Resolving it here rather
             * than at import time is deliberate and matters: the quoted member
             * is very often imported LATER than the post quoting them (imports
             * walk threads, not users), so an import-time lookup would miss and
             * the miss would be frozen into the stored XML forever.
             *
             * Resolution is by users.imported_id — never by display name, which
             * is not unique and is sanitised on import.
             */
            $member = isset($attributes['member']) ? (int) $attributes['member'] : 0;
            if ($member > 0) {
                $user = $this->resolveUser($member);
                if ($user !== null) {
                    if ($user['avatar'] !== null && ! isset($attributes['avatar'])) {
                        $attributes['avatar'] = $user['avatar'];
                    }
                    if (! isset($attributes['profile'])) {
                        $attributes['profile'] = $this->basePath().'/u/'.$user['slug'];
                    }
                    // Prefer the LOCAL display name: it is what the reader can
                    // click through to and search for. A stale source-board name
                    // beside a link to a differently-named profile reads as a bug.
                    if ($user['name'] !== null) {
                        $attributes['author'] = $user['name'];
                    }
                }
            }

            return $attributes;
        });
    }

    /*
     * Long-quote collapsing is NOT decided here.
     *
     * It was tempting to compute it from the body's character count at render
     * time, but character count is the wrong measure: a 200-character quote
     * containing three images is far taller than a 900-character one that is
     * plain prose, and nested quotes multiply the difference. The thing that
     * actually pushes the reply off the screen is rendered HEIGHT.
     *
     * So the decision is made in js/dist/forum.js against a measured pixel
     * height. The corpus distribution (reports/CORPUS-SURVEY.md §5 — p50 75,
     * p90 382, p99 7,000, max 87,600 characters) is what sets the target
     * proportion, and the px threshold is chosen to collapse roughly that same
     * top decile.
     */

    private function resolve(int $sourceId): ?string
    {
        if (array_key_exists($sourceId, $this->cache)) {
            return $this->cache[$sourceId];
        }

        try {
            $row = $this->db->table('posts')
                ->where('imported_id', $sourceId)
                ->first(['discussion_id', 'number']);
        } catch (\Throwable $e) {
            // posts.imported_id is added by looksmax-import's migration; if it
            // has not run, quoting must still render rather than 500.
            return $this->cache[$sourceId] = null;
        }

        return $this->cache[$sourceId] = $row
            ? $this->basePath().'/d/'.$row->discussion_id.'/'.$row->number
            : null;
    }

    /**
     * Source user id => local display name / avatar / profile slug.
     *
     * Keyed on users.imported_id. Misses are cached as null so a quote of a
     * member who was never imported costs one query per render, not one per
     * quote — quote chains repeat the same authors constantly.
     *
     * @var array<int,?array{name:?string,avatar:?string,slug:string}>
     */
    private array $users = [];

    private function resolveUser(int $sourceId): ?array
    {
        if (array_key_exists($sourceId, $this->users)) {
            return $this->users[$sourceId];
        }

        try {
            $row = $this->db->table('users')
                ->where('imported_id', $sourceId)
                ->first(['id', 'username', 'nickname', 'avatar_url']);
        } catch (\Throwable $e) {
            // users.imported_id comes from looksmax-import's migration. If it
            // has not run, quotes must still render.
            return $this->users[$sourceId] = null;
        }

        if (! $row) {
            return $this->users[$sourceId] = null;
        }

        $avatar = $row->avatar_url ?? null;
        if ($avatar !== null && $avatar !== '' && ! preg_match('#^https?://#i', $avatar)) {
            // Flarum stores a bare filename for locally uploaded avatars.
            $avatar = $this->basePath().'/assets/avatars/'.$avatar;
        }

        return $this->users[$sourceId] = [
            'name' => $row->nickname ?: $row->username,
            'avatar' => $avatar ?: null,
            'slug' => (string) $row->id,
        ];
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
