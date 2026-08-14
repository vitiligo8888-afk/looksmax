<?php

use Illuminate\Database\Schema\Blueprint;

/**
 * The store's own tables.
 *
 * Four things are separated on purpose, because the previous cut of this idea
 * (the buy endpoints inside the identity extension) collapsed them into one
 * and could not answer basic questions:
 *
 *   store_items         what is for sale, editable by an admin at runtime
 *   store_orders        every purchase ATTEMPT, including the ones that failed
 *   store_entitlements  what an order actually granted, and when it runs out
 *   store_audit         who changed a price or a balance, and what it was before
 *
 * The catalogue lives in a table rather than in code because prices are the
 * one part of an economy that has to move without a deploy — an item that is
 * being farmed has to be repriced the same hour. The cosmetics catalogue in
 * Local\Ranks\Catalog stays in code (it is versioned with the CSS that paints
 * it); the store mirrors those rows in as `managed` items and owns only the
 * commercial columns. See Catalogue::sync().
 *
 * store_orders holds failures deliberately. "Nothing happened" and "it was
 * refused because you were 300 short" look identical from the outside, and the
 * second one is the row you need when somebody says the store is broken.
 */
return [
    'up' => function (Illuminate\Database\Schema\Builder $schema) {
        $schema->create('store_items', function (Blueprint $table) {
            $table->increments('id');
            $table->string('sku', 60)->unique();
            $table->string('name', 120);
            $table->string('blurb', 255)->default('');
            $table->string('category', 30)->default('misc');
            $table->string('kind', 24);                 // grant handler key
            $table->text('payload')->nullable();        // json, handler-specific
            $table->integer('price')->default(0);
            $table->string('rarity', 16)->default('common');
            $table->string('icon', 60)->default('ph:tag-fill');
            $table->string('color', 16)->nullable();
            $table->integer('sort')->default(100);
            $table->boolean('active')->default(true);
            $table->boolean('giftable')->default(true);
            $table->boolean('discountable')->default(true);

            // Scarcity. `stock_total` null means unlimited; when it is set,
            // stock_sold is incremented inside the purchase transaction under a
            // row lock, which is the only thing that makes a limited drop
            // actually limited when two people click at the same millisecond.
            $table->integer('max_per_user')->default(0); // 0 = no limit
            $table->integer('stock_total')->nullable();
            $table->integer('stock_sold')->default(0);

            // Gates. Checked server-side on every purchase; the UI greys these
            // out but the UI is a hint, not a rule.
            $table->string('min_rank', 24)->nullable();
            $table->string('min_tier', 24)->nullable();
            $table->string('requires_sku', 60)->nullable();

            $table->dateTime('available_from')->nullable();
            $table->dateTime('available_until')->nullable();

            $table->integer('duration_days')->nullable(); // null = permanent
            $table->integer('uses')->nullable();          // consumables: charges granted
            $table->integer('refund_minutes')->default(0);// self-service refund window
            $table->boolean('managed')->default(false);   // mirrored from code catalogue
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();

            $table->index(['active', 'category', 'sort']);
        });

        $schema->create('store_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');           // who paid
            $table->unsignedInteger('recipient_id');      // who receives (gifting)
            $table->unsignedInteger('item_id')->nullable();
            $table->string('sku', 60);
            $table->integer('unit_price')->default(0);
            $table->integer('discount')->default(0);
            $table->integer('total')->default(0);
            $table->string('currency', 16)->default('points');
            $table->string('state', 16)->default('pending');
            // pending -> granted | refused | failed -> refunded | expired

            // A double-submitted purchase is the single most common way a store
            // charges twice. The client sends a key per intent; the unique index
            // turns the second insert into a lookup of the first order.
            $table->string('idempotency_key', 64);
            $table->string('provider', 24)->default('points');
            $table->string('provider_ref', 80)->nullable();
            $table->string('error', 255)->nullable();
            $table->text('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('refunded_at')->nullable();

            $table->unique(['user_id', 'idempotency_key'], 'order_once');
            $table->index(['user_id', 'created_at']);
            $table->index(['recipient_id', 'state']);
            $table->index('state');
        });

        $schema->create('store_entitlements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('sku', 60);
            $table->string('kind', 24);
            $table->text('payload')->nullable();
            $table->timestamp('granted_at')->useCurrent();
            $table->dateTime('expires_at')->nullable();   // null = permanent
            $table->integer('uses_left')->nullable();     // null = not a consumable
            $table->dateTime('revoked_at')->nullable();
            $table->string('note', 120)->nullable();

            $table->index(['user_id', 'kind']);
            $table->index('expires_at');
            $table->index('order_id');
        });

        $schema->create('store_audit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('action', 40);
            $table->unsignedInteger('target_user_id')->nullable();
            $table->string('subject', 60)->nullable();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index('target_user_id');
        });
    },

    'down' => function (Illuminate\Database\Schema\Builder $schema) {
        $schema->dropIfExists('store_audit');
        $schema->dropIfExists('store_entitlements');
        $schema->dropIfExists('store_orders');
        $schema->dropIfExists('store_items');
    },
];
