<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;
use Local\Index\Rails;
use Local\Index\Sections;

/**
 * The left rail's reason to exist: the six sections, always in reach.
 *
 * Measured before this was built: at 1920 the theme's 1100px container leaves
 * 820px — 43% of the viewport — as empty margin, and there is no left rail at
 * all. Filling it with more cards would have been the wrong answer; a reader
 * does not need two of anything. What a left rail is FOR is standing navigation:
 * the six names stay on screen while the reader scrolls the main column, so
 * "where do I go" is answerable from anywhere on the page rather than only from
 * the top of it.
 *
 * Sticky, because a nav that scrolls away is a nav you have to scroll back for.
 */
class NavBlock extends AbstractBlock
{
    public function id(): string
    {
        return 'nav';
    }

    public function icon(): ?string
    {
        return 'ph:compass-fill';
    }

    public function defaultSide(): string
    {
        return Rails::SIDE_LEFT;
    }

    public function defaultPosition(): int
    {
        return 10;
    }

    public function render(Context $ctx): ?string
    {
        $counts = $ctx->once('nav.counts', function () use ($ctx) {
            return $ctx->db->table('tags')
                ->whereIn('slug', array_keys(Sections::bySlug()))
                ->pluck('discussion_count', 'slug');
        });

        if (! count($counts)) {
            return null;
        }

        $alwaysShow = SectionsBlock::alwaysShow($ctx);

        $items = '';
        foreach (Sections::SECTIONS as $key => $def) {
            // Same rule as the section cards, including the exception: a nav
            // chip for an empty section is a dead end, unless the operator
            // listed that slug in `looksmax-index.always_show_sections`. The
            // two surfaces must agree — a chip that leads to a card that is not
            // there reads as a broken page. See SectionsBlock::sectionRows().
            if (! isset($counts[$def['slug']])) {
                continue;
            }
            if ((int) $counts[$def['slug']] < 1 && ! in_array($def['slug'], $alwaysShow, true)) {
                continue;
            }
            $items .= '<li><a class="LmxNav-link" href="/t/' . Html::esc($def['slug']) . '"'
                . ' style="--tag: ' . Html::esc(Sections::color($key)) . '">'
                . '<span class="LmxNav-icon">' . Html::icon($def['icon']) . '</span>'
                . '<span class="LmxNav-name">' . Html::esc($ctx->t(Sections::titleKey($key))) . '</span>'
                . '<span class="LmxNav-count">' . Html::num((int) $counts[$def['slug']]) . '</span>'
                . '</a></li>';
        }

        $extra = '';
        foreach ([
            ['/all', 'ph:list-bullets-bold', 'all'],
            ['/all?sort=newest', 'ph:clock-fill', 'newest'],
            ['/t/f-9', 'ph:trophy-fill', 'best'],
            ['/tags', 'ph:tag-fill', 'tags'],
        ] as [$href, $icon, $key]) {
            $extra .= '<li><a class="LmxNav-link LmxNav-link--quiet" href="' . $href . '">'
                . '<span class="LmxNav-icon">' . Html::icon($icon) . '</span>'
                . '<span class="LmxNav-name">' . Html::esc($ctx->t('local-looksmax-index.forum.index.nav.' . $key)) . '</span>'
                . '</a></li>';
        }

        return $this->card(
            $ctx,
            '<ul class="LmxNav">' . $items . '</ul>'
            . '<hr class="LmxNav-rule">'
            . '<ul class="LmxNav LmxNav--extra">' . $extra . '</ul>',
            'LmxCard--nav'
        );
    }
}
