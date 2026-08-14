<?php

namespace Local\UserInfo;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * ONE place that decides how the identity surface behaves and looks.
 *
 * Every call site of the hover card — post author, mention, chat, member list,
 * index last-poster, notification, search result, leaderboard — reads its
 * behaviour from the single object this class emits on the forum payload as
 * `lmxUserInfo`. There is deliberately no per-call-site option anywhere in the
 * JS: a card opened from the shoutbox and a card opened from a post mention are
 * the same component with the same configuration, because the operator's
 * complaint was precisely that they were not.
 *
 * Each key is a Flarum setting (`userinfo.<key>`), so it is editable from the
 * admin panel (js/dist/admin.js registers the controls) and from
 * `php flarum` / the settings table, and it survives a redeploy. A key that has
 * never been set falls back to the default below — the defaults are the product
 * decision, the settings are the escape hatch.
 *
 * ── Why this is not a bag of CSS ────────────────────────────────────────────
 * Style is configurable through TOKENS, not through arbitrary declarations.
 * `--lmx-card-w`, `--lmx-card-radius`, `--lmx-card-surface` and friends are
 * declared by less/forum.less with the theme's own custom properties as their
 * defaults, and the only thing this config does is override the handful that a
 * forum operator actually wants to move. Letting an admin paste CSS here would
 * be the fastest possible way to break the theme's contrast guarantees.
 */
class Config
{
    /**
     * @var array<string, array{0: mixed, 1: string}> key => [default, cast]
     */
    public const KEYS = [
        // --- behaviour -----------------------------------------------------
        // How a card is opened on a pointer device. Touch is decided by the
        // browser, not by this: see `mobile`.
        'trigger' => ['hover', 'enum:hover,click'],
        'openDelay' => [320, 'int'],
        'closeDelay' => [220, 'int'],
        // 'auto' flips to whichever side has room. 'bottom'/'top' pin it and
        // still clamp inside the viewport, because a card off-screen is worse
        // than a card on the "wrong" side.
        'placement' => ['auto', 'enum:auto,bottom,top'],
        'followScroll' => [true, 'bool'],

        // --- responsive ----------------------------------------------------
        // A hover card is wrong on a touch screen. Below the breakpoint the
        // same component renders as a tap-to-open bottom sheet.
        'mobile' => ['sheet', 'enum:sheet,popover,off'],
        'mobileBreakpoint' => [767, 'int'],

        // --- style ---------------------------------------------------------
        'width' => [340, 'int'],
        'avatarSize' => [64, 'int'],

        // --- content -------------------------------------------------------
        // Ordered, comma-separated. An unknown token is ignored rather than
        // throwing, so a stale setting cannot blank the card.
        'fields' => [
            'avatar,name,rank,title,groups,banners,presence,joined,posts,threads,reactions,perDay,bestPost,mix,legacy',
            'csv',
        ],
        // Order is the order they render in. Every one of them is additionally
        // permission-gated at render time — listing an action here does not
        // make it appear for someone who cannot perform it.
        'actions' => ['message,mention,profile,posts,report,suspend,edit', 'csv'],

        // --- the post author rail ------------------------------------------
        'railEnabled' => [true, 'bool'],
        'railWidth' => [200, 'int'],

        // --- direct messages ------------------------------------------------
        'dmEnabled' => [true, 'bool'],
        'dmMaxLength' => [8000, 'int'],
        'dmMaxRecipients' => [10, 'int'],

        // --- signatures -----------------------------------------------------
        // Shown under every post by an author. Off by default: a signature is
        // the only surface on the forum where one user's content is injected
        // into thousands of other users' page views, so turning it on should be
        // a decision rather than an inheritance.
        'sigEnabled' => [false, 'bool'],
        // Enforced on WRITE (SignatureController), not on render, so lowering it
        // later cannot retroactively truncate someone's saved text into
        // nonsense — existing signatures keep rendering until next edited.
        'sigMaxLength' => [280, 'int'],
        // Line count is capped separately from character count. 280 characters
        // of "\n" is a legal 280-character string that would push every post in
        // the thread a screen apart.
        'sigMaxLines' => [4, 'int'],
        // Minimum account age in days before a signature renders. The reason is
        // spam: a signature is a free backlink on every post, which is exactly
        // what a throwaway account wants. 0 disables the gate.
        'sigMinAccountDays' => [7, 'int'],
    ];

    public static function all(SettingsRepositoryInterface $settings): array
    {
        $out = [];

        foreach (self::KEYS as $key => [$default, $cast]) {
            $raw = $settings->get('userinfo.' . $key);
            $out[$key] = ($raw === null || $raw === '') ? $default : self::cast($raw, $cast, $default);
        }

        return $out;
    }

    private static function cast($raw, string $cast, $default)
    {
        if ($cast === 'int') {
            return (int) $raw;
        }
        if ($cast === 'bool') {
            // A checkbox stores "1"/"0"; a hand-edited row may hold "true".
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $default;
        }
        if ($cast === 'csv') {
            $list = array_values(array_filter(array_map('trim', explode(',', (string) $raw))));

            return $list ? implode(',', $list) : $default;
        }
        if (str_starts_with($cast, 'enum:')) {
            $allowed = explode(',', substr($cast, 5));

            return in_array((string) $raw, $allowed, true) ? (string) $raw : $default;
        }

        return $raw;
    }
}
