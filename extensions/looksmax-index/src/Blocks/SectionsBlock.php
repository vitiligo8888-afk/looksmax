<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;
use Local\Index\Rails;
use Local\Index\Sections;

/**
 * The six sections — the front page's whole job.
 *
 * ── Two layouts, not one layout with a switch bolted on ─────────────────────
 *
 * CARDS is for discovery: six big tiles, each one an icon, the name, one line of
 * what it is for, its size, and the two most recent threads. It answers "what is
 * this place and where do I go" without reading, which is the brief. It is the
 * default for anyone who has not chosen, because a reader who has not chosen is
 * by definition new.
 *
 * LIST is for density: one row per section, the same information in a scan line,
 * with the last poster and the time — for the reader who comes back every day,
 * already knows the six names, and wants to see what moved. Six rows fit above
 * the fold with the news band still visible; six cards do not.
 *
 * They share the data and share nothing else. The switch persists per user
 * (`lmxIndexView` preference; localStorage for guests) and is applied
 * SERVER-SIDE, so a returning reader's choice is in the first paint rather than
 * a flash of the other layout.
 *
 * ── The names ───────────────────────────────────────────────────────────────
 *
 * Read from `local-looksmax-index.forum.section.<key>.title`, never hardcoded.
 * Spanish is the forum's default locale and is therefore the source string.
 */
class SectionsBlock extends AbstractBlock
{
    public function id(): string
    {
        return 'sections';
    }

    public function titleKey(): ?string
    {
        return null; // it has its own header with the layout switch in it
    }

    public function defaultSide(): string
    {
        return Rails::SIDE_MAIN;
    }

    public function defaultPosition(): int
    {
        return 100;
    }

    public function render(Context $ctx): ?string
    {
        $rows = $this->sectionRows($ctx);
        if (! $rows) {
            return null;
        }

        $view = $ctx->view === 'list' ? 'list' : 'cards';

        $switch = '<div class="LmxSwitch" role="group" aria-label="' . Html::esc($ctx->t('local-looksmax-index.forum.index.view.label')) . '">'
            . $this->switchButton($ctx, 'cards', 'ph:squares-four-fill', $view)
            . $this->switchButton($ctx, 'list', 'ph:rows-fill', $view)
            . '</div>';

        $out = '<section class="LmxSections" data-block="sections" data-view="' . $view . '">'
            . '<header class="LmxSections-head">'
            . '<h2 class="LmxSections-title">' . Html::esc($ctx->t('local-looksmax-index.forum.index.sections.heading')) . '</h2>'
            . '<p class="LmxSections-lede">' . Html::esc($ctx->t('local-looksmax-index.forum.index.sections.lede')) . '</p>'
            . $switch
            . '</header>';

        $out .= '<div class="LmxSections-grid">';
        foreach ($rows as $r) {
            $out .= $this->sectionTile($ctx, $r);
        }
        $out .= '</div></section>';

        return $out;
    }

    private function switchButton(Context $ctx, string $mode, string $icon, string $current): string
    {
        $on = $mode === $current;

        return '<button type="button" class="LmxSwitch-btn' . ($on ? ' is-on' : '') . '"'
            . ' data-lmx-view="' . $mode . '" aria-pressed="' . ($on ? 'true' : 'false') . '">'
            . Html::icon($icon)
            . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.view.' . $mode)) . '</span>'
            . '</button>';
    }

    /**
     * One query for the six tags, one for their two most recent threads each.
     *
     * The recent-threads query is a single ranked pass rather than six queries,
     * because six sections becoming twelve round trips is how a front page ends
     * up slower than the discussion list it replaced.
     */
    private function sectionRows(Context $ctx): array
    {
        return $ctx->once('sections.rows', function () use ($ctx) {
            $bySlug = Sections::bySlug();

            $tags = $ctx->db->table('tags')
                ->whereIn('slug', array_keys($bySlug))
                ->get(['id', 'slug', 'discussion_count', 'last_posted_at', 'last_posted_discussion_id'])
                ->keyBy('slug');

            if ($tags->isEmpty()) {
                return [];
            }

            $tagIds = $tags->pluck('id')->all();

            $latest = [];
            // Rank WITHIN each tag, not globally.
            //
            // The previous version took one date-ordered pass over every section
            // and cut it at `count * 14`. That silently starves small sections:
            // measured live, the 151 threads in Softmaxing/Looksmaxing filled the
            // whole window, so Mejores Guías rendered "6 threads" next to
            // "Nothing here yet" — a count and a preview disagreeing on the same
            // card. ROW_NUMBER() partitions by tag so each section always gets
            // its own newest three, and it stays one round trip.
            $in = implode(',', array_map('intval', $tagIds));
            $rows = collect($ctx->db->select(
                "SELECT tag_id, id, title, slug, comment_count, last_posted_at, username FROM (
                    SELECT dt.tag_id, d.id, d.title, d.slug, d.comment_count, d.last_posted_at, u.username,
                           ROW_NUMBER() OVER (PARTITION BY dt.tag_id ORDER BY d.last_posted_at DESC, d.id DESC) rn
                    FROM discussion_tag dt
                    JOIN discussions d ON d.id = dt.discussion_id
                    LEFT JOIN users u ON u.id = d.last_posted_user_id
                    WHERE dt.tag_id IN ($in) AND d.hidden_at IS NULL AND d.is_private = 0
                 ) ranked WHERE rn <= 3"
            ));

            foreach ($rows as $r) {
                $latest[$r->tag_id][] = $r;
            }

            $out = [];
            foreach (Sections::SECTIONS as $key => $def) {
                $tag = $tags[$def['slug']] ?? null;
                if (! $tag) {
                    continue;
                }
                // An empty section is worse than a missing one: a front page of
                // cards reading "0 threads / nothing here yet" is what an
                // abandoned board looks like, and three of the six were empty
                // after the translated content came down. Hiding them is
                // self-healing rather than a decision — the section reappears
                // by itself the moment it holds a visible thread, so nothing
                // has to be remembered and re-enabled by hand later.
                if ((int) $tag->discussion_count < 1) {
                    continue;
                }
                $out[] = (object) [
                    'key' => $key,
                    'slug' => $def['slug'],
                    'color' => Sections::color($key),
                    'icon' => Sections::icon($key),
                    'count' => (int) $tag->discussion_count,
                    'latest' => array_slice($latest[$tag->id] ?? [], 0, 3),
                ];
            }

            return $out;
        });
    }

    /**
     * One markup for both layouts.
     *
     * The two views are genuinely different renderings, but they are different
     * because the STYLESHEET reflows the same semantic parts — a tile is a
     * figure with an icon, a name, a lede, a count and a recent list in both. Two
     * separate markups would mean two places to fix a wrong title and two DOM
     * subtrees to keep in sync across a switch that must not reload the page.
     */
    private function sectionTile(Context $ctx, object $r): string
    {
        $title = $ctx->t(Sections::titleKey($r->key));
        $desc = $ctx->t(Sections::descKey($r->key));
        $href = '/t/' . Html::esc($r->slug);

        $recent = '';
        foreach ($r->latest as $d) {
            $recent .= '<li><a href="' . Html::discussionUrl((int) $d->id, $d->slug) . '">'
                . Html::trim($d->title, 52) . '</a>'
                . '<span class="LmxTile-meta">' . Html::time($d->last_posted_at)
                . ($d->username ? ' · ' . Html::trim($d->username, 18) : '')
                . ' · ' . Html::num((int) $d->comment_count) . '</span></li>';
        }

        if ($recent === '') {
            // A real empty state, not a blank list: a brand-new section is the
            // normal case for the first weeks and must not look broken.
            $recent = '<li class="LmxTile-empty">' . Html::esc($ctx->t('local-looksmax-index.forum.index.sections.empty')) . '</li>';
        }

        return '<article class="LmxTile" style="--tag: ' . Html::esc($r->color) . '" data-section="' . Html::esc($r->key) . '">'
            . '<a class="LmxTile-hit" href="' . $href . '" aria-label="' . Html::esc($title) . '"></a>'
            . '<span class="LmxTile-icon">' . Html::icon($r->icon) . '</span>'
            . '<div class="LmxTile-body">'
            . '<h3 class="LmxTile-name">' . Html::esc($title) . '</h3>'
            . '<p class="LmxTile-desc">' . Html::esc($desc) . '</p>'
            . '</div>'
            . '<div class="LmxTile-count"><b>' . Html::num($r->count) . '</b>'
            . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.sections.threads')) . '</span></div>'
            . '<ul class="LmxTile-recent">' . $recent . '</ul>'
            . '<span class="LmxTile-go">' . Html::icon('ph:arrow-right-bold') . '</span>'
            . '</article>';
    }
}
