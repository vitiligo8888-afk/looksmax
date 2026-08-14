<?php

namespace Local\Analytics\PostHog;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;

/**
 * Minimal PostHog batch client.
 *
 * Deliberately NOT the official SDK: we already spool to our own table, so all
 * this needs to do is POST /batch reliably and tell the caller what actually
 * landed. Two failure modes are handled explicitly because they bit us on the
 * exoma integration (see the analytics-delivery notes):
 *
 *  - partial batches: a 200 does not mean every event was accepted, so the
 *    caller only advances the watermark for ids we actually sent
 *  - terminal events: anything captured during shutdown must not rely on a
 *    normal async request; the spool means we never depend on that here
 */
class Client
{
    protected string $host;
    protected string $key;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->host = rtrim((string) $settings->get('analytics.posthog.host', ''), '/');
        $this->key = (string) $settings->get('analytics.posthog.key', '');
    }

    public function configured(): bool
    {
        return $this->host !== '' && $this->key !== '';
    }

    /**
     * @param array $events rows from analytics_events
     * @return array{sent:int, ids:array, error:?string}
     */
    public function sendBatch(array $events): array
    {
        if (!$this->configured() || empty($events)) {
            return ['sent' => 0, 'ids' => [], 'error' => $this->configured() ? null : 'posthog not configured'];
        }

        $batch = [];
        $ids = [];
        foreach ($events as $e) {
            $props = json_decode($e->props ?? '{}', true) ?: [];
            $batch[] = [
                'event' => $e->type,
                // distinct_id: real user when known, else the stable anonymous
                // session id — never a random per-request value, or every event
                // becomes its own "person" in PostHog.
                'distinct_id' => $e->user_id ? "user:{$e->user_id}" : ('anon:' . ($e->session ?: 'unknown')),
                'timestamp' => $e->created_at,
                'properties' => array_merge($props, array_filter([
                    'discussion_id' => $e->discussion_id,
                    'post_id' => $e->post_id,
                    '$current_url' => $e->path,
                    'forum' => 'flarum',
                    '$lib' => 'flarum-analytics',
                ], fn ($v) => $v !== null)),
            ];
            $ids[] = $e->id;
        }

        $payload = json_encode(['api_key' => $this->key, 'batch' => $batch]);

        $ch = curl_init("{$this->host}/batch/");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || $status >= 300) {
            return ['sent' => 0, 'ids' => [], 'error' => $err ?: "http $status: " . substr((string) $body, 0, 200)];
        }

        return ['sent' => count($ids), 'ids' => $ids, 'error' => null];
    }
}
