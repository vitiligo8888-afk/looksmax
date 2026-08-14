<?php

namespace Local\Search\Meili;

use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * The vector side of the index: which model, reached how, at what size.
 *
 * ## Why a REST embedder and not `huggingFace`
 *
 * Meilisearch can run a BERT model in its own process (`source: huggingFace`).
 * That was rejected here for one measured reason: this box is already at load
 * 31–46 of 32 cores because an importer is writing ~540 posts/s, and the
 * in-process embedder burns those cores INSIDE the process that also has to
 * index them. A separate service can be given a hard CPU budget
 * (`--cpus 6 --cpuset-cpus 24-31`) that the kernel enforces, and Meilisearch's
 * indexing thread then blocks on a socket instead of competing for a core.
 * The budget is the point; the process boundary is how you get one.
 *
 * ## Why the wire shape is a setting
 *
 * Two shapes are supported and both go through `source: rest`:
 *
 *   `tei`    — `{"inputs": [...]}` → `[[...]]`, which is what
 *              text-embeddings-inference speaks natively.
 *   `openai` — `{"model": ..., "input": [...]}` → `{"data":[{"embedding":...}]}`,
 *              which is what OpenAI, OpenRouter and most hosted embedding
 *              APIs speak.
 *
 * So moving from the local CPU model to a hosted one is
 * `search:embed --wire=openai --url=… --model=… --dimensions=…` plus a
 * re-embed. No code changes, no re-architecting. That is deliberate: the local
 * model is the choice that needs no API key and no per-token cost, not a
 * commitment.
 *
 * ## Why `embed_text` and not a Liquid template over the real fields
 *
 * Meilisearch re-embeds a document only when the RENDERED document template
 * changes. Rendering from `title`/`excerpt`/`tag_names` directly would work,
 * but the exact truncation then lives inside a Liquid string. Embedding cost is
 * super-linear in text length on CPU — measured on this box with e5-small:
 *
 *     ~177 chars/doc →  88–121 docs/s
 *     ~296 chars/doc →      83 docs/s
 *     ~467 chars/doc →   25–28 docs/s
 *
 * A 1.6x longer document costs 3x the throughput. That makes the truncation a
 * load-bearing decision, so it is made in PHP where it can be tested
 * (`DocumentBuilder::embedText`), stored as one field, and rendered by the
 * trivial template `{{doc.embed_text}}`. It also means routine document
 * churn — a new reply bumping `comment_count`, an hourly `rank_score`
 * refresh — leaves `embed_text` byte-identical and therefore does NOT trigger
 * a re-embed. On a corpus taking 540 posts/s that property is the difference
 * between an embedder that keeps up and one that never catches up.
 */
class Embedder
{
    /** The embedder's name inside Meilisearch. Referenced by every hybrid query. */
    public const NAME = 'sem';

    private Guzzle $http;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private LoggerInterface $log
    ) {
        $this->http = new Guzzle([
            'timeout' => (float) $this->setting('timeout', 30),
            'connect_timeout' => 3.0,
            'http_errors' => false,
        ]);
    }

    private function setting(string $key, $default = null)
    {
        $v = $this->settings->get('looksmax-search.embed.' . $key);

        return ($v === null || $v === '') ? $default : $v;
    }

    /** Is semantic search switched on? Off means every code path stays keyword-only. */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('looksmax-search.embed.enabled');
    }

    public function name(): string
    {
        return (string) $this->setting('name', self::NAME);
    }

    public function wire(): string
    {
        return (string) $this->setting('wire', 'tei');
    }

    public function url(): string
    {
        return (string) $this->setting('url', 'http://lmx-embed:80/embed');
    }

    public function model(): string
    {
        return (string) $this->setting('model', '');
    }

    public function dimensions(): int
    {
        return (int) $this->setting('dimensions', 384);
    }

    /**
     * The default blend for a query with no exact-match signal. Not 1.0 and not
     * 0: see Semantic::ratioFor for what actually picks the per-query value.
     */
    public function defaultRatio(): float
    {
        return max(0.0, min(1.0, (float) $this->setting('ratio', 0.35)));
    }

    /** Characters of `embed_text` to keep. The throughput lever; see class docblock. */
    public function textCap(): int
    {
        return max(80, (int) $this->setting('cap', 420));
    }

    private function apiKey(): string
    {
        return (string) $this->setting('key', '');
    }

    /**
     * The embedder definition Meilisearch stores on the index.
     *
     * `documentTemplateMaxBytes` is set slightly above the character cap
     * because the cap is in CHARACTERS and this limit is in BYTES — Spanish
     * accents and Russian are 2 bytes each, so a 420-character Cyrillic title
     * is 840 bytes. Setting them equal would silently truncate exactly the
     * non-English half of the corpus this model was chosen for.
     */
    public function definition(): array
    {
        $def = [
            'source' => 'rest',
            'url' => $this->url(),
            'documentTemplate' => '{{doc.embed_text}}',
            'documentTemplateMaxBytes' => $this->textCap() * 3,
            'dimensions' => $this->dimensions(),
        ];

        if ($this->apiKey() !== '') {
            $def['apiKey'] = $this->apiKey();
        }

        if ($this->wire() === 'openai') {
            // The shape OpenAI, OpenRouter and most hosted APIs serve.
            $def['request'] = [
                'model' => $this->model(),
                'input' => ['{{text}}', '{{..}}'],
            ];
            $def['response'] = [
                'data' => [
                    ['embedding' => '{{embedding}}'],
                    '{{..}}',
                ],
            ];
        } else {
            // text-embeddings-inference native.
            $def['request'] = [
                'inputs' => ['{{text}}', '{{..}}'],
                'truncate' => true,
            ];
            $def['response'] = ['{{embedding}}', '{{..}}'];
        }

        return $def;
    }

    /**
     * Embed text ourselves.
     *
     * Hybrid search does not need this — Meilisearch embeds the query string
     * itself through the same embedder. This exists for the two things that
     * cannot be expressed as a query string:
     *
     *   - duplicate detection on a draft that is longer than a search query;
     *   - recommendations, which search from the MEAN of several documents'
     *     vectors rather than from any text at all.
     *
     * @param  string[] $texts
     * @return array<int, float[]>
     * @throws MeiliException  so callers degrade visibly rather than silently.
     */
    public function embed(array $texts): array
    {
        if (!$texts) {
            return [];
        }

        $openai = $this->wire() === 'openai';
        $body = $openai
            ? ['model' => $this->model(), 'input' => array_values($texts)]
            : ['inputs' => array_values($texts), 'truncate' => true];

        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey() !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey();
        }

        try {
            $res = $this->http->post($this->url(), [
                'headers' => $headers,
                'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        } catch (RequestException $e) {
            throw new MeiliException(
                'embed transport: ' . $e->getMessage() . ' (url ' . $this->url() . ')',
                0, 'POST', $this->url()
            );
        }

        $status = $res->getStatusCode();
        $raw = (string) $res->getBody();
        if ($status >= 400) {
            throw new MeiliException(
                sprintf('embed HTTP %d from %s — %s', $status, $this->url(), mb_substr($raw, 0, 400)),
                $status, 'POST', $this->url()
            );
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new MeiliException('embed: non-JSON response: ' . mb_substr($raw, 0, 200), $status, 'POST', $this->url());
        }

        $vectors = $openai
            ? array_map(fn ($row) => $row['embedding'] ?? null, $decoded['data'] ?? [])
            : $decoded;

        $out = [];
        foreach ($vectors as $v) {
            if (!is_array($v) || $v === []) {
                throw new MeiliException('embed: response did not contain a vector', $status, 'POST', $this->url());
            }
            $out[] = array_map('floatval', $v);
        }

        if (count($out) !== count($texts)) {
            throw new MeiliException(
                sprintf('embed: asked for %d vectors, got %d', count($texts), count($out)),
                $status, 'POST', $this->url()
            );
        }

        return $out;
    }

    /**
     * Component-wise mean of several vectors, re-normalised to unit length.
     *
     * This is the "centroid" a recommendation searches from. Re-normalising
     * matters: Meilisearch scores by cosine similarity, and the mean of k unit
     * vectors has length < 1 (exactly 1 only if they are identical). Feeding
     * the un-normalised mean makes every recommendation score look weak and
     * makes `rankingScoreThreshold` mean something different for a user with
     * 3 seeds than for one with 20.
     *
     * @param  array<int, float[]> $vectors
     * @return float[]
     */
    public static function centroid(array $vectors): array
    {
        $vectors = array_values(array_filter($vectors, fn ($v) => is_array($v) && $v !== []));
        if (!$vectors) {
            return [];
        }

        $dims = count($vectors[0]);
        $sum = array_fill(0, $dims, 0.0);
        $n = 0;
        foreach ($vectors as $v) {
            if (count($v) !== $dims) {
                continue;   // a swapped model mid-flight; skip rather than corrupt
            }
            for ($i = 0; $i < $dims; $i++) {
                $sum[$i] += (float) $v[$i];
            }
            $n++;
        }
        if ($n === 0) {
            return [];
        }

        $norm = 0.0;
        for ($i = 0; $i < $dims; $i++) {
            $sum[$i] /= $n;
            $norm += $sum[$i] * $sum[$i];
        }
        $norm = sqrt($norm);
        if ($norm <= 0.0) {
            return [];
        }
        for ($i = 0; $i < $dims; $i++) {
            $sum[$i] /= $norm;
        }

        return $sum;
    }

    /**
     * Is the embedding backend actually answering, and with the right shape?
     *
     * Reports the measured dimension count rather than the configured one, so a
     * model swap that quietly changes dimensions shows up as a mismatch instead
     * of as thousands of rejected documents later.
     */
    public function health(): array
    {
        $started = microtime(true);
        try {
            $v = $this->embed(['health probe']);
        } catch (MeiliException $e) {
            return [
                'ok' => false,
                'url' => $this->url(),
                'wire' => $this->wire(),
                'error' => $e->getMessage(),
            ];
        }

        $dims = count($v[0] ?? []);

        return [
            'ok' => $dims > 0 && $dims === $this->dimensions(),
            'url' => $this->url(),
            'wire' => $this->wire(),
            'model' => $this->model(),
            'measuredDimensions' => $dims,
            'configuredDimensions' => $this->dimensions(),
            'dimensionMismatch' => $dims !== $this->dimensions(),
            'ms' => round((microtime(true) - $started) * 1000, 2),
        ];
    }
}
