<?php

use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\Extend;
use Flarum\User\User;
use Local\Cosmetics\Api\CosmeticsActionController;
use Local\Cosmetics\Api\CosmeticsController;
use Local\Cosmetics\Console;
use Local\Cosmetics\InjectCosmetics;
use Local\Cosmetics\Ownership;

/**
 * Cosmetic personalisation.
 *
 * The problem it was written for, measured on the live database on 2026-08-13:
 * the store sells seven avatar frames, `store_entitlements` contains ZERO rows
 * of kind `frame`, and the only renderer for them
 * (looksmax-ranks/js/dist/forum.js:253) fires from a `.username` node and
 * therefore reaches an avatar only where a name happens to sit next to it. The
 * catalogue was selling something with no renderer and no owner.
 *
 * ── division of labour ─────────────────────────────────────────────────────
 *
 *   looksmax-store   commerce. Owns store_items, store_entitlements, the
 *                    order, the refund. This extension READS both and writes
 *                    neither.
 *   looksmax-ranks   identity. Owns identity_inventory, identity_badges,
 *                    identity_memberships and the name styles. This extension
 *                    READS all of them and writes none of them. It does write
 *                    `users.avatar_frame`, which is a mirror and is explained
 *                    in src/Loadout.php.
 *   looksmax-cosmetics   what a cosmetic LOOKS like, whether this account may
 *                    wear it, where on the page it is drawn, and the screen
 *                    where you put it on.
 *
 * ── why the frontend is a DOM decorator, not component overrides ───────────
 *
 * An avatar appears in eight places owned by five extensions: the post author
 * rail (core), the hovercard and profile (core + looksmax-userinfo), the
 * discussion list (core + looksmax-theme), the shoutbox (looksmax-chat), the
 * index last-poster (looksmax-index), notifications, search results and the
 * member list. Overriding those is five compat imports, five load-order
 * dependencies and edits in five directories this lane does not own. Watching
 * the DOM for `.Avatar` is ONE implementation that covers all of them and edits
 * nothing. looksmax-icons and looksmax-ranks reached the same conclusion for
 * the same reasons.
 */
return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__ . '/less/forum.less')
        ->content(InjectCosmetics::class),
    /*
     * No new frontend ROUTE, on purpose.
     *
     * The wardrobe lives on the core /settings page, which is where the
     * operator asked for it and which already exists. Adding a client-side
     * route from an injected head script means redefining `window.flarum` with
     * an accessor to catch the one tick between the bundle and app.boot()
     * (looksmax-store/js/dist/forum.js:1069 does exactly that, and has to). Two
     * extensions doing that to the same property is a coin toss over which
     * one's setter survives — and the loser silently stops registering its
     * routes. /store is not mine to break, so this extension decorates a page
     * that already routes instead.
     */

    (new Extend\Locales(__DIR__ . '/locale')),

    /*
     * BasicUserSerializer, NOT UserSerializer.
     *
     * Flarum serializes a post or discussion author through BasicUserSerializer
     * — username, displayName, avatarUrl, slug. The full UserSerializer only
     * runs for /api/users/{id}. Extending the wrong one produces an attribute
     * that is present on a profile fetch and absent on every post in the
     * stream, which is a bug a 200 response hides completely. Mutators on a
     * parent serializer apply to its subclasses, so this covers both.
     *
     * Cost: Ownership reads three bounded tables once per request into static
     * maps, so this is O(1) queries for the whole payload rather than O(users).
     * See the performance note in src/Ownership.php.
     */
    (new Extend\ApiSerializer(BasicUserSerializer::class))
        ->attributes(function (BasicUserSerializer $serializer, User $user, array $attributes): array {
            try {
                $attributes['cosmetics'] = resolve(Ownership::class)->live(
                    (int) $user->id,
                    $user->tier_slug,
                    $user->tier_expires_at ? (string) $user->tier_expires_at : null,
                    // `users.avatar_frame` — see Ownership::loadout(). A frame
                    // bought through the store is equipped by the store, in a
                    // column that predates this extension.
                    $user->avatar_frame ?: null
                );
            } catch (\Throwable $e) {
                // A cosmetic is decoration. It must never be the reason a post
                // fails to serialize.
                $attributes['cosmetics'] = [];
            }

            return $attributes;
        }),

    (new Extend\Routes('api'))
        ->get('/cosmetics/{what:[a-z]+}', 'cosmetics.read', CosmeticsController::class)
        ->post('/cosmetics/{action:[a-z]+}', 'cosmetics.write', CosmeticsActionController::class),

    (new Extend\Console())
        ->command(Console\SyncCommand::class),
];
