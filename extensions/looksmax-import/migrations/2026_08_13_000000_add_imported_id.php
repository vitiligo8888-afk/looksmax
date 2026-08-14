<?php
use Flarum\Database\Migration;

/**
 * Provenance. Every imported row remembers where it came from, which makes the
 * import idempotent, lets a re-scrape update in place, and makes the mirror
 * diffable against the source.
 */
return Migration::addColumns('discussions', [
    'imported_id' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
