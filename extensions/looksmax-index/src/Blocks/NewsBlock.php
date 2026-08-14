<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;
use Local\Index\Rails;

/**
 * News and announcements, above the sections, across the full content width.
 *
 * ── Why it is tag-backed and not an admin surface ───────────────────────────
 *
 * The board already has `f-11 News & Announcements` with 49 discussions in it,
 * posted by the people who would be writing the news. A tag-backed block
 * therefore:
 *
 *   - has real content the moment it ships, with no seeding and no migration;
 *   - needs no authoring UI at all — staff publish by posting a thread, which
 *     they already know how to do, and edit by editing the post;
 *   - inherits moderation, permissions, editing, history and the ability to
 *     DISCUSS an announcement, none of which an admin textarea has;
 *   - orders itself: Flarum's sticky flag is already the "pin this" control.
 *
 * An admin surface would only have won on ordering and on writing copy that is
 * not also a thread — and the price is a second authoring path that no one uses
 * and that goes stale. The one thing it would have won is configurability, and
 * that is recovered by making the SOURCE a setting rather than a constant:
 * `looksmax-index.news_tag` (slug, default `f-11`) and `looksmax-index.news_limit`
 * (default 4). Point it at a different tag and the block follows.
 *
 * ── It has to read as news ──────────────────────────────────────────────────
 *
 * A title and a link is a pinned-thread list with a heading on it. Each item
 * carries: the date, the author, an excerpt of the announcement itself (the
 * first post, with the bbcode source and images stripped — see Html::excerpt),
 * the reply count as a proxy for "is this being argued about", and a NEW flag
 * when the reader has not read it.
 *
 * ── The three states are the deliverable ────────────────────────────────────
 *
 * TEN items: a scrolling row of cards, three-and-a-bit visible, the rest reached
 * by dragging — the band never grows past one row's height whatever the count.
 * ONE item: the row becomes a single wide card that uses the full width with a
 * longer excerpt, rather than one narrow card marooned beside empty space.
 * NONE: an explicit empty state with what the block is for, so an admin who has
 * pointed it at the wrong tag can see that it is working and empty rather than
 * broken. It renders from the same query, which returns nothing.
 *
 * ── Dismissal ───────────────────────────────────────────────────────────────
 *
 * Per user, persisted as `lmxNewsSeen` = the highest discussion id the reader
 * has dismissed (localStorage for guests). Dismissing collapses the band to a
 * one-line bar that can be re-opened; a NEWER announcement re-opens it by
 * itself, because the point is that a daily reader stops seeing the same three
 * announcements, not that they stop seeing announcements.
 */
class NewsBlock extends AbstractBlock
{
    public const TAG_SETTING = 'looksmax-index.news_tag';
    public const LIMIT_SETTING = 'looksmax-index.news_limit';

    public function id(): string
    {
        return 'news';
    }

    public function icon(): ?string
    {
        return 'ph:megaphone-fill';
    }

    public function defaultSide(): string
    {
        // The MAIN column, under the feed — not the top band.
        //
        // Operator: "and news and shit on top too ofc", and separately "not
        // polluted by bs banners". Both are satisfiable: news IS content, so it
        // stays high, but it goes in the content column under the live feed
        // rather than in a full-width band above everything. Measured before
        // this: the news band was the first thing under two heroes and its
        // three items were the ONLY content in the 1440x900 fold, at y=670.
        return Rails::SIDE_MAIN;
    }

    public function defaultPosition(): int
    {
        return 200;   // feed 10, sections 100, news 200, browse 300
    }

    public function render(Context $ctx): ?string
    {
        $items = $this->items($ctx);

        $newest = 0;
        foreach ($items as $i) {
            $newest = max($newest, (int) $i->id);
        }

        $seen = (int) ($ctx->isGuest() ? 0 : ($ctx->actor->getPreference('lmxNewsSeen') ?: 0));
        $collapsed = $newest > 0 && $seen >= $newest;

        $body = $items
            ? '<div class="LmxNews-row" data-count="' . count($items) . '">'
                . implode('', array_map(fn ($i) => $this->item($ctx, $i, count($items) === 1), $items))
                . '</div>'
            : $this->empty($ctx);

        $action = '<div class="LmxNews-actions">'
            . ($items ? '<button type="button" class="LmxIconBtn" data-lmx-news-dismiss="' . $newest . '"'
                . ' title="' . Html::esc($ctx->t('local-looksmax-index.forum.index.news.dismiss')) . '"'
                . ' aria-label="' . Html::esc($ctx->t('local-looksmax-index.forum.index.news.dismiss')) . '">'
                . Html::icon('ph:eye-slash-bold') . '</button>' : '')
            . '<a class="LmxNews-all" href="/t/' . Html::esc($this->tagSlug($ctx)) . '">'
            . Html::esc($ctx->t('local-looksmax-index.forum.index.news.more')) . Html::icon('ph:caret-right-bold') . '</a>'
            . '</div>';

        return '<section class="LmxNews' . ($collapsed ? ' is-collapsed' : '') . ($items ? '' : ' is-empty') . '"'
            . ' data-block="news" data-newest="' . $newest . '">'
            . '<header class="LmxNews-head">'
            . Html::icon('ph:megaphone-fill', 'LmxNews-glyph')
            . '<h2 class="LmxNews-title">' . Html::esc($ctx->t('local-looksmax-index.forum.index.news.heading')) . '</h2>'
            . $action
            . '</header>'
            . $body
            // The re-open bar. Rendered always and hidden by the stylesheet
            // unless collapsed, so dismissing is instant and does not need the
            // block re-rendered from the server.
            . '<button type="button" class="LmxNews-reopen" data-lmx-news-open>'
            . Html::icon('ph:megaphone-fill')
            . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.news.show')) . '</span>'
            . '</button>'
            . '</section>';
    }

    private function tagSlug(Context $ctx): string
    {
        return (string) ($ctx->settings->get(self::TAG_SETTING) ?: 'f-11');
    }

    /**
     * @return object[] newest first, stickies first
     */
    private function items(Context $ctx): array
    {
        return $ctx->once('news.items', function () use ($ctx) {
            $limit = max(1, min(20, (int) ($ctx->settings->get(self::LIMIT_SETTING) ?: 4)));

            $tagId = $ctx->db->table('tags')->where('slug', $this->tagSlug($ctx))->value('id');
            if (! $tagId) {
                return [];
            }

            $q = $ctx->db->table('discussions')
                ->join('discussion_tag', 'discussion_tag.discussion_id', '=', 'discussions.id')
                ->leftJoin('users', 'users.id', '=', 'discussions.user_id')
                ->where('discussion_tag.tag_id', $tagId)
                ->whereNull('discussions.hidden_at')
                ->orderByDesc('discussions.is_sticky')
                ->orderByDesc('discussions.created_at')
                ->limit($limit);

            // The column list is set HERE, not passed to get(). `get($columns)`
            // only applies its argument when no select has been set yet, so the
            // conditional addSelect() below silently made `read_to` the ONLY
            // selected column for logged-in readers: every row came back missing
            // id, title, slug, created_at, comment_count, is_sticky and username.
            // Guests were fine, because that branch never runs — so the front
            // page rendered perfectly logged out and fataled for members with
            // "Undefined property: stdClass::$id" followed by an EmitterException
            // once the warnings had been printed ahead of the headers.
            $q->select([
                'discussions.id', 'discussions.title', 'discussions.slug', 'discussions.created_at',
                'discussions.comment_count', 'discussions.is_sticky', 'users.username',
            ]);

            // Read state, so "NEW" means new to THIS reader rather than new in
            // absolute terms. Guests get no flags at all rather than everything
            // flagged, which would make the flag meaningless.
            if (! $ctx->isGuest()) {
                $q->leftJoin('discussion_user', function ($j) use ($ctx) {
                    $j->on('discussion_user.discussion_id', '=', 'discussions.id')
                        ->where('discussion_user.user_id', '=', $ctx->actor->id);
                })->addSelect('discussion_user.last_read_post_number as read_to');
            }

            $rows = $q->get();

            if ($rows->isEmpty()) {
                return [];
            }

            $bodies = $ctx->db->table('posts')
                ->whereIn('discussion_id', $rows->pluck('id')->all())
                ->where('type', 'comment')->where('number', 1)
                ->pluck('content', 'discussion_id');

            foreach ($rows as $r) {
                $r->excerpt = $bodies[$r->id] ?? '';
                $r->is_new = ! $ctx->isGuest() && (($r->read_to ?? null) === null);
            }

            return $rows->all();
        });
    }

    private function item(Context $ctx, object $r, bool $solo): string
    {
        return '<article class="LmxNewsItem' . ($solo ? ' is-solo' : '') . ($r->is_sticky ? ' is-pinned' : '') . '">'
            . '<a class="LmxNewsItem-hit" href="' . Html::discussionUrl((int) $r->id, $r->slug) . '"></a>'
            . '<div class="LmxNewsItem-top">'
            . ($r->is_new ? '<span class="LmxBadge LmxBadge--new">' . Html::esc($ctx->t('local-looksmax-index.forum.index.news.new_badge')) . '</span>' : '')
            . ($r->is_sticky ? '<span class="LmxBadge LmxBadge--pin">' . Html::icon('ph:push-pin-fill') . '</span>' : '')
            . '<span class="LmxNewsItem-date">' . Html::time($r->created_at) . '</span>'
            . '</div>'
            . '<h3 class="LmxNewsItem-title">' . Html::trim($r->title, $solo ? 120 : 72) . '</h3>'
            . '<p class="LmxNewsItem-excerpt">' . Html::excerpt($r->excerpt, $solo ? 320 : 150) . '</p>'
            . '<div class="LmxNewsItem-foot">'
            . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.news.by')) . ' ' . Html::trim((string) $r->username, 22) . '</span>'
            . '<span>' . Html::icon('ph:chat-circle-fill') . Html::num((int) $r->comment_count) . '</span>'
            . '</div>'
            . '</article>';
    }

    private function empty(Context $ctx): string
    {
        return '<div class="LmxNews-empty">'
            . Html::icon('ph:newspaper-fill', 'LmxNews-emptyGlyph')
            . '<p>' . Html::esc($ctx->t('local-looksmax-index.forum.index.news.empty')) . '</p>'
            . '</div>';
    }
}
