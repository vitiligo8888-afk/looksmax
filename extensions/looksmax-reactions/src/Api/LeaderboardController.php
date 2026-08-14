<?php

namespace Local\Reactions\Api;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reaction leaderboards.
 *
 * GET /api/lmx/reactions/leaderboard?period=week&kind=received&limit=25
 *
 *   kind=received  users whose posts drew the most reactions
 *   kind=given     users who handed the most out
 *   kind=posts     the posts that drew the most
 *
 * period: day | week | month | all
 *
 * `all` deliberately counts native reactions only. The imported XenForo rows
 * have no user attribution at all — 381k of them record a type on a post and
 * nothing about who did it — so an all-time "top reactors" table built on them
 * would be fiction. The `posts` board is the one place legacy CAN participate,
 * because a post-level score is exactly what the scrape recorded, so it is
 * returned there as a separate, labelled column rather than silently summed in.
 */
class LeaderboardController implements RequestHandlerInterface
{
    private const PERIODS = ['day' => 1, 'week' => 7, 'month' => 30];

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertCan('viewForum');

        $q = $request->getQueryParams();
        $period = (string) Arr::get($q, 'period', 'week');
        $kind = (string) Arr::get($q, 'kind', 'received');
        $limit = min(100, max(1, (int) Arr::get($q, 'limit', 25)));
        $since = isset(self::PERIODS[$period])
            ? Carbon::now()->subDays(self::PERIODS[$period]) : null;

        $rows = match ($kind) {
            'given' => $this->given($since, $limit),
            'posts' => $this->posts($since, $limit),
            default => $this->received($since, $limit),
        };

        return new JsonResponse([
            'kind' => $kind,
            'period' => $period,
            'rows' => $rows,
            'includesLegacy' => $kind === 'posts' && $since === null,
        ]);
    }

    private function received(?Carbon $since, int $limit): array
    {
        $q = $this->db->table('post_reactions as pr')
            ->join('posts as p', 'p.id', '=', 'pr.post_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->join('reactions as r', 'r.id', '=', 'pr.reaction_id')
            ->select('u.id', 'u.username', 'u.avatar_url', 'u.rank_slug', 'u.name_style')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(r.points) as points')
            ->whereNull('p.hidden_at')
            ->groupBy('u.id', 'u.username', 'u.avatar_url', 'u.rank_slug', 'u.name_style')
            ->orderByDesc('total')
            ->limit($limit);
        if ($since) {
            $q->where('pr.created_at', '>=', $since);
        }

        return $this->shape($q->get());
    }

    private function given(?Carbon $since, int $limit): array
    {
        $q = $this->db->table('post_reactions as pr')
            ->join('users as u', 'u.id', '=', 'pr.user_id')
            ->join('reactions as r', 'r.id', '=', 'pr.reaction_id')
            ->select('u.id', 'u.username', 'u.avatar_url', 'u.rank_slug', 'u.name_style')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(r.points) as points')
            ->groupBy('u.id', 'u.username', 'u.avatar_url', 'u.rank_slug', 'u.name_style')
            ->orderByDesc('total')
            ->limit($limit);
        if ($since) {
            $q->where('pr.created_at', '>=', $since);
        }

        return $this->shape($q->get());
    }

    private function posts(?Carbon $since, int $limit): array
    {
        $q = $this->db->table('posts as p')
            ->leftJoin('post_reactions as pr', 'pr.post_id', '=', 'p.id')
            ->leftJoin('legacy_post_totals as l', 'l.post_id', '=', 'p.id')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('discussions as d', 'd.id', '=', 'p.discussion_id')
            ->select('p.id', 'p.discussion_id', 'p.number', 'd.title', 'd.slug as dslug',
                     'u.username', 'u.avatar_url')
            ->selectRaw('COUNT(pr.id) as native')
            ->selectRaw('COALESCE(MAX(l.score), 0) as legacy')
            ->whereNull('p.hidden_at')
            ->where('p.is_private', false)
            ->groupBy('p.id', 'p.discussion_id', 'p.number', 'd.title', 'd.slug',
                      'u.username', 'u.avatar_url')
            ->orderByDesc($this->db->raw('COUNT(pr.id) + COALESCE(MAX(l.score), 0)'))
            ->limit($limit);
        if ($since) {
            $q->where('p.created_at', '>=', $since);
        }

        return array_map(fn ($r) => [
            'postId' => (int) $r->id,
            'discussionId' => (int) $r->discussion_id,
            'number' => (int) $r->number,
            'title' => $r->title,
            'url' => '/d/' . $r->discussion_id . '-' . $r->dslug . '/' . (int) $r->number,
            'username' => $r->username,
            'avatarUrl' => $r->avatar_url,
            'native' => (int) $r->native,
            'legacy' => (int) $r->legacy,
            'total' => (int) $r->native + (int) $r->legacy,
        ], $q->get()->all());
    }

    private function shape($rows): array
    {
        return array_map(fn ($r) => [
            'userId' => (int) $r->id,
            'username' => $r->username,
            'avatarUrl' => $r->avatar_url,
            'rankSlug' => $r->rank_slug ?? null,
            'nameStyle' => $r->name_style ?? null,
            'total' => (int) $r->total,
            'points' => (int) $r->points,
        ], $rows->all());
    }
}
