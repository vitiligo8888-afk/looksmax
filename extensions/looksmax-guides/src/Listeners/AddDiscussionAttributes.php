<?php

namespace Local\Guides\Listeners;

use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Local\Guides\Models\GuideMeta;

/**
 * Expose guide metadata on the discussion payload.
 *
 * `isGuide` is always present and comes from the column on `discussions`, so
 * the discussion list can pick a row renderer without a join. The full `guide`
 * object is attached only when the relation is already loaded, which keeps the
 * list payload small and — more importantly — stops a 20-row list turning into
 * 20 satellite lookups. The single-discussion endpoint eager-loads it, so the
 * guide page gets everything in one request.
 */
class AddDiscussionAttributes
{
    public function __invoke(DiscussionSerializer $serializer, Discussion $discussion, array $attributes): array
    {
        $attributes['isGuide'] = (bool) ($discussion->is_guide ?? false);

        if (!$attributes['isGuide']) {
            return $attributes;
        }

        $meta = $discussion->relationLoaded('guideMeta')
            ? $discussion->getRelation('guideMeta')
            : GuideMeta::find($discussion->id);

        if (!$meta instanceof GuideMeta) {
            return $attributes;
        }

        $attributes['guide'] = [
            'status' => $meta->status,
            'version' => (int) $meta->version,
            'difficulty' => $meta->difficulty !== null ? (int) $meta->difficulty : null,
            'riskLevel' => $meta->risk_level,
            'reversibility' => $meta->reversibility,
            'requiresPro' => (bool) $meta->requires_pro,
            'readMinutes' => (int) $meta->read_minutes,
            'wordCount' => (int) $meta->word_count,
            'sectionCount' => (int) $meta->section_count,
            'claimCount' => (int) $meta->claim_count,
            'sourcedCount' => (int) $meta->sourced_count,
            // The aggregate score is sent but never rendered as a number; the
            // stacked profile bar is the reader-facing form, because a visible
            // number on this board is a target and the target would be "put T4
            // on everything".
            'evidenceScore' => $meta->evidence_score !== null ? (float) $meta->evidence_score : null,
            'evidenceProfile' => $meta->profile ?: (object) [],
            'toc' => $meta->toc,
            'publishedAt' => $meta->published_at ? $meta->published_at->toIso8601String() : null,
            'reviewedAt' => $meta->reviewed_at ? $meta->reviewed_at->toIso8601String() : null,
            'reviewDueAt' => $meta->review_due_at ? $meta->review_due_at->toIso8601String() : null,
            'freshness' => $meta->freshness(),
            'rankMultiplier' => $meta->rankMultiplier(),
        ];

        return $attributes;
    }
}
