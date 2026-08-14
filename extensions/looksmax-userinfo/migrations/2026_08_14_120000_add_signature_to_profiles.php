<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Per-user post signature.
 *
 * Lives on userinfo_profiles rather than on `users` for the same reason
 * legacy_title does: this extension already owns a row per user, already has it
 * eager-loaded onto every post author (extend.php's ApiController->load calls),
 * and already serialises it through Presenter::user. Adding a column here makes
 * the signature ride the payload the post stream ALREADY fetches — zero extra
 * queries per post — where a column on `users` would still have needed the same
 * relation to reach the stream.
 *
 * TEXT, not string(191). A signature is prose with line breaks; the length that
 * actually applies is `userinfo.sigMaxLength`, enforced on write in
 * SignatureController so the limit is configurable without a migration.
 *
 * STORED AS PLAIN TEXT. It is escaped at render (the decorator writes
 * textContent, never innerHTML) and stripped of tags on write. A signature is
 * attacker-controlled content that renders under EVERY post its author has ever
 * made, which makes it the highest-leverage stored-XSS target on the forum —
 * one payload, thousands of impressions. Rich formatting is deliberately not
 * offered here rather than offered and sanitised.
 */
return Migration::addColumns('userinfo_profiles', [
    'signature' => ['text', 'nullable' => true],
]);
