<?php

namespace Local\Reactions\Api;

use Carbon\Carbon;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Reactions\Counts;
use Local\Reactions\Events\PostWasReacted;
use Local\Reactions\Reaction;
use Local\Reactions\PostReaction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Add or toggle one reaction on one post.
 *
 * POST /api/lmx/posts/{id}/react  {"slug": "chrigga"}
 *
 * Toggling rather than a bare insert, because that is what a click on an
 * already-active chip means, and making the client choose between POST and
 * DELETE for the same gesture is how you end up with a chip that gets stuck on
 * after a lost response. The response always carries the post's full, freshly
 * read reaction state, so the client never has to guess what the count became
 * and a double-click cannot drift the UI out of sync with the table.
 */
class ReactController implements RequestHandlerInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private Counts $counts,
        private SettingsRepositoryInterface $settings,
        private \Illuminate\Contracts\Events\Dispatcher $events,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $postId = (int) Arr::get($request->getQueryParams(), 'id');
        $post = Post::query()->find($postId);
        if (!$post) {
            return new JsonResponse(['error' => 'post-not-found'], 404);
        }

        $actor->assertCan('react', $post);

        $body = (array) ($request->getParsedBody() ?: []);
        $slug = (string) Arr::get($body, 'slug', '');
        $reaction = Reaction::query()->where('slug', $slug)->where('enabled', true)->first();
        if (!$reaction) {
            return new JsonResponse(['error' => 'unknown-reaction', 'slug' => $slug], 422);
        }

        if ($over = $this->rateLimited($actor->id)) {
            return new JsonResponse($over, 429);
        }

        $existing = PostReaction::query()
            ->where('post_id', $post->id)
            ->where('user_id', $actor->id)
            ->where('reaction_id', $reaction->id)
            ->first();

        $added = false;
        if ($existing) {
            $existing->delete();
        } else {
            $max = (int) ($this->settings->get('lmxreactions.maxPerPost') ?: 6);
            $held = PostReaction::query()
                ->where('post_id', $post->id)->where('user_id', $actor->id)->count();
            if ($held >= $max) {
                return new JsonResponse([
                    'error' => 'too-many-on-post', 'max' => $max, 'held' => $held,
                ], 422);
            }

            // The unique key is the real guard. Two clicks racing each other
            // both pass the SELECT above; only one survives the INSERT, and the
            // loser is a duplicate-key error that means "already reacted",
            // which is not an error the user should ever see.
            try {
                PostReaction::query()->create([
                    'post_id' => $post->id,
                    'user_id' => $actor->id,
                    'reaction_id' => $reaction->id,
                ]);
                $added = true;
            } catch (\Illuminate\Database\QueryException $e) {
                if (!str_contains((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }
        }

        if ($added) {
            $this->events->dispatch(new PostWasReacted($post, $actor, $reaction));
        }

        $this->counts->forget((int) $post->id);

        return new JsonResponse([
            'postId' => (int) $post->id,
            'slug' => $reaction->slug,
            'added' => $added,
            'reactions' => $this->counts->forPost((int) $post->id, (int) $actor->id),
        ]);
    }

    /**
     * Reactions are cheap to issue and cheap to script, and a reaction bomb is
     * both a notification flood for the victim and a leaderboard forgery. Two
     * windows: a burst window that stops a script dead, and an hourly ceiling
     * that a human clicking around will never reach.
     */
    private function rateLimited(int $userId): ?array
    {
        $burst = (int) ($this->settings->get('lmxreactions.rateBurst') ?: 20);
        $hourly = (int) ($this->settings->get('lmxreactions.rateHourly') ?: 300);

        $recent = $this->db->table('post_reactions')
            ->where('user_id', $userId)
            ->where('created_at', '>=', Carbon::now()->subMinute())
            ->count();
        if ($recent >= $burst) {
            return ['error' => 'rate-limited', 'window' => '1m', 'limit' => $burst, 'used' => $recent];
        }

        $hour = $this->db->table('post_reactions')
            ->where('user_id', $userId)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();
        if ($hour >= $hourly) {
            return ['error' => 'rate-limited', 'window' => '1h', 'limit' => $hourly, 'used' => $hour];
        }

        return null;
    }
}
