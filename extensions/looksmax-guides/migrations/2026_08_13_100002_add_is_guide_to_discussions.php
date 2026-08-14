<?php

use Flarum\Database\Migration;

/**
 * The one deliberate exception to "never ALTER a core table".
 *
 * The discussion list has to pick a renderer per row. Joining guide_meta for
 * every row of a 20-row list is 20 joins on a 2.2M-row table to answer a
 * yes/no question, so this one boolean earns its place next to the columns the
 * list already reads. Everything else stays in the satellite.
 */
return Migration::addColumns('discussions', [
    'is_guide' => ['boolean', 'default' => 0],
]);
