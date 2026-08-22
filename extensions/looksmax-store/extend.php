<?php

use Flarum\Api\Controller\ListDiscussionsController;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Extend;
use Local\Store\Api\StoreActionController;
use Local\Store\Api\StoreController;
use Local\Store\Console;
use Local\Store\InjectAdminScript;
use Local\Store\Listeners;

/**
 * The store.
 *
 * It exists because the header showed a credit balance and linked it to
 * `/store`, and `/store` was a 404. A balance you cannot spend is not an
 * economy, it is a score with an extra step.
 *
 * Division of labour with the two extensions either side of it:
 *
 *   looksmax-economy  owns the ledger: what a balance is, how it is earned,
 *                     what stops it being farmed. The store never writes
 *                     `users.points` directly — it goes through Ledger, so
 *                     every credit movement in the forum has a row.
 *
 *   looksmax-ranks    owns identity: what a membership tier MEANS, which
 *                     styles and frames exist, and the CSS that paints them.
 *                     The store mirrors those into its catalogue and calls
 *                     Standing to grant them, so there is exactly one answer
 *                     to "does this account own Fire".
 *
 *   looksmax-store    owns commerce: the catalogue rows, the price somebody
 *                     actually pays, the order, the transaction that makes
 *                     payment and delivery inseparable, the refund, the
 *                     expiry, and the audit trail behind all of it.
 *
 * The frontend routes below are the fix for the 404: `->route()` registers the
 * path server-side, so /store returns a real forum document instead of an
 * error page even if every line of our JavaScript fails to run.
 */
return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->route('/store', 'store')
        ->route('/store/inventory', 'store.inventory')
        ->route('/store/orders', 'store.orders')
        ->route('/store/admin', 'store.admin')
        ->content(Listeners\InjectStore::class)
        // The Oro wallet: a self-contained launcher + buy/spend modal for the
        // paid currency. See Listeners/InjectOroWallet.php for why it is inline
        // rather than a component in the (source-less) dist bundle.
        ->content(Listeners\InjectOroWallet::class),

    (new Extend\Frontend('admin'))
        ->content(Listeners\InjectAdminLink::class)
        // The boost stacking ceiling — see src/Config.php for why it is the
        // one setting this extension owns outside the catalogue table.
        ->content(InjectAdminScript::class),

    // Read and write on separate routes with a parameterised view/action, the
    // same shape the identity extension uses. New routes have no conflict
    // surface with any other extension, which is the entire reason the
    // frontend can stay free of override() calls.
    (new Extend\Routes('api'))
        ->get('/store/{what:[a-z]+}', 'store.read', StoreController::class)
        ->post('/store/{action:[a-z]+}', 'store.write', StoreActionController::class),

    // Bought thread effects on the discussion payload, bulk-loaded for the
    // listing so a highlighted thread costs no extra query per row.
    (new Extend\ApiSerializer(DiscussionSerializer::class))
        ->attributes(Listeners\DiscussionEffects::class),

    (new Extend\ApiController(ListDiscussionsController::class))
        ->prepareDataForSerialization([Listeners\DiscussionEffects::class, 'preload']),

    (new Extend\Locales(__DIR__ . '/locale')),

    (new Extend\Console())
        ->command(Console\SyncCommand::class)
        ->command(Console\ExpireCommand::class)
        ->command(Console\ReconcileCommand::class)
        ->schedule(Console\ExpireCommand::class, function (\Illuminate\Console\Scheduling\Event $event) {
            // Pins have to come off on time. Everything else here is
            // time-filtered on read and would survive a missed run.
            $event->everyFiveMinutes();
        }),
];
