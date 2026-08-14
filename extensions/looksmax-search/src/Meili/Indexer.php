<?php

namespace Local\Search\Meili;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Everything that writes to the engine.
 *
 * Batching rules, learned the expensive way rather than guessed:
 *
 * - Batch by BYTES, not by row count. Forum posts have a pathological length
 *   distribution — most are two lines, a few are 40 KB guides. A fixed
 *   1000-row batch is 300 KB most of the time and 12 MB when it hits a run of
 *   guides, and the large one either exceeds the payload limit or blows the
 *   indexer's memory budget. Byte-bounded batches are uniform.
 *
 * - Do not wait for every task. Meilisearch processes its task queue
 *   asynchronously and batches adjacent tasks internally; blocking on each
 *   push serialises the client to the indexer's slowest step and throws away
 *   most of the throughput. We pipeline, keep the accepted task ids, and
 *   settle them at the end — where a failure is still caught, just not
 *   caught one batch at a time.
 *
 * - A 202 is not an index. `waitAll()` converts every accepted task into
 *   succeeded-or-raised before the caller is allowed to report success.
 */
class Indexer
{
    /** ~8 MB per batch: comfortably under the payload limit, big enough to amortise the round trip. */
    private const BATCH_BYTES = 8_000_000;
    private const BATCH_MAX_DOCS = 5_000;

    private array $pendingTasks = [];

    public function __construct(
        private Client $client,
        private DocumentBuilder $builder,
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log
    ) {
    }

    public function prefix(): string
    {
        return (string) ($this->settings->get('looksmax-search.prefix') ?: 'lmx');
    }

    public function index(string $kind): string
    {
        return $this->prefix() . '_' . $kind;
    }

    /** Create every index and push its settings. Idempotent. */
    public function ensureIndexes(bool $force = false): array
    {
        $result = [];
        foreach (IndexSettings::KINDS as $kind) {
            $uid = $this->index($kind);
            if (!$this->client->indexExists($uid)) {
                $task = $this->client->createIndex($uid, 'id');
                $this->client->waitForTask((int) $task['taskUid']);
                $result[$uid] = 'created';
            } else {
                $result[$uid] = 'exists';
            }

            $task = $this->client->updateSettings($uid, IndexSettings::for($kind));
            $this->client->waitForTask((int) $task['taskUid']);
            $result[$uid] .= ' + settings';
        }

        return $result;
    }

    /**
     * Install (or remove) the vector embedder on the discussions index.
     *
     * ## Why only discussions
     *
     * Because the measurement said so. Embedding on this box runs at roughly
     * 17–120 documents/s depending on how hard the importer is hitting the
     * other 26 cores. That makes the two candidate scopes:
     *
     *     48.5k discussions   →  7 minutes to ~48 minutes,  ~75 MB of vectors
     *      1.4M posts         →  3.2 hours to 23 hours,     ~2.1 GB of vectors
     *                            and growing to 5M posts    (~7.7 GB)
     *
     * The 1.4M-post number is not merely slower, it never converges: the
     * importer is adding ~540 posts/s, which is 30x the sustained embedding
     * rate available on the CPU left over on this box. An embedder that falls
     * further behind every second is not an incremental pipeline.
     *
     * And it buys little. Related topics, recommendations and "this has been
     * asked before" all compare THREADS. The thing a post-level embedding adds
     * is finding the paragraph inside a thread, which the keyword index already
     * does well because a person searching inside a thread uses that thread's
     * own words.
     *
     * ## Why this is a separate call from `ensureIndexes`
     *
     * Adding an embedder to a populated index makes Meilisearch re-embed every
     * document in it, as ONE task. Meilisearch's scheduler processes one batch
     * at a time across all indexes, so that task blocks post indexing for its
     * whole duration. That is a deliberate, scheduled operation with a known
     * cost — not something `search:index --settings-only` should trigger as a
     * side effect while somebody is fixing a typo in a synonym list.
     */
    public function applyEmbedder(bool $remove = false): array
    {
        $uid = $this->index('discussions');
        $embedder = resolve(Embedder::class);
        $name = $embedder->name();

        $payload = [$name => $remove ? null : $embedder->definition()];
        $task = $this->client->updateEmbedders($uid, $payload);

        return [
            'index' => $uid,
            'embedder' => $name,
            'action' => $remove ? 'removed' : 'applied',
            'definition' => $remove ? null : $embedder->definition(),
            'taskUid' => (int) ($task['taskUid'] ?? 0),
        ];
    }

    /**
     * How much of the discussions index actually carries a vector.
     *
     * `numberOfEmbeddedDocuments` vs `numberOfDocuments` is the only honest
     * coverage number: a settings task can report `succeeded` while individual
     * documents failed to embed (a 429 from a hosted API, a text that tokenised
     * to nothing), and those documents are simply absent from every semantic
     * result forever with no error anywhere.
     */
    public function embedStatus(): array
    {
        $uid = $this->index('discussions');
        $embedder = resolve(Embedder::class);

        $out = [
            'index' => $uid,
            'enabled' => $embedder->enabled(),
            'name' => $embedder->name(),
            'wire' => $embedder->wire(),
            'url' => $embedder->url(),
            'model' => $embedder->model(),
            'dimensions' => $embedder->dimensions(),
            'textCap' => $embedder->textCap(),
            'defaultRatio' => $embedder->defaultRatio(),
        ];

        try {
            $out['configured'] = array_keys($this->client->getEmbedders($uid));
        } catch (MeiliException $e) {
            $out['configured'] = [];
            $out['error'] = $e->getMessage();
        }

        try {
            $stats = $this->client->request('GET', "indexes/$uid/stats");
            $docs = (int) ($stats['numberOfDocuments'] ?? 0);
            $embedded = (int) ($stats['numberOfEmbeddedDocuments'] ?? 0);
            $out['documents'] = $docs;
            $out['embeddedDocuments'] = $embedded;
            $out['embeddings'] = (int) ($stats['numberOfEmbeddings'] ?? 0);
            $out['coveragePct'] = $docs > 0 ? round($embedded / $docs * 100, 2) : 0.0;
            $out['isIndexing'] = (bool) ($stats['isIndexing'] ?? false);
        } catch (MeiliException $e) {
            $out['error'] = $e->getMessage();
        }

        $out['backend'] = $embedder->health();

        return $out;
    }

    /**
     * Compare live settings against the declared ones. Settings drift is
     * invisible until a query silently stops filtering, so it gets a command.
     */
    public function settingsDiff(): array
    {
        $diff = [];
        foreach (IndexSettings::KINDS as $kind) {
            $uid = $this->index($kind);
            if (!$this->client->indexExists($uid)) {
                $diff[$uid] = ['__missing' => true];
                continue;
            }
            $live = $this->client->getSettings($uid);
            foreach (IndexSettings::for($kind) as $key => $want) {
                $have = $live[$key] ?? null;
                if ($this->drifted($have, $want)) {
                    $diff[$uid][$key] = ['live' => $have, 'declared' => $want];
                }
            }
        }

        return $diff;
    }

    /**
     * Has the live value drifted from what we declared?
     *
     * Only the keys we actually declared are assertions. Meilisearch echoes
     * back the full object including its own defaults, so a `typoTolerance` we
     * declared with three keys comes back with five — `disableOnAttributes: []`
     * and `disableOnWords: []` that we never expressed an opinion about.
     *
     * Comparing whole objects therefore reported permanent drift on three
     * indexes immediately after `search:index --settings-only` had just applied
     * them. A drift check that is red when nothing is wrong is worse than no
     * drift check: it trains you to ignore the one time it is right.
     *
     * Lists are still compared in full and order-insensitively — for a list,
     * "extra entries" IS drift.
     */
    private function drifted($live, $want): bool
    {
        $isMap = is_array($want) && $want !== [] && array_keys($want) !== range(0, count($want) - 1);

        if ($isMap) {
            if (!is_array($live)) {
                return true;
            }
            foreach ($want as $k => $v) {
                if (!array_key_exists($k, $live) || $this->drifted($live[$k], $v)) {
                    return true;
                }
            }

            return false;
        }

        return $this->normalise($live) !== $this->normalise($want);
    }

    private function normalise($v)
    {
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            $out = array_map([$this, 'normalise'], $v);
            if ($isList) {
                sort($out);
            } else {
                ksort($out);
            }

            return $out;
        }

        return $v;
    }

    // ------------------------------------------------------------- bulk paths

    /**
     * @param callable(int,int,int):void|null $progress  (done, total, docsThisChunk)
     */
    public function reindexDiscussions(?callable $progress = null, int $chunk = 2000, ?int $sinceId = null): int
    {
        return $this->reindexTable('discussions', 'discussions', fn ($ids) => $this->builder->discussions($ids), $progress, $chunk, $sinceId);
    }

    public function reindexPosts(?callable $progress = null, int $chunk = 2000, ?int $sinceId = null): int
    {
        return $this->reindexTable('posts', 'posts', fn ($ids) => $this->builder->posts($ids), $progress, $chunk, $sinceId, function ($q) {
            $q->where('type', 'comment');
        });
    }

    public function reindexUsers(?callable $progress = null, int $chunk = 5000, ?int $sinceId = null): int
    {
        return $this->reindexTable('users', 'users', fn ($ids) => $this->builder->users($ids), $progress, $chunk, $sinceId);
    }

    public function reindexTags(): int
    {
        $docs = $this->builder->tags();
        if ($docs) {
            $this->push('tags', array_values($docs));
        }

        return count($docs);
    }

    private function reindexTable(
        string $table,
        string $kind,
        callable $build,
        ?callable $progress,
        int $chunk,
        ?int $sinceId,
        ?callable $scope = null
    ): int {
        $total = 0;
        $lastId = $sinceId ?? 0;
        $buffer = [];
        $bufferBytes = 0;

        while (true) {
            $q = $this->db->table($table)->where('id', '>', $lastId)->orderBy('id')->limit($chunk);
            if ($scope) {
                $scope($q);
            }
            $ids = $q->pluck('id')->all();
            if (!$ids) {
                break;
            }
            $lastId = (int) end($ids);

            $docs = $build(array_map('intval', $ids));
            foreach ($docs as $doc) {
                $size = $this->approxSize($doc);
                if ($buffer && ($bufferBytes + $size > self::BATCH_BYTES || count($buffer) >= self::BATCH_MAX_DOCS)) {
                    $this->push($kind, $buffer);
                    $buffer = [];
                    $bufferBytes = 0;
                }
                $buffer[] = $doc;
                $bufferBytes += $size;
            }

            $total += count($docs);
            if ($progress) {
                $progress($total, $lastId, count($docs));
            }
        }

        if ($buffer) {
            $this->push($kind, $buffer);
        }

        return $total;
    }

    private function approxSize(array $doc): int
    {
        // strlen on the two large fields plus a flat overhead is within a few
        // percent of the encoded size and ~50x cheaper than json_encode-ing
        // every document twice.
        return 400
            + strlen($doc['content'] ?? '')
            + strlen($doc['excerpt'] ?? '')
            + strlen($doc['title'] ?? '')
            + strlen($doc['discussion_title'] ?? '');
    }

    public function push(string $kind, array $docs): void
    {
        if (!$docs) {
            return;
        }
        $res = $this->client->addDocuments($this->index($kind), array_values($docs));
        if (isset($res['taskUid'])) {
            $this->pendingTasks[] = (int) $res['taskUid'];
        }
        // Bound the outstanding set so a full reindex cannot accumulate
        // millions of task ids, and so backpressure reaches us if the engine
        // falls behind rather than surfacing as an OOM an hour later.
        if (count($this->pendingTasks) >= 64) {
            $this->waitAll();
        }
    }

    public function delete(string $kind, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $res = $this->client->deleteDocuments($this->index($kind), array_map('intval', array_values($ids)));
        if (isset($res['taskUid'])) {
            $this->pendingTasks[] = (int) $res['taskUid'];
        }
    }

    /** Turn every accepted task into succeeded-or-thrown. */
    public function waitAll(int $timeoutMs = 1_800_000): void
    {
        $tasks = $this->pendingTasks;
        $this->pendingTasks = [];
        if (!$tasks) {
            return;
        }
        // Only the highest id needs polling: Meilisearch processes tasks in
        // order, so when it has settled, every earlier one has too. But each
        // still needs its status checked, because "settled" includes "failed".
        sort($tasks);
        $this->client->waitForTask((int) end($tasks), $timeoutMs);

        $failed = [];
        foreach (array_chunk($tasks, 200) as $chunkIds) {
            $res = $this->client->tasks(['uids' => implode(',', $chunkIds), 'statuses' => 'failed', 'limit' => 200]);
            foreach ($res['results'] ?? [] as $t) {
                $failed[] = ['uid' => $t['uid'] ?? null, 'error' => $t['error'] ?? null];
            }
        }
        if ($failed) {
            throw new MeiliException('indexing tasks failed: ' . json_encode(array_slice($failed, 0, 5)));
        }
    }

    // ------------------------------------------------------- incremental path

    /**
     * Apply one batch of outbox rows. Groups by (kind, action) so a burst of
     * activity becomes a handful of requests rather than one per event.
     *
     * @param object[] $rows  rows from search_index_queue
     */
    public function applyQueue(array $rows): array
    {
        $byKind = ['upsert' => [], 'delete' => []];
        foreach ($rows as $r) {
            $byKind[$r->action][$r->kind][] = (int) $r->object_id;
        }

        $stats = [];
        foreach ($byKind['delete'] ?? [] as $kind => $ids) {
            $this->delete($kind, array_unique($ids));
            $stats["delete:$kind"] = count(array_unique($ids));
        }

        foreach ($byKind['upsert'] ?? [] as $kind => $ids) {
            $ids = array_values(array_unique($ids));
            $docs = match ($kind) {
                'discussions' => $this->builder->discussions($ids),
                'posts' => $this->builder->posts($ids),
                'users' => $this->builder->users($ids),
                'tags' => $this->builder->tags($ids),
                default => [],
            };
            if ($docs) {
                $this->push($kind, array_values($docs));
            }
            // Anything requested but not built no longer exists (or is no
            // longer indexable, e.g. a post that is now only a quote). Deleting
            // it is what keeps the index from accumulating tombstones that
            // still match queries.
            $gone = array_values(array_diff($ids, array_map('intval', array_keys($docs))));
            if ($gone) {
                $this->delete($kind, $gone);
            }
            $stats["upsert:$kind"] = count($docs);
            if ($gone) {
                $stats["prune:$kind"] = count($gone);
            }
        }

        $this->waitAll(120_000);

        return $stats;
    }

    /**
     * Refresh the recency component of rank_score for documents whose score has
     * drifted, using a partial update so nothing is re-tokenised.
     *
     * Without this, `rank_score` freezes at the value it had when the document
     * was last written, and a thread nobody has posted in keeps the recency
     * bonus it earned a year ago.
     */
    public function refreshScores(int $days = 400, int $limit = 100000): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $ids = $this->db->table('discussions')
            ->where('last_posted_at', '>=', $cutoff)
            ->orderByDesc('last_posted_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $n = 0;
        foreach (array_chunk($ids, 2000) as $chunkIds) {
            $docs = $this->builder->discussions(array_map('intval', $chunkIds));
            $partial = array_map(
                fn ($d) => ['id' => $d['id'], 'rank_score' => $d['rank_score'], 'reactions' => $d['reactions'], 'views' => $d['views'], 'comment_count' => $d['comment_count']],
                array_values($docs)
            );
            if ($partial) {
                // PUT = add-or-update: merges these fields into the stored
                // document instead of replacing it, so the text is untouched
                // and Meilisearch does not re-tokenise anything.
                $res = $this->client->updateDocuments($this->index('discussions'), $partial);
                if (isset($res['taskUid'])) {
                    $this->pendingTasks[] = (int) $res['taskUid'];
                }
                $n += count($partial);
            }
        }
        $this->waitAll();

        return $n;
    }

    public function stats(): array
    {
        $out = ['host' => $this->client->host(), 'indexes' => []];
        try {
            $stats = $this->client->stats();
            $out['databaseSize'] = $stats['databaseSize'] ?? null;
            $out['usedDatabaseSize'] = $stats['usedDatabaseSize'] ?? null;
            foreach (IndexSettings::KINDS as $kind) {
                $uid = $this->index($kind);
                $out['indexes'][$uid] = $stats['indexes'][$uid] ?? null;
            }
        } catch (MeiliException $e) {
            $out['error'] = $e->getMessage();
            $out['context'] = $e->context();
        }

        return $out;
    }
}
