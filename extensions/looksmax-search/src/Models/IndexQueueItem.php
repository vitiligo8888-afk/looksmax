<?php

namespace Local\Search\Models;

use Flarum\Database\AbstractModel;

class IndexQueueItem extends AbstractModel
{
    protected $table = 'search_index_queue';
    public $timestamps = false;

    protected $fillable = ['kind', 'object_id', 'action', 'attempts', 'last_error', 'created_at', 'available_at'];

    protected $casts = [
        'created_at' => 'datetime',
        'available_at' => 'datetime',
    ];
}
