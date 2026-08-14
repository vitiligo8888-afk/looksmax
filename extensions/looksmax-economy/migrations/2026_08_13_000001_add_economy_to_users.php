<?php
use Flarum\Database\Migration;

return Migration::addColumns('users', [
    'points'          => ['integer', 'default' => 0],
    'lifetime_points' => ['integer', 'default' => 0],
    'rank_slug'       => ['string', 'length' => 40, 'nullable' => true],
]);
