<?php

use Flarum\Database\Migration;

/**
 * The carried post count needs its own column.
 *
 * The first cut of the badge engine read `users.comment_count`, on the
 * reasonable-looking assumption that the importer had set it to the source
 * board's post count — it had, when it was measured. Then the importer's
 * `recountUsers()` ran again and reset every one of them to the number of posts
 * that exist locally, and 1,411 accounts went from tens of thousands of posts to
 * under a hundred. `Hundred` was held by exactly one account on a board whose
 * top user has 66,411 posts.
 *
 * The lesson is not "use comment_count carefully", it is that a denormalised
 * column owned by another extension is not a place to keep our data. This one is
 * ours, only the backfill writes it, and the badge engine takes the greater of
 * the two so a native poster is never penalised for not having been imported.
 */
return Migration::addColumns('users', [
    'legacy_posts' => ['integer', 'unsigned' => true, 'default' => 0],
]);
