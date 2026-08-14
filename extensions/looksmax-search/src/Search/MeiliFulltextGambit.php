<?php

namespace Local\Search\Search;

use Flarum\Search\GambitInterface;
use Flarum\Search\SearchState;
use Illuminate\Database\Query\Expression;
use Local\Search\Meili\Client;
use Local\Search\Meili\MeiliException;
use Psr\Log\LoggerInterface;

/**
 * `/api/discussions?filter[q]=…`, served by the engine.
 *
 * This is the compatibility plane. Our own endpoint is strictly better — it can
 * paginate, facet, count and highlight — but a lot of the forum goes through
 * core's discussion list: the tag pages, `/all?q=`, every third-party extension
 * that composes a filtered list, and any client that speaks Flarum's API. All
 * of that should get engine results too, without knowing this extension exists.
 *
 * ## What Flarum 1.8 lets a gambit do, and what it does not
 *
 * `Flarum\Extend\SimpleFlarumSearch::setFullTextGambit()` is the only seam;
 * there is no `SearchDriver` extender before 2.0 (verified against
 * flarum/core v1.8.18 — `src/Extend/SearchDriver.php` does not exist).
 * `AbstractSearcher::search()` applies the gambits FIRST and only then calls
 * `applySort`, `applyOffset` and `applyLimit` on the SQL builder. Three
 * consequences follow, and pretending otherwise is where the existing
 * Meilisearch extensions for Flarum go wrong:
 *
 *   1. **The gambit never sees the offset.** It must return a window of ids
 *      large enough to cover the page the caller will slice out of it. Hence
 *      `WINDOW`, which is a real limit and is stated rather than hidden — deep
 *      pagination past it is what our own endpoint is for.
 *   2. **There is no total.** Core takes `$limit + 1` and reports a boolean.
 *      Nothing here can change that; the results page gets the real count from
 *      the engine directly.
 *   3. **Other gambits filter in SQL, after ours.** `tag:` and `author:` are
 *      applied by flarum/tags to the same query builder. If the engine
 *      truncated to 500 ids and the tag filter then removes 490 of them, the
 *      page is nearly empty for no visible reason. So the tag and author
 *      constraints are pushed INTO the engine query as filters as well —
 *      belt and braces, and the SQL filter then removes nothing.
 *
 * Ordering is imposed with a portable `CASE` expression rather than MySQL's
 * `FIELD()`, which does not exist on PostgreSQL or SQLite. Flarum supports all
 * three, and a search extension that silently only works on MySQL is a trap for
 * whoever moves the database.
 */
class MeiliFulltextGambit implements GambitInterface
{
    /** How many engine results are fetched to back one core-paginated list. */
    private const WINDOW = 500;

    public function __construct(
        private Client $client,
        private Engine $engine,
        private QueryParser $parser,
        private \Flarum\Settings\SettingsRepositoryInterface $settings,
        private LoggerInterface $log
    ) {
    }

    public function apply(SearchState $search, $bit)
    {
        $actor = $search->getActor();
        $query = $search->getQuery();

        try {
            $ids = $this->idsFor((string) $bit, $actor);
        } catch (MeiliException $e) {
            $this->log->error('search gambit: engine unavailable, falling back to title match', $e->context());

            return $this->titleFallback($search, (string) $bit);
        }

        if (!$ids) {
            // An impossible condition rather than an unconstrained query. Without
            // this a zero-hit search returns the ENTIRE discussion list, which
            // looks like a broken filter and is far worse than an empty page.
            $query->whereRaw('1 = 0');

            return true;
        }

        $query->whereIn('discussions.id', $ids);

        // Relevance is the engine's order, and it exists only in that array.
        // A CASE ladder is the portable way to impose it; it is only applied
        // when the caller did not ask for a different sort, which is what
        // `setDefaultSort` means.
        $search->setDefaultSort(function ($q) use ($ids) {
            $q->orderByRaw($this->orderCase($ids));
        });

        return true;
    }

    /**
     * @return int[]
     */
    private function idsFor(string $bit, $actor): array
    {
        $parsed = $this->parser->parse($bit);
        $terms = trim($parsed['text'] . ' '
            . implode(' ', array_map(fn ($p) => '"' . $p . '"', $parsed['phrases'])) . ' '
            . implode(' ', array_map(fn ($n) => '-' . $n, $parsed['negatives'])));

        if ($terms === '' && !$parsed['tags'] && !$parsed['authors']) {
            return [];
        }

        $prefix = (string) ($this->settings->get('looksmax-search.prefix') ?: 'lmx');

        $discussionFilters = $this->engine->filtersFor($parsed, $actor, 'discussions');
        $postFilters = $this->engine->filtersFor($parsed, $actor, 'posts');

        // No per-query `limit` here. Meilisearch rejects a federated
        // multi-search whose member queries carry pagination options
        // (`invalid_multi_search_query_pagination`, HTTP 400) — the window is
        // set once, on `federation`, and applies to the merged result.
        $queries = [
            array_filter([
                'indexUid' => $prefix . '_discussions',
                'q' => $terms,
                'attributesToRetrieve' => ['id'],
                'filter' => $discussionFilters ? implode(' AND ', array_map(fn ($f) => "($f)", $discussionFilters)) : null,
                'federationOptions' => ['weight' => 1.4],
            ], fn ($v) => $v !== null),
            array_filter([
                'indexUid' => $prefix . '_posts',
                'q' => $terms,
                'attributesToRetrieve' => ['id', 'discussion_id'],
                'distinct' => 'discussion_id',
                'filter' => $postFilters ? implode(' AND ', array_map(fn ($f) => "($f)", $postFilters)) : null,
                'federationOptions' => ['weight' => 1.0],
            ], fn ($v) => $v !== null),
        ];

        $res = $this->client->multiSearch($queries, ['limit' => self::WINDOW, 'offset' => 0]);

        $ids = [];
        foreach ($res['hits'] ?? [] as $hit) {
            $id = isset($hit['discussion_id']) ? (int) $hit['discussion_id'] : (int) ($hit['id'] ?? 0);
            if ($id && !isset($ids[$id])) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Portable relevance ordering. `FIELD()` is MySQL-only; a CASE ladder runs
     * everywhere Flarum runs. Bound as literals because they are integers we
     * produced ourselves, never user input.
     */
    private function orderCase(array $ids): string
    {
        $when = '';
        foreach (array_values($ids) as $position => $id) {
            $when .= ' WHEN ' . (int) $id . ' THEN ' . $position;
        }

        return 'CASE discussions.id' . $when . ' ELSE ' . count($ids) . ' END ASC';
    }

    /**
     * Engine down: match titles only, never post bodies. Running the core
     * gambit here would replace a search outage with a database outage — the
     * measured reason this extension exists is that the post-table aggregate
     * does not complete at this corpus size.
     */
    private function titleFallback(SearchState $search, string $bit): bool
    {
        $terms = trim(preg_replace('/[^\p{L}\p{N}\p{M}_ ]+/u', ' ', $bit) ?? '');
        if ($terms === '') {
            $search->getQuery()->whereRaw('1 = 0');

            return true;
        }

        $grammar = $search->getQuery()->getGrammar();
        $search->getQuery()->whereRaw(
            'MATCH(' . $grammar->wrap('discussions.title') . ') AGAINST (? IN NATURAL LANGUAGE MODE)',
            [$terms]
        );
        $search->setDefaultSort(function ($q) use ($grammar, $terms) {
            $q->orderByRaw('MATCH(' . $grammar->wrap('discussions.title') . ') AGAINST (?) DESC', [$terms]);
        });

        return true;
    }
}
