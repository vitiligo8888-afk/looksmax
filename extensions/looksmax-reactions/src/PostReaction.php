<?php

namespace Local\Reactions;

use Flarum\Database\AbstractModel;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row = one user reacted to one post with one type. Multiple rows per
 * (user, post) are the point: this is a multi-reaction system, not likes with
 * a choice of icon.
 */
class PostReaction extends AbstractModel
{
    protected $table = 'post_reactions';

    public $timestamps = true;

    protected $fillable = ['post_id', 'user_id', 'reaction_id'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reaction(): BelongsTo
    {
        return $this->belongsTo(Reaction::class, 'reaction_id');
    }
}
