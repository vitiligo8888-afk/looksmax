<?php

namespace Local\Search\Meili;

/**
 * Carries the whole exchange, not just a message. Every catch site in this
 * extension logs `context()`, so a search failure in production names the
 * request that caused it instead of leaving a status code to guess from.
 */
class MeiliException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $status = 0,
        private string $method = '',
        private string $path = '',
        private ?array $payload = null
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Meilisearch's structured error body, when there was one.
     *
     * Callers branch on `body()['code']` — a stable machine identifier — never
     * on the human message, which changes between patch releases.
     */
    public function body(): array
    {
        return $this->payload ?? [];
    }

    public function context(): array
    {
        return [
            'status' => $this->status,
            'method' => $this->method,
            'path' => $this->path,
            'meili' => $this->payload,
            'lastExchange' => Client::$lastExchange,
        ];
    }
}
