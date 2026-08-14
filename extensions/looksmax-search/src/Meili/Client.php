<?php

namespace Local\Search\Meili;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * A small, explicit Meilisearch client.
 *
 * Deliberately not `meilisearch/meilisearch-php`. That package pulls a
 * `php-http` discovery chain and its own HTTP client into a tree that already
 * ships Guzzle, and it wraps every call in objects that hide the one thing that
 * matters when a query is slow or wrong: the exact request and the exact
 * response. Everything here is one method deep and every failure carries the
 * status, the body and the payload that produced it, because a bare exception
 * message is not evidence of a cause.
 *
 * Failure posture is asymmetric on purpose:
 *   - reads (`search`) throw, so the caller can fall back to the database and
 *     the user still gets results;
 *   - writes (`addDocuments`) throw too, so the sync queue can retry rather
 *     than silently drop a document and leave the index permanently stale.
 * Nothing here swallows an error.
 */
class Client
{
    private Guzzle $http;
    private string $host;
    private string $key;

    /** Last request/response pair, for the status command and error reporting. */
    public static ?array $lastExchange = null;

    public function __construct(
        SettingsRepositoryInterface $settings,
        private LoggerInterface $log
    ) {
        $this->host = rtrim(
            $settings->get('looksmax-search.host') ?: (getenv('MEILI_HOST') ?: 'http://flarum-meili:7700'),
            '/'
        );
        $this->key = (string) ($settings->get('looksmax-search.key') ?: (getenv('MEILI_MASTER_KEY') ?: ''));

        $this->http = new Guzzle([
            'base_uri' => $this->host . '/',
            'timeout' => (float) ($settings->get('looksmax-search.timeout') ?: 5),
            'connect_timeout' => 2.0,
            'http_errors' => false,
            'headers' => array_filter([
                'Authorization' => $this->key ? 'Bearer ' . $this->key : null,
                'Content-Type' => 'application/json',
            ]),
        ]);
    }

    public function host(): string
    {
        return $this->host;
    }

    public function hasKey(): bool
    {
        return $this->key !== '';
    }

    /**
     * @throws MeiliException
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $started = microtime(true);
        $options = [];
        if ($body !== null) {
            // JSON_INVALID_UTF8_SUBSTITUTE: imported posts carry bytes that are
            // not valid UTF-8. Without this, one bad post makes json_encode
            // return false and the whole 1000-document batch vanishes with no
            // error at all — the exact silent-drop this class exists to avoid.
            $options['body'] = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($options['body'] === false) {
                throw new MeiliException('payload could not be encoded: ' . json_last_error_msg(), 0, $method, $path);
            }
        }
        if ($query) {
            $options['query'] = $query;
        }

        try {
            $res = $this->http->request($method, ltrim($path, '/'), $options);
        } catch (RequestException $e) {
            throw new MeiliException(
                'transport: ' . $e->getMessage() . ' (host ' . $this->host . ')',
                0, $method, $path
            );
        }

        $ms = (microtime(true) - $started) * 1000;
        $raw = (string) $res->getBody();
        $status = $res->getStatusCode();

        self::$lastExchange = [
            'method' => $method, 'path' => $path, 'status' => $status,
            'ms' => round($ms, 2), 'response' => mb_substr($raw, 0, 2000),
        ];

        if ($status >= 400) {
            // Meilisearch returns a structured error; surface all of it. A bare
            // "400" tells you nothing, `invalid_search_filter` names the bug.
            $decoded = json_decode($raw, true);
            throw new MeiliException(
                sprintf(
                    'HTTP %d %s %s — %s (code=%s type=%s)',
                    $status, $method, $path,
                    $decoded['message'] ?? mb_substr($raw, 0, 400),
                    $decoded['code'] ?? '?', $decoded['type'] ?? '?'
                ),
                $status, $method, $path,
                is_array($decoded) ? $decoded : null
            );
        }

        $out = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($out)) {
            throw new MeiliException("non-JSON response: " . mb_substr($raw, 0, 300), $status, $method, $path);
        }
        $out['__ms'] = round($ms, 2);

        return $out;
    }

    public function health(): array
    {
        return $this->request('GET', 'health');
    }

    public function version(): array
    {
        return $this->request('GET', 'version');
    }

    public function stats(): array
    {
        return $this->request('GET', 'stats');
    }

    public function createIndex(string $uid, string $primaryKey = 'id'): array
    {
        return $this->request('POST', 'indexes', ['uid' => $uid, 'primaryKey' => $primaryKey]);
    }

    public function indexExists(string $uid): bool
    {
        try {
            $this->request('GET', "indexes/$uid");

            return true;
        } catch (MeiliException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw $e;
        }
    }

    public function deleteIndex(string $uid): array
    {
        return $this->request('DELETE', "indexes/$uid");
    }

    public function updateSettings(string $uid, array $settings): array
    {
        return $this->request('PATCH', "indexes/$uid/settings", $settings);
    }

    public function getSettings(string $uid): array
    {
        return $this->request('GET', "indexes/$uid/settings");
    }

    /** Add or REPLACE. Use when the builder produces the whole document. */
    public function addDocuments(string $uid, array $docs): array
    {
        return $this->request('POST', "indexes/$uid/documents", $docs);
    }

    /** Add or UPDATE: merges into the existing document, for partial pushes. */
    public function updateDocuments(string $uid, array $docs): array
    {
        return $this->request('PUT', "indexes/$uid/documents", $docs);
    }

    public function deleteDocuments(string $uid, array $ids): array
    {
        return $this->request('POST', "indexes/$uid/documents/delete-batch", array_values($ids));
    }

    public function search(string $uid, array $params): array
    {
        return $this->request('POST', "indexes/$uid/search", $params);
    }

    public function multiSearch(array $queries, ?array $federation = null): array
    {
        $body = ['queries' => $queries];
        if ($federation !== null) {
            $body['federation'] = $federation;
        }

        return $this->request('POST', 'multi-search', $body);
    }

    public function facetSearch(string $uid, array $params): array
    {
        return $this->request('POST', "indexes/$uid/facet-search", $params);
    }

    /**
     * Nearest neighbours of a document that is already in the index.
     *
     * Stable in 1.53 — NOT behind an experimental flag, despite what several
     * blog posts describing the 1.6-era `vectorStore` feature still say.
     * Verified against this engine's own `/experimental-features`, which lists
     * `compositeEmbedders`, `chatCompletions` and `multimodal` but neither
     * vector search nor `/similar`.
     */
    public function similar(string $uid, array $params): array
    {
        return $this->request('POST', "indexes/$uid/similar", $params);
    }

    /**
     * Fetch documents by filter, optionally with their stored vectors.
     *
     * `POST /documents/fetch` rather than `GET /documents`, because only the
     * POST form accepts a `filter`, and `retrieveVectors` is the only way to
     * read an embedding back out of the engine — which is what a
     * recommendation needs in order to average several of them.
     */
    public function fetchDocuments(string $uid, array $params): array
    {
        return $this->request('POST', "indexes/$uid/documents/fetch", $params);
    }

    public function getEmbedders(string $uid): array
    {
        return $this->request('GET', "indexes/$uid/settings/embedders");
    }

    public function updateEmbedders(string $uid, array $embedders): array
    {
        return $this->request('PATCH', "indexes/$uid/settings/embedders", $embedders);
    }

    public function task(int $uid): array
    {
        return $this->request('GET', "tasks/$uid");
    }

    public function tasks(array $query = []): array
    {
        return $this->request('GET', 'tasks', null, $query);
    }

    /**
     * Block until a task settles. Indexing is asynchronous, so a 202 from
     * addDocuments is not evidence that anything was indexed — this is what
     * turns "accepted" into "succeeded" or a real error.
     */
    public function waitForTask(int $uid, int $timeoutMs = 300000, int $intervalMs = 200): array
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (microtime(true) < $deadline) {
            $task = $this->task($uid);
            if (in_array($task['status'] ?? '', ['succeeded', 'failed', 'canceled'], true)) {
                if ($task['status'] !== 'succeeded') {
                    throw new MeiliException(
                        'task ' . $uid . ' ' . $task['status'] . ': '
                        . json_encode($task['error'] ?? null),
                        0, 'GET', "tasks/$uid", $task
                    );
                }

                return $task;
            }
            usleep($intervalMs * 1000);
        }

        throw new MeiliException("task $uid did not settle in {$timeoutMs}ms", 0, 'GET', "tasks/$uid");
    }

    /** Mint a search-only key scoped to our indexes, for the browser. */
    public function createSearchKey(array $indexes, string $description): array
    {
        return $this->request('POST', 'keys', [
            'description' => $description,
            'actions' => ['search'],
            'indexes' => $indexes,
            'expiresAt' => null,
        ]);
    }

    public function keys(): array
    {
        return $this->request('GET', 'keys');
    }
}
