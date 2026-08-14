<?php

namespace Local\Store\Listeners;

use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Illuminate\Database\ConnectionInterface;

/**
 * Put bought thread effects on the discussion payload.
 *
 * The listing renders 20 to 50 discussions per page, so this must not be a
 * query per row. `preload()` fills a request-scoped map from the controller
 * hook before serialization; the serializer then reads memory. A single
 * discussion page falls back to one point query, which is one query, not N.
 *
 * The N+1 shape this avoids is not hypothetical on this stack: the extension
 * audit found it shipped in fof/reactions (3 queries per post) and in
 * v17development/flarum-seo (a likes count per post).
 */
class DiscussionEffects
{
    /** @var array<int,array>|null */
    private static ?array $map = null;

    public function __construct(protected ConnectionInterface $db)
    {
    }

    /**
     * Controller hook: bulk-load for the whole page.
     *
     * Static, because Flarum's prepareDataForSerialization type-hints a
     * `callable` and does not run it through the container — a
     * [Class::class, 'method'] pair only satisfies that when the method is
     * static. The connection comes off Eloquent's resolver rather than an
     * injected one for the same reason.
     */
    public static function preload($controller, $data, $request): void
    {
        $ids = [];
        foreach (is_iterable($data) ? $data : [$data] as $row) {
            if ($row instanceof Discussion) {
                $ids[] = (int) $row->id;
            }
        }

        if (!$ids) {
            return;
        }

        $db = Discussion::query()->getConnection();
        self::$map = (self::fetchWith($db, $ids) + (self::$map ?? []));
    }

    public function __invoke(DiscussionSerializer $serializer, Discussion $discussion, array $attributes): array
    {
        $id = (int) $discussion->id;

        if (self::$map === null || !array_key_exists($id, self::$map)) {
            self::$map = (self::fetchWith($this->db, [$id]) + (self::$map ?? []));
        }

        $effects = self::$map[$id] ?? [];

        $attributes['storeEffects'] = $effects;
        // Flat and boolean because the listing only needs to know whether to
        // paint the edge, and a nested lookup in a Mithril view runs per redraw.
        $attributes['storeHighlight'] = null;
        foreach ($effects as $e) {
            if ($e['kind'] === 'highlight') {
                $attributes['storeHighlight'] = $e['variant'] ?: 'gold';
            }
        }

        return $attributes;
    }

    private static function fetchWith(ConnectionInterface $db, array $ids): array
    {
        $rows = $db->table('store_discussion_effects')
            ->whereIn('discussion_id', $ids)
            ->whereNull('ended_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->get(['discussion_id', 'kind', 'variant', 'expires_at']);

        $out = array_fill_keys($ids, []);
        foreach ($rows as $r) {
            $out[(int) $r->discussion_id][] = [
                'kind' => $r->kind,
                'variant' => $r->variant,
                'expiresAt' => $r->expires_at,
            ];
        }

        return $out;
    }
}
