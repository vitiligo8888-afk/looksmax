<?php

namespace Local\Search\Models;

use Flarum\Database\AbstractModel;

class SearchQuery extends AbstractModel
{
    protected $table = 'search_queries';
    public $timestamps = false;

    protected $fillable = [
        'query', 'normalised', 'user_id', 'session', 'type', 'result_count',
        'engine_ms', 'total_ms', 'offset', 'filters', 'source', 'created_at',
        'clicked_result_id', 'clicked_type', 'clicked_position', 'clicked_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'clicked_at' => 'datetime',
    ];

    /**
     * The aggregation key. Operators are stripped so that the same underlying
     * demand expressed with and without filters counts once, and the string is
     * truncated to the index width so a pathological query cannot fail an
     * insert and lose the row.
     */
    public static function normalise(string $q): string
    {
        $q = preg_replace('/\b(tag|in|forum|category|prefix|by|author|from|user|before|until|after|since|reactions|likes|score|replies|comments|posts|views|len|length|words|lang|language|is|has|sort|order|thread|discussion|d|type):("[^"]*"|\S+)/iu', ' ', $q) ?? $q;
        $q = preg_replace('/["\-]/u', ' ', $q) ?? $q;
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;

        return mb_substr(trim(mb_strtolower($q)), 0, 180);
    }
}
