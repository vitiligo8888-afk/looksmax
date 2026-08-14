<?php

namespace Local\Guides\Models;

use Flarum\Database\AbstractModel;

class GuideClaim extends AbstractModel
{
    protected $table = 'guide_claims';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'tier' => 'int',
        'disputed' => 'bool',
        'created_at' => 'datetime',
    ];
}
