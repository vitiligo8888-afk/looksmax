<?php

namespace Local\Welcome\Api;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Welcome\Config;
use Local\Welcome\Survey;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Save — or skip — the acting user's own "why did you join" answers.
 *
 * POST /api/lmx-welcome/survey        { "answers": ["section_looksmaxing", "intent_learn", ...] }
 * POST /api/lmx-welcome/survey/skip   {}
 *
 * One class, two routes, dispatched on path suffix — the same shape as
 * looksmax-userinfo's DmController, for a survey with exactly two possible
 * actions rather than six.
 *
 * ── the actor is the subject, always ────────────────────────────────────────
 * There is no user id anywhere in the route or the body, on purpose — the
 * same rule looksmax-userinfo's SignatureController documents: a capability
 * to answer FOR someone else has no legitimate caller, so it is not built
 * rather than built-and-permission-gated. `RequestUtil::getActor()` is the
 * only identity this controller ever writes against.
 *
 * ── CSRF ─────────────────────────────────────────────────────────────────
 * Enforced by the same middleware every authenticated, state-changing
 * `/api/*` route on this forum goes through (Flarum's session CSRF check on
 * the token in `X-CSRF-Token`). The client reads that token off
 * `session.csrfToken` in the page's own JSON payload and sends it on every
 * write — see js/dist/forum.js `csrf()`, the same helper looksmax-cosmetics'
 * forum.js uses for its own writes.
 *
 * ── never trust the client (the actual security model) ──────────────────────
 * `answers` arrives as an arbitrary JSON array from the request body. It is
 * NEVER inserted as given. `Survey::sanitize()` intersects it against
 * `Survey::OPTIONS` — the one PHP-side catalogue — and anything not in that
 * map is silently dropped, not rejected-with-an-error: a stale client (an old
 * cached catalogue after this list changes) degrades to "fewer answers
 * saved", never to "a client-supplied string reaches `option_key`". This is
 * the same posture SignatureController takes with `strip_tags()` on write:
 * defence at the write boundary, not trust that the renderer will be careful
 * later.
 */
class SurveyController implements RequestHandlerInterface
{
    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (!$actor->exists || $actor->isGuest()) {
            return new JsonResponse(['error' => 'unauthenticated'], 401);
        }

        try {
            $cfg = Config::all($this->settings);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'misconfigured'], 500);
        }

        if (empty($cfg['enabled'])) {
            return new JsonResponse(['error' => 'disabled'], 404);
        }

        $path = $request->getUri()->getPath();

        return str_ends_with($path, '/skip')
            ? $this->skip($actor)
            : $this->save($actor, $request);
    }

    private function save(User $actor, ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $submitted = (array) ($body['answers'] ?? []);
        $clean = Survey::sanitize($submitted);

        $now = date('Y-m-d H:i:s');

        $this->db->transaction(function () use ($actor, $clean, $now) {
            // Replace, not append: this is "what the user currently says",
            // not a log of every time they hit save. See the migration's
            // docblock.
            $this->db->table('lmx_welcome_survey_answers')->where('user_id', $actor->id)->delete();

            if ($clean) {
                $rows = [];
                foreach ($clean as $key) {
                    $rows[] = [
                        'user_id' => (int) $actor->id,
                        'category' => Survey::category($key),
                        'option_key' => $key,
                        'created_at' => $now,
                    ];
                }
                $this->db->table('lmx_welcome_survey_answers')->insert($rows);
            }

            $this->upsertState($actor, 'completed', $now);
        });

        return new JsonResponse([
            'status' => 'completed',
            'answers' => $clean,
        ]);
    }

    private function skip(User $actor): ResponseInterface
    {
        $now = date('Y-m-d H:i:s');
        $this->upsertState($actor, 'skipped', $now);

        return new JsonResponse(['status' => 'skipped']);
    }

    /**
     * Hand-rolled rather than `updateOrInsert()`: that helper would overwrite
     * `created_at` on every call when a row already exists (it writes every
     * key in `$values` unconditionally), which would silently rewrite this
     * account's registration timestamp every time they re-saved their
     * answers. `created_at` is set once, at INSERT, by SeedSurveyState (or
     * here, only in the defensive fallback path below) and never touched
     * again.
     */
    private function upsertState(User $actor, string $status, string $now): void
    {
        $exists = $this->db->table('lmx_welcome_survey_state')->where('user_id', $actor->id)->exists();

        if ($exists) {
            $this->db->table('lmx_welcome_survey_state')
                ->where('user_id', $actor->id)
                ->update(['status' => $status, 'responded_at' => $now, 'updated_at' => $now]);

            return;
        }

        // Defensive only: the normal path always has a row by the time a
        // logged-in user can reach this endpoint (SeedSurveyState creates it
        // at registration). This covers the extension being enabled after
        // some accounts already existed, or a row lost to manual cleanup —
        // either way, an answer must still be storable rather than 500ing.
        $this->db->table('lmx_welcome_survey_state')->insert([
            'user_id' => (int) $actor->id,
            'status' => $status,
            'responded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
