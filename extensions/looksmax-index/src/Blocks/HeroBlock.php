<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;
use Local\Index\Rails;

/**
 * The two-second answer.
 *
 * Someone landing cold has to learn what this forum is and where to go before
 * they decide to leave, and the previous front page opened with a category
 * header called "Looksmax" above nineteen rows — which tells a stranger nothing
 * and a returning member nothing they did not know.
 *
 * ── Logged out and logged in are different pages, not the same page ─────────
 *
 * GUEST: one sentence of what the board is, the board's real size as social
 * proof (a forum with 7,000 threads is worth registering for; the number is
 * measured, not claimed), and exactly TWO buttons — join, or look around first.
 * Two targets, both large. A guest who has to choose between six links has been
 * given a decision instead of a direction.
 *
 * MEMBER: the greeting is not the point; what moved since they last looked is.
 * So the member hero is their own unread count, their post count and a single
 * primary action — start a thread — because a member who is already here does
 * not need to be sold the forum again.
 */
class HeroBlock extends AbstractBlock
{
    public function id(): string
    {
        return 'hero';
    }

    public function titleKey(): ?string
    {
        return null;
    }

    /**
     * THE RIGHT RAIL, not the top of the page.
     *
     * Operator: "not polluted by bs banners and 'welcome back' shit ... just
     * rich feed and shit", and "Keep positioning/signup available but out of the
     * fold". Measured before this moved: at 1440x900 the guest hero occupied
     * y=430..640 of the main column and pushed the first thread to y=749; at
     * 390x844 it took 310px of an 844px fold. As a rail card it is 210px wide,
     * still on the first screen, and costs the feed nothing.
     */
    public function defaultSide(): string
    {
        return Rails::SIDE_RIGHT;
    }

    /** First card in the rail: it is still the most important thing for a guest. */
    public function defaultPosition(): int
    {
        return 5;
    }

    public function render(Context $ctx): ?string
    {
        // A logged-in member gets NOTHING here. The member hero was a greeting,
        // an unread count and a "start a thread" button — the operator named it
        // directly ("'welcome back' shit"), and every one of those three things
        // already exists in the chrome: the session menu, the notification
        // badge, and the composer button. Returning null removes the block
        // entirely rather than rendering an empty card.
        return $ctx->isGuest() ? $this->guest($ctx) : null;
    }

    private function guest(Context $ctx): string
    {
        $stats = $this->stats($ctx);

        $stat = fn (string $k, int $v) => '<div class="LmxHero-stat"><b>' . Html::num($v) . '</b>'
            . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.stats.' . $k)) . '</span></div>';

        return '<section class="LmxHero" data-block="hero" data-state="guest">'
            . '<div class="LmxHero-copy">'
            . '<h1 class="LmxHero-title">' . Html::esc($ctx->t('local-looksmax-index.forum.index.hero.title')) . '</h1>'
            . '<p class="LmxHero-lede">' . Html::esc($ctx->t('local-looksmax-index.forum.index.hero.lede')) . '</p>'
            . '<div class="LmxHero-cta">'
            . '<button type="button" class="LmxBtn LmxBtn--primary" data-lmx-signup>'
            . Html::icon('ph:user-plus-fill')
            . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.hero.cta_join')) . '</span></button>'
            . '<a class="LmxBtn LmxBtn--ghost" href="#lmx-sections">'
            . Html::icon('ph:compass-fill')
            . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.hero.cta_browse')) . '</span></a>'
            . '</div>'
            . '</div>'
            . '<div class="LmxHero-stats">'
            . $stat('threads', $stats['discussions'])
            . $stat('posts', $stats['posts'])
            . $stat('members', $stats['members'])
            . '</div>'
            . '</section>';
    }

    private function member(Context $ctx): string
    {
        $unread = $ctx->once('hero.unread', function () use ($ctx) {
            // Threads with activity the reader has not seen. Counted, not
            // listed, and capped: an accurate 4,000 is the same information as
            // "lots" and costs a table scan to produce.
            return (int) $ctx->db->table('discussions')
                ->leftJoin('discussion_user', function ($j) use ($ctx) {
                    $j->on('discussion_user.discussion_id', '=', 'discussions.id')
                        ->where('discussion_user.user_id', '=', $ctx->actor->id);
                })
                ->whereNull('discussions.hidden_at')
                ->where('discussions.last_posted_at', '>', date('Y-m-d H:i:s', strtotime('-7 days')))
                ->whereRaw('COALESCE(discussion_user.last_read_post_number, 0) < discussions.comment_count')
                ->limit(500)
                ->count();
        });

        $name = (string) $ctx->actor->username;

        return '<section class="LmxHero LmxHero--member" data-block="hero" data-state="member">'
            . '<div class="LmxHero-copy">'
            . '<h1 class="LmxHero-title">' . Html::esc($ctx->t('local-looksmax-index.forum.index.hero.member_greeting', ['{username}' => $name])) . '</h1>'
            . '<p class="LmxHero-lede">'
            . Html::esc($ctx->t('local-looksmax-index.forum.index.hero.member_lede', ['{count}' => Html::num($unread)]))
            . '</p>'
            . '<div class="LmxHero-cta">'
            . '<button type="button" class="LmxBtn LmxBtn--primary" data-lmx-compose>'
            . Html::icon('ph:note-pencil-fill')
            . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.hero.cta_post')) . '</span></button>'
            . '<a class="LmxBtn LmxBtn--ghost" href="/all?sort=newest">'
            . Html::icon('ph:clock-fill')
            . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.hero.cta_unread')) . '</span></a>'
            . '</div>'
            . '</div>'
            . '</section>';
    }

    /** Shared with StatsBlock — one set of counts per request, not two. */
    private function stats(Context $ctx): array
    {
        return $ctx->once('board.stats', fn () => [
            'discussions' => (int) $ctx->db->table('discussions')->count(),
            'posts' => (int) $ctx->db->table('posts')->count(),
            'members' => (int) $ctx->db->table('users')->count(),
        ]);
    }
}
