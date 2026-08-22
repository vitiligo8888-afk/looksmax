<?php

namespace Local\Welcome;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * ONE place that decides whether the first-run survey runs at all.
 *
 * Deliberately a single key. Every other extension's Config in this codebase
 * (userinfo, economy) carries a dozen knobs because their surfaces have a dozen
 * genuine style/behaviour decisions an operator might want to move. This
 * surface does not: the six sections and six intents are the operator's own
 * words (see Survey.php) and are not meant to be admin-editable text, the
 * copy lives in locale/*.yml like every other string on this forum, and the
 * one thing an operator legitimately wants at 3am is a kill switch — so that
 * is the one thing exposed. Adding more knobs later (a delay, a reshow
 * cooldown) is a one-line addition to KEYS, not a redesign.
 *
 * Read through SettingsRepositoryInterface as `welcome.<key>`, exposed on the
 * forum payload as `lmxWelcome.enabled` (extend.php) and editable from the
 * admin panel (js/dist/admin.js registers the control) — same shape as
 * looksmax-userinfo/src/Config.php.
 */
class Config
{
    /** @var array<string, array{0: mixed, 1: string}> key => [default, cast] */
    public const KEYS = [
        // The kill switch. Off means: no new pending rows are surfaced (the
        // client never shows the overlay), the write endpoints 404 rather than
        // silently accepting answers nobody can see themselves give, and the
        // admin stats endpoint keeps working — historical answers stay
        // queryable even while collection is paused.
        'enabled' => [true, 'bool'],
    ];

    public static function all(SettingsRepositoryInterface $settings): array
    {
        $out = [];

        foreach (self::KEYS as $key => [$default, $cast]) {
            $raw = $settings->get('welcome.' . $key);
            $out[$key] = ($raw === null || $raw === '') ? $default : self::cast($raw, $cast, $default);
        }

        return $out;
    }

    private static function cast($raw, string $cast, $default)
    {
        if ($cast === 'bool') {
            // A checkbox stores "1"/"0"; a hand-edited row may hold "true".
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $default;
        }

        return $raw;
    }
}
