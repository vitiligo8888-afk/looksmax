<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Meili\Client;
use Local\Search\Meili\Indexer;
use Local\Search\Meili\IndexSettings;
use Local\Search\Meili\MeiliException;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What the search subsystem actually believes about itself, right now.
 *
 * This exists because every failure mode this extension has is invisible from
 * the outside. A forum whose engine is down, whose index is three days stale,
 * or whose settings have drifted all render an identical results page — the
 * only difference is which results are missing, and missing results are exactly
 * what nobody notices. So the numbers that would reveal each of those are put
 * in one place and surfaced in the admin panel.
 *
 * Three drift signals, each catching something the others cannot:
 *
 *   coverage  — documents in the engine vs indexable rows in the database.
 *               Catches a reindex that died halfway, and an import that ran
 *               while the sync worker was stopped.
 *   backlog   — unapplied rows in the outbox, and the age of the oldest.
 *               Catches a stopped worker. Count alone does not: a queue of 40
 *               is healthy at noon and alarming if the oldest row is from
 *               Tuesday, which is why the age is reported next to it.
 *   settings  — live index settings vs the declared ones in IndexSettings.
 *               Catches the silent one. A filterable attribute that was never
 *               applied does not error; it makes every query using that filter
 *               return nothing, forever.
 *
 * Admin-gated: it reports host names, key presence and row counts.
 */
class StatusController implements RequestHandlerInterface
{
    public function __construct(
        private Client $client,
        private Indexer $indexer,
        private ConnectionInterface $db
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $out = [
            'host' => $this->client->host(),
            'hasKey' => $this->client->hasKey(),
            'prefix' => $this->indexer->prefix(),
            'engine' => null,
            'indexes' => [],
            'queue' => $this->queue(),
            'drift' => [],
            'healthy' => false,
        ];

        try {
            $out['engine'] = [
                'health' => $this->client->health()['status'] ?? '?',
                'version' => $this->client->version()['pkgVersion'] ?? '?',
                'lastExchangeMs' => Client::$lastExchange['ms'] ?? null,
            ];
            $stats = $this->client->stats();
            $out['databaseSize'] = $stats['databaseSize'] ?? null;
            $out['usedDatabaseSize'] = $stats['usedDatabaseSize'] ?? null;
            $out['lastUpdate'] = $stats['lastUpdate'] ?? null;

            $rowCounts = $this->rowCounts();
            foreach (IndexSettings::KINDS as $kind) {
                $uid = $this->indexer->index($kind);
                $s = $stats['indexes'][$uid] ?? null;
                $docs = (int) ($s['numberOfDocuments'] ?? 0);
                $rows = $rowCounts[$kind] ?? 0;
                $out['indexes'][] = [
                    'kind' => $kind,
                    'uid' => $uid,
                    'exists' => $s !== null,
                    'documents' => $docs,
                    'rows' => $rows,
                    // Deliberately not clamped to 100%: a coverage of 140% means
                    // the index holds documents whose rows are gone, which is a
                    // real and separate bug from an incomplete index, and
                    // clamping would hide it.
                    'coverage' => $rows > 0 ? round($docs / $rows * 100, 1) : null,
                    'isIndexing' => (bool) ($s['isIndexing'] ?? false),
                    'sizeBytes' => $s['rawDocumentDbSize'] ?? ($s['indexSize'] ?? null),
                ];
            }

            // Tasks the engine has failed. A reindex reports success per batch;
            // a task that failed after acceptance is only visible here.
            $failed = $this->client->tasks(['statuses' => 'failed', 'limit' => 5]);
            $out['failedTasks'] = array_map(fn ($t) => [
                'uid' => $t['uid'] ?? null,
                'type' => $t['type'] ?? null,
                'index' => $t['indexUid'] ?? null,
                'error' => $t['error']['message'] ?? null,
            ], $failed['results'] ?? []);
            $out['failedTaskTotal'] = $failed['total'] ?? 0;

            $out['drift'] = $this->indexer->settingsDiff();
            $out['healthy'] = ($out['engine']['health'] ?? '') === 'available'
                && $out['drift'] === []
                && ($out['failedTaskTotal'] ?? 0) === 0;
        } catch (MeiliException $e) {
            $out['error'] = $e->getMessage();
            $out['context'] = $e->context();
        }

        return new JsonResponse($out);
    }

    /**
     * Indexable rows, counted the same way the Indexer selects them — a
     * coverage figure computed against a different predicate than the indexer
     * uses is worse than no coverage figure, because it is confidently wrong.
     */
    private function rowCounts(): array
    {
        return [
            'discussions' => (int) $this->db->table('discussions')->count(),
            'posts' => (int) $this->db->table('posts')->where('type', 'comment')->count(),
            'users' => (int) $this->db->table('users')->count(),
            'tags' => (int) $this->db->table('tags')->count(),
        ];
    }

    private function queue(): array
    {
        try {
            $pending = (int) $this->db->table('search_index_queue')->count();
            $oldest = $this->db->table('search_index_queue')->min('created_at');

            return [
                'pending' => $pending,
                'oldest' => $oldest,
                // The number that turns a count into a verdict.
                'oldestAgeSeconds' => $oldest ? max(0, time() - strtotime((string) $oldest)) : null,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
