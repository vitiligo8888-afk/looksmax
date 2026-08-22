<?php

namespace Local\Reactions\Api;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reaction shape of one thread, for filtering and sorting it.
 *
 * GET /api/lmx/discussions/{id}/reactions[?slug=jfl]
 *
 * Returns the per-reaction totals for the whole thread and, for the requested
 * reaction (or for every one, ungrouped), the post NUMBERS carrying it. Numbers
 * rather than ids because that is what the reader's scroll position and the
 * /d/{id}/{number} URL are addressed by, so the client can jump straight there
 * without a second lookup.
 *
 * Legacy rows are included in the totals and flagged, so "show me the funniest
 * posts in this 900-post thread" works across the import boundary — which for
 * a board whose entire history was imported is the only way it works at all.
 */
class DiscussionReactionsController implements RequestHandlerInterface
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $q = $request->getQueryParams();
        $id = (int) Arr::get($q, 'id');
        $slug = Arr::get($q, 'slug');

        // Visibility via the SCOPE, not assertCan('view', $discussion).
        //
        // Flarum 1.8 registers no `view` ability for a Discussion — thread
        // visibility is a query scope, not a policy. So `can('view', $d)`
        // matched no policy, fell through to the isAdmin() default, and this
        // endpoint returned 403 to every non-administrator on the forum while
        // the very same thread rendered fine for them. Measured before the fix:
        //
        //   whereVisibleTo($user)->find(334)  -> found
        //   $user->can('view', $discussion)   -> false   (admin: true)
        //
        // looksmax-userinfo/src/Api/SummaryController.php:43-58 hit the
        // identical trap on User and documents it; this is the same bug on
        // Discussion.
        //
        // 404 rather than 403 when it is not visible: whether a thread exists
        // is itself information.
        $discussion = Discussion::query()->whereVisibleTo($actor)->find($id);
        if (!$discussion) {
            return new JsonResponse(['error' => 'discussion-not-found'], 404);
        }

        $totals = $this->db->table('post_reactions as pr')
            ->join('posts as p', 'p.id', '=', 'pr.post_id')
            ->join('reactions as r', 'r.id', '=', 'pr.reaction_id')
            ->where('p.discussion_id', $id)
            ->groupBy('r.slug')
            ->select('r.slug')->selectRaw('COUNT(*) as c')
            ->pluck('c', 'slug')->all();

        $legacyTotals = $this->db->table('legacy_post_reactions as lp')
            ->join('posts as p', 'p.id', '=', 'lp.post_id')
            ->join('reactions as r', 'r.id', '=', 'lp.reaction_id')
            ->where('p.discussion_id', $id)
            ->groupBy('r.slug')
            ->select('r.slug')->selectRaw('COUNT(*) as posts')->selectRaw('SUM(COALESCE(lp.count,0)) as known')
            ->get()->keyBy('slug');

        $posts = $this->db->table('posts as p')
            ->leftJoin('post_reactions as pr', 'pr.post_id', '=', 'p.id')
            ->leftJoin('reactions as r', 'r.id', '=', 'pr.reaction_id')
            ->leftJoin('legacy_post_totals as l', 'l.post_id', '=', 'p.id')
            ->where('p.discussion_id', $id)
            ->whereNull('p.hidden_at')
            ->when($slug, fn ($qq) => $qq->where(function ($w) use ($slug, $id) {
                $w->where('r.slug', $slug)
                  ->orWhereExists(fn ($e) => $e->from('legacy_post_reactions as lr')
                      ->join('reactions as r2', 'r2.id', '=', 'lr.reaction_id')
                      ->whereColumn('lr.post_id', 'p.id')->where('r2.slug', $slug));
            }))
            ->groupBy('p.id', 'p.number')
            ->select('p.id', 'p.number')
            ->selectRaw('COUNT(pr.id) as native')
            ->selectRaw('COALESCE(MAX(l.score),0) as legacy')
            ->havingRaw('COUNT(pr.id) + COALESCE(MAX(l.score),0) > 0')
            ->orderByDesc($this->db->raw('COUNT(pr.id) + COALESCE(MAX(l.score),0)'))
            ->limit(200)
            ->get();

        return new JsonResponse([
            'discussionId' => $id,
            'totals' => (object) $totals,
            'legacyTotals' => (object) $legacyTotals->map(fn ($r) => [
                'posts' => (int) $r->posts, 'knownCount' => (int) $r->known,
            ])->all(),
            'posts' => array_map(fn ($p) => [
                'id' => (int) $p->id,
                'number' => (int) $p->number,
                'native' => (int) $p->native,
                'legacy' => (int) $p->legacy,
            ], $posts->all()),
        ]);
    }
}
