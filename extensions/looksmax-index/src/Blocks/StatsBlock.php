<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;

/**
 * Board size, and the newest member.
 *
 * The newest member is here for one reason: it is the only line on the page that
 * changes because somebody joined, and a board that visibly gains people is a
 * board worth joining. The counts share the query the hero already ran.
 */
class StatsBlock extends AbstractBlock
{
    public function id(): string
    {
        return 'stats';
    }

    public function icon(): ?string
    {
        return 'ph:chart-line-up-fill';
    }

    public function defaultPosition(): int
    {
        return 120;
    }

    public function render(Context $ctx): ?string
    {
        $stats = $ctx->once('board.stats', fn () => [
            'discussions' => (int) $ctx->db->table('discussions')->count(),
            'posts' => (int) $ctx->db->table('posts')->count(),
            'members' => (int) $ctx->db->table('users')->count(),
        ]);

        $newest = $ctx->db->table('users')->orderByDesc('id')->value('username');

        $cell = fn (string $k, string $v) => '<div><dt>'
            . Html::esc($ctx->t('local-looksmax-index.forum.index.stats.' . $k))
            . '</dt><dd>' . $v . '</dd></div>';

        return $this->card(
            $ctx,
            '<dl class="LmxStats">'
            . $cell('threads', Html::num($stats['discussions']))
            . $cell('posts', Html::num($stats['posts']))
            . $cell('members', Html::num($stats['members']))
            . $cell('newest', Html::trim((string) $newest, 16))
            . '</dl>',
            'LmxCard--stats'
        );
    }
}
