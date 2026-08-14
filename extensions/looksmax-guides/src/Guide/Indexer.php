<?php

namespace Local\Guides\Guide;

use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use Local\Guides\Models\GuideMeta;

/**
 * Turns a saved post into queryable guide metadata.
 *
 * Runs on the post lifecycle rather than on a schedule, because the whole
 * point of the format is that the author gets immediate feedback: publish a
 * claim without a source and the readiness state has to change now, not
 * tonight. The work is bounded (one DOM parse of one post) so it is safe
 * inline; nothing here scans the discussion's other posts.
 */
class Indexer
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Extractor $extractor
    ) {
    }

    public function indexPost(Post $post): ?GuideMeta
    {
        // Only the opening post is the document. Replies are discussion, and
        // conflating the two is exactly what makes the source board's guides
        // unreadable.
        if ((int) $post->number !== 1 || !$post->discussion_id) {
            return null;
        }

        // parsed_content, NOT content. CommentPost::getContentAttribute runs
        // the value back through Formatter::unparse and hands you the original
        // BBCode source; only getParsedContentAttribute returns the stored
        // TextFormatter XML that actually carries the tier/src attributes.
        // Reading ->content here silently extracts nothing, which is a very
        // quiet way to lose the entire feature.
        $data = $this->extractor->extract($post->parsed_content);
        $discussionId = (int) $post->discussion_id;

        if (!$data['is_guide']) {
            $this->clear($discussionId);

            return null;
        }

        return $this->write($discussionId, (int) $post->id, $data);
    }

    public function clear(int $discussionId): void
    {
        $this->db->table('guide_claims')->where('discussion_id', $discussionId)->delete();
        $this->db->table('guide_meta')->where('discussion_id', $discussionId)->delete();
        $this->db->table('discussions')->where('id', $discussionId)->update(['is_guide' => 0]);
    }

    public function write(int $discussionId, int $postId, array $data): GuideMeta
    {
        $now = date('Y-m-d H:i:s');

        $profile = $data['profile'];
        $sourced = 0;
        foreach ($data['claims'] as $c) {
            if ($c['source_url'] || $c['source_doi']) {
                $sourced++;
            }
        }

        $existing = GuideMeta::find($discussionId);

        // Review interval is a function of risk, not a global constant. A
        // permanent surgical procedure whose evidence base moves is a very
        // different staleness problem from a skincare routine, and giving both
        // a one-year clock means either nagging about the routine or letting
        // the surgery guide rot.
        $risk = $data['risk_level'] ?: ($existing->risk_level ?? 'low');
        $interval = $this->reviewInterval($risk);

        $attrs = [
            'post_id' => $postId,
            'status' => $existing->status ?? 'published',
            'difficulty' => $this->intOrNull($data['spec']['difficulty'] ?? null, 1, 5),
            'risk_level' => $risk,
            'reversibility' => $this->enumOrNull(
                $data['spec']['reversibility'] ?? null,
                ['reversible', 'partly', 'permanent']
            ),
            'requires_pro' => $this->truthy($data['spec']['pro'] ?? null),
            'evidence_profile' => json_encode((object) $profile),
            'claim_count' => count($data['claims']),
            'sourced_count' => $sourced,
            'evidence_score' => Tiers::score($profile),
            'word_count' => $data['word_count'],
            'read_minutes' => $data['read_minutes'],
            'section_count' => $data['section_count'],
            'toc' => json_encode($data['toc']),
            'review_interval_d' => $interval,
            'updated_at' => $now,
        ];

        if ($existing) {
            $attrs['version'] = ((int) $existing->version) + 1;
            // Editing is not reviewing. An author who fixes a typo has not
            // rechecked the evidence, so the review clock only ever moves when
            // someone explicitly reviews.
            $attrs['review_due_at'] = $existing->reviewed_at
                ? date('Y-m-d H:i:s', $existing->reviewed_at->getTimestamp() + $interval * 86400)
                : $existing->review_due_at;

            $this->db->table('guide_meta')->where('discussion_id', $discussionId)->update($attrs);
        } else {
            $attrs['discussion_id'] = $discussionId;
            $attrs['version'] = 1;
            $attrs['published_at'] = $now;
            $attrs['reviewed_at'] = $now;
            $attrs['review_due_at'] = date('Y-m-d H:i:s', time() + $interval * 86400);
            $attrs['created_at'] = $now;

            $this->db->table('guide_meta')->insert($attrs);
        }

        // Claims are replaced wholesale rather than diffed: the set is small
        // (tens, not thousands) and a diff would have to solve identity for
        // edited text, which is a lot of machinery to avoid two cheap queries.
        $this->db->table('guide_claims')->where('discussion_id', $discussionId)->delete();

        if ($data['claims']) {
            $rows = [];
            foreach ($data['claims'] as $c) {
                $rows[] = [
                    'discussion_id' => $discussionId,
                    'post_id' => $postId,
                    'position' => $c['position'],
                    'tier' => $c['tier'],
                    'anchor' => $c['anchor'],
                    'claim_text' => $c['claim_text'],
                    'source_url' => $c['source_url'],
                    'source_doi' => $c['source_doi'],
                    'disputed' => 0,
                    'created_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                $this->db->table('guide_claims')->insert($chunk);
            }
        }

        $this->db->table('discussions')->where('id', $discussionId)->update(['is_guide' => 1]);

        return GuideMeta::find($discussionId);
    }

    private function reviewInterval(?string $risk): int
    {
        switch ($risk) {
            case 'medical':
            case 'high':
                return 180;
            case 'moderate':
                return 365;
            case 'none':
                return 730;
            default:
                return 545;
        }
    }

    private function intOrNull($v, int $min, int $max): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $i = (int) $v;

        return ($i >= $min && $i <= $max) ? $i : null;
    }

    private function enumOrNull($v, array $allowed): ?string
    {
        $v = strtolower(trim((string) $v));

        return in_array($v, $allowed, true) ? $v : null;
    }

    private function truthy($v): bool
    {
        $v = strtolower(trim((string) $v));

        return in_array($v, ['1', 'yes', 'y', 'true', 'required'], true);
    }
}
