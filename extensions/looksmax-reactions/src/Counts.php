<?php

namespace Local\Reactions;

use Illuminate\Database\ConnectionInterface;

/**
 * Request-scoped reaction counts, warmed in bulk.
 *
 * This class exists because of a specific, measured defect in the incumbent
 * prior art. fof/reactions 1.x resolves reaction counts inside
 * PostSerializer::getDefaultAttributes, per post, with a fresh groupBy query
 * each time plus a Reaction::all(). A 20-post discussion page therefore costs
 * ~60 queries before anything else has run. Upstream only fixed it on the 2.x
 * branch (PR #115, merged 2026-06-13); the 1.x branch a Flarum 1.8 install
 * would actually use still has it.
 *
 * The fix is not to be cleverer inside the serializer — the serializer is
 * called one post at a time and cannot know the batch. It is to warm the whole
 * batch from the one place that DOES know it: the API controllers, via
 * prepareDataForSerialization, which hands over every post in the response
 * before serialization starts. Three queries for a page, whatever its size.
 *
 * Registered as a singleton, so "request-scoped" is literal: Flarum builds a
 * fresh container per request and the cache dies with it. There is a lazy
 * single-post fallback for any path that was never warmed (a POST response, an
 * extension listing posts by some route nobody thought of), so a missed warm
 * is a performance regression rather than missing data.
 */
class Counts
{
    /** @var array<int, array<int,int>> post id => [reaction id => count] */
    private array $counts = [];

    /** @var array<int, int[]> post id => reaction ids the actor used */
    private array $mine = [];

    /** @var array<int, array{score:int,summary:?string,types:array<int,?int>}> */
    private array $legacy = [];

    /** @var array<int,bool> posts already warmed */
    private array $warm = [];

    private ?int $actorId = null;

    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * @param int[] $postIds
     */
    public function warm(array $postIds, ?int $actorId): void
    {
        $this->actorId = $actorId;
        $ids = array_values(array_unique(array_filter(array_map('intval', $postIds))));
        $ids = array_values(array_diff($ids, array_keys($this->warm)));
        if (!$ids) {
            return;
        }

        foreach ($ids as $id) {
            $this->warm[$id] = true;
            $this->counts[$id] ??= [];
            $this->mine[$id] ??= [];
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $this->db->table('post_reactions')
                ->select('post_id', 'reaction_id', $this->db->raw('COUNT(*) as c'))
                ->whereIn('post_id', $chunk)
                ->groupBy('post_id', 'reaction_id')
                ->get();
            foreach ($rows as $r) {
                $this->counts[(int) $r->post_id][(int) $r->reaction_id] = (int) $r->c;
            }

            if ($actorId) {
                $rows = $this->db->table('post_reactions')
                    ->select('post_id', 'reaction_id')
                    ->whereIn('post_id', $chunk)
                    ->where('user_id', $actorId)
                    ->get();
                foreach ($rows as $r) {
                    $this->mine[(int) $r->post_id][] = (int) $r->reaction_id;
                }
            }

            $rows = $this->db->table('legacy_post_totals')->whereIn('post_id', $chunk)->get();
            foreach ($rows as $r) {
                $this->legacy[(int) $r->post_id] = [
                    'score' => (int) $r->score,
                    'summary' => $r->summary,
                    'types' => [],
                ];
            }
            $rows = $this->db->table('legacy_post_reactions')->whereIn('post_id', $chunk)->get();
            foreach ($rows as $r) {
                $pid = (int) $r->post_id;
                $this->legacy[$pid] ??= ['score' => 0, 'summary' => null, 'types' => []];
                $this->legacy[$pid]['types'][(int) $r->reaction_id] =
                    $r->count === null ? null : (int) $r->count;
            }
        }
    }

    public function forPost(int $postId, ?int $actorId): array
    {
        if (!isset($this->warm[$postId])) {
            $this->warm([$postId], $actorId);
        }

        $legacy = $this->legacy[$postId] ?? null;

        return [
            'counts' => (object) ($this->counts[$postId] ?? []),
            'mine' => array_values(array_unique($this->mine[$postId] ?? [])),
            'legacy' => $legacy ? (object) $legacy['types'] : (object) [],
            'legacyScore' => $legacy['score'] ?? 0,
            'legacySummary' => $legacy['summary'] ?? null,
        ];
    }

    /** Drop one post from the cache after a write, so the response is fresh. */
    public function forget(int $postId): void
    {
        unset($this->warm[$postId], $this->counts[$postId], $this->mine[$postId]);
    }
}
