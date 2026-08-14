<?php

namespace Local\Index\Blocks;

use Local\Index\BlockInterface;
use Local\Index\Context;
use Local\Index\Html;
use Local\Index\Rails;

/**
 * The card chrome every rail block shares, so a block author writes the inside
 * and never the outside — and so the twelve cards on the page are provably one
 * component rather than twelve that happen to look alike.
 */
abstract class AbstractBlock implements BlockInterface
{
    public function titleKey(): ?string
    {
        return 'local-looksmax-index.forum.index.' . $this->id() . '.heading';
    }

    public function icon(): ?string
    {
        return null;
    }

    public function defaultSide(): string
    {
        return Rails::SIDE_RIGHT;
    }

    public function defaultPosition(): int
    {
        return 500;
    }

    /**
     * @param string      $body   already-escaped markup
     * @param string      $modifier extra class, e.g. `LmxCard--ad`
     * @param string|null $action right-aligned control in the header
     */
    protected function card(Context $ctx, string $body, string $modifier = '', ?string $action = null): string
    {
        $title = $this->titleKey() ? $ctx->t($this->titleKey()) : null;

        $head = $title === null ? '' : '<h3 class="LmxCard-head">'
            . ($this->icon() ? Html::icon($this->icon()) : '')
            . '<span class="LmxCard-title">' . Html::esc($title) . '</span>'
            . ($action ?: '')
            . '</h3>';

        return '<section class="LmxCard ' . Html::esc($modifier) . '" data-block="' . Html::esc($this->id()) . '">'
            . $head . $body . '</section>';
    }

    /**
     * A list of discussions, which four separate blocks needed and three of them
     * had drawn slightly differently before this existed.
     *
     * @param iterable $rows   objects with id, title, slug
     * @param callable|null $meta row => the small line under the title
     */
    protected function discussionList(iterable $rows, ?callable $meta = null, string $class = ''): string
    {
        $h = '<ul class="LmxList ' . Html::esc($class) . '">';
        foreach ($rows as $r) {
            $h .= '<li class="LmxList-item"><a class="LmxList-link" href="' . Html::discussionUrl((int) $r->id, $r->slug ?? null) . '">'
                . Html::trim($r->title ?? '', 64) . '</a>'
                . ($meta ? '<span class="LmxList-meta">' . $meta($r) . '</span>' : '')
                . '</li>';
        }

        return $h . '</ul>';
    }
}
