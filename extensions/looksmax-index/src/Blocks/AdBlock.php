<?php

namespace Local\Index\Blocks;

use Local\Index\Context;
use Local\Index\Html;

/**
 * One reserved inventory slot, 300 × 250, that never moves the page.
 *
 * ── The whole point is the reservation ──────────────────────────────────────
 *
 * The box is declared at its final size in the stylesheet — `height: 250px`, not
 * `min-height`, not `aspect-ratio`, not "whatever the creative turns out to be".
 * So the slot occupies exactly the same space empty, filled, and failed, and
 * nothing below it reflows when a creative arrives late or never arrives. This
 * is the cheapest layout-shift bug there is to avoid and the most annoying one
 * to experience.
 *
 * ── And no network request unless there is something to fetch ───────────────
 *
 * With no creative configured the block renders a labelled placeholder and
 * requests nothing at all — no script tag, no pixel, no third party told that
 * this page was loaded. `looksmax-index.ad_html` holds the creative when there
 * is one; until an operator puts something there, this costs a visitor nothing.
 */
class AdBlock extends AbstractBlock
{
    public const HTML_SETTING = 'looksmax-index.ad_html';
    public const WIDTH = 300;
    public const HEIGHT = 250;

    public function id(): string
    {
        return 'ad';
    }

    public function titleKey(): ?string
    {
        return null;
    }

    public function defaultPosition(): int
    {
        return 200;
    }

    public function render(Context $ctx): ?string
    {
        $creative = trim((string) ($ctx->settings->get(self::HTML_SETTING) ?: ''));

        $inner = $creative !== ''
            // Trusted by definition: only an administrator can write this
            // setting, and an ad creative is markup by nature. It is not user
            // input and is deliberately not escaped.
            ? $creative
            : '<div class="LmxAd-placeholder">' . self::WIDTH . ' × ' . self::HEIGHT . '</div>';

        return '<section class="LmxCard LmxCard--ad" data-block="ad" data-ad-slot="rail-1">'
            . '<span class="LmxAd-label">' . Html::esc($ctx->t('local-looksmax-index.forum.index.ad.label')) . '</span>'
            . '<div class="LmxAd-slot" style="width:' . self::WIDTH . 'px;height:' . self::HEIGHT . 'px">'
            . $inner
            . '</div>'
            . '</section>';
    }
}
