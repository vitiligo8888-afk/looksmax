<?php
use Flarum\Database\Migration;

return Migration::addColumns('users', [
    'imported_id' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
