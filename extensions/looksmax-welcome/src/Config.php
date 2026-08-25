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

        // Confirm a new account's email address at registration instead of
        // waiting for the click in a mail nobody receives.
        //
        // Measured on this install 2026-08-25: `mail_driver` is `mail`, and
        // PHP's sendmail_path points at /usr/sbin/sendmail, which here is a
        // symlink to busybox. Busybox's sendmail relays to 127.0.0.1:25 and
        // nothing listens there, so every message Flarum has ever sent failed
        // with "Connection refused" — silently, because mail() only returns a
        // boolean nobody checks. Confirmation mail is therefore not late, it
        // is impossible, and an account that cannot confirm never reaches the
        // Member group and so cannot post. Registration was open and
        // completely non-functional.
        //
        // This is the stopgap for that, and it is deliberately a SETTING and
        // not a code change: the day a real SMTP relay exists, turning this off
        // restores ordinary verification with no deploy. Default is false so
        // that a correctly-configured install never silently skips
        // verification — it is switched on per-install, on purpose.
        //
        // Understand the trade before leaving it on: nothing proves the address
        // belongs to the person, so throwaway signups get in, and password
        // reset stays broken regardless (that mail cannot be delivered either).
        // It buys a working front door, not a working mailbox.
        'autoConfirm' => [false, 'bool'],
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
