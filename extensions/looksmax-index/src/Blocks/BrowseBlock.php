<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;
use Local\Index\Palette;
use Local\Index\Rails;
use Local\Index\Sections;

/**
 * Every imported forum, still reachable — but folded away.
 *
 * ── Why it is still here at all ─────────────────────────────────────────────
 *
 * The six sections are a curated VIEW. The 47 imported tags are the actual
 * board, they hold every one of the ~7,000 discussions, and roughly half of
 * those discussions belong to no section by design (`f-3 Offtopic` alone is
 * 1,821). Dropping the tree from the front page would make that half reachable
 * only by URL. Nothing here is deleted or re-parented; this is the same tree,
 * rendered the same way it always was.
 *
 * ── Why it is folded ────────────────────────────────────────────────────────
 *
 * "No wall of 47 tags" is the brief, and it was right: the previous front page
 * opened with nineteen forum rows under a heading called "Looksmax", which is
 * neither a destination nor a decision. Collapsed, it is one line the reader can
 * ignore — and one line the reader who wants the old board can open, with their
 * choice remembered.
 *
 * The six section tags are excluded here, because they are already the thing
 * above it and listing them twice is exactly the duplication that makes a page
 * feel like two pages stapled together.
 */
class BrowseBlock extends AbstractBlock
{
    public function id(): string
    {
        return 'browse';
    }

    public function titleKey(): ?string
    {
        return null;
    }

    public function defaultSide(): string
    {
        return Rails::SIDE_MAIN;
    }

    public function defaultPosition(): int
    {
        return 300;
    }

    public function render(Context $ctx): ?string
    {
        $sectionSlugs = array_keys(Sections::bySlug());

        $tags = $ctx->db->table('tags')
            ->whereNotNull('position')
            ->whereNotIn('slug', $sectionSlugs)
            ->orderBy('position')
            ->get(['id', 'name', 'slug', 'description', 'color', 'icon', 'parent_id', 'discussion_count', 'last_posted_discussion_id']);

        if ($tags->isEmpty()) {
            return null;
        }

        $categories = $tags->whereNull('parent_id');
        $children = $tags->whereNotNull('parent_id')->groupBy('parent_id');

        $lastIds = $tags->pluck('last_posted_discussion_id')->filter()->all();
        $last = $lastIds
            ? $ctx->db->table('discussions')
                ->leftJoin('users', 'users.id', '=', 'discussions.last_posted_user_id')
                ->whereIn('discussions.id', $lastIds)
                ->get(['discussions.id', 'discussions.title', 'discussions.slug', 'discussions.last_posted_at', 'users.username'])
                ->keyBy('id')
            : collect();

        $postCounts = $ctx->once('browse.postcounts', fn () => $ctx->db->table('discussion_tag')
            ->join('discussions', 'discussions.id', '=', 'discussion_tag.discussion_id')
            ->groupBy('tag_id')
            ->select('tag_id', $ctx->db->raw('SUM(comment_count) AS posts'))
            ->pluck('posts', 'tag_id'));

        $body = '';
        $forums = 0;

        foreach ($categories as $cat) {
            $kids = $children[$cat->id] ?? collect();
            if ($kids->isEmpty()) {
                continue;
            }

            $body .= '<section class="LmxCat" style="--cat: ' . Html::esc($this->color($cat)) . '">'
                . '<header class="LmxCat-head">' . Html::icon($this->glyph($cat))
                . '<h3 class="LmxCat-name">' . Html::esc($cat->name) . '</h3>'
                . ($cat->description ? '<span class="LmxCat-desc">' . Html::esc($cat->description) . '</span>' : '')
                . '</header><ul class="LmxForums">';

            foreach ($kids as $f) {
                $forums++;
                $l = $last[$f->last_posted_discussion_id] ?? null;
                // --tag goes on the ROW, not on a child: a custom property
                // inherits down and not up, and the icon fill, the hover wash,
                // the rail and the name hover all read the same one.
                $body .= '<li class="LmxForum" style="--tag: ' . Html::esc($this->color($f)) . '">'
                    . '<a class="LmxForum-icon" href="/t/' . Html::esc($f->slug) . '">' . Html::icon($this->glyph($f)) . '</a>'
                    . '<div class="LmxForum-body">'
                    . '<a class="LmxForum-name" href="/t/' . Html::esc($f->slug) . '">' . Html::esc($f->name) . '</a>'
                    . ($f->description ? '<p class="LmxForum-desc">' . Html::esc($f->description) . '</p>' : '')
                    . '</div>'
                    . '<div class="LmxForum-stats">'
                    . '<span><b>' . Html::num((int) $f->discussion_count) . '</b> '
                    . Html::esc($ctx->t('local-looksmax-index.forum.index.sections.threads')) . '</span>'
                    . '<span><b>' . Html::num((int) ($postCounts[$f->id] ?? 0)) . '</b> '
                    . Html::esc($ctx->t('local-looksmax-index.forum.index.stats.posts')) . '</span>'
                    . '</div>'
                    . '<div class="LmxForum-last">'
                    . ($l
                        ? '<a href="' . Html::discussionUrl((int) $l->id, $l->slug) . '">' . Html::trim($l->title, 46) . '</a>'
                          . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.activity.by')) . ' '
                          . Html::trim((string) $l->username, 18) . ' · ' . Html::time($l->last_posted_at) . '</span>'
                        : '<span class="LmxForum-quiet">' . Html::esc($ctx->t('local-looksmax-index.forum.index.sections.empty')) . '</span>')
                    . '</div></li>';
            }

            $body .= '</ul></section>';
        }

        if (! $forums) {
            return null;
        }

        return '<section class="LmxBrowse" data-block="browse">'
            . '<button type="button" class="LmxBrowse-toggle" aria-expanded="false" data-lmx-browse>'
            . Html::icon('ph:stack-fill')
            . '<span class="LmxBrowse-label">'
            . Html::esc($ctx->t('local-looksmax-index.forum.index.browse.heading', ['{count}' => (string) $forums]))
            . '</span>'
            . '<span class="LmxBrowse-hint">' . Html::esc($ctx->t('local-looksmax-index.forum.index.browse.hint')) . '</span>'
            . Html::icon('ph:caret-down-fill', 'LmxBrowse-caret')
            . '</button>'
            . '<div class="LmxBrowse-body" hidden>' . $body . '</div>'
            . '</section>';
    }

    /**
     * The importer wrote ONE colour for every forum — measured, all 19 `f-*`
     * tags were `#7aa2f7` and 20 of 26 `p-*` were `#414868` — so "use the
     * column" rendered a page of identical blue tiles. Those two values and
     * empty mean "nobody chose"; anything else is somebody's decision and is
     * kept.
     */
    private function color(object $tag): string
    {
        $c = (string) ($tag->color ?? '');

        return ($c === '' || in_array($c, ['#7aa2f7', '#414868', '#2a323d'], true))
            ? Palette::color($tag->slug ?? null)
            : $c;
    }

    /** Same rule for the glyph: `fas fa-tag` on 26 prefixes is not a choice. */
    private function glyph(object $tag): string
    {
        $i = (string) ($tag->icon ?? '');

        return ($i === '' || $i === 'fas fa-tag' || $i === 'fas fa-comments')
            ? Palette::icon($tag->slug ?? null)
            : $i;
    }
}
