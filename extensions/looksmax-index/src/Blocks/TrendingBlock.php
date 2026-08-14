<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;

/**
 * What is being argued about right now.
 *
 * Ordered by Flarum's own `hotness` column, which already decays with age, and
 * then by reply count — so an old thread with 400 replies does not outrank
 * today's argument. Window is deliberately short: "trending" over all time is
 * just "most replied", which the board already has a sort for.
 */
class TrendingBlock extends AbstractBlock
{
    public function id(): string
    {
        return 'trending';
    }

    public function icon(): ?string
    {
        return 'ph:fire-fill';
    }

    public function defaultPosition(): int
    {
        return 100;
    }

    public function render(Context $ctx): ?string
    {
        $rows = $ctx->db->table('discussions')
            ->whereNull('hidden_at')
            ->where('last_posted_at', '>', date('Y-m-d H:i:s', strtotime('-14 days')))
            ->orderByDesc('hotness')->orderByDesc('comment_count')
            ->limit(6)
            ->get(['id', 'title', 'slug', 'comment_count']);

        // Nothing in a fortnight means a quiet board, not a broken block — and a
        // "Trending" card with nothing in it is worse than no card.
        if ($rows->isEmpty()) {
            return null;
        }

        return $this->card($ctx, $this->discussionList(
            $rows,
            fn ($r) => Html::icon('ph:chat-circle-fill') . Html::num((int) $r->comment_count)
                . ' ' . Html::esc($ctx->t('local-looksmax-index.forum.index.trending.replies')),
            'LmxList--numbered'
        ));
    }
}
