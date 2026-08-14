<?php

namespace Local\Guides\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The guide library.
 *
 * A new route rather than a filter on the discussion list, for two reasons.
 * The obvious one: DiscussionList.view is overridden by six extensions and
 * DiscussionListState.loadPage by five, so decorating the existing list means
 * joining a five-way fight for a feature that is not even shaped like a list.
 * The real one: guides are compared, not scanned. "Which of these is the cheap
 * low-risk one" is the question readers arrive with, and it needs facets over
 * difficulty, cost, risk and evidence — none of which exist on a discussion.
 *
 * Sorting and filtering are entirely index-backed against guide_meta, which is
 * a few thousand rows even when discussions is 2.2M. Nothing here touches
 * posts.
 */
class ListGuidesController implements RequestHandlerInterface
{
    private const SORTS = [
        'recent' => ['gm.published_at', 'desc'],
        'evidence' => ['gm.evidence_score', 'desc'],
        'quick' => ['gm.time_to_result_d', 'asc'],
        'easy' => ['gm.difficulty', 'asc'],
        'longest' => ['gm.read_minutes', 'desc'],
        'due' => ['gm.review_due_at', 'asc'],
    ];

    public function __construct(protected ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $q = $request->getQueryParams();

        $limit = min(100, max(1, (int) ($q['limit'] ?? 30)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $query = $this->db->table('guide_meta as gm')
            ->join('discussions as d', 'd.id', '=', 'gm.discussion_id')
            ->whereNull('d.hidden_at')
            ->where('d.is_private', false)
            ->where('gm.status', '!=', 'draft');

        // Visibility is enforced with the same scope the rest of the forum
        // uses rather than reimplemented here, so a restricted tag stays
        // restricted in the library.
        $visible = \Flarum\Discussion\Discussion::whereVisibleTo($actor)->select('id');
        $query->whereIn('d.id', $visible);

        if (!empty($q['risk'])) {
            $query->whereIn('gm.risk_level', array_slice((array) explode(',', $q['risk']), 0, 5));
        }
        if (isset($q['maxDifficulty']) && $q['maxDifficulty'] !== '') {
            $query->where('gm.difficulty', '<=', (int) $q['maxDifficulty']);
        }
        if (isset($q['minEvidence']) && $q['minEvidence'] !== '') {
            $query->where('gm.evidence_score', '>=', (float) $q['minEvidence']);
        }
        if (!empty($q['fresh'])) {
            $query->where('gm.review_due_at', '>', date('Y-m-d H:i:s'));
        }
        if (!empty($q['needsReview'])) {
            $query->where('gm.review_due_at', '<=', date('Y-m-d H:i:s'));
        }
        if (!empty($q['tag'])) {
            $query->whereExists(function ($sub) use ($q) {
                $sub->from('discussion_tag as dt')
                    ->join('tags as t', 't.id', '=', 'dt.tag_id')
                    ->whereColumn('dt.discussion_id', 'd.id')
                    ->where('t.slug', $q['tag']);
            });
        }

        $total = (clone $query)->count();

        [$col, $dir] = self::SORTS[$q['sort'] ?? 'recent'] ?? self::SORTS['recent'];
        $query->orderByRaw($col . ' IS NULL')->orderBy($col, $dir);

        $rows = $query->limit($limit)->offset($offset)->get([
            'gm.discussion_id', 'gm.status', 'gm.difficulty', 'gm.cost_min', 'gm.cost_max',
            'gm.time_to_result_d', 'gm.risk_level', 'gm.reversibility', 'gm.requires_pro',
            'gm.evidence_profile', 'gm.claim_count', 'gm.sourced_count', 'gm.evidence_score',
            'gm.word_count', 'gm.read_minutes', 'gm.section_count',
            'gm.published_at', 'gm.reviewed_at', 'gm.review_due_at', 'gm.version',
            'd.title', 'd.slug', 'd.comment_count', 'd.user_id', 'd.created_at as started_at',
        ]);

        $now = time();
        $data = [];
        foreach ($rows as $r) {
            $due = $r->review_due_at ? strtotime($r->review_due_at) : null;
            $data[] = [
                'id' => (int) $r->discussion_id,
                'title' => $r->title,
                'slug' => $r->slug,
                'url' => '/d/' . $r->discussion_id . '-' . $r->slug,
                'commentCount' => (int) $r->comment_count,
                'authorId' => $r->user_id ? (int) $r->user_id : null,
                'status' => $r->status,
                'version' => (int) $r->version,
                'difficulty' => $r->difficulty !== null ? (int) $r->difficulty : null,
                'riskLevel' => $r->risk_level,
                'reversibility' => $r->reversibility,
                'requiresPro' => (bool) $r->requires_pro,
                'readMinutes' => (int) $r->read_minutes,
                'wordCount' => (int) $r->word_count,
                'sectionCount' => (int) $r->section_count,
                'claimCount' => (int) $r->claim_count,
                'sourcedCount' => (int) $r->sourced_count,
                'evidenceScore' => $r->evidence_score !== null ? (float) $r->evidence_score : null,
                'evidenceProfile' => json_decode((string) $r->evidence_profile, true) ?: (object) [],
                'reviewedAt' => $r->reviewed_at,
                'reviewDueAt' => $r->review_due_at,
                // Freshness is computed once, server-side, so the banner, the
                // library badge and the ranking penalty can never disagree.
                'freshness' => $due === null ? 'unknown'
                    : ($now < $due ? 'fresh' : ($now > $due + 86400 * 90 ? 'stale' : 'due')),
            ];
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ]);
    }
}
