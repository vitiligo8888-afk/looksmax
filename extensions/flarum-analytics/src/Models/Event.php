<?php

namespace Local\Analytics\Models;

use Flarum\Database\AbstractModel;

/**
 * One row per thing that happened. `sent_at` is the PostHog watermark.
 */
class Event extends AbstractModel
{
    protected $table = 'analytics_events';
    public $timestamps = false;

    protected $fillable = [
        'type', 'user_id', 'discussion_id', 'post_id', 'session', 'path', 'props', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /** Record an event without ever letting instrumentation break a request. */
    public static function record(string $type, array $attrs = []): void
    {
        try {
            static::create(array_merge(
                ['type' => $type, 'created_at' => date('Y-m-d H:i:s')],
                $attrs
            ));
        } catch (\Throwable $e) {
            // swallow: analytics is never worth a 500
        }
    }
}
