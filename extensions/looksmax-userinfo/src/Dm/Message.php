<?php

namespace Local\UserInfo\Dm;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a direct-message thread.
 *
 * `content` is stored exactly as typed and is escaped at render time by the
 * DOM, never by a sanitiser here: a sanitiser that runs on write cannot be
 * fixed retroactively, and the front end already has to be safe against
 * content it did not write. Nothing in this table is ever interpreted as
 * markup by this extension.
 */
class Message extends AbstractModel
{
    protected $table = 'lmx_dm_messages';
    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
        'edited_at' => 'datetime',
        'deleted_at' => 'datetime',
        'thread_id' => 'int',
        'user_id' => 'int',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
