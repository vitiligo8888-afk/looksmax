<?php

use Flarum\Database\Migration;

/**
 * Equipped state, denormalised onto the user.
 *
 * These are all caches of rows in identity_inventory / identity_memberships,
 * and `identity:sync` rebuilds every one of them from those tables. They exist
 * because a discussion listing renders 50 usernames and each of them needs a
 * colour: a join per name is a join per name, and the serializer runs on every
 * user payload in the API.
 *
 * `legacy_style` and `legacy_title` are provenance, not display state — they
 * record what the source board said about this account so a mis-mapped tier can
 * be re-derived without going back to the 2.6 GB SQLite file.
 */
return Migration::addColumns('users', [
    'tier_slug'        => ['string', 'length' => 24, 'nullable' => true],
    'tier_expires_at'  => ['dateTime', 'nullable' => true],
    'name_style'       => ['string', 'length' => 40, 'nullable' => true],
    'avatar_frame'     => ['string', 'length' => 40, 'nullable' => true],
    'custom_title'     => ['string', 'length' => 120, 'nullable' => true],
    'title_color'      => ['string', 'length' => 16, 'nullable' => true],
    'profile_accent'   => ['string', 'length' => 16, 'nullable' => true],
    'badge_count'      => ['integer', 'unsigned' => true, 'default' => 0],
    'legacy_style'     => ['string', 'length' => 8, 'nullable' => true],
    'legacy_title'     => ['string', 'length' => 120, 'nullable' => true],
    'legacy_threads'   => ['integer', 'unsigned' => true, 'default' => 0],
    'legacy_reactions' => ['integer', 'unsigned' => true, 'default' => 0],
]);
