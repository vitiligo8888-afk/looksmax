<?php

namespace Local\Economy\Api;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Economy\Config;
use Local\Economy\Ledger;
use Local\Economy\Streaks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Everything the forum-side widget (js/dist/forum.js) needs to make earning
 * visible, in one request: streak state, progress to the next rank, and how
 * much of today's caps are left. Before this endpoint existed, none of that
 * was readable from anywhere on the forum — points/lifetimePoints/rankSlug
 * rode on the user payload (see extend.php), but a raw number is not the same
 * as "you are 340 points from Gold" or "you have 12 of 40 posts left today
 * before post.created stops paying".
 *
 * Deliberately actor-only (no `?id=` like userinfo's summary): this is a
 * "your own dashboard" endpoint, not a profile surface, so there is no other
 * account to ask about and no visibility check to get wrong.
 */
class SummaryController implements RequestHandlerInterface
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Ledger $ledger,
        protected Streaks $streaks,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            return new JsonResponse(['data' => null]);
        }

        $lifetime = (int) ($actor->lifetime_points ?? 0);
        $points = (int) ($actor->points ?? 0);

        $today = gmdate('Y-m-d');
        $since = $today . ' 00:00:00';

        // Today's progress against the two caps a poster actually bumps into
        // day to day. Read live rather than cached: this is one query per
        // reason, once per widget load, not once per post.
        $postsToday = (int) $this->db->table('economy_transactions')
            ->where('user_id', $actor->id)
            ->where('reason', 'post.created')
            ->where('created_at', '>=', $since)
            ->count();

        $reactionsToday = (int) $this->db->table('economy_transactions')
            ->where('user_id', $actor->id)
            ->where('reason', 'reaction.received')
            ->where('created_at', '>=', $since)
            ->count();

        return new JsonResponse([
            'data' => [
                'points' => $points,
                'lifetimePoints' => $lifetime,
                'rank' => $this->ledger->progress($lifetime),
                'streak' => $this->streaks->of((int) $actor->id),
                'goals' => [
                    'postsToday' => ['count' => $postsToday, 'cap' => (int) Config::get($this->settings, 'cap.postCreated')],
                    'reactionsToday' => ['count' => $reactionsToday, 'cap' => (int) Config::get($this->settings, 'cap.reactionReceived')],
                ],
            ],
        ]);
    }
}
