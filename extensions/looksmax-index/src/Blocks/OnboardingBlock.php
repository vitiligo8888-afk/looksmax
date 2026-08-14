<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;
use Local\Index\Rails;

/**
 * For someone who has never used a forum.
 *
 * The old front page's version of this was a card headed "Before you post" with
 * a paragraph of prose in it — which is a rule, not an onboarding. A stranger
 * does not need the etiquette before they have decided to participate; they need
 * to know that this thing has three moves and that they can make all three.
 *
 * So: three numbered steps, each one a verb and a target they can click. It is
 * shown to guests and to members whose account is younger than a week, and it
 * disappears by itself after that — an onboarding that a two-year member still
 * sees is a permanent advert for something they have already done.
 *
 * Dismissible, persisted the same way the layout switch is.
 */
class OnboardingBlock extends AbstractBlock
{
    /** Members past this are not new any more and stop seeing it. */
    private const NEW_FOR_DAYS = 7;

    public function id(): string
    {
        return 'onboarding';
    }

    public function icon(): ?string
    {
        return 'ph:signpost-fill';
    }

    public function defaultSide(): string
    {
        return Rails::SIDE_LEFT;
    }

    public function defaultPosition(): int
    {
        return 20;
    }

    public function render(Context $ctx): ?string
    {
        if (! $this->shouldShow($ctx)) {
            return null;
        }

        $steps = '';
        foreach ([
            ['1', '#lmx-sections', 'ph:target-fill'],
            ['2', '/t/mejores-guias', 'ph:book-open-text-fill'],
            ['3', '/t/looksmaxing', 'ph:hand-pointing-fill'],
        ] as [$n, $href, $icon]) {
            $steps .= '<li class="LmxStep">'
                . '<a href="' . Html::esc($href) . '">'
                . '<span class="LmxStep-n">' . $n . '</span>'
                . '<span class="LmxStep-body">'
                . '<b>' . Html::esc($ctx->t('local-looksmax-index.forum.index.onboarding.step' . $n . '_title')) . '</b>'
                . '<span>' . Html::esc($ctx->t('local-looksmax-index.forum.index.onboarding.step' . $n . '_body')) . '</span>'
                . '</span>'
                . Html::icon($icon, 'LmxStep-glyph')
                . '</a></li>';
        }

        $dismiss = '<button type="button" class="LmxIconBtn" data-lmx-dismiss="onboarding"'
            . ' aria-label="' . Html::esc($ctx->t('local-looksmax-index.forum.index.onboarding.dismiss')) . '">'
            . Html::icon('ph:x-bold') . '</button>';

        return $this->card($ctx, '<ol class="LmxSteps">' . $steps . '</ol>', 'LmxCard--onboarding', $dismiss);
    }

    private function shouldShow(Context $ctx): bool
    {
        if ($ctx->isGuest()) {
            return true;
        }

        if ($ctx->actor->getPreference('lmxOnboardingDone')) {
            return false;
        }

        $joined = $ctx->actor->joined_at;

        return ! $joined || $joined->diffInDays(now()) < self::NEW_FOR_DAYS;
    }
}
