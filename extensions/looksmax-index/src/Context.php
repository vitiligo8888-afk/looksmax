<?php

namespace Local\Index;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Everything a block is allowed to know about the request.
 *
 * Passed in rather than resolved per block, so twelve blocks are not twelve
 * container lookups and one shared query (board stats, the tag tree) is fetched
 * once. `$actor` is nullable in practice — a guest is a Guest instance, and
 * `isGuest()` is the check, never `=== null`.
 */
class Context
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        public readonly ConnectionInterface $db,
        public readonly SettingsRepositoryInterface $settings,
        public readonly TranslatorInterface $translator,
        public readonly User $actor,
        /** 'cards' | 'list' — the reader's persisted choice */
        public readonly string $view,
    ) {
    }

    public function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }

    /** Memoised per request, so blocks can share a query without coordinating. */
    public function once(string $key, callable $fn): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $fn();
        }

        return $this->memo[$key];
    }

    public function isGuest(): bool
    {
        return $this->actor->isGuest();
    }
}
