<?php

namespace Local\Welcome\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Welcome\Survey;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The admin-readable half of item 5 in the brief: "expose the collected data
 * ... so it is usable, and document how personalisation would consume it."
 *
 * GET /api/lmx-welcome/stats
 *
 * Restricted to `$actor->isAdmin()` — the same gate looksmax-search's
 * InsightsController uses for its own aggregate report, and for the same
 * reason: this is a distribution over what real, individually-identifiable
 * accounts said about themselves, and an aggregate count is the right
 * granularity for a dashboard even though it is not personal data on its own.
 *
 * ── what this endpoint is NOT ────────────────────────────────────────────
 * It does not return a per-user list of who-picked-what. That is a
 * meaningfully more sensitive capability (a name-and-answers export) than a
 * bar chart, and nothing on this install currently needs it — building it
 * "just in case" is exactly the kind of unused attack surface the brief's
 * security section warns against. A future feature that legitimately needs
 * per-user answers should read them the way personalisation is documented to
 * (below), not through this endpoint.
 *
 * ── how personalisation reads this data, in practice ────────────────────────
 * 1. FOR THE ACTOR'S OWN EXPERIENCE (the common case: "show this member
 *    Peptides content because they said they came for it"): read
 *    `lmxJoinSurvey` straight off the user object already in the SPA's store
 *    — `app.session.user.data.attributes.lmxJoinSurvey.answers` client-side,
 *    or `$user->userInfoProfile` style relationship if a future PHP surface
 *    wants it server-side (see extend.php's UserSerializer mutator for the
 *    exact shape: `{status, answers: [key,...], respondedAt}`). No new query
 *    for the common case — it rides the payload every page already has,
 *    same principle as looksmax-userinfo's `userInfo` attribute.
 * 2. FOR A BULK/OFFLINE JOB (e.g. "email everyone who picked intent_surgery
 *    a Hardmaxing digest"): query `lmx_welcome_survey_answers` directly —
 *    `SELECT user_id FROM lmx_welcome_survey_answers WHERE option_key = ?`.
 *    That table is exactly the shape this needs already; no serializer, no
 *    HTTP round trip.
 * 3. FOR AGGREGATE REPORTING (e.g. "what fraction of new members say they're
 *    here to be rated"): this endpoint, or the same query this endpoint runs.
 */
class StatsController implements RequestHandlerInterface
{
    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (!$actor->isAdmin()) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }

        $byStatus = $this->db->table('lmx_welcome_survey_state')
            ->groupBy('status')
            ->get(['status', $this->db->raw('COUNT(*) as c')])
            ->pluck('c', 'status');

        $pending = (int) ($byStatus['pending'] ?? 0);
        $completed = (int) ($byStatus['completed'] ?? 0);
        $skipped = (int) ($byStatus['skipped'] ?? 0);
        $total = $pending + $completed + $skipped;

        $rows = $this->db->table('lmx_welcome_survey_answers')
            ->groupBy('option_key', 'category')
            ->orderByRaw('COUNT(*) DESC')
            ->get(['option_key', 'category', $this->db->raw('COUNT(*) as c'), $this->db->raw('COUNT(DISTINCT user_id) as u')]);

        $perOption = [];
        foreach ($rows as $r) {
            $perOption[] = [
                'key' => $r->option_key,
                'category' => $r->category,
                'count' => (int) $r->c,
                // Should always be true; false would mean rows exist for an
                // option id that has since been removed from Survey::OPTIONS
                // (a retired chip), which is worth surfacing rather than
                // silently mixing into "current" totals.
                'known' => Survey::isValid($r->option_key),
            ];
        }

        return new JsonResponse([
            'totals' => [
                'pending' => $pending,
                'completed' => $completed,
                'skipped' => $skipped,
                'total' => $total,
                'completionRate' => $total ? round(100 * $completed / $total, 1) : 0,
                'skipRate' => $total ? round(100 * $skipped / $total, 1) : 0,
            ],
            'perOption' => $perOption,
            'catalog' => Survey::forClient(),
        ]);
    }
}
