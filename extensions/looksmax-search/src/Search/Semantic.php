<?php

namespace Local\Search\Search;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Local\Search\Meili\Client;
use Local\Search\Meili\Embedder;
use Local\Search\Meili\MeiliException;
use Psr\Log\LoggerInterface;

/**
 * Discovery that is not a search box: related topics, recommendations,
 * trending, "people also read", and near-duplicate detection.
 *
 * ## The two signals, and why neither is enough alone
 *
 * **Vectors** know what a thread is ABOUT. They will match
 * "¿cómo recuperarse de una rinoplastia?" to "nose job recovery timeline"
 * across a language boundary and with no shared token. What they cannot do is
 * tell a good thread from a dead one: the nearest neighbour of a popular guide
 * is often a two-post thread that asked the same question and got no answer.
 *
 * **Behaviour** knows what is WORTH reading. Two threads that the same people
 * posted in are related in a way no model can infer from text — that is the
 * signal behind every "customers also bought" that has ever worked. What it
 * cannot do is cover the tail: a thread with four participants has no reliable
 * co-visitation at all, and a brand-new thread has none by definition.
 *
 * So every surface here blends them, and each one degrades to the other when
 * its partner has nothing to say. `related()` is vector-first with a
 * behavioural bonus; `alsoRead()` is behaviour-only and returns an empty list
 * rather than a bad guess; `recommend()` is vector-first from behavioural
 * seeds and falls back to `trending()` when there are no seeds at all.
 *
 * ## Permissions
 *
 * Every method here goes through `Engine::engineFilter()` for the engine-side
 * filter and `Engine::hydrateHits()` for the authoritative database gate. That
 * is not politeness: a recommendation strip is exactly the kind of surface that
 * leaks a restricted tag, because it is generated for the reader rather than
 * requested by them, and nobody notices a leak in a sidebar.
 */
class Semantic
{
    /**
     * Above this cosine score two discussions are not "related", they are the
     * same thread asked twice. Calibrated, not guessed — see
     * `tools/similarity-calibrate.py`, which reports the score distribution
     * over real neighbour pairs on this corpus.
     */
    private const NEAR_DUPLICATE = 0.92;

    /** Below this, a "related" suggestion is noise and is better not shown. */
    private const RELATED_FLOOR = 0.55;

    public function __construct(
        private Client $client,
        private Embedder $embedder,
        private Engine $engine,
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log
    ) {
    }

    private function uid(string $kind): string
    {
        return $this->engine->uid($kind);
    }

    private function filterString(array $filters): ?string
    {
        $filters = array_values(array_filter($filters));

        return $filters ? implode(' AND ', array_map(fn ($f) => "($f)", $filters)) : null;
    }

    public function available(): bool
    {
        return $this->embedder->enabled();
    }

    // ------------------------------------------------------------------ related

    /**
     * Threads related to this one.
     *
     * Vector neighbours, re-ranked. The re-rank exists because raw cosine order
     * is not the order a reader wants:
     *
     * - **quality**, from the `rank_score` already materialised on every
     *   document (reactions, replies, views, recency). A 0.72-similar thread
     *   with 40 replies beats a 0.78-similar thread with one.
     * - **tag agreement**, a small bonus. Two threads in the same section that
     *   a model thinks are 0.70 similar really are more related than two in
     *   different sections at the same score.
     * - **near-duplicates are separated out**, not shown inline. At >= 0.92 the
     *   thread is the same question asked again; that is worth surfacing as
     *   "this has been asked before", and it is actively bad as a "read next".
     *
     * Over-fetches 4x the requested limit so the re-rank and the two permission
     * gates all have something to work with — a strip that asks for 6 and shows
     * 2 because 4 were filtered is worse than one round trip that costs 3ms
     * more.
     */
    public function related(int $discussionId, User $actor, int $limit = 8, array $opts = []): array
    {
        $started = microtime(true);
        $limit = max(1, min(30, $limit));

        if (!$this->embedder->enabled()) {
            return $this->relatedFallback($discussionId, $actor, $limit, 'embedder-disabled', $started);
        }

        $seed = $this->document($discussionId);
        if ($seed === null) {
            return [
                'discussionId' => $discussionId, 'related' => [], 'duplicates' => [],
                'alsoRead' => [], 'strategy' => 'not-indexed', 'totalMs' => $this->ms($started),
            ];
        }

        $filters = $this->engine->engineFilter($actor, 'discussions');
        $filters[] = 'id != ' . $discussionId;

        try {
            $res = $this->client->similar($this->uid('discussions'), array_filter([
                'id' => $discussionId,
                'embedder' => $this->embedder->name(),
                'limit' => $limit * 4,
                'filter' => $this->filterString($filters),
                'showRankingScore' => true,
                'rankingScoreThreshold' => self::RELATED_FLOOR,
            ], fn ($v) => $v !== null));
        } catch (MeiliException $e) {
            $this->log->warning('related: similar failed', $e->context() + ['discussion' => $discussionId]);

            return $this->relatedFallback($discussionId, $actor, $limit, 'engine-error', $started);
        }

        $hits = $res['hits'] ?? [];
        $shaped = $this->engine->hydrateHits($hits, $actor);

        // hydrateHits preserves order, so scores line up by position only if
        // nothing was filtered. Re-key by id instead of trusting the index.
        $scoreById = [];
        foreach ($hits as $h) {
            $scoreById[(int) $h['id']] = (float) ($h['_rankingScore'] ?? 0);
        }

        $seedTags = array_map('intval', $seed['tag_ids'] ?? []);
        $related = [];
        $duplicates = [];

        foreach ($shaped as $row) {
            $score = $scoreById[$row['id']] ?? 0.0;
            $row['similarity'] = round($score, 4);

            if ($score >= self::NEAR_DUPLICATE) {
                $row['reason'] = 'near-duplicate';
                $duplicates[] = $row;
                continue;
            }

            $rowTags = array_map(fn ($t) => $t['slug'] ?? '', $row['tags'] ?? []);
            $overlap = $seedTags && $rowTags
                ? count(array_intersect(
                    array_map('strval', $seed['tag_slugs'] ?? []),
                    $rowTags
                ))
                : 0;

            $row['_rank'] = 0.72 * $score
                + 0.20 * $this->qualityNorm($row)
                + 0.08 * min(1.0, $overlap / 2);
            $row['reason'] = 'semantic';
            $related[] = $row;
        }

        usort($related, fn ($a, $b) => $b['_rank'] <=> $a['_rank']);
        $related = array_slice($related, 0, $limit);
        foreach ($related as &$r) {
            unset($r['_rank']);
        }
        unset($r);

        $out = [
            'discussionId' => $discussionId,
            'related' => $related,
            'duplicates' => array_slice($duplicates, 0, 3),
            'strategy' => 'semantic',
            'candidates' => count($hits),
            'engineMs' => $res['processingTimeMs'] ?? null,
            'totalMs' => $this->ms($started),
        ];

        if (($opts['alsoRead'] ?? true)) {
            $out['alsoRead'] = $this->alsoRead($discussionId, $actor, min(6, $limit));
        }

        // A thread nobody has embedded yet, or one whose neighbours were all
        // filtered away, must still show something.
        if (!$related && !$duplicates) {
            $fb = $this->relatedFallback($discussionId, $actor, $limit, 'no-neighbours', $started);
            $out['related'] = $fb['related'];
            $out['strategy'] = 'semantic+' . $fb['strategy'];
        }

        return $out;
    }

    /**
     * What "related" means when there are no vectors: same tags, ranked by the
     * same quality score. Materially worse than semantic neighbours, which is
     * why it is labelled in `strategy` rather than passed off as the real
     * thing — a caller that cannot tell the two apart cannot tell whether the
     * embedder is down.
     */
    private function relatedFallback(int $discussionId, User $actor, int $limit, string $why, float $started): array
    {
        $seed = $this->document($discussionId);
        $tags = array_values(array_filter(array_map('strval', $seed['tag_slugs'] ?? [])));

        $filters = $this->engine->engineFilter($actor, 'discussions');
        $filters[] = 'id != ' . $discussionId;
        if ($tags) {
            $filters[] = '(' . implode(' OR ', array_map(fn ($t) => 'tag_slugs = "' . addslashes($t) . '"', $tags)) . ')';
        }

        try {
            $res = $this->client->search($this->uid('discussions'), array_filter([
                'q' => '',
                'limit' => $limit * 2,
                'filter' => $this->filterString($filters),
                'sort' => ['rank_score:desc'],
            ], fn ($v) => $v !== null));
        } catch (MeiliException $e) {
            return [
                'discussionId' => $discussionId, 'related' => [], 'duplicates' => [], 'alsoRead' => [],
                'strategy' => 'unavailable', 'error' => $e->getMessage(), 'totalMs' => $this->ms($started),
            ];
        }

        $rows = array_slice($this->engine->hydrateHits($res['hits'] ?? [], $actor), 0, $limit);
        foreach ($rows as &$r) {
            $r['reason'] = 'same-tag';
        }
        unset($r);

        return [
            'discussionId' => $discussionId,
            'related' => $rows,
            'duplicates' => [],
            'alsoRead' => $this->alsoRead($discussionId, $actor, min(6, $limit)),
            'strategy' => 'tag-fallback:' . $why,
            'totalMs' => $this->ms($started),
        ];
    }

    // ---------------------------------------------------------------- alsoRead

    /**
     * "People who posted here also posted in…"
     *
     * Computed in the SEARCH ENGINE, not in SQL, and that is the whole trick.
     * The natural SQL is a self-join on `posts` — take this thread's
     * participants, then group their other posts by discussion — and on a
     * 1.4M-row table with users who have 10,000 posts each it is a table scan
     * per request. Meilisearch already holds `author_id` and `discussion_id` as
     * filterable attributes, and a facet distribution over `discussion_id` for
     * `author_id IN [...]` is exactly the same aggregate, computed from the
     * inverted index, with no load on the database the importer is hammering.
     *
     * Two corrections applied to the raw counts:
     *
     *  - **participants are capped at 40 and taken from the most recent posts.**
     *    An unbounded participant set makes the filter string enormous and lets
     *    one mega-thread define everyone's recommendations.
     *  - **the count is damped by thread size.** Raw co-occurrence always ranks
     *    the forum's biggest threads first, because everyone has posted in
     *    them; dividing by log(size) is what stops "related to everything"
     *    threads from being related to everything.
     */
    public function alsoRead(int $discussionId, User $actor, int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));

        try {
            $participants = $this->db->table('posts')
                ->where('discussion_id', $discussionId)
                ->whereNotNull('user_id')
                ->orderBy('number', 'desc')
                ->limit(120)
                ->pluck('user_id')
                ->unique()
                ->take(40)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $this->log->warning('alsoRead: participant query failed', ['error' => $e->getMessage()]);

            return [];
        }

        if (count($participants) < 2) {
            return [];
        }

        $filters = $this->engine->engineFilter($actor, 'posts');
        $filters[] = 'author_id IN [' . implode(',', array_map('intval', $participants)) . ']';
        $filters[] = 'discussion_id != ' . $discussionId;

        try {
            $res = $this->client->search($this->uid('posts'), array_filter([
                'q' => '',
                'limit' => 0,
                'filter' => $this->filterString($filters),
                'facets' => ['discussion_id'],
            ], fn ($v) => $v !== null));
        } catch (MeiliException $e) {
            $this->log->warning('alsoRead: facet query failed', $e->context());

            return [];
        }

        $dist = $res['facetDistribution']['discussion_id'] ?? [];
        if (!$dist) {
            return [];
        }
        arsort($dist);
        $candidateIds = array_slice(array_map('intval', array_keys($dist)), 0, $limit * 6);
        if (!$candidateIds) {
            return [];
        }

        $dfilters = $this->engine->engineFilter($actor, 'discussions');
        $dfilters[] = 'id IN [' . implode(',', $candidateIds) . ']';

        try {
            $docs = $this->client->search($this->uid('discussions'), array_filter([
                'q' => '',
                'limit' => count($candidateIds),
                'filter' => $this->filterString($dfilters),
            ], fn ($v) => $v !== null));
        } catch (MeiliException $e) {
            $this->log->warning('alsoRead: discussion fetch failed', $e->context());

            return [];
        }

        $rows = $this->engine->hydrateHits($docs['hits'] ?? [], $actor);
        foreach ($rows as &$r) {
            $co = (int) ($dist[(string) $r['id']] ?? 0);
            // Damp by thread size: everybody has posted in the big threads.
            $r['coPosts'] = $co;
            $r['_rank'] = $co / log(2.71828 + max(0, (int) ($r['commentCount'] ?? 0)));
            $r['reason'] = 'also-read';
        }
        unset($r);

        usort($rows, fn ($a, $b) => $b['_rank'] <=> $a['_rank']);
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$r) {
            unset($r['_rank']);
        }
        unset($r);

        return $rows;
    }

    // ------------------------------------------------------------- recommend

    /**
     * "For you".
     *
     * Three tiers, chosen by how much signal the reader has actually left. The
     * tier is reported in `strategy`, because a recommendation you cannot
     * explain is a recommendation you cannot debug.
     *
     * 1. **Personalised** — the reader has posted somewhere. Take up to 25 of
     *    the discussions they most recently participated in, read those
     *    documents' stored vectors back out of the engine, average them into a
     *    centroid, and search from that. This finds threads that are like the
     *    reader's INTERESTS rather than like any single thread, which is the
     *    difference between a recommendation and a "related" strip.
     *
     * 2. **Cold-start with a hint** — no history, but the caller passed tags or
     *    a discussion the reader is currently looking at. Seed from that.
     *
     * 3. **Cold start** — a logged-out visitor, which on a public forum is most
     *    of the traffic. There is no personal signal and pretending otherwise
     *    produces the "recommended: whatever is newest" strip that everybody
     *    ignores. Instead: what is genuinely moving right now (`trending`),
     *    padded with the best-of-all-time, and diversified across sections so
     *    the strip is not six threads from one subforum.
     *
     * Diversity is applied in every tier. Un-diversified nearest-neighbour
     * recommendations collapse: someone who reads three skincare threads gets
     * ten skincare threads and never discovers anything, which is the failure
     * mode that makes people stop clicking recommendations at all.
     */
    public function recommend(User $actor, int $limit = 12, array $opts = []): array
    {
        $started = microtime(true);
        $limit = max(1, min(50, $limit));
        $seenIds = [];

        $seedIds = [];
        if (!$actor->isGuest()) {
            $seedIds = $this->personalSeeds($actor);
            $seenIds = $seedIds;
        }
        foreach ((array) ($opts['seedDiscussions'] ?? []) as $d) {
            $seedIds[] = (int) $d;
        }
        $seedIds = array_values(array_unique(array_filter($seedIds)));

        $filters = $this->engine->engineFilter($actor, 'discussions');
        $exclude = array_values(array_unique(array_merge($seenIds, array_map('intval', (array) ($opts['exclude'] ?? [])))));
        if ($exclude) {
            $filters[] = 'id NOT IN [' . implode(',', array_slice($exclude, 0, 300)) . ']';
        }
        if (!empty($opts['tags'])) {
            $filters[] = '(' . implode(' OR ', array_map(
                fn ($t) => 'tag_slugs = "' . addslashes((string) $t) . '"',
                (array) $opts['tags']
            )) . ')';
        }
        // A recommendation for a dead thread is not a recommendation. Nothing
        // older than the configured horizon unless it is genuinely excellent,
        // which `rank_score` already encodes — so this is a floor, not a date cut.
        if (!empty($opts['minRank'])) {
            $filters[] = 'rank_score >= ' . (float) $opts['minRank'];
        }

        $rows = [];
        $strategy = 'cold-start';

        if ($seedIds && $this->embedder->enabled()) {
            $vectors = $this->vectorsFor($seedIds);
            $centroid = Embedder::centroid($vectors);
            if ($centroid) {
                try {
                    $res = $this->client->search($this->uid('discussions'), array_filter([
                        'q' => '',
                        'vector' => $centroid,
                        'hybrid' => ['embedder' => $this->embedder->name(), 'semanticRatio' => 1.0],
                        'limit' => $limit * 5,
                        'filter' => $this->filterString($filters),
                        'showRankingScore' => true,
                    ], fn ($v) => $v !== null));
                    $hits = $res['hits'] ?? [];
                    $scoreById = [];
                    foreach ($hits as $h) {
                        $scoreById[(int) $h['id']] = (float) ($h['_rankingScore'] ?? 0);
                    }
                    $rows = $this->engine->hydrateHits($hits, $actor);
                    foreach ($rows as &$r) {
                        $r['similarity'] = round($scoreById[$r['id']] ?? 0, 4);
                        $r['_rank'] = 0.7 * ($scoreById[$r['id']] ?? 0) + 0.3 * $this->qualityNorm($r);
                        $r['reason'] = 'your-activity';
                    }
                    unset($r);
                    usort($rows, fn ($a, $b) => $b['_rank'] <=> $a['_rank']);
                    $strategy = $actor->isGuest() ? 'seeded' : 'personalised';
                } catch (MeiliException $e) {
                    $this->log->warning('recommend: vector search failed', $e->context());
                    $rows = [];
                }
            }
        }

        if (!$rows) {
            // Cold start. Trending first — it is the only thing on a forum that
            // is genuinely interesting to a stranger — then best-of as padding.
            $trend = $this->trending($actor, $limit * 3, (int) ($opts['windowHours'] ?? 72));
            $rows = $trend['discussions'];
            foreach ($rows as &$r) {
                $r['reason'] = 'trending';
                $r['_rank'] = 1.0 + (float) ($r['trendScore'] ?? 0);
            }
            unset($r);

            if (count($rows) < $limit * 2) {
                try {
                    $best = $this->client->search($this->uid('discussions'), array_filter([
                        'q' => '',
                        'limit' => $limit * 3,
                        'filter' => $this->filterString($filters),
                        'sort' => ['rank_score:desc'],
                    ], fn ($v) => $v !== null));
                    $have = array_flip(array_column($rows, 'id'));
                    foreach ($this->engine->hydrateHits($best['hits'] ?? [], $actor) as $r) {
                        if (!isset($have[$r['id']])) {
                            $r['reason'] = 'top-rated';
                            $r['_rank'] = $this->qualityNorm($r);
                            $rows[] = $r;
                        }
                    }
                } catch (MeiliException $e) {
                    $this->log->warning('recommend: cold-start fallback failed', $e->context());
                }
            }
            usort($rows, fn ($a, $b) => $b['_rank'] <=> $a['_rank']);
        }

        $rows = $this->diversify($rows, $limit, (int) ($opts['perTag'] ?? 3));
        foreach ($rows as &$r) {
            unset($r['_rank']);
        }
        unset($r);

        return [
            'recommendations' => $rows,
            'strategy' => $strategy,
            'personalised' => $strategy === 'personalised',
            'seedCount' => count($seedIds),
            'totalMs' => $this->ms($started),
        ];
    }

    /**
     * The discussions this reader has actually engaged with, most recent first.
     *
     * Posting is the strongest available signal and the only one guaranteed to
     * exist. Reactions are added when the reactions extension is installed —
     * probed rather than depended on, because this extension must not require
     * that one. Views would be better still and are deliberately NOT used:
     * there is no per-user view table on this forum, and inventing a signal
     * from `discussions.view_count` (which is global) would produce
     * "recommendations" identical for every reader.
     *
     * @return int[]
     */
    private function personalSeeds(User $actor): array
    {
        $ids = [];

        try {
            $ids = $this->db->table('posts')
                ->where('user_id', $actor->id)
                ->orderBy('created_at', 'desc')
                ->limit(150)
                ->pluck('discussion_id')
                ->unique()
                ->take(25)
                ->map('intval')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $this->log->warning('recommend: seed query failed', ['error' => $e->getMessage()]);
        }

        foreach (['post_reactions', 'reactions'] as $table) {
            if (count($ids) >= 25) {
                break;
            }
            try {
                if (!$this->db->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                $extra = $this->db->table($table . ' as r')
                    ->join('posts as p', 'p.id', '=', 'r.post_id')
                    ->where('r.user_id', $actor->id)
                    ->orderBy('r.id', 'desc')
                    ->limit(100)
                    ->pluck('p.discussion_id')
                    ->map('intval')
                    ->all();
                $ids = array_values(array_unique(array_merge($ids, $extra)));
            } catch (\Throwable) {
                // The table exists but does not have the columns we assumed.
                // A missing personalisation signal is not an error worth a 500.
            }
        }

        return array_slice(array_values(array_unique(array_filter($ids))), 0, 25);
    }

    /**
     * Read stored embeddings back out of the engine.
     *
     * `retrieveVectors` on `/documents/fetch` is the only way to do this, and
     * it is why `id` had to become a filterable attribute on the discussions
     * index. Meilisearch returns `_vectors.<name>.embeddings` as an ARRAY of
     * vectors, not a single vector, because one document may be chunked into
     * several — we take the first, which for a single-fragment embedder is the
     * whole document.
     *
     * @param  int[] $ids
     * @return array<int, float[]>
     */
    private function vectorsFor(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        try {
            $res = $this->client->fetchDocuments($this->uid('discussions'), [
                'filter' => 'id IN [' . implode(',', array_map('intval', $ids)) . ']',
                'limit' => count($ids),
                'fields' => ['id'],
                'retrieveVectors' => true,
            ]);
        } catch (MeiliException $e) {
            $this->log->warning('recommend: vector fetch failed', $e->context());

            return [];
        }

        $name = $this->embedder->name();
        $out = [];
        foreach ($res['results'] ?? [] as $doc) {
            $emb = $doc['_vectors'][$name]['embeddings'] ?? null;
            if (!is_array($emb) || !$emb) {
                continue;
            }
            // Either [[...]] (chunked shape) or [...] (flat shape) depending on
            // how the document was written. Detect rather than assume.
            $vec = is_array($emb[0]) ? $emb[0] : $emb;
            if (is_array($vec) && count($vec) === $this->embedder->dimensions()) {
                $out[] = array_map('floatval', $vec);
            }
        }

        return $out;
    }

    // -------------------------------------------------------------- trending

    /**
     * What is actually moving right now.
     *
     * "Most replies in the window" alone just returns the forum's permanent
     * megathreads, which are not news to anybody. What a reader wants is
     * ACCELERATION: threads whose current rate of replies is high relative to
     * their own history. So the score is
     *
     *     window_posts / log(e + lifetime_posts)
     *
     * which promotes a two-day-old thread with 30 replies over a three-year-old
     * thread with 30 replies this week and 8,000 in total.
     *
     * Like `alsoRead`, the aggregate is a Meilisearch facet distribution over
     * `discussion_id` on the posts index rather than a `GROUP BY` against a
     * table the importer is writing 540 rows/s into.
     *
     * NOTE the ceiling: `maxValuesPerFacet` is 200 on this index, so trending
     * considers the 200 most active threads in the window. That is the right
     * number for a "what's hot" strip and the wrong number for analytics; it is
     * stated here rather than discovered later.
     */
    public function trending(User $actor, int $limit = 10, int $windowHours = 48): array
    {
        $started = microtime(true);
        $limit = max(1, min(50, $limit));
        $windowHours = max(1, min(24 * 30, $windowHours));
        $cutoff = time() - $windowHours * 3600;

        $filters = $this->engine->engineFilter($actor, 'posts');
        $filters[] = 'created_at > ' . $cutoff;

        try {
            $res = $this->client->search($this->uid('posts'), array_filter([
                'q' => '',
                'limit' => 0,
                'filter' => $this->filterString($filters),
                'facets' => ['discussion_id'],
            ], fn ($v) => $v !== null));
        } catch (MeiliException $e) {
            $this->log->warning('trending: facet query failed', $e->context());

            return ['discussions' => [], 'windowHours' => $windowHours, 'strategy' => 'unavailable', 'totalMs' => $this->ms($started)];
        }

        $dist = $res['facetDistribution']['discussion_id'] ?? [];
        if (!$dist) {
            return ['discussions' => [], 'windowHours' => $windowHours, 'strategy' => 'no-activity', 'totalMs' => $this->ms($started)];
        }

        arsort($dist);
        $ids = array_slice(array_map('intval', array_keys($dist)), 0, max(60, $limit * 5));

        $dfilters = $this->engine->engineFilter($actor, 'discussions');
        $dfilters[] = 'id IN [' . implode(',', $ids) . ']';

        try {
            $docs = $this->client->search($this->uid('discussions'), array_filter([
                'q' => '',
                'limit' => count($ids),
                'filter' => $this->filterString($dfilters),
            ], fn ($v) => $v !== null));
        } catch (MeiliException $e) {
            $this->log->warning('trending: discussion fetch failed', $e->context());

            return ['discussions' => [], 'windowHours' => $windowHours, 'strategy' => 'unavailable', 'totalMs' => $this->ms($started)];
        }

        $rows = $this->engine->hydrateHits($docs['hits'] ?? [], $actor);
        foreach ($rows as &$r) {
            $window = (int) ($dist[(string) $r['id']] ?? 0);
            $lifetime = max(1, (int) ($r['commentCount'] ?? 1));
            $r['windowPosts'] = $window;
            $r['trendScore'] = round($window / log(M_E + $lifetime), 4);
            $r['reason'] = 'trending';
        }
        unset($r);

        usort($rows, fn ($a, $b) => $b['trendScore'] <=> $a['trendScore']);

        return [
            'discussions' => array_slice($rows, 0, $limit),
            'windowHours' => $windowHours,
            'consideredThreads' => count($dist),
            'facetCeiling' => 200,
            'strategy' => 'acceleration',
            'totalMs' => $this->ms($started),
        ];
    }

    // ------------------------------------------------------------ duplicates

    /**
     * "This has been asked before" — for a draft that has not been posted yet.
     *
     * Runs BOTH signals and merges, because they fail on opposite inputs:
     * the vector search catches a duplicate phrased completely differently
     * (and in the other language, which on this board is half the value), and
     * the keyword search catches the case where somebody pasted a near-identical
     * title, which a 384-dimension model may score at only 0.88 while a human
     * would call it the same thread.
     *
     * Takes the draft text directly rather than a document id, so it can run
     * from the composer before anything exists to be similar to. That is why
     * `Embedder::embed()` exists at all.
     */
    public function duplicates(string $title, string $body, User $actor, int $limit = 5): array
    {
        $started = microtime(true);
        $limit = max(1, min(20, $limit));
        $title = trim($title);
        $body = trim($body);

        if ($title === '' && $body === '') {
            return ['duplicates' => [], 'strategy' => 'empty', 'totalMs' => $this->ms($started)];
        }

        $filters = $this->engine->engineFilter($actor, 'discussions');
        $filterStr = $this->filterString($filters);
        $byId = [];
        $strategy = [];

        // --- semantic
        if ($this->embedder->enabled()) {
            try {
                $text = trim($title . '. ' . mb_substr($body, 0, max(0, $this->embedder->textCap() - mb_strlen($title) - 2)));
                $vec = $this->embedder->embed([$text])[0] ?? null;
                if ($vec) {
                    $res = $this->client->search($this->uid('discussions'), array_filter([
                        'q' => '',
                        'vector' => $vec,
                        'hybrid' => ['embedder' => $this->embedder->name(), 'semanticRatio' => 1.0],
                        'limit' => $limit * 3,
                        'filter' => $filterStr,
                        'showRankingScore' => true,
                        'rankingScoreThreshold' => 0.78,
                    ], fn ($v) => $v !== null));
                    foreach ($res['hits'] ?? [] as $h) {
                        $byId[(int) $h['id']] = ['hit' => $h, 'semantic' => (float) ($h['_rankingScore'] ?? 0), 'keyword' => 0.0];
                    }
                    $strategy[] = 'semantic';
                }
            } catch (MeiliException $e) {
                $this->log->warning('duplicates: semantic leg failed', $e->context());
            }
        }

        // --- keyword on the title
        if ($title !== '') {
            try {
                $res = $this->client->search($this->uid('discussions'), array_filter([
                    'q' => \Local\Search\Meili\Text::fold($title),
                    'limit' => $limit * 3,
                    'filter' => $filterStr,
                    'showRankingScore' => true,
                    'rankingScoreThreshold' => 0.6,
                ], fn ($v) => $v !== null));
                foreach ($res['hits'] ?? [] as $h) {
                    $id = (int) $h['id'];
                    if (isset($byId[$id])) {
                        $byId[$id]['keyword'] = (float) ($h['_rankingScore'] ?? 0);
                    } else {
                        $byId[$id] = ['hit' => $h, 'semantic' => 0.0, 'keyword' => (float) ($h['_rankingScore'] ?? 0)];
                    }
                }
                $strategy[] = 'keyword';
            } catch (MeiliException $e) {
                $this->log->warning('duplicates: keyword leg failed', $e->context());
            }
        }

        if (!$byId) {
            return ['duplicates' => [], 'strategy' => implode('+', $strategy) ?: 'none', 'totalMs' => $this->ms($started)];
        }

        $rows = $this->engine->hydrateHits(array_column($byId, 'hit'), $actor);
        foreach ($rows as &$r) {
            $s = $byId[$r['id']]['semantic'] ?? 0.0;
            $k = $byId[$r['id']]['keyword'] ?? 0.0;
            $r['similarity'] = round(max($s, $k), 4);
            $r['semanticScore'] = round($s, 4);
            $r['keywordScore'] = round($k, 4);
            // Agreement between two independent signals is much stronger
            // evidence than a high score from either one alone.
            $r['_rank'] = max($s, $k) + 0.25 * min($s, $k);
            $r['confidence'] = $r['_rank'] >= 1.0 ? 'high' : ($r['_rank'] >= 0.85 ? 'medium' : 'low');
        }
        unset($r);

        usort($rows, fn ($a, $b) => $b['_rank'] <=> $a['_rank']);
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$r) {
            unset($r['_rank']);
        }
        unset($r);

        return [
            'duplicates' => $rows,
            'strategy' => implode('+', $strategy),
            'totalMs' => $this->ms($started),
        ];
    }

    // ----------------------------------------------------------------- shared

    /** The stored document, or null if this discussion is not indexed yet. */
    private function document(int $id): ?array
    {
        try {
            $res = $this->client->fetchDocuments($this->uid('discussions'), [
                'filter' => 'id = ' . $id,
                'limit' => 1,
                'fields' => ['id', 'title', 'tag_ids', 'tag_slugs', 'primary_tag', 'embed_text'],
            ]);
        } catch (MeiliException $e) {
            $this->log->warning('semantic: document fetch failed', $e->context() + ['id' => $id]);

            return null;
        }

        return $res['results'][0] ?? null;
    }

    /**
     * `rank_score` mapped into 0..1 so it can be mixed with a cosine similarity.
     *
     * The raw score is unbounded (it is a sum of damped logs — see
     * DocumentBuilder::rankScore), so a linear normalisation would be dominated
     * by whatever the single largest thread on the forum is. `x/(x+k)` is
     * bounded, monotonic and has no free parameter beyond the half-way point,
     * which is set at the value a solidly-good thread reaches.
     */
    private function qualityNorm(array $row): float
    {
        $score = (float) ($row['score'] ?? 0);
        if ($score <= 0) {
            // `score` on a hydrated row is the Meilisearch ranking score, not
            // rank_score. Reconstruct from the fields we do carry.
            $score = log(1 + max(0, (int) ($row['reactions'] ?? 0))) * 1.5
                + log(1 + max(0, (int) ($row['commentCount'] ?? 0)))
                + log(1 + max(0, (int) ($row['views'] ?? 0))) * 0.5;
        }

        return $score / ($score + 6.0);
    }

    /**
     * Cap how many results may come from one section.
     *
     * Applied after ranking, preserving order within each section, and it
     * BACKFILLS: if capping leaves fewer than `limit` rows, the best of the
     * ones that were capped out are added back. A diversity rule that returns
     * four items when you asked for twelve has just made the product worse in
     * the name of making it better.
     */
    private function diversify(array $rows, int $limit, int $perTag = 3): array
    {
        if ($perTag <= 0) {
            return array_slice($rows, 0, $limit);
        }

        $seen = [];
        $kept = [];
        $overflow = [];
        foreach ($rows as $r) {
            $key = $r['tags'][0]['slug'] ?? ($r['primaryTag'] ?? '_');
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] <= $perTag && count($kept) < $limit) {
                $kept[] = $r;
            } else {
                $overflow[] = $r;
            }
        }

        foreach ($overflow as $r) {
            if (count($kept) >= $limit) {
                break;
            }
            $kept[] = $r;
        }

        return $kept;
    }

    private function ms(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }
}
