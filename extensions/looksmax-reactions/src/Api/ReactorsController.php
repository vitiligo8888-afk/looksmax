<?php

namespace Local\Reactions\Api;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Reactions\Reaction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Who reacted, grouped by reaction.
 *
 * GET /api/lmx/posts/{id}/reactors
 *
 * Lazy-loaded rather than shipped in the post payload: a busy post can hold
 * hundreds of reactors and none of them are needed until someone asks.
 *
 * The legacy block is the part that needs care. Imported XenForo reactions
 * have NO user attribution — the scrape recorded types per post, plus a byline
 * naming at most three people. So this returns them as what they are: a count,
 * and the original byline text verbatim, clearly separated from the native
 * reactors. It does not invent user rows for them.
 */
class ReactorsController implements RequestHandlerInterface
{
    // Constructor-injected connection, NOT the DB facade. Flarum does not boot
    // Laravel's facade layer, so Illuminate\Support\Facades\DB::table() throws
    // "A facade root has not been set" — a 500 that only appears on the code
    // path that uses it, which is why this one survived a green deploy and was
    // caught by the e2e run instead.
    public function __construct(private \Illuminate\Database\ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $postId = (int) Arr::get($request->getQueryParams(), 'id');

        // Visibility via the SCOPE, not assertCan('view', $post->discussion).
        //
        // THIS was the live bug behind "reactions don't work": Flarum 1.8
        // registers no `view` ability for a Discussion, so `can('view', $d)`
        // matched no policy and fell through to the isAdmin() default. Every
        // non-administrator who clicked to see who had reacted got a 403 and a
        // "no tienes permiso" toast, on every post on the forum. Observed in
        // the access log before the fix:
        //
        //   GET /api/lmx/posts/9778/reactors -> 403   (Firefox, real reader)
        //   GET /api/lmx/posts/8514/reactors -> 403
        //
        // whereVisibleTo is the scope the thread list itself uses, so this
        // cannot disagree with what the reader can already see. Same trap, and
        // the same fix, as looksmax-userinfo SummaryController.php:43-58.
        $post = Post::query()->whereVisibleTo($actor)->find($postId);
        if (!$post) {
            return new JsonResponse(['error' => 'post-not-found'], 404);
        }

        $rows = \Local\Reactions\PostReaction::query()
            ->where('post_id', $postId)
            ->with(['user:id,username,avatar_url,name_style,rank_slug'])
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        $groups = [];
        foreach ($rows as $r) {
            $groups[(int) $r->reaction_id][] = [
                'id' => (int) $r->user_id,
                'username' => $r->user->username ?? null,
                'avatarUrl' => $r->user->avatar_url ?? null,
                'nameStyle' => $r->user->name_style ?? null,
                'rankSlug' => $r->user->rank_slug ?? null,
                'at' => optional($r->created_at)->toIso8601String(),
            ];
        }

        $slugs = Reaction::query()->pluck('slug', 'id')->all();
        $out = [];
        foreach ($groups as $rid => $users) {
            $out[] = [
                'reactionId' => $rid,
                'slug' => $slugs[$rid] ?? null,
                'count' => count($users),
                'users' => $users,
            ];
        }

        $legacy = [];
        foreach ($this->db->table('legacy_post_reactions')
                     ->where('post_id', $postId)->get() as $l) {
            $legacy[] = [
                'reactionId' => (int) $l->reaction_id,
                'slug' => $slugs[(int) $l->reaction_id] ?? null,
                'count' => $l->count === null ? null : (int) $l->count,
                'exact' => (bool) $l->exact,
            ];
        }
        $total = $this->db->table('legacy_post_totals')
            ->where('post_id', $postId)->first();

        return new JsonResponse([
            'postId' => $postId,
            'groups' => $out,
            'legacy' => [
                'types' => $legacy,
                'score' => $total ? (int) $total->score : 0,
                // XenForo's own byline, kept verbatim. It names at most three
                // people and then "and N others"; it is not parsed into users
                // because most of those names are "Deleted member 6401" and
                // there is no id to resolve them to.
                'summary' => $total->summary ?? null,
            ],
        ]);
    }
}
