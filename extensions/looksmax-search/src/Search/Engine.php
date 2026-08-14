<?php

namespace Local\Search\Search;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\Search\Meili\Client;
use Local\Search\Meili\IndexSettings;
use Local\Search\Meili\MeiliException;
use Local\Search\Meili\Text;
use Psr\Log\LoggerInterface;

/**
 * Parsed query in, results out.
 *
 * ## Permissions
 *
 * Two gates, deliberately redundant, because they fail in opposite directions.
 *
 * 1. An **engine-side filter** excludes restricted tags, private and hidden
 *    documents. This is what keeps facet counts honest and stops a query from
 *    having to over-fetch ten thousand documents to find ten the reader may
 *    see. It is a performance and correctness-of-counts mechanism.
 * 2. A **database gate** — `whereVisibleTo($actor)` — decides what is actually
 *    returned. Flarum's own visibility scopes are the only thing that knows
 *    about every extension's rules (byobu recipients, approval, suspensions),
 *    and reimplementing them in a filter string is how search engines leak
 *    private forums. So the engine never gets the last word.
 *
 * Gate 2 alone would be correct but slow; gate 1 alone would be fast and
 * eventually wrong. The pair is fast and cannot leak: the worst case for a
 * stale index is a result that disappears, never one that should not exist.
 *
 * ## Highlighting
 *
 * Meilisearch returns `_formatted` with the highlight tags inserted into raw,
 * unescaped user content. Emitting that is a stored-XSS delivery mechanism. We
 * ask for control characters as the tags, escape the whole string, and only
 * then substitute real `<mark>` elements — so any HTML in the post is inert by
 * the time the marks exist.
 */
class Engine
{
    private const HL_PRE = "\x02";
    private const HL_POST = "\x03";

    public function __construct(
        private Client $client,
        private QueryParser $parser,
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log,
        private \Local\Search\Meili\Embedder $embedder,
        private SemanticPolicy $policy
    ) {
    }

    private function prefix(): string
    {
        return (string) ($this->settings->get('looksmax-search.prefix') ?: 'lmx');
    }

    public function uid(string $kind): string
    {
        return $this->prefix() . '_' . $kind;
    }

    public function embedder(): \Local\Search\Meili\Embedder
    {
        return $this->embedder;
    }

    /**
     * The engine-side permission filter, for callers outside this class.
     *
     * Related topics, recommendations and trending all have to apply the SAME
     * two gates a search does — the engine filter here, and `hydrateHits()`'s
     * database gate. Exposing this rather than letting each of them build its
     * own filter string is the whole point: a discovery surface that
     * reimplements permissions is a discovery surface that leaks a restricted
     * tag into a "you might also like" strip.
     *
     * @return string[]
     */
    public function engineFilter(User $actor, string $kind = 'discussions'): array
    {
        return $this->permissionFilter($actor, $kind);
    }

    /**
     * Apply the authoritative database visibility gate and shape hits.
     * Public so the semantic surfaces cannot skip it. See `hydrate()`.
     */
    public function hydrateHits(array $hits, User $actor, bool $federated = false): array
    {
        return $this->hydrate($hits, $actor, $federated);
    }

    // ------------------------------------------------------------ permissions

    /** Restricted tags this actor may NOT view. Small set; cached per request. */
    private ?array $deniedTagIds = null;

    private function deniedTagIds(User $actor): array
    {
        if ($this->deniedTagIds !== null) {
            return $this->deniedTagIds;
        }

        $restricted = $this->db->table('tags')->where('is_restricted', 1)->pluck('id')->all();
        if (!$restricted) {
            return $this->deniedTagIds = [];
        }

        $allowed = [];
        foreach ($restricted as $id) {
            // `hasPermission` on a tag-scoped permission is exactly what
            // flarum/tags checks when listing discussions, so this cannot
            // drift from the behaviour of the discussion list.
            if ($actor->hasPermission('tag' . $id . '.viewForum')) {
                $allowed[] = (int) $id;
            }
        }

        return $this->deniedTagIds = array_values(array_diff(array_map('intval', $restricted), $allowed));
    }

    private function permissionFilter(User $actor, string $kind): array
    {
        $f = [];

        $denied = $this->deniedTagIds($actor);
        if ($denied) {
            $f[] = 'NOT restricted_tag_ids IN [' . implode(',', $denied) . ']';
        }

        if (!$actor->hasPermission('discussion.viewPrivate')) {
            $f[] = 'is_private = false';
        }
        if (!$actor->hasPermission('discussion.hide')) {
            $f[] = 'is_hidden = false';
        }

        if ($kind === 'users') {
            $f = [];
            if (!$actor->hasPermission('user.suspend')) {
                $f[] = 'is_suspended = false';
            }
        }
        if ($kind === 'tags') {
            $f = [];
            if (!$actor->isAdmin()) {
                $f[] = 'is_restricted = false';
            }
        }

        return $f;
    }

    // ----------------------------------------------------------- filter build

    /** @return string[] Meilisearch filter expressions, ANDed by the caller. */
    public function filtersFor(array $q, User $actor, string $kind): array
    {
        $f = $this->permissionFilter($actor, $kind);

        if ($q['tags']) {
            // Slugs, because that is what a URL and a chip carry. Multiple tags
            // are OR — "in this forum or that one" is what a person means when
            // they tick two boxes.
            $f[] = '(' . implode(' OR ', array_map(fn ($t) => 'tag_slugs = ' . $this->q($t), $q['tags'])) . ')';
        }
        if ($q['prefixes'] && $kind !== 'users' && $kind !== 'tags') {
            $f[] = '(' . implode(' OR ', array_map(fn ($p) => 'prefixes = ' . $this->q($p), $q['prefixes'])) . ')';
        }
        if ($q['authors']) {
            $field = $kind === 'users' ? 'username' : 'author';
            $f[] = '(' . implode(' OR ', array_map(fn ($a) => "$field = " . $this->q($a), $q['authors'])) . ')';
        }
        if ($q['after']) {
            $f[] = 'created_at >= ' . (int) $q['after'];
        }
        if ($q['before']) {
            $f[] = 'created_at <= ' . (int) $q['before'];
        }
        if ($q['langs']) {
            $f[] = '(' . implode(' OR ', array_map(fn ($l) => 'lang = ' . $this->q($l), $q['langs'])) . ')';
        }
        if ($q['discussion'] !== null) {
            $f[] = $kind === 'posts'
                ? 'discussion_id = ' . (int) $q['discussion']
                : 'id = ' . (int) $q['discussion'];
        }

        $fieldMap = $kind === 'posts'
            ? ['reactions' => 'reactions', 'length' => 'length', 'replies' => null, 'views' => null]
            : ['reactions' => 'reactions', 'replies' => 'comment_count', 'views' => 'views', 'length' => 'length'];

        foreach ($q['ranges'] as $field => $ranges) {
            $col = $fieldMap[$field] ?? null;
            if ($col === null) {
                continue;
            }
            foreach ($ranges as $r) {
                $f[] = $col . ' ' . ($r['op'] === '=' ? '=' : $r['op']) . ' ' . $r['value'];
            }
        }

        foreach ($q['flags'] as $flag) {
            $neg = str_starts_with($flag, '!');
            $name = $neg ? substr($flag, 1) : $flag;
            $expr = match ($name) {
                'sticky', 'pinned' => 'is_sticky = true',
                'locked', 'closed' => 'is_locked = true',
                'guide' => 'is_guide = true',
                'answered' => 'has_best_answer = true',
                'unanswered' => $kind === 'posts' ? null : 'comment_count = 0',
                'private' => 'is_private = true',
                'hidden' => 'is_hidden = true',
                'first', 'op' => $kind === 'posts' ? 'is_first = true' : null,
                default => null,
            };
            if ($expr !== null) {
                $f[] = $neg ? "NOT ($expr)" : $expr;
            }
        }

        return array_values(array_filter($f));
    }

    private function q(string $v): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }

    /**
     * Build the term string Meilisearch sees. Phrases keep their quotes (that
     * is Meilisearch's own phrase syntax) and negatives keep their minus, so
     * the language passes straight through rather than being re-implemented.
     */
    private function terms(array $q): string
    {
        $parts = [];
        if ($q['text'] !== '') {
            $parts[] = $q['text'];
        }
        foreach ($q['phrases'] as $p) {
            $parts[] = '"' . $p . '"';
        }
        foreach ($q['negatives'] as $n) {
            $parts[] = '-' . $n;
        }

        // The index stores folded text (Text::fold), so the query has to be
        // folded by exactly the same function or the fix is only half applied:
        // an index that knows `strasse` and a query that still says `straße`
        // match nothing at all, which is strictly worse than not folding.
        // This is why fold() lives in one place and is called from two.
        return Text::fold(trim(implode(' ', $parts)));
    }

    private function sortFor(array $q, string $kind): array
    {
        return match ($q['sort'] ?? null) {
            'new', 'newest' => ['created_at:desc'],
            'old', 'oldest' => ['created_at:asc'],
            'top' => ['reactions:desc'],
            'active' => [$kind === 'posts' ? 'created_at:desc' : 'last_post_at:desc'],
            'replies' => $kind === 'posts' ? [] : ['comment_count:desc'],
            'views' => $kind === 'posts' ? [] : ['views:desc'],
            'length' => ['length:desc'],
            default => [],   // relevance: let the ranking rules decide
        };
    }

    // -------------------------------------------------------------- searching

    /**
     * The results page. One engine round trip per requested kind (via
     * multi-search, so it is literally one HTTP request), then one cheap SQL
     * query to apply the authoritative visibility gate.
     */
    public function search(string $rawQuery, User $actor, array $options = []): array
    {
        $started = microtime(true);
        $q = $this->parser->parse($rawQuery);
        $terms = $this->terms($q);

        $kind = $options['type'] ?? $q['type'] ?? 'all';
        $limit = max(1, min(50, (int) ($options['limit'] ?? 20)));
        $offset = max(0, (int) ($options['offset'] ?? 0));
        $wantFacets = (bool) ($options['facets'] ?? true);

        // Search-within-thread implies posts, never a thread list.
        if ($q['discussion'] !== null && $kind === 'all') {
            $kind = 'posts';
        }

        $kinds = $kind === 'all' ? ['discussions', 'posts'] : [$kind];

        // How much semantics this query wants. Decided once and applied only to
        // the discussions index, because that is the only index carrying
        // vectors — see Embedder for why embedding 1.4M posts was not the
        // trade to make. A federated multi-search happily mixes a hybrid query
        // on one index with a keyword query on another; verified against the
        // live 1.53.0 engine before this was written.
        $semantic = ['ratio' => 0.0, 'reason' => 'disabled'];
        if ($this->embedder->enabled()) {
            $semantic = $this->policy->decide(
                $q,
                $terms,
                $this->embedder->defaultRatio(),
                isset($options['semanticRatio']) && $options['semanticRatio'] !== null
                    ? (float) $options['semanticRatio']
                    : null
            );
        }

        $queries = [];
        foreach ($kinds as $k) {
            $queries[] = $this->buildQuery($k, $terms, $q, $actor, $limit, $offset, $wantFacets, $kind === 'all', $semantic['ratio']);
        }

        try {
            if ($kind === 'all') {
                // Federated: Meilisearch merges by score across both indexes, so
                // a strong body match can outrank a weak title match instead of
                // the two lists being stapled together in a fixed order.
                $res = $this->client->multiSearch($queries, [
                    'limit' => $limit,
                    'offset' => $offset,
                    'facetsByIndex' => $wantFacets ? [
                        $this->uid('discussions') => ['tag_names', 'prefixes', 'author', 'lang'],
                    ] : null,
                    'mergeFacets' => ['maxValuesPerFacet' => 30],
                ]);
                $hits = $res['hits'] ?? [];
                $estimated = $res['estimatedTotalHits'] ?? count($hits);
                $facets = $res['facetDistribution'] ?? [];
                $processingMs = $res['processingTimeMs'] ?? null;
                $semanticHits = $res['semanticHitCount'] ?? null;
            } else {
                $res = $this->client->multiSearch($queries);
                $first = $res['results'][0] ?? [];
                $hits = $first['hits'] ?? [];
                $estimated = $first['estimatedTotalHits'] ?? count($hits);
                $facets = $first['facetDistribution'] ?? [];
                $processingMs = $first['processingTimeMs'] ?? null;
                $semanticHits = $first['semanticHitCount'] ?? null;
            }
        } catch (MeiliException $e) {
            // A vector failure must never cost the reader their results. The
            // embedding backend is a separate container with its own CPU
            // budget; if it is restarting, saturated, or a hosted API is
            // rate-limiting, the keyword engine is still perfectly good.
            // Retry once without the hybrid clause and say so, rather than
            // falling all the way through to the database fallback.
            if ($semantic['ratio'] > 0.0 && $this->isEmbeddingFailure($e)) {
                $this->log->warning('search: semantic degraded to keyword', $e->context() + ['q' => $rawQuery]);
                $out = $this->search($rawQuery, $actor, ['semanticRatio' => 0.0] + $options);
                $out['semantic'] = [
                    'ratio' => 0.0,
                    'reason' => 'embedder-unavailable',
                    'degraded' => true,
                    'error' => $e->getMessage(),
                ];

                return $out;
            }
            $this->log->error('search: engine failure', $e->context() + ['q' => $rawQuery]);
            throw $e;
        }

        $results = $this->hydrate($hits, $actor, $kind === 'all');

        return [
            'query' => $rawQuery,
            'parsed' => $q,
            'type' => $kind,
            'results' => $results,
            'estimatedTotalHits' => $estimated,
            'facets' => $this->shapeFacets($facets),
            'limit' => $limit,
            'offset' => $offset,
            'engineMs' => $processingMs,
            'totalMs' => round((microtime(true) - $started) * 1000, 2),
            'filteredOut' => count($hits) - count($results),
            // Echoed so a client — and a human debugging "why did this change" —
            // can see exactly how much semantics this query got and why.
            // `semanticHitCount` is Meilisearch's own count of results that came
            // from the vector side rather than the keyword side.
            'semantic' => [
                'ratio' => $semantic['ratio'],
                'reason' => $semantic['reason'],
                'degraded' => false,
                'semanticHitCount' => $semanticHits,
            ],
        ];
    }

    /**
     * Was this Meilisearch failure caused by the embedding backend rather than
     * by the query?
     *
     * Matched on Meilisearch's own error code, not on the message text, because
     * messages change between patch releases and a substring match on prose is
     * how a "resilient" fallback quietly stops firing.
     */
    private function isEmbeddingFailure(MeiliException $e): bool
    {
        $code = $e->body()['code'] ?? '';

        return in_array($code, [
            'vector_embedding_error',
            'invalid_search_embedder',
            'invalid_embedder',
        ], true);
    }

    private function buildQuery(string $kind, string $terms, array $q, User $actor, int $limit, int $offset, bool $facets, bool $federated, float $semanticRatio = 0.0): array
    {
        $filters = $this->filtersFor($q, $actor, $kind);
        $sort = $this->sortFor($q, $kind);

        $query = [
            'indexUid' => $this->uid($kind),
            'q' => $terms,
            'attributesToHighlight' => $kind === 'posts'
                ? ['content', 'discussion_title']
                : ($kind === 'discussions' ? ['title', 'excerpt'] : ['*']),
            'highlightPreTag' => self::HL_PRE,
            'highlightPostTag' => self::HL_POST,
            'showMatchesPosition' => false,
            'showRankingScore' => true,
        ];

        if ($kind === 'posts') {
            $query['attributesToCrop'] = ['content'];
            $query['cropLength'] = 45;
            $query['cropMarker'] = '…';
            // One hit per thread in the merged view; every hit in the Posts tab
            // and in search-within-thread, where the user asked for posts.
            if ($federated || ($q['discussion'] === null && ($q['sort'] ?? null) === null && !$facets)) {
                $query['distinct'] = 'discussion_id';
            }
            if ($federated) {
                $query['distinct'] = 'discussion_id';
            }
        } elseif ($kind === 'discussions') {
            $query['attributesToCrop'] = ['excerpt'];
            $query['cropLength'] = 45;
            $query['cropMarker'] = '…';

            // Only the discussions index carries vectors, and only when the
            // policy asked for some. A `hybrid` clause naming an embedder that
            // does not exist on the index is a hard 400, not a degradation —
            // hence both guards, not one.
            if ($semanticRatio > 0.0 && $this->embedder->enabled()) {
                $query['hybrid'] = [
                    'embedder' => $this->embedder->name(),
                    'semanticRatio' => $semanticRatio,
                ];
                // Highlighting is a KEYWORD artefact: Meilisearch can only mark
                // terms it matched literally. A hit that arrived purely through
                // the vector side has no marks, and the client must be able to
                // tell "no highlight because it matched semantically" from
                // "no highlight because something broke".
                $query['showRankingScoreDetails'] = true;
            }
        }

        if ($filters) {
            $query['filter'] = implode(' AND ', array_map(fn ($f) => "($f)", $filters));
        }
        if ($sort) {
            $query['sort'] = $sort;
        }

        if ($federated) {
            // A title match is a stronger signal of intent than a body match:
            // someone who names a thread is looking for that thread. The weight
            // is a multiplier on the ranking score before the merge.
            $query['federationOptions'] = ['weight' => $kind === 'discussions' ? 1.4 : 1.0];
        } else {
            $query['limit'] = $limit;
            $query['offset'] = $offset;
            if ($facets) {
                $query['facets'] = $kind === 'discussions'
                    ? ['tag_names', 'prefixes', 'author', 'lang']
                    : ($kind === 'posts' ? ['prefixes', 'author', 'lang'] : ['group_names']);
            }
        }

        if ($terms === '') {
            // An empty query with filters is a browse, not a search. Relevance
            // is meaningless, so fall back to the most useful default order
            // rather than returning documents in internal order.
            if (!$sort) {
                $query['sort'] = $kind === 'posts' ? ['created_at:desc'] : ['last_post_at:desc'];
            }
        }

        return $query;
    }

    // -------------------------------------------------------------- hydration

    /**
     * Apply the authoritative visibility gate and shape hits for the client.
     *
     * Two queries total, regardless of how many hits: one id lookup per model
     * type. The display data comes out of the stored document, which is why a
     * results page does not cost a join per row.
     */
    private function hydrate(array $hits, User $actor, bool $federated): array
    {
        if (!$hits) {
            return [];
        }

        $discussionIds = [];
        $postIds = [];
        foreach ($hits as $h) {
            $index = $h['_federation']['indexUid'] ?? null;
            $isPost = $index ? str_ends_with($index, '_posts') : isset($h['discussion_id']);
            if ($isPost) {
                $postIds[] = (int) $h['id'];
                $discussionIds[] = (int) $h['discussion_id'];
            } elseif (isset($h['title'])) {
                $discussionIds[] = (int) $h['id'];
            }
        }

        $visibleDiscussions = [];
        if ($discussionIds) {
            $visibleDiscussions = array_flip(
                Discussion::whereVisibleTo($actor)
                    ->whereIn('id', array_unique($discussionIds))
                    ->pluck('id')
                    ->all()
            );
        }
        $visiblePosts = [];
        if ($postIds) {
            $visiblePosts = array_flip(
                Post::whereVisibleTo($actor)
                    ->whereIn('id', array_unique($postIds))
                    ->pluck('id')
                    ->all()
            );
        }

        $out = [];
        $seenDiscussions = [];
        foreach ($hits as $h) {
            $index = $h['_federation']['indexUid'] ?? null;
            $isPost = $index ? str_ends_with($index, '_posts') : isset($h['discussion_id']);
            $isUser = isset($h['username']);
            $isTag = isset($h['slug']) && isset($h['discussion_count']) && !isset($h['title']);

            if ($isUser) {
                $out[] = $this->shapeUser($h);
                continue;
            }
            if ($isTag) {
                $out[] = $this->shapeTag($h);
                continue;
            }

            if ($isPost) {
                if (!isset($visiblePosts[(int) $h['id']]) || !isset($visibleDiscussions[(int) $h['discussion_id']])) {
                    continue;
                }
                // In the merged view the same thread can arrive twice — once by
                // title, once by a matching post. Keep the first (higher-ranked)
                // and fold the other in as evidence rather than as a duplicate.
                if ($federated) {
                    $did = (int) $h['discussion_id'];
                    if (isset($seenDiscussions[$did])) {
                        $out[$seenDiscussions[$did]]['matchedPost'] ??= $this->shapePostMatch($h);
                        continue;
                    }
                    $seenDiscussions[$did] = count($out);
                }
                $out[] = $this->shapePost($h);
                continue;
            }

            if (!isset($visibleDiscussions[(int) $h['id']])) {
                continue;
            }
            if ($federated) {
                $did = (int) $h['id'];
                if (isset($seenDiscussions[$did])) {
                    continue;
                }
                $seenDiscussions[$did] = count($out);
            }
            $out[] = $this->shapeDiscussion($h);
        }

        return $out;
    }

    /**
     * Escape first, then substitute the marks. Never the other way round:
     * `_formatted` contains raw post text, and a post containing
     * `<img onerror=…>` would otherwise ship straight into the results page.
     */
    private function hl(?string $s): string
    {
        if ($s === null || $s === '') {
            return '';
        }

        return str_replace(
            [htmlspecialchars(self::HL_PRE, ENT_QUOTES, 'UTF-8'), htmlspecialchars(self::HL_POST, ENT_QUOTES, 'UTF-8')],
            ['<mark>', '</mark>'],
            htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    private function shapeDiscussion(array $h): array
    {
        $f = $h['_formatted'] ?? [];

        return [
            'type' => 'discussion',
            'id' => (int) $h['id'],
            'title' => (string) ($h['title'] ?? ''),
            'titleHtml' => $this->hl($f['title'] ?? $h['title'] ?? ''),
            'excerptHtml' => $this->hl($f['excerpt'] ?? mb_substr((string) ($h['excerpt'] ?? ''), 0, 220)),
            'slug' => (string) ($h['slug'] ?? ''),
            'url' => '/d/' . $h['id'] . '-' . ($h['slug'] ?? ''),
            'author' => (string) ($h['author'] ?? ''),
            'authorId' => (int) ($h['author_id'] ?? 0),
            'tags' => $this->zipTags($h),
            'prefixes' => $h['prefixes'] ?? [],
            'createdAt' => (int) ($h['created_at'] ?? 0),
            'lastPostAt' => (int) ($h['last_post_at'] ?? 0),
            'commentCount' => (int) ($h['comment_count'] ?? 0),
            'reactions' => (int) ($h['reactions'] ?? 0),
            'views' => (int) ($h['views'] ?? 0),
            'isSticky' => (bool) ($h['is_sticky'] ?? false),
            'isLocked' => (bool) ($h['is_locked'] ?? false),
            'lang' => (string) ($h['lang'] ?? ''),
            'score' => $h['_rankingScore'] ?? null,
        ];
    }

    private function shapePost(array $h): array
    {
        $f = $h['_formatted'] ?? [];

        return [
            'type' => 'post',
            'id' => (int) $h['id'],
            'discussionId' => (int) ($h['discussion_id'] ?? 0),
            'number' => (int) ($h['number'] ?? 0),
            'title' => (string) ($h['discussion_title'] ?? ''),
            'titleHtml' => $this->hl($f['discussion_title'] ?? $h['discussion_title'] ?? ''),
            'excerptHtml' => $this->hl($f['content'] ?? mb_substr((string) ($h['content'] ?? ''), 0, 220)),
            'url' => '/d/' . ($h['discussion_id'] ?? 0) . '-' . ($h['discussion_slug'] ?? '') . '/' . ($h['number'] ?? 1),
            'author' => (string) ($h['author'] ?? ''),
            'authorId' => (int) ($h['author_id'] ?? 0),
            'tags' => [],
            'prefixes' => $h['prefixes'] ?? [],
            'createdAt' => (int) ($h['created_at'] ?? 0),
            'reactions' => (int) ($h['reactions'] ?? 0),
            'lang' => (string) ($h['lang'] ?? ''),
            'score' => $h['_rankingScore'] ?? null,
        ];
    }

    private function shapePostMatch(array $h): array
    {
        $f = $h['_formatted'] ?? [];

        return [
            'id' => (int) $h['id'],
            'number' => (int) ($h['number'] ?? 0),
            'author' => (string) ($h['author'] ?? ''),
            'excerptHtml' => $this->hl($f['content'] ?? ''),
        ];
    }

    private function shapeUser(array $h): array
    {
        $f = $h['_formatted'] ?? [];

        return [
            'type' => 'user',
            'id' => (int) $h['id'],
            'username' => (string) ($h['username'] ?? ''),
            'displayName' => (string) ($h['display_name'] ?? ''),
            'titleHtml' => $this->hl($f['display_name'] ?? $h['display_name'] ?? ''),
            'url' => '/u/' . ($h['username'] ?? ''),
            // The index stores the raw `users.avatar_url` column, which is a
            // BARE FILENAME ("3311.jpg"), not a URL. Handing that to an <img
            // src> makes the browser resolve it against the current path:
            // measured live, GET /3311.jpg is 404 while
            // GET /assets/avatars/3311.jpg is 200. Resolved here rather than at
            // index time so changing the base URL or moving avatars to a CDN
            // does not require a full reindex.
            'avatarUrl' => $this->avatarUrl((string) ($h['avatar_url'] ?? '')),
            'groups' => $h['group_names'] ?? [],
            'groupColor' => (string) ($h['group_color'] ?? ''),
            'postsCount' => (int) ($h['posts_count'] ?? 0),
            'points' => (int) ($h['points'] ?? 0),
            'score' => $h['_rankingScore'] ?? null,
        ];
    }

    /**
     * Resolve a stored avatar filename through the same filesystem disk core's
     * `User::getAvatarUrlAttribute` accessor uses, so the two cannot drift.
     */
    private function avatarUrl(string $value): string
    {
        if ($value === '' || str_contains($value, '://')) {
            return $value;
        }

        return (string) resolve(\Illuminate\Contracts\Filesystem\Factory::class)
            ->disk('flarum-avatars')->url($value);
    }

    private function shapeTag(array $h): array
    {
        $f = $h['_formatted'] ?? [];

        return [
            'type' => 'tag',
            'id' => (int) $h['id'],
            'name' => (string) ($h['name'] ?? ''),
            'titleHtml' => $this->hl($f['name'] ?? $h['name'] ?? ''),
            'excerptHtml' => $this->hl($f['description'] ?? $h['description'] ?? ''),
            'slug' => (string) ($h['slug'] ?? ''),
            'url' => '/t/' . ($h['slug'] ?? ''),
            'color' => (string) ($h['color'] ?? ''),
            'discussionCount' => (int) ($h['discussion_count'] ?? 0),
            'isPrefix' => (bool) ($h['is_prefix'] ?? false),
            'score' => $h['_rankingScore'] ?? null,
        ];
    }

    private function zipTags(array $h): array
    {
        $out = [];
        $names = $h['tag_names'] ?? [];
        $slugs = $h['tag_slugs'] ?? [];
        $colors = $h['tag_colors'] ?? [];
        foreach ($names as $i => $name) {
            $out[] = [
                'name' => $name,
                'slug' => $slugs[$i] ?? '',
                'color' => $colors[$i] ?? '',
                'isPrefix' => str_starts_with((string) ($slugs[$i] ?? ''), 'p-'),
            ];
        }

        return $out;
    }

    private function shapeFacets(array $facets): array
    {
        $out = [];
        foreach ($facets as $field => $values) {
            arsort($values);
            $out[] = [
                'field' => $field,
                'label' => match ($field) {
                    'tag_names', 'prefixes', 'author', 'lang', 'group_names' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)
                        ->trans('local-looksmax-search.forum.facets.' . $field),
                    default => ucfirst(str_replace('_', ' ', $field)),
                },
                'operator' => match ($field) {
                    'tag_names' => 'tag',
                    'prefixes' => 'prefix',
                    'author' => 'by',
                    'lang' => 'lang',
                    default => $field,
                },
                'values' => array_map(
                    fn ($v, $c) => ['value' => (string) $v, 'count' => (int) $c],
                    array_keys($values),
                    array_values($values)
                ),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------- suggestion

    /**
     * Autocomplete. Optimised for the 250 ms budget a keystroke has: one
     * multi-search covering four indexes, tiny limits, no facets, no crops
     * beyond a short window, and no database round trip at all unless a hit
     * survives to be shown.
     */
    public function suggest(string $rawQuery, User $actor, int $limit = 5): array
    {
        $started = microtime(true);
        $q = $this->parser->parse($rawQuery);
        $terms = $this->terms($q);
        if ($terms === '' && !$q['tags'] && !$q['authors']) {
            return ['query' => $rawQuery, 'groups' => [], 'totalMs' => 0];
        }

        $queries = [];
        foreach (['discussions', 'posts', 'users', 'tags'] as $kind) {
            $filters = $this->filtersFor($q, $actor, $kind);
            $query = [
                'indexUid' => $this->uid($kind),
                'q' => $terms,
                'limit' => $kind === 'discussions' ? $limit + 2 : ($kind === 'tags' ? 3 : $limit),
                'attributesToHighlight' => match ($kind) {
                    'discussions' => ['title'],
                    'posts' => ['content'],
                    'users' => ['username', 'display_name'],
                    'tags' => ['name'],
                },
                'highlightPreTag' => self::HL_PRE,
                'highlightPostTag' => self::HL_POST,
            ];
            if ($kind === 'posts') {
                $query['attributesToCrop'] = ['content'];
                $query['cropLength'] = 24;
                $query['cropMarker'] = '…';
                $query['distinct'] = 'discussion_id';
            }
            if ($filters) {
                $query['filter'] = implode(' AND ', array_map(fn ($f) => "($f)", $filters));
            }
            $queries[] = $query;
        }

        $res = $this->client->multiSearch($queries);

        $groups = [];
        $engineMs = 0;
        foreach ($res['results'] ?? [] as $r) {
            $uid = $r['indexUid'] ?? '';
            $kind = substr($uid, strrpos($uid, '_') + 1);
            $engineMs = max($engineMs, $r['processingTimeMs'] ?? 0);
            $hits = $this->hydrate($r['hits'] ?? [], $actor, false);
            if ($hits) {
                $groups[] = ['kind' => $kind, 'hits' => array_slice($hits, 0, $limit)];
            }
        }

        return [
            'query' => $rawQuery,
            'groups' => $groups,
            'engineMs' => $engineMs,
            'totalMs' => round((microtime(true) - $started) * 1000, 2),
        ];
    }

    /**
     * Values for one facet, matched against what the user is typing. This is
     * what makes a facet with 200,000 authors usable — `/facet-search` searches
     * the facet values themselves rather than shipping them all to the client.
     */
    public function facetValues(string $field, string $partial, string $rawQuery, User $actor, string $kind = 'discussions'): array
    {
        $q = $this->parser->parse($rawQuery);
        $filters = $this->filtersFor($q, $actor, $kind);

        $params = ['facetName' => $field, 'facetQuery' => $partial, 'q' => $this->terms($q)];
        if ($filters) {
            $params['filter'] = implode(' AND ', array_map(fn ($f) => "($f)", $filters));
        }

        $res = $this->client->facetSearch($this->uid($kind), $params);

        return array_map(
            fn ($h) => ['value' => $h['value'], 'count' => $h['count']],
            $res['facetHits'] ?? []
        );
    }

    /**
     * Recovery suggestions for a query that found nothing.
     *
     * A zero-result page that just says "no results" is where a session ends.
     * Each strategy here is tried against the engine and only offered if it
     * actually returns something, so every suggestion shown is a link that
     * demonstrably works — never a guess that leads to a second empty page.
     */
    public function recover(string $rawQuery, User $actor): array
    {
        $q = $this->parser->parse($rawQuery);
        $suggestions = [];

        $probes = [];

        // 1. Drop the filters, keep the words. Usually the filter is the
        //    problem: a tag that has no such thread, a date window too narrow.
        if ($q['tags'] || $q['authors'] || $q['prefixes'] || $q['before'] || $q['after'] || $q['ranges'] || $q['flags']) {
            $bare = $this->parser->unparse(['text' => $q['text'], 'phrases' => $q['phrases'], 'negatives' => $q['negatives']]);
            $probes[] = ['label' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-search.forum.recovery.without_filters'), 'query' => $bare, 'reason' => 'filters'];
        }

        // 2. Relax a phrase into loose words.
        if ($q['phrases']) {
            $loose = trim($q['text'] . ' ' . implode(' ', $q['phrases']));
            $probes[] = ['label' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-search.forum.recovery.without_phrase'), 'query' => $loose, 'reason' => 'phrase'];
        }

        // 3. Drop the rarest word. With several terms, one typo'd or
        //    hyper-specific word is usually what zeroed the result set.
        $words = preg_split('/\s+/u', $q['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) > 1) {
            foreach ($words as $i => $w) {
                $rest = $words;
                unset($rest[$i]);
                $probes[] = ['label' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-search.forum.recovery.without_word', ['word' => $w]), 'query' => implode(' ', $rest), 'reason' => 'term'];
            }
        }

        foreach (array_slice($probes, 0, 6) as $p) {
            if (trim($p['query']) === '') {
                continue;
            }
            try {
                $r = $this->search($p['query'], $actor, ['limit' => 3, 'facets' => false]);
            } catch (MeiliException) {
                continue;
            }
            if (count($r['results']) > 0) {
                $suggestions[] = [
                    'label' => $p['label'],
                    'query' => $p['query'],
                    'reason' => $p['reason'],
                    'count' => $r['estimatedTotalHits'],
                    'sample' => array_slice($r['results'], 0, 3),
                ];
            }
            if (count($suggestions) >= 3) {
                break;
            }
        }

        // 4. Nearby tags, so an empty result still ends somewhere useful.
        $related = [];
        if ($q['text'] !== '') {
            try {
                $t = $this->client->search($this->uid('tags'), ['q' => $q['text'], 'limit' => 4]);
                $related = array_map(fn ($h) => $this->shapeTag($h), $t['hits'] ?? []);
            } catch (MeiliException) {
            }
        }

        return ['suggestions' => $suggestions, 'relatedTags' => $related];
    }
}
