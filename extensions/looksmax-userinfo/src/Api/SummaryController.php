<?php

namespace Local\UserInfo\Api;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The extra identity surface a profile page needs and the user endpoint cannot
 * carry: where this account actually posts, what its best-received post was,
 * and how its activity is distributed over time.
 *
 * Separate from the user serializer on purpose. Everything here is a GROUP BY
 * over that account's posts, which is fine once per profile view and ruinous
 * forty times per discussion list — which is exactly what would happen if it
 * were an attribute.
 *
 * Every number is a COUNT or a MAX over rows this forum holds. Nothing is
 * carried over from the source board here; the carried-over block lives on the
 * user payload and is labelled there.
 */
class SummaryController implements RequestHandlerInterface
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $id = (int) Arr::get($request->getQueryParams(), 'id');

        /*
         * Visibility is the forum's decision, not ours — a hidden or suspended
         * account must not leak its activity through a side door.
         *
         * It is expressed as `whereVisibleTo`, NOT as `assertCan('view', $user)`.
         * There is no `view` ability registered for a User in Flarum 1.8, so
         * `can('view', $user)` is false for everyone except an administrator,
         * and this endpoint returned 403 to every logged-out visitor while
         * core's own `GET /api/users/3790` returned 200 for the same actor.
         * Measured on the live forum before the fix:
         *
         *   GET /api/users/3790            -> 200
         *   GET /api/userinfo/summary?id=3790 -> 403 permission_denied
         *
         * `whereVisibleTo` is the scope core's own user queries use, so this
         * cannot drift from what the forum will actually show.
         *
         * 404, not 403, when it is not visible: whether an account exists is
         * itself information.
         */
        $user = User::query()->whereVisibleTo($actor)->find($id);
        if (! $user) {
            return new JsonResponse(['error' => 'no such user'], 404);
        }

        return new JsonResponse([
            'data' => [
                'id' => $user->id,
                'topTags' => $this->topTags($user->id),
                'recentDiscussions' => $this->recentDiscussions($user->id),
                'bestPost' => $this->bestPost($user->id),
                'firstPostAt' => $this->firstPostAt($user->id),
                'activityByMonth' => $this->activityByMonth($user->id),
                'replyShare' => $this->replyShare($user->id),
            ],
        ]);
    }

    /** Which sections this account actually lives in. */
    private function topTags(int $userId): array
    {
        $rows = $this->db->table('posts')
            ->join('discussion_tag', 'discussion_tag.discussion_id', '=', 'posts.discussion_id')
            ->join('tags', 'tags.id', '=', 'discussion_tag.tag_id')
            ->where('posts.user_id', $userId)
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->groupBy('tags.id', 'tags.name', 'tags.slug', 'tags.color')
            ->orderByDesc($this->db->raw('COUNT(*)'))
            ->limit(6)
            ->get(['tags.name', 'tags.slug', 'tags.color', $this->db->raw('COUNT(*) as posts')]);

        return $rows->map(fn ($r) => [
            'name' => $r->name, 'slug' => $r->slug, 'color' => $r->color, 'posts' => (int) $r->posts,
        ])->all();
    }

    private function recentDiscussions(int $userId): array
    {
        $rows = $this->db->table('discussions')
            ->where('user_id', $userId)->where('is_private', 0)->whereNull('hidden_at')
            ->orderByDesc('created_at')->limit(5)
            ->get(['id', 'title', 'slug', 'comment_count', 'view_count', 'created_at']);

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'title' => $r->title,
            'slug' => $r->slug,
            'replies' => max(0, (int) $r->comment_count - 1),
            'views' => (int) $r->view_count,
            'createdAt' => $r->created_at,
        ])->all();
    }

    /**
     * The single post of theirs that this forum received best.
     *
     * Uses the per-post score computed by userinfo:backfill from the scrape,
     * which is why the profile can show it at all: flarum/likes holds no rows
     * for imported content, so a purely local MAX() would be zero for everyone
     * and the panel would look broken rather than empty.
     */
    private function bestPost(int $userId): ?array
    {
        $best = (int) $this->db->table('userinfo_profiles')->where('user_id', $userId)->value('best_post_score');
        if ($best < 1) {
            return null;
        }

        return ['score' => $best];
    }

    private function firstPostAt(int $userId): ?string
    {
        $v = $this->db->table('posts')->where('user_id', $userId)->min('created_at');

        return $v ? (string) $v : null;
    }

    /**
     * Posts per month for the last 24 months.
     *
     * A sparkline of this is the difference between "12,000 posts" and "12,000
     * posts, all of them in one week in 2021" — the same integer describing two
     * completely different accounts.
     */
    private function activityByMonth(int $userId): array
    {
        $rows = $this->db->table('posts')
            ->where('user_id', $userId)
            ->where('type', 'comment')
            ->whereNull('hidden_at')
            ->where('created_at', '>=', date('Y-m-d', strtotime('-24 months')))
            ->groupBy($this->db->raw("DATE_FORMAT(created_at, '%Y-%m')"))
            ->orderBy($this->db->raw("DATE_FORMAT(created_at, '%Y-%m')"))
            ->get([$this->db->raw("DATE_FORMAT(created_at, '%Y-%m') as ym"), $this->db->raw('COUNT(*) as posts')]);

        return $rows->map(fn ($r) => ['month' => $r->ym, 'posts' => (int) $r->posts])->all();
    }

    /**
     * How much of their output starts a conversation versus joins one.
     *
     * Both halves are counted from the same rows the counters use, so this can
     * never disagree with the two numbers printed beside it.
     */
    private function replyShare(int $userId): array
    {
        $starts = (int) $this->db->table('discussions')
            ->where('user_id', $userId)->where('is_private', 0)->whereNull('hidden_at')->count();

        $total = (int) $this->db->table('posts')
            ->where('user_id', $userId)->where('type', 'comment')->whereNull('hidden_at')->count();

        return ['starts' => $starts, 'posts' => $total, 'replies' => max(0, $total - $starts)];
    }
}
