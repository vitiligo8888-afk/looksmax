<?php

/**
 * Write the forum's identity into the database.
 *
 * Idempotent, and run from inside the app container:
 *
 *   docker exec flarum-app php /flarum/extensions/looksmax-brand/tools/apply-identity.php
 *   docker exec flarum-app php /flarum/extensions/looksmax-brand/tools/apply-identity.php --check
 *
 * Written as a script rather than a migration on purpose. Migrations run once
 * and are then recorded as done; forum copy, the logo and the admin address are
 * things that get edited in the admin panel and then need re-asserting, and a
 * migration that has already run cannot do that. --check reports drift without
 * writing, which is what the verification step calls.
 *
 * Settings are written through Flarum's repository rather than with UPDATE
 * statements so the settings cache is invalidated; a direct write leaves the
 * old value being served out of cache until something else clears it.
 */

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

require '/flarum/app/vendor/autoload.php';

$site = require '/flarum/app/site.php';
$app = $site->bootApp();
$container = $app->getContainer();
/** @var SettingsRepositoryInterface $settings */
$settings = $container->make(SettingsRepositoryInterface::class);

$check = in_array('--check', $argv, true);

const ASSETS = 'extensions/local-looksmax-brand';

$WANT = [
    'forum_title' => 'Looksmax.lat',

    // Also the meta description and, through Head.php, og:description and
    // twitter:description. One sentence saying what the place is, one saying
    // what is expected of you.
    'forum_description' => 'Face and body ratings, and the guides behind them. Read the guides before you post, and expect to be argued with.',

    'welcome_title' => 'Looksmax.lat',
    'welcome_message' => 'Face and body ratings, guides and protocols, with the evidence attached. Read the guides before you post, and rate someone before you ask to be rated.',

    // Header lockup and browser icon. Both resolve through the assets
    // filesystem, so these are paths relative to public/assets, which is where
    // the extension's assets/ directory is published to.
    'logo_path' => ASSETS.'/mark-lmx-v3.svg',
    'favicon_path' => ASSETS.'/favicon.ico',

    // The brass, and the surface behind the header. theme_primary_color is
    // read by core for the theme-color meta tag; Head.php overrides that with
    // the header surface, because a gold browser chrome above a near-black
    // page is not the brand.
    'theme_primary_color' => '#e8c07d',
    'theme_secondary_color' => '#12161c',
    'theme_dark_mode' => '1',
    'theme_colored_header' => '0',

    // Transactional mail. The default is noreply@localhost, which is both
    // off-brand and undeliverable.
    'mail_from' => 'noreply@looksmax.lat',
];

$ADMIN_EMAIL = 'admin@looksmax.lat';

$drift = [];
foreach ($WANT as $key => $value) {
    $current = $settings->get($key);
    if ((string) $current === (string) $value) {
        continue;
    }
    $drift[] = [$key, $current, $value];
    if (! $check) {
        $settings->set($key, $value);
    }
}

// The admin account's address. Installed as admin@example.com by the
// entrypoint's default, which is what the forum sends "reply to this" from.
$admin = User::where('username', 'admin')->first();
if ($admin && $admin->email !== $ADMIN_EMAIL) {
    $drift[] = ['users.admin.email', $admin->email, $ADMIN_EMAIL];
    if (! $check) {
        $admin->email = $ADMIN_EMAIL;
        $admin->is_email_confirmed = true;
        $admin->save();
    }
}

if (! $drift) {
    echo "identity: already correct\n";
    exit(0);
}

echo ($check ? "identity: DRIFT\n" : "identity: applied\n");
foreach ($drift as [$key, $was, $now]) {
    printf("  %-24s %s -> %s\n", $key,
        var_export($was === null ? null : mb_strimwidth((string) $was, 0, 46, '…'), true),
        mb_strimwidth((string) $now, 0, 46, '…'));
}

exit($check ? 1 : 0);
