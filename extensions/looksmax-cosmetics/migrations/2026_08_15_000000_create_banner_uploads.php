<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * User-uploaded profile banners, and the moderation trail an uploaded image
 * needs that a generated CSS plate never did.
 *
 * `Defs.php` explains why the six shipped banners are generated plates and
 * not images: no storage, no moderation path, cannot be a shock image. This
 * migration is what changes that calculus for the rank/tier-gated upload the
 * gamification brief asked for — the storage and moderation path Defs.php
 * said did not exist yet.
 *
 * `cosmetic_banner_uploads` — ONE ROW PER ACCOUNT, ON PURPOSE.
 *
 *   A member has exactly one custom banner at a time; re-uploading replaces
 *   it (BannerUploads::store() overwrites the same deterministic filename,
 *   `lmx-banner-<user_id>.<ext>`, on the SAME disk looksmax-ranks already
 *   proved works for avatars — `flarum-avatars`, see
 *   Api/IdentityController.php:301 and its measured GET-200 note). One row
 *   means one file to purge on moderation, one thing to reason about, and no
 *   unbounded storage growth from a member who re-uploads fifty times.
 *
 *   `status` starts 'active' on every (re)upload — a rejected member gets a
 *   real second chance, not a permanent flag on the row — and moves to
 *   'rejected' only through BannerUploads::moderate(), which also deletes the
 *   file and clears the equip slot in the same call. `reject_count` is the
 *   repeat-offender counter: BannerUploads::eligible() consults it and
 *   permanently pulls upload access past a small threshold, because a member
 *   who gets the same image rejected three times is not going to stop on
 *   their own.
 *
 * `cosmetic_banner_reports` — the flag path this forum did not otherwise
 *   have for this surface (no flarum/flags dependency anywhere in this
 *   install — checked). One row per (banner owner, reporter): a single
 *   member cannot inflate a report count by clicking twice, and the count
 *   itself is what BannerUploads::queue() sorts the admin screen by.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('cosmetic_banner_uploads', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->string('filename', 80);          // e.g. lmx-banner-42.jpg — the flarum-avatars disk key
            $table->string('mime', 40);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('bytes');
            $table->string('status', 16)->default('active'); // active | rejected
            $table->unsignedTinyInteger('reject_count')->default(0);
            $table->boolean('banned')->default(false); // past the reject_count threshold — see BannerUploads::eligible()
            $table->timestamp('uploaded_at')->useCurrent();
            $table->unsignedInteger('moderated_by')->nullable();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_reason', 255)->nullable();

            $table->index('status');
        });

        $schema->create('cosmetic_banner_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id');     // whose banner
            $table->unsignedInteger('reporter_id');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'reporter_id'], 'banner_report_once');
            $table->index('user_id');
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('cosmetic_banner_reports');
        $schema->dropIfExists('cosmetic_banner_uploads');
    },
];
