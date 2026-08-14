<?php

namespace Local\Guides\Models;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;

class GuideMeta extends AbstractModel
{
    protected $table = 'guide_meta';
    protected $primaryKey = 'discussion_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'requires_pro' => 'bool',
        'evidence_score' => 'float',
        'published_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'review_due_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $guarded = [];

    public function discussion()
    {
        return $this->belongsTo(Discussion::class, 'discussion_id');
    }

    public function claims()
    {
        return $this->hasMany(GuideClaim::class, 'discussion_id', 'discussion_id');
    }

    public function getProfileAttribute(): array
    {
        $raw = json_decode((string) $this->evidence_profile, true);

        return is_array($raw) ? $raw : [];
    }

    public function getTocAttribute($value): array
    {
        $raw = json_decode((string) $value, true);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Freshness as a word rather than a date difference, because the banner,
     * the ranking multiplier and the review queue all need the same answer and
     * must never disagree with each other.
     */
    public function freshness(): string
    {
        if (!$this->review_due_at) {
            return 'unknown';
        }

        $now = time();
        $due = $this->review_due_at->getTimestamp();

        if ($now < $due) {
            return 'fresh';
        }

        $interval = max(1, (int) $this->review_interval_d) * 86400;

        return $now > $due + ($interval / 2) ? 'stale' : 'due';
    }

    /**
     * Ranking multiplier. A banner is advisory and gets ignored; withdrawing
     * the recommendation is the only thing that actually protects a reader
     * from a guide whose sources have rotted.
     */
    public function rankMultiplier(): float
    {
        switch ($this->freshness()) {
            case 'stale':
                return 0.4;
            case 'due':
                return 0.8;
            default:
                return 1.0;
        }
    }
}
