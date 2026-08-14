<?php

namespace Local\Search\Models;

use Flarum\Database\AbstractModel;

class SavedSearch extends AbstractModel
{
    protected $table = 'search_saved';
    public $timestamps = false;

    protected $fillable = ['user_id', 'name', 'query', 'type', 'notify', 'last_seen_id', 'last_count', 'created_at', 'checked_at'];

    protected $casts = [
        'notify' => 'bool',
        'created_at' => 'datetime',
        'checked_at' => 'datetime',
    ];
}
