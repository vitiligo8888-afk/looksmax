<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Two tables, and the reason each of them is not a column somewhere else.
 *
 * `cosmetic_defs` — WHAT A COSMETIC LOOKS LIKE.
 *
 *   The obvious home for this was `store_items.payload`, and it is the wrong
 *   one. `Local\Store\Catalogue::sync()` builds a full column record from
 *   `Seed::all()` and writes it over every existing row —
 *   looksmax-store/src/Catalogue.php:42 sets `payload` from the shipped seed
 *   and :76 UPDATEs it unconditionally on every `php flarum store:sync`, which
 *   the store's own admin screen can trigger over HTTP
 *   (StoreActionController::syncCatalogue, :284). Anything this extension
 *   wrote into that column would survive until the next deploy and then
 *   silently vanish, which is the worst possible failure shape for a visual.
 *   `store_items.color` is a single hex and cannot express a frame (two to
 *   four stops, a width, a glow and a motion period).
 *
 *   So: the store keeps owning commerce (sku, name, blurb, price, rarity,
 *   icon, min_tier, duration) and this table owns the visual, keyed by the
 *   same slug and linked by the same sku. Nothing is duplicated — see
 *   src/Definitions.php, which reads both and says which column it took each
 *   field from.
 *
 * `cosmetic_loadout` — WHAT A USER IS WEARING.
 *
 *   `users.avatar_frame` already exists (looksmax-ranks). It is not enough on
 *   its own: `Local\Ranks\Standing::equip()` refuses any slug that is not in
 *   the hardcoded `Catalog::FRAMES` constant (Standing.php:263) and
 *   `identity:sync` NULLs the column for any slug that constant does not know
 *   (Console/SyncCommand.php:133-138). A cosmetic system whose catalogue is a
 *   PHP constant in another extension is not a system.
 *
 *   So this table is the source of truth for what this renderer draws, and
 *   Loadout::equip() MIRRORS into `users.avatar_frame` whenever the slug is
 *   one looksmax-ranks also knows, so the two can never disagree about a
 *   shared frame. Frames that only exist here leave that column NULL, which is
 *   exactly what ranks' own sync would do to them anyway.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('cosmetic_defs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kind', 16);          // frame | banner
            $table->string('slug', 40);
            // The store SKU that sells this, when one does. NULL means the
            // cosmetic is earned rather than bought, and `obtain` in the spec
            // says from what.
            $table->string('sku', 60)->nullable();
            $table->text('spec');                // JSON: renderer + parameters + obtain rule
            $table->unsignedInteger('sort')->default(100);
            $table->boolean('active')->default(true);
            // 'shipped' rows are refreshed by `cosmetics:sync`; anything else
            // is left alone, so a frame added by hand in SQL is not clobbered
            // by the next deploy. This is the mistake store:sync makes.
            $table->string('source', 16)->default('shipped');
            $table->timestamp('updated_at')->nullable();

            $table->unique(['kind', 'slug']);
            $table->index('sku');
        });

        $schema->create('cosmetic_loadout', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->string('frame', 40)->nullable();
            $table->string('banner', 40)->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('cosmetic_loadout');
        $schema->dropIfExists('cosmetic_defs');
    },
];
