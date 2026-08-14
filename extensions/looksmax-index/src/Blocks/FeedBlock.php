<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;
use Local\Index\Rails;
use Local\Index\Sections;

/**
 * The feed. The thing the front page was missing.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * Operator: "ensure it's rich and has what we want front and center the main
 * content of the site, not polluted by bs banners and 'welcome back' shit and
 * whatever just rich feed and shit", and separately "CURATED FEEDS ... trending,
 * best-of, newest, most-reacted, by-category, unanswered".
 *
 * Measured on the front page before this block, at 390x844: the first viewport
 * held a 185px welcome band, a 310px landing hero, and one partially-visible
 * news item — 79% of the fold spent on things that are not content. At 1440x900
 * the first thread title sat at y=749. This block puts real threads at the top
 * of the main column and the two banners are gone (the welcome band in
 * looksmax-theme/less/discussion.less, the landing hero moved to the right rail
 * by HeroBlock).
 *
 * ── Why all six feeds are rendered, not fetched ─────────────────────────────
 *
 * Six tabs x 10 rows = 60 rows of markup, ~14KB gzipped, and switching a tab is
 * then a class change with no request, no spinner and no layout shift. The
 * alternative is an API round trip per tab on a page whose entire job is to be
 * instantly useful. The queries are six indexed LIMIT 10s against columns that
 * all have their own index (last_posted_at, created_at, comment_count,
 * legacy_post_totals.score) — see the per-feed notes below. This is measured in
 * e2e, not asserted: the block reports its own query time in a data attribute.
 *
 * ── Why it is not Flarum's DiscussionList ───────────────────────────────────
 *
 * Core's list is one ordering, chosen by a dropdown, and it is what /all is for.
 * A front page's job is different: it has to show a stranger that six different
 * kinds of thing are happening here. That is six queries, side by side, not one
 * query with a sort parameter.
 */
class FeedBlock extends AbstractBlock
{
    /**
     * Rows per tab.
     *
     * SIX, not ten. Operator: "smaller by default box so that the 'Por dónde
     * empezar' cards appear above the fold or at least the 1st ones". Measured
     * at 1440x900 with ten rows: the feed occupied y=172..982, so the section
     * cards began below the fold and the acceptance criterion failed. At six
     * rows the feed ends around y=690 and the first "Por dónde empezar" card is
     * inside the first viewport. e2e/visual/fold.ts asserts it — see the
     * startHere block there — so this constant cannot be raised back without the
     * gate going red.
     *
     * "See all" in the header is the escape hatch for anyone who wants the long
     * list; /all is a better long list than a longer front page.
     */
    private const ROWS = 6;

    /**
     * The tabs, in the order a reader meets them.
     *
     *   id        the tab key; also the i18n key suffix and the data attribute
     *   icon      iconify name. VERIFIED PRESENT in
     *             looksmax-icons/js/dist/icons.json, not merely valid on the
     *             Iconify API: nothing is fetched from a CDN here, so a name
     *             that is real but unbundled renders as an empty coloured
     *             square. ph:trend-up-bold and ph:chat-circle-dots-fill are
     *             both real and both unbundled — that is why 'top' and
     *             'unanswered' use chart-line-up-bold and question-fill.
     *
     * "hot" is first because a stranger who reads one thing should read the
     * thing the forum is arguing about today, not the newest thing, which on a
     * board this size is usually a one-line question.
     */
    private const TABS = [
        ['hot', 'ph:fire-fill'],
        ['new', 'ph:clock-fill'],
        ['top', 'ph:chart-line-up-bold'],
        ['reacted', 'ph:heart-fill'],
        ['unanswered', 'ph:question-fill'],
        ['guides', 'ph:book-open-text-fill'],
    ];

    public function id(): string
    {
        return 'feed';
    }

    public function titleKey(): ?string
    {
        return null; // its header is the tab strip
    }

    public function defaultSide(): string
    {
        return Rails::SIDE_MAIN;
    }

    /** Before sections (100) and everything else. This is the top of the page. */
    public function defaultPosition(): int
    {
        return 10;
    }

    public function render(Context $ctx): ?string
    {
        $t0 = microtime(true);

        $panels = '';
        $tabs = '';
        $first = null;
        $rendered = 0;

        foreach (self::TABS as [$id, $icon]) {
            $rows = $this->rows($ctx, $id);
            if (! $rows) {
                // A tab with nothing behind it is not shown at all. An empty
                // "Unanswered" is good news about the forum and bad news about
                // the page; either way it is not a thing to click.
                continue;
            }
            $first ??= $id;
            $on = $id === $first;
            $rendered++;

            $tabs .= '<button type="button" class="LmxFeed-tab' . ($on ? ' is-on' : '') . '"'
                . ' data-lmx-feed="' . $id . '" role="tab" aria-selected="' . ($on ? 'true' : 'false') . '"'
                . ' aria-controls="lmx-feed-' . $id . '">'
                . Html::icon($icon)
                . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.feed.' . $id)) . '</span>'
                . '</button>';

            $panels .= '<ol class="LmxFeed-list" id="lmx-feed-' . $id . '" data-feed="' . $id . '"'
                . ' role="tabpanel"' . ($on ? '' : ' hidden') . '>'
                . $this->rowsHtml($ctx, $rows, $id)
                . '</ol>';
        }

        if ($rendered === 0) {
            return null;
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);

        return '<section class="LmxFeed" data-block="feed" data-ms="' . $ms . '">'
            . '<header class="LmxFeed-head">'
            . '<div class="LmxFeed-tabs" role="tablist"'
            . ' aria-label="' . Html::esc($ctx->t('local-looksmax-index.forum.index.feed.heading')) . '">'
            . $tabs . '</div>'
            . '<a class="LmxFeed-all" href="/all">'
            . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.feed.all')) . '</span>'
            . Html::icon('ph:caret-right-bold') . '</a>'
            . '</header>'
            . $panels
            . '</section>';
    }

    // ------------------------------------------------------------------ rows

    /**
     * One row: category chip, title, excerpt, author, replies, views, when.
     *
     * The excerpt is the point. A list of 10 bare titles is a table of contents;
     * with one line of the actual post under each, a reader can decide without
     * clicking, which is the difference between a feed and an index.
     */
    private function rowsHtml(Context $ctx, array $rows, string $feed): string
    {
        $h = '';
        foreach ($rows as $r) {
            $tagName = (string) ($r->tag_name ?? '');
            $tagColor = (string) ($r->tag_color ?? '');
            $chip = $tagName === '' ? '' :
                '<a class="LmxFeed-cat" href="/t/' . Html::esc((string) $r->tag_slug) . '">'
                . Html::esc($tagName) . '</a>';
            // --cat is set on the ROW, not on the chip. CSS cannot read a
            // descendant's custom property, and the row's hover rail is the
            // same colour as its chip by definition — declaring it twice is how
            // the two drift apart.
            $catVar = $tagColor !== '' ? ' style="--cat: ' . Html::esc($tagColor) . '"' : '';

            // Html::excerpt() already escapes via Html::trim(); do not esc() it again.
            $excerpt = Html::excerpt($r->content ?? null, 128);

            // A stat that is zero is omitted rather than printed. "0 replies" on
            // an unanswered thread is the tab's whole premise restated, and
            // "0 views" would be a claim we cannot make about imported rows.
            $stats = '';
            $replies = max(0, ((int) $r->comment_count) - 1);
            if ($replies > 0) {
                $stats .= '<span class="LmxFeed-stat" title="' . Html::esc($ctx->t('local-looksmax-index.forum.index.feed.replies')) . '">'
                    . Html::icon('ph:chat-circle-fill') . Html::num($replies) . '</span>';
            }
            if ((int) $r->view_count > 0) {
                $stats .= '<span class="LmxFeed-stat" title="' . Html::esc($ctx->t('local-looksmax-index.forum.index.feed.views')) . '">'
                    . Html::icon('ph:eye-fill') . Html::num((int) $r->view_count) . '</span>';
            }
            if (isset($r->score) && (int) $r->score > 0) {
                $stats .= '<span class="LmxFeed-stat LmxFeed-stat--hot" title="' . Html::esc($ctx->t('local-looksmax-index.forum.index.feed.reactions')) . '">'
                    . Html::icon('ph:heart-fill') . Html::num((int) $r->score) . '</span>';
            }

            $who = trim((string) ($r->last_username ?? $r->username ?? ''));

            $h .= '<li class="LmxFeed-item"' . $catVar . '>'
                . '<a class="LmxFeed-hit" href="' . Html::discussionUrl((int) $r->id, $r->slug) . '" tabindex="-1" aria-hidden="true"></a>'
                . '<div class="LmxFeed-main">'
                . '<div class="LmxFeed-top">'
                . ((int) ($r->is_sticky ?? 0) === 1 ? '<span class="LmxFeed-pin">' . Html::icon('ph:push-pin-fill') . '</span>' : '')
                . $chip
                . '<a class="LmxFeed-title" href="' . Html::discussionUrl((int) $r->id, $r->slug) . '">'
                . Html::trim((string) $r->title, 120) . '</a>'
                . '</div>'
                . ($excerpt !== '' ? '<p class="LmxFeed-excerpt">' . $excerpt . '</p>' : '')
                . '<div class="LmxFeed-meta">'
                . ($who !== '' ? '<a class="LmxFeed-who" href="/u/' . Html::esc($who) . '">' . Html::esc($who) . '</a>' : '')
                . '<span class="LmxFeed-when">' . Html::time($feed === 'new' ? $r->created_at : $r->last_posted_at) . '</span>'
                . $stats
                . '</div>'
                . '</div>'
                . '</li>';
        }

        return $h;
    }

    // --------------------------------------------------------------- queries

    /**
     * @return array<int, object>
     */
    private function rows(Context $ctx, string $feed): array
    {
        return $ctx->once('feed.' . $feed, function () use ($ctx, $feed) {
            $q = $ctx->db->table('discussions as d')
                ->leftJoin('users as lu', 'lu.id', '=', 'd.last_posted_user_id')
                ->whereNull('d.hidden_at')
                ->where('d.is_private', 0);

            $select = [
                'd.id', 'd.title', 'd.slug', 'd.comment_count', 'd.view_count',
                'd.created_at', 'd.last_posted_at', 'd.is_sticky',
                'lu.username as last_username',
            ];

            switch ($feed) {
                // Recent movement. The is_sticky+last_posted_at composite index
                // covers this exactly.
                case 'hot':
                    $q->orderByDesc('d.last_posted_at');
                    break;

                case 'new':
                    $q->orderByDesc('d.created_at');
                    break;

                // Biggest threads that are still alive. Bounded by a date range
                // first so this is a range scan and not a sort of 59,000 rows;
                // without the bound it returns the same six 2019 megathreads
                // forever, which is a hall of fame and not a feed.
                case 'top':
                    $q->where('d.last_posted_at', '>', date('Y-m-d H:i:s', strtotime('-120 days')))
                        ->orderByDesc('d.comment_count');
                    break;

                // Reception, from the imported reaction totals keyed on the
                // discussion's own first post.
                // Reception, from the imported reaction totals.
                //
                // Joined through the OPENING POST located by (discussion_id,
                // number = 1), not through discussions.first_post_id — that
                // column is NULL on 71,469 of 71,473 rows (see
                // attachExcerpts()). Joined on first_post_id this tab returned
                // zero rows and silently removed itself from the tab strip,
                // which is exactly the failure mode a "curated feeds" brief
                // cannot afford: the tab that is missing is the one nobody
                // notices is missing.
                //
                // Measured: 85,633 legacy_post_totals rows all resolve to a
                // local post id, 2,370 of them to an opening post, max score
                // 1,028. So this tab has real content behind it.
                case 'reacted':
                    $q->join('posts as fp', function ($j) {
                        $j->on('fp.discussion_id', '=', 'd.id')->where('fp.number', '=', 1);
                    })
                        ->join('legacy_post_totals as t', 't.post_id', '=', 'fp.id')
                        ->where('t.score', '>', 0)
                        ->orderByDesc('t.score');
                    $select[] = 't.score';
                    break;

                // comment_count counts the opening post, so 1 means nobody
                // answered. Verified against the data: 1,155 discussions have
                // comment_count = 1 and exactly one has 0.
                case 'unanswered':
                    $q->where('d.comment_count', '<=', 1)
                        ->orderByDesc('d.created_at');
                    break;

                // The reference shelf. Tag-backed rather than a heuristic, so it
                // is exactly what the board itself marked as a guide.
                //
                // whereIn on a SUBQUERY, never a join on discussion_tag.
                //
                // Operator report: "on guias in the home page there's 2 of every
                // item". Root cause measured, not guessed: three slugs qualify a
                // discussion as a guide (p-guide, mejores-guias, f-9) and
                //
                //   SELECT COUNT(*) FROM (SELECT discussion_id, COUNT(*) c
                //     FROM discussion_tag dt JOIN tags t ON t.id = dt.tag_id
                //     WHERE t.slug IN ('p-guide','mejores-guias','f-9')
                //     GROUP BY discussion_id HAVING c > 1) x;
                //   -> 2686
                //
                // 2,686 discussions carry two or three of them, so the join
                // returned each of those rows two or three times. There are ZERO
                // duplicate rows in discussion_tag itself (verified separately),
                // so the data is fine and the query was not. A subquery filters
                // without fanning out and needs no DISTINCT, which would also
                // have hidden the problem rather than removed it.
                case 'guides':
                    $q->whereIn('d.id', function ($s) {
                        $s->from('discussion_tag')
                            ->select('discussion_id')
                            ->whereIn('tag_id', function ($t) {
                                $t->from('tags')->select('id')
                                    ->whereIn('slug', ['p-guide', 'mejores-guias', 'f-9']);
                            });
                    })->orderByDesc('d.last_posted_at');
                    break;

                default:
                    return [];
            }

            $rows = $q->limit(self::ROWS)->get($select)->all();

            return $rows ? $this->attachTags($ctx, $this->attachExcerpts($ctx, $rows)) : [];
        });
    }

    /**
     * The opening post of each row, for the excerpt.
     *
     * ── Why this is not a join on first_post_id ─────────────────────────────
     *
     * Because that column is empty. Measured on the live database:
     *
     *     SELECT COUNT(*), SUM(first_post_id IS NULL) FROM discussions;
     *     -> 71473 total, 71469 NULL
     *
     * 99.99% of discussions have no `first_post_id`. The bulk import inserts
     * posts without going through the model events that maintain it, exactly as
     * it did for `users.discussion_count`. The first version of this block
     * joined on it and every excerpt came back empty — which looked like a
     * stripping bug in Html::excerpt() and was not.
     *
     * So the opening post is found by (discussion_id, number = 1), which is a
     * unique index Flarum ships (posts_discussion_id_number_unique). One query
     * for the whole tab, ten ids, index lookup.
     *
     * This is a workaround for a defect OUTSIDE this extension and is reported
     * as such — see the handoff note. Fixing the column would also fix core
     * features that read it.
     */
    private function attachExcerpts(Context $ctx, array $rows): array
    {
        $ids = array_map(fn ($r) => (int) $r->id, $rows);

        $first = $ctx->db->table('posts')
            ->whereIn('discussion_id', $ids)
            ->where('number', 1)
            ->whereNull('hidden_at')
            ->get(['discussion_id', 'content'])
            ->keyBy('discussion_id');

        foreach ($rows as $r) {
            $r->content = $first[(int) $r->id]->content ?? null;
        }

        return $rows;
    }

    /**
     * The category chip for each row, in ONE query for the whole feed.
     *
     * Per-row this would be ten queries per tab and sixty per page. The tag
     * chosen for the chip is the most specific one the discussion carries: a
     * section tag if it has one (those are the names the front page teaches),
     * otherwise its smallest tag by discussion_count, because the smallest tag
     * is the most informative one — "Serious" on a board where 18,409 threads
     * are Serious tells the reader nothing.
     */
    private function attachTags(Context $ctx, array $rows): array
    {
        $ids = array_map(fn ($r) => (int) $r->id, $rows);

        $sectionSlugs = array_keys(Sections::bySlug());

        $pairs = $ctx->db->table('discussion_tag as dt')
            ->join('tags as t', 't.id', '=', 'dt.tag_id')
            ->whereIn('dt.discussion_id', $ids)
            ->get(['dt.discussion_id', 't.name', 't.slug', 't.color', 't.discussion_count']);

        $best = [];
        foreach ($pairs as $p) {
            $d = (int) $p->discussion_id;
            $isSection = in_array($p->slug, $sectionSlugs, true);
            $cur = $best[$d] ?? null;
            if ($cur === null) {
                $best[$d] = $p;
                continue;
            }
            $curIsSection = in_array($cur->slug, $sectionSlugs, true);
            if ($isSection && ! $curIsSection) {
                $best[$d] = $p;
            } elseif ($isSection === $curIsSection && (int) $p->discussion_count < (int) $cur->discussion_count) {
                $best[$d] = $p;
            }
        }

        foreach ($rows as $r) {
            $t = $best[(int) $r->id] ?? null;
            $r->tag_name = $t?->name;
            $r->tag_slug = $t?->slug;
            $r->tag_color = $t?->color;
        }

        return $rows;
    }
}
