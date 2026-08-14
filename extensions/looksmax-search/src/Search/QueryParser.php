<?php

namespace Local\Search\Search;

/**
 * The query language.
 *
 * A search box that only takes words forces every refinement through the UI,
 * and a UI can only offer the refinements someone thought to build. A parsed
 * query language means the URL is the state: a filtered search is a link that
 * can be bookmarked, saved, shared in a post, or turned into a feed. The facet
 * chips in the UI do not have their own state at all — they write into this
 * string and re-run, so the two can never disagree.
 *
 * Design rules:
 *
 * - **Unknown operators are text, not errors.** Someone searching for
 *   `ratio:1.618` means it literally. Rejecting a query because it looks like
 *   an operator is the worst possible failure — it turns a search into a form
 *   validation error. Only the known keys are consumed; everything else falls
 *   through to the free-text side untouched.
 * - **Quoted strings survive intact**, including inside an operator value
 *   (`by:"user name"`), because usernames and tag names contain spaces.
 * - **Every operator is reversible.** `unparse()` rebuilds the string from the
 *   parsed structure, so the UI can add and remove a filter chip without ever
 *   doing string surgery on what the user typed.
 */
class QueryParser
{
    /** operator => canonical field */
    private const ALIASES = [
        'tag' => 'tag', 'in' => 'tag', 'forum' => 'tag', 'category' => 'tag', 'prefix' => 'prefix',
        'by' => 'author', 'author' => 'author', 'from' => 'author', 'user' => 'author',
        'before' => 'before', 'until' => 'before',
        'after' => 'after', 'since' => 'after',
        'reactions' => 'reactions', 'likes' => 'reactions', 'score' => 'reactions',
        'replies' => 'replies', 'comments' => 'replies', 'posts' => 'replies',
        'views' => 'views',
        'len' => 'length', 'length' => 'length', 'words' => 'length',
        'lang' => 'lang', 'language' => 'lang',
        'is' => 'is', 'has' => 'is',
        'sort' => 'sort', 'order' => 'sort',
        'thread' => 'discussion', 'discussion' => 'discussion', 'd' => 'discussion',
        'type' => 'type',
    ];

    private const IS_FLAGS = [
        'sticky', 'pinned', 'locked', 'closed', 'guide', 'answered', 'unanswered',
        'private', 'hidden', 'first', 'op',
    ];

    private const SORTS = [
        'relevance', 'new', 'newest', 'old', 'oldest', 'top', 'active', 'replies', 'views', 'length',
    ];

    /**
     * @return array{
     *   text:string, phrases:string[], negatives:string[],
     *   tags:string[], prefixes:string[], authors:string[],
     *   before:?int, after:?int,
     *   ranges:array<string,array{op:string,value:float}[]>,
     *   flags:string[], langs:string[], sort:?string, discussion:?int, type:?string,
     *   unknown:array<string,string[]>
     * }
     */
    public function parse(string $input): array
    {
        $out = [
            'text' => '', 'phrases' => [], 'negatives' => [],
            'tags' => [], 'prefixes' => [], 'authors' => [],
            'before' => null, 'after' => null,
            'ranges' => [], 'flags' => [], 'langs' => [],
            'sort' => null, 'discussion' => null, 'type' => null,
            'unknown' => [],
        ];

        $words = [];
        foreach ($this->tokenise($input) as $token) {
            [$raw, $quoted] = $token;

            if ($quoted) {
                $out['phrases'][] = $raw;
                continue;
            }

            if ($raw === '') {
                continue;
            }

            // Negation of a bare word: -spam
            if ($raw[0] === '-' && strlen($raw) > 1 && !str_contains($raw, ':')) {
                $out['negatives'][] = substr($raw, 1);
                continue;
            }

            $colon = strpos($raw, ':');
            if ($colon === false || $colon === 0) {
                $words[] = $raw;
                continue;
            }

            $key = strtolower(substr($raw, 0, $colon));
            $value = substr($raw, $colon + 1);
            $negate = false;
            if ($key !== '' && $key[0] === '-') {
                $negate = true;
                $key = substr($key, 1);
            }

            $field = self::ALIASES[$key] ?? null;
            if ($field === null || $value === '') {
                // Not ours. It is part of what the user is searching for.
                $words[] = $raw;
                continue;
            }

            $this->applyOperator($out, $field, $value, $negate, $words, $raw);
        }

        $out['text'] = trim(implode(' ', $words));

        return $out;
    }

    private function applyOperator(array &$out, string $field, string $value, bool $negate, array &$words, string $raw): void
    {
        switch ($field) {
            case 'tag':
                foreach (explode(',', $value) as $v) {
                    if ($v !== '') {
                        $out['tags'][] = $this->slugish($v);
                    }
                }
                break;

            case 'prefix':
                foreach (explode(',', $value) as $v) {
                    if ($v !== '') {
                        $out['prefixes'][] = $v;
                    }
                }
                break;

            case 'author':
                foreach (explode(',', $value) as $v) {
                    if ($v !== '') {
                        $out['authors'][] = ltrim($v, '@');
                    }
                }
                break;

            case 'before':
            case 'after':
                $ts = $this->parseDate($value, $field === 'before');
                if ($ts === null) {
                    $words[] = $raw;   // not a date we understand: leave it as text
                    break;
                }
                $out[$field] = $ts;
                break;

            case 'reactions':
            case 'replies':
            case 'views':
            case 'length':
                $range = $this->parseRange($value);
                if ($range === null) {
                    $words[] = $raw;
                    break;
                }
                foreach ($range as $r) {
                    $out['ranges'][$field][] = $r;
                }
                break;

            case 'lang':
                foreach (explode(',', $value) as $v) {
                    $out['langs'][] = strtolower(substr($v, 0, 3));
                }
                break;

            case 'is':
                $flag = strtolower($value);
                if (!in_array($flag, self::IS_FLAGS, true)) {
                    $words[] = $raw;
                    break;
                }
                $out['flags'][] = ($negate ? '!' : '') . $flag;
                break;

            case 'sort':
                $s = strtolower($value);
                if (in_array($s, self::SORTS, true)) {
                    $out['sort'] = $s;
                } else {
                    $words[] = $raw;
                }
                break;

            case 'discussion':
                if (ctype_digit($value)) {
                    $out['discussion'] = (int) $value;
                } else {
                    $words[] = $raw;
                }
                break;

            case 'type':
                $t = strtolower($value);
                $out['type'] = in_array($t, ['discussions', 'posts', 'users', 'tags'], true) ? $t : null;
                if ($out['type'] === null) {
                    $words[] = $raw;
                }
                break;
        }
    }

    /**
     * Tokenise respecting quotes, including quotes that begin after a colon so
     * that `by:"two words"` is one token rather than two broken ones.
     *
     * @return array<array{0:string,1:bool}>  [text, wasQuoted]
     */
    private function tokenise(string $input): array
    {
        $tokens = [];
        $len = strlen($input);
        $buf = '';
        $inQuote = false;
        $quoteStartedAtTokenStart = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $input[$i];

            if ($c === '"') {
                if ($inQuote) {
                    $inQuote = false;
                    if ($quoteStartedAtTokenStart) {
                        $tokens[] = [$buf, true];
                        $buf = '';
                    }
                    continue;
                }
                $inQuote = true;
                $quoteStartedAtTokenStart = ($buf === '');
                continue;
            }

            if (!$inQuote && ($c === ' ' || $c === "\t" || $c === "\n")) {
                if ($buf !== '') {
                    $tokens[] = [$buf, false];
                    $buf = '';
                }
                continue;
            }

            $buf .= $c;
        }

        if ($buf !== '') {
            $tokens[] = [$buf, $inQuote && $quoteStartedAtTokenStart];
        }

        return $tokens;
    }

    /**
     * Dates: absolute (2024-03-01, 2024-03, 2024) or relative (7d, 3w, 6mo, 1y,
     * today, yesterday). Absolute dates snap to the END of their precision when
     * used as `before:` so that `before:2024` means "everything in 2024 and
     * earlier", which is what a person means, rather than "before 1 January".
     */
    private function parseDate(string $v, bool $inclusiveEnd): ?int
    {
        $v = strtolower(trim($v));

        if ($v === 'today') {
            return strtotime('today');
        }
        if ($v === 'yesterday') {
            return strtotime('yesterday');
        }

        if (preg_match('/^(\d+)\s*(d|day|days|w|week|weeks|mo|month|months|y|year|years|h|hour|hours)$/', $v, $m)) {
            $n = (int) $m[1];
            $unit = match (true) {
                str_starts_with($m[2], 'h') => 'hours',
                str_starts_with($m[2], 'd') => 'days',
                str_starts_with($m[2], 'w') => 'weeks',
                str_starts_with($m[2], 'mo'), str_starts_with($m[2], 'month') => 'months',
                default => 'years',
            };

            return strtotime("-$n $unit") ?: null;
        }

        if (preg_match('/^(\d{4})$/', $v, $m)) {
            return $inclusiveEnd ? strtotime("{$m[1]}-12-31 23:59:59") : strtotime("{$m[1]}-01-01");
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $v, $m)) {
            return $inclusiveEnd
                ? strtotime("{$m[1]}-{$m[2]}-01 +1 month -1 second")
                : strtotime("{$m[1]}-{$m[2]}-01");
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v)) {
            return $inclusiveEnd ? strtotime("$v 23:59:59") : strtotime($v);
        }

        return null;
    }

    /** `>10`, `>=10`, `<5`, `10..50`, `10` (treated as >=). */
    private function parseRange(string $v): ?array
    {
        $v = trim($v);
        if (preg_match('/^(\d+)\.\.(\d+)$/', $v, $m)) {
            return [['op' => '>=', 'value' => (float) $m[1]], ['op' => '<=', 'value' => (float) $m[2]]];
        }
        if (preg_match('/^(>=|<=|>|<|=)?\s*(\d+(?:\.\d+)?)$/', $v, $m)) {
            $op = $m[1] ?: '>=';

            return [['op' => $op === '=' ? '=' : $op, 'value' => (float) $m[2]]];
        }

        return null;
    }

    private function slugish(string $v): string
    {
        return strtolower(trim($v));
    }

    /** Rebuild a query string from a parsed structure. The UI's only editor. */
    public function unparse(array $q): string
    {
        $parts = [];
        if (($q['text'] ?? '') !== '') {
            $parts[] = $q['text'];
        }
        foreach ($q['phrases'] ?? [] as $p) {
            $parts[] = '"' . $p . '"';
        }
        foreach ($q['negatives'] ?? [] as $n) {
            $parts[] = '-' . $n;
        }
        foreach ($q['tags'] ?? [] as $t) {
            $parts[] = 'tag:' . $this->quoteIfNeeded($t);
        }
        foreach ($q['prefixes'] ?? [] as $p) {
            $parts[] = 'prefix:' . $this->quoteIfNeeded($p);
        }
        foreach ($q['authors'] ?? [] as $a) {
            $parts[] = 'by:' . $this->quoteIfNeeded($a);
        }
        if (!empty($q['after'])) {
            $parts[] = 'after:' . date('Y-m-d', $q['after']);
        }
        if (!empty($q['before'])) {
            $parts[] = 'before:' . date('Y-m-d', $q['before']);
        }
        foreach ($q['ranges'] ?? [] as $field => $rs) {
            $key = ['reactions' => 'reactions', 'replies' => 'replies', 'views' => 'views', 'length' => 'len'][$field] ?? $field;
            foreach ($rs as $r) {
                $parts[] = $key . ':' . ($r['op'] === '>=' ? '' : $r['op']) . (int) $r['value'];
            }
        }
        foreach ($q['langs'] ?? [] as $l) {
            $parts[] = 'lang:' . $l;
        }
        foreach ($q['flags'] ?? [] as $f) {
            $parts[] = (str_starts_with($f, '!') ? '-is:' . substr($f, 1) : 'is:' . $f);
        }
        if (!empty($q['discussion'])) {
            $parts[] = 'thread:' . (int) $q['discussion'];
        }
        if (!empty($q['sort']) && $q['sort'] !== 'relevance') {
            $parts[] = 'sort:' . $q['sort'];
        }

        return implode(' ', $parts);
    }

    private function quoteIfNeeded(string $v): string
    {
        return str_contains($v, ' ') ? '"' . $v . '"' : $v;
    }

    /** Everything the UI needs to render autocomplete for the language itself. */
    public static function grammar(): array
    {
        return [
            'operators' => array_values(array_unique(array_keys(self::ALIASES))),
            'canonical' => self::ALIASES,
            'flags' => self::IS_FLAGS,
            'sorts' => self::SORTS,
        ];
    }
}
