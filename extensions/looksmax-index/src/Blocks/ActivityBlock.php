<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;

/**
 * The pulse: the last few threads anyone posted in, with who and when.
 *
 * This is the block that tells a stranger the forum is alive, which is why it
 * carries the time and the person rather than only the title. A front page with
 * no visible recency reads as abandoned however many threads it claims.
 */
class ActivityBlock extends AbstractBlock
{
    public function id(): string
    {
        return 'activity';
    }

    public function icon(): ?string
    {
        return 'ph:pulse-bold';
    }

    public function defaultPosition(): int
    {
        return 110;
    }

    public function render(Context $ctx): ?string
    {
        $rows = $ctx->db->table('discussions')
            ->leftJoin('users', 'users.id', '=', 'discussions.last_posted_user_id')
            ->whereNull('discussions.hidden_at')
            ->whereNotNull('discussions.last_posted_at')
            ->orderByDesc('discussions.last_posted_at')
            ->limit(7)
            ->get([
                'discussions.id', 'discussions.title', 'discussions.slug',
                'discussions.last_posted_at', 'users.username',
            ]);

        if ($rows->isEmpty()) {
            return null;
        }

        return $this->card($ctx, $this->discussionList(
            $rows,
            fn ($r) => Html::esc($ctx->t('local-looksmax-index.forum.index.activity.by'))
                . ' ' . Html::trim((string) $r->username, 20) . ' · ' . Html::time($r->last_posted_at)
        ));
    }
}
