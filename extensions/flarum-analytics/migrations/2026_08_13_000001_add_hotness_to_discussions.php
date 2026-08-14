<?php
use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Materialised ranking score. Computed from the event stream on a schedule
 * rather than at read time — sorting 2.2M discussions cannot join an event
 * table per request.
 */
return Migration::addColumns('discussions', [
    'hotness'      => ['double', 'default' => 0],
    'view_count'   => ['integer', 'unsigned' => true, 'default' => 0],
    'unique_views' => ['integer', 'unsigned' => true, 'default' => 0],
]);
