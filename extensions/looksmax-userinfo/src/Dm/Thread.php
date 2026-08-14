<?php

namespace Local\UserInfo\Dm;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One direct-message conversation.
 *
 * Deliberately thin. Every mutation goes through the controller so that the
 * three writes a message implies — insert the message, bump the thread's
 * denormalised tail, advance the sender's own read cursor — happen in one
 * transaction. A model method that did any one of them on its own would be a
 * way to leave the inbox showing a conversation with no last message, or the
 * sender's own message unread by the sender.
 */
class Thread extends AbstractModel
{
    /**
     * The permission a member needs to open a conversation.
     *
     * Named here rather than as a bare string at each check, because there are
     * four of them (serializer, create, reply, admin registration) and a typo
     * in one is a silently permissive endpoint.
     */
    public const PERMISSION_SEND = 'lmxdm.send';

    protected $table = 'lmx_dm_threads';
    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
        'last_message_at' => 'datetime',
        'message_count' => 'int',
        'last_message_id' => 'int',
        'created_by' => 'int',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'lmx_dm_participants', 'thread_id', 'user_id')
            ->withPivot(['joined_at', 'last_read_at', 'left_at']);
    }
}
