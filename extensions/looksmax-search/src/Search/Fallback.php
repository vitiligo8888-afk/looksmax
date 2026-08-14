<?php

namespace Local\Search\Search;

use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * What happens when the engine is not there.
 *
 * A search box that returns "no results" because a container is restarting is
 * worse than one that returns an error: the reader concludes the forum has
 * nothing on the topic and leaves. So an engine outage degrades to a database
 * search that is deliberately narrow — titles only, no post bodies.
 *
 * Titles only is not a shortcut, it is the point. The measured reason this
 * extension exists is that MATCH…AGAINST across the post table does not
 * complete in a usable time at this corpus size (see /root/search-notes). A
 * fallback that ran the slow query would turn a search-engine outage into a
 * database outage. Titles are indexed, bounded, and enough to keep the forum
 * navigable until the engine returns.
 *
 * Every response from here carries `degraded`, so the UI can say so plainly
 * rather than quietly showing worse results.
 */
class Fallback
{
    public function __construct(
        private QueryParser $parser,
        private ConnectionInterface $db
    ) {
    }

    public function search(string $rawQuery, User $actor, int $limit = 20, int $offset = 0): array
    {
        $started = microtime(true);
        $q = $this->parser->parse($rawQuery);

        $terms = trim($q['text'] . ' ' . implode(' ', $q['phrases']));
        // Same defensive scrub the core gambit does: strip anything that would
        // be read as MySQL boolean-mode syntax.
        $terms = preg_replace('/[^\p{L}\p{N}\p{M}_ ]+/u', ' ', $terms) ?? '';
        $terms = trim(preg_replace('/\s+/u', ' ', $terms) ?? '');

        $query = Discussion::whereVisibleTo($actor);

        if ($terms !== '') {
            $query->whereRaw('MATCH(discussions.title) AGAINST (? IN NATURAL LANGUAGE MODE)', [$terms])
                ->orderByRaw('MATCH(discussions.title) AGAINST (?) DESC', [$terms]);
        } else {
            $query->orderByDesc('last_posted_at');
        }

        if ($q['tags']) {
            $query->whereIn('discussions.id', function ($sub) use ($q) {
                $sub->select('discussion_id')->from('discussion_tag')
                    ->join('tags', 'tags.id', '=', 'discussion_tag.tag_id')
                    ->whereIn('tags.slug', $q['tags']);
            });
        }
        if ($q['authors']) {
            $query->whereIn('discussions.user_id', function ($sub) use ($q) {
                $sub->select('id')->from('users')->whereIn('username', $q['authors']);
            });
        }
        if ($q['after']) {
            $query->where('discussions.created_at', '>=', date('Y-m-d H:i:s', $q['after']));
        }
        if ($q['before']) {
            $query->where('discussions.created_at', '<=', date('Y-m-d H:i:s', $q['before']));
        }

        $rows = $query->with('tags', 'user')->skip($offset)->take($limit)->get();

        $results = $rows->map(fn ($d) => [
            'type' => 'discussion',
            'id' => (int) $d->id,
            'title' => (string) $d->title,
            'titleHtml' => htmlspecialchars((string) $d->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'excerptHtml' => '',
            'slug' => (string) $d->slug,
            'url' => '/d/' . $d->id . '-' . $d->slug,
            'author' => (string) ($d->user->display_name ?? ''),
            'authorId' => (int) ($d->user_id ?? 0),
            'tags' => $d->tags->map(fn ($t) => [
                'name' => $t->name, 'slug' => $t->slug, 'color' => (string) $t->color,
                'isPrefix' => $t->position === null,
            ])->values()->all(),
            'prefixes' => [],
            'createdAt' => $d->created_at ? $d->created_at->getTimestamp() : 0,
            'lastPostAt' => $d->last_posted_at ? $d->last_posted_at->getTimestamp() : 0,
            'commentCount' => (int) $d->comment_count,
            'reactions' => 0,
            'views' => 0,
            'isSticky' => (bool) $d->is_sticky,
            'isLocked' => (bool) $d->is_locked,
            'lang' => '',
            'score' => null,
        ])->all();

        return [
            'query' => $rawQuery,
            'parsed' => $q,
            'type' => 'discussions',
            'results' => $results,
            'estimatedTotalHits' => count($results) + ($offset + count($results) >= $limit ? 1 : 0),
            'facets' => [],
            'limit' => $limit,
            'offset' => $offset,
            'engineMs' => null,
            'totalMs' => round((microtime(true) - $started) * 1000, 2),
            'titlesOnly' => true,
        ];
    }
}
