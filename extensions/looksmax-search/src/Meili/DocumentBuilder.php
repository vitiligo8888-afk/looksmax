<?php

namespace Local\Search\Meili;

use Illuminate\Database\ConnectionInterface;

/**
 * Rows in, documents out.
 *
 * Built on the query builder rather than Eloquent on purpose. A full reindex of
 * this corpus is tens of millions of rows; hydrating a model per row spends
 * most of the wall clock in the ORM, and every relation access is a query we
 * did not intend. Everything below is a bounded number of queries per chunk,
 * regardless of chunk size.
 *
 * The schema this reads is the *live* one, including columns added by our other
 * extensions (`view_count`, `hotness`, `is_guide`, `points`). Each of those is
 * probed once and degrades to a neutral value if the extension is not
 * installed, so this extension never hard-depends on them.
 */
class DocumentBuilder
{
    /**
     * Characters of `embed_text`. Overridden per-instance from settings by the
     * service provider; this is the value the measurements below were taken at.
     */
    public int $embedCap = 420;

    private array $columnCache = [];

    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * The exact string the embedding model sees for a discussion.
     *
     * Three decisions, each measured rather than assumed:
     *
     * 1. **Title first, then tags, then the opening post.** A thread's title is
     *    the densest statement of what it is about, and it is the part a
     *    "related topics" reader is comparing against. Putting it first means
     *    it survives truncation.
     *
     * 2. **The tag names are included as words.** They are human-written topic
     *    labels ("Rutinas de piel", "Cirugía"), so they carry real semantic
     *    signal in the site's own vocabulary and cost ~20 characters.
     *
     * 3. **The cap is ~420 characters, not the whole opening post.** Embedding
     *    cost on CPU is worse than linear in length: measured on this box with
     *    multilingual-e5-small under the live import load,
     *    ~296 chars/doc ran at 83 docs/s but ~467 chars/doc ran at 25–28
     *    docs/s. Roughly 1.6x the text for 3x the cost. 420 characters is
     *    ~120 tokens, which covers a title plus the first paragraph — the part
     *    of a forum opening post that states the question. The rest is usually
     *    a rating request, an image, or a signature.
     *
     * Deliberately NOT folded (`Text::fold`). Folding strips the diacritics and
     * case that the keyword index does not want, but the embedding model was
     * trained on natural text and its tokeniser handles "mandíbula" correctly.
     * Feeding it "mandibula" throws away information for no benefit.
     */
    public function embedText(string $title, array $tagNames, string $excerpt): string
    {
        $parts = [trim($title)];
        if ($tagNames) {
            $parts[] = implode(', ', array_slice($tagNames, 0, 6));
        }
        if ($excerpt !== '') {
            $parts[] = $excerpt;
        }

        $text = preg_replace('/\s+/u', ' ', implode('. ', array_filter($parts))) ?? '';
        $text = trim($text);

        if (mb_strlen($text) > $this->embedCap) {
            $cut = mb_substr($text, 0, $this->embedCap);
            $sp = mb_strrpos($cut, ' ');
            $text = $sp !== false && $sp > $this->embedCap * 0.6 ? mb_substr($cut, 0, $sp) : $cut;
        }

        return $text;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = "$table.$column";
        if (!isset($this->columnCache[$key])) {
            try {
                $this->columnCache[$key] = $this->db->getSchemaBuilder()->hasColumn($table, $column);
            } catch (\Throwable) {
                $this->columnCache[$key] = false;
            }
        }

        return $this->columnCache[$key];
    }

    private function hasTable(string $table): bool
    {
        $key = "table:$table";
        if (!isset($this->columnCache[$key])) {
            try {
                $this->columnCache[$key] = $this->db->getSchemaBuilder()->hasTable($table);
            } catch (\Throwable) {
                $this->columnCache[$key] = false;
            }
        }

        return $this->columnCache[$key];
    }

    // ------------------------------------------------------------ discussions

    /**
     * @param int[] $ids
     * @return array<int, array> keyed by discussion id
     */
    public function discussions(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $cols = ['d.id', 'd.title', 'd.slug', 'd.comment_count', 'd.participant_count',
            'd.created_at', 'd.last_posted_at', 'd.user_id', 'd.first_post_id',
            'd.is_private', 'd.is_locked', 'd.is_sticky', 'd.hidden_at',
            'u.username as author', 'u.nickname as author_nick'];
        foreach (['view_count' => 'd.view_count', 'hotness' => 'd.hotness', 'is_guide' => 'd.is_guide'] as $c => $expr) {
            if ($this->hasColumn('discussions', $c)) {
                $cols[] = $expr;
            }
        }

        $rows = $this->db->table('discussions as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->whereIn('d.id', $ids)
            ->get($cols);

        if ($rows->isEmpty()) {
            return [];
        }

        $found = $rows->pluck('id')->all();

        // One query for tags, one for opening posts. Never N.
        $tagRows = $this->db->table('discussion_tag as dt')
            ->join('tags as t', 't.id', '=', 'dt.tag_id')
            ->whereIn('dt.discussion_id', $found)
            ->get(['dt.discussion_id', 't.id', 't.name', 't.slug', 't.color', 't.parent_id', 't.position', 't.is_restricted']);
        $tagsByDiscussion = [];
        foreach ($tagRows as $t) {
            $tagsByDiscussion[$t->discussion_id][] = $t;
        }

        // Opening posts, keyed by DISCUSSION id rather than by post id.
        //
        // `discussions.first_post_id` is authoritative when it is set — and it
        // is NULL for every row the bulk importer writes (measured on the live
        // database: 2,612 of 2,612 discussions). That column is maintained by
        // Flarum's Discussion model, and a bulk insert never goes through it.
        // Trusting it alone produced a results page where not one thread had a
        // snippet, which is most of what makes a result judgeable.
        //
        // So: use it where present, and otherwise fall back to the lowest-
        // numbered comment in the thread. One extra query per chunk, never one
        // per row.
        $openingPosts = [];
        $firstPostIds = $rows->pluck('first_post_id')->filter()->all();
        if ($firstPostIds) {
            foreach (
                $this->db->table('posts')->whereIn('id', $firstPostIds)
                    ->get(['discussion_id', 'content']) as $p
            ) {
                $openingPosts[(int) $p->discussion_id] = $p->content;
            }
        }
        $missing = array_values(array_diff($found, array_keys($openingPosts)));
        if ($missing) {
            $lowest = $this->db->table('posts')
                ->selectRaw('discussion_id, MIN(number) as n')
                ->whereIn('discussion_id', $missing)
                ->where('type', 'comment')
                ->groupBy('discussion_id');

            foreach (
                $this->db->table('posts as p')
                    ->joinSub($lowest, 'm', function ($join) {
                        $join->on('m.discussion_id', '=', 'p.discussion_id')
                            ->on('m.n', '=', 'p.number');
                    })
                    ->get(['p.discussion_id', 'p.content']) as $p
            ) {
                $openingPosts[(int) $p->discussion_id] ??= $p->content;
            }
        }

        $reactions = $this->reactionCountsForDiscussions($found);

        $out = [];
        foreach ($rows as $r) {
            $tags = $tagsByDiscussion[$r->id] ?? [];
            // A tag with `position` is a forum/category; a tag without one is a
            // prefix. That is the taxonomy the import established, and it is
            // the distinction the facet UI needs, so it is materialised here
            // rather than re-derived on every query.
            $primary = null;
            $prefixes = [];
            foreach ($tags as $t) {
                if ($t->position !== null) {
                    if ($primary === null || ($t->parent_id !== null)) {
                        $primary = $t->slug;
                    }
                } else {
                    $prefixes[] = $t->name;
                }
            }

            $excerptSource = $openingPosts[(int) $r->id] ?? null;
            $excerpt = Text::plain($excerptSource, 1200);
            $lang = Text::guessLang($r->title . ' ' . $excerpt);

            $created = $this->ts($r->created_at);
            $lastPost = $this->ts($r->last_posted_at) ?: $created;

            $out[$r->id] = [
                'id' => (int) $r->id,
                'title' => (string) $r->title,
                'slug' => (string) $r->slug,
                'excerpt' => $excerpt,
                // Searchable copies. See Text::fold and IndexSettings::LOCALIZED
                // for why these exist; the un-suffixed fields above are what a
                // reader sees, these are what the engine tokenises.
                'title_s' => Text::fold((string) $r->title),
                'excerpt_s' => Text::fold($excerpt),
                // Only populated for German, so the single-locale rule that
                // reaches the compound splitter applies to German text and to
                // nothing else. An empty string here would still create the
                // field and drag non-German documents through German
                // segmentation for no reason.
                'title_de' => $lang === 'de' ? Text::fold((string) $r->title) : null,
                'excerpt_de' => $lang === 'de' ? Text::fold($excerpt) : null,
                // What the embedding model reads. A stable function of title,
                // tags and opening post ONLY — so a new reply, a view count or
                // an hourly rank_score refresh leaves it byte-identical and
                // Meilisearch skips re-embedding the document. See
                // DocumentBuilder::embedText and Embedder's class docblock.
                'embed_text' => $this->embedText(
                    (string) $r->title,
                    array_values(array_map(fn ($t) => (string) $t->name, $tags)),
                    $excerpt
                ),
                'tag_ids' => array_values(array_map(fn ($t) => (int) $t->id, $tags)),
                'tag_slugs' => array_values(array_map(fn ($t) => (string) $t->slug, $tags)),
                'tag_names' => array_values(array_map(fn ($t) => (string) $t->name, $tags)),
                'tag_colors' => array_values(array_map(fn ($t) => (string) ($t->color ?: ''), $tags)),
                'primary_tag' => $primary ?? '',
                'prefixes' => $prefixes,
                'restricted_tag_ids' => array_values(array_map(
                    fn ($t) => (int) $t->id,
                    array_filter($tags, fn ($t) => (int) $t->is_restricted === 1)
                )),
                'author_id' => (int) ($r->user_id ?? 0),
                'author' => (string) ($r->author_nick ?: $r->author ?: ''),
                'created_at' => $created,
                'last_post_at' => $lastPost,
                'comment_count' => (int) $r->comment_count,
                'participant_count' => (int) $r->participant_count,
                'views' => (int) ($r->view_count ?? 0),
                'reactions' => (int) ($reactions[$r->id] ?? 0),
                'length' => mb_strlen($excerpt),
                'is_sticky' => (bool) $r->is_sticky,
                'is_locked' => (bool) $r->is_locked,
                'is_private' => (bool) $r->is_private,
                'is_hidden' => $r->hidden_at !== null,
                'is_approved' => true,
                'is_guide' => (bool) ($r->is_guide ?? false),
                // Read off the stored TextFormatter XML rather than off the
                // plain-text excerpt, because `Text::plain` has already thrown
                // the markup away by then. s9e emits `<IMG src="…">` for an
                // image and `<UPL>`/`<UPL-IMAGE>` for a fl-uploads attachment;
                // matching the tag names is exact, where matching an extension
                // in the URL would miss a CDN link with a query string.
                'has_images' => $excerptSource !== null && (bool) preg_match(
                    '/<(IMG|UPL[A-Z-]*)\b/i',
                    $excerptSource
                ),
                // NOT populated: no best-answer extension is installed on this
                // forum, so this is a constant false. Kept as a field (rather
                // than removed) because the filter and the facet are already
                // wired end-to-end, so the day flarum/likes-style best answers
                // land, one line here turns the whole surface on. A facet whose
                // only value is `false` is hidden by the facet shaper.
                'has_best_answer' => false,
                'lang' => $lang,
                'rank_score' => $this->rankScore(
                    (int) ($reactions[$r->id] ?? 0),
                    (int) $r->comment_count,
                    (int) ($r->view_count ?? 0),
                    $lastPost,
                    (float) ($r->hotness ?? 0)
                ),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ posts

    /** @return array<int, array> keyed by post id */
    public function posts(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $rows = $this->db->table('posts as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('discussions as d', 'd.id', '=', 'p.discussion_id')
            ->whereIn('p.id', $ids)
            ->where('p.type', 'comment')
            ->get([
                'p.id', 'p.discussion_id', 'p.number', 'p.created_at', 'p.user_id',
                'p.content', 'p.hidden_at', 'p.is_private',
                'u.username as author', 'u.nickname as author_nick',
                'd.title as discussion_title', 'd.slug as discussion_slug',
                'd.first_post_id', 'd.is_private as d_private', 'd.hidden_at as d_hidden',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $discussionIds = $rows->pluck('discussion_id')->unique()->filter()->all();
        $tagRows = $discussionIds
            ? $this->db->table('discussion_tag as dt')
                ->join('tags as t', 't.id', '=', 'dt.tag_id')
                ->whereIn('dt.discussion_id', $discussionIds)
                ->get(['dt.discussion_id', 't.id', 't.slug', 't.name', 't.position', 't.is_restricted'])
            : collect();
        $tagsBy = [];
        foreach ($tagRows as $t) {
            $tagsBy[$t->discussion_id][] = $t;
        }

        $reactions = $this->reactionCountsForPosts($rows->pluck('id')->all());

        $out = [];
        foreach ($rows as $r) {
            $content = Text::plain($r->content);
            if ($content === '') {
                // A post with no words after quote-stripping is a bare quote or
                // a lone image. Indexing it adds a document that can never
                // match a text query but still costs storage on every search.
                continue;
            }
            $tags = $tagsBy[$r->discussion_id] ?? [];
            $created = $this->ts($r->created_at);
            $react = (int) ($reactions[$r->id] ?? 0);
            $lang = Text::guessLang($content);

            $out[$r->id] = [
                'id' => (int) $r->id,
                'discussion_id' => (int) $r->discussion_id,
                'discussion_title' => (string) ($r->discussion_title ?? ''),
                'discussion_slug' => (string) ($r->discussion_slug ?? ''),
                'number' => (int) ($r->number ?? 0),
                'content' => $content,
                'content_s' => Text::fold($content),
                'discussion_title_s' => Text::fold((string) ($r->discussion_title ?? '')),
                'content_de' => $lang === 'de' ? Text::fold($content) : null,
                'author_id' => (int) ($r->user_id ?? 0),
                'author' => (string) ($r->author_nick ?: $r->author ?: ''),
                'created_at' => $created,
                'reactions' => $react,
                'length' => mb_strlen($content),
                'tag_ids' => array_values(array_map(fn ($t) => (int) $t->id, $tags)),
                'tag_slugs' => array_values(array_map(fn ($t) => (string) $t->slug, $tags)),
                'prefixes' => array_values(array_map(
                    fn ($t) => (string) $t->name,
                    array_filter($tags, fn ($t) => $t->position === null)
                )),
                'restricted_tag_ids' => array_values(array_map(
                    fn ($t) => (int) $t->id,
                    array_filter($tags, fn ($t) => (int) $t->is_restricted === 1)
                )),
                'is_hidden' => $r->hidden_at !== null || $r->d_hidden !== null,
                'is_private' => (bool) ($r->is_private || $r->d_private),
                'is_approved' => true,
                'is_first' => (int) $r->id === (int) $r->first_post_id,
                'lang' => $lang,
                'rank_score' => $this->rankScore($react, 0, 0, $created, 0),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ users

    public function users(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $cols = ['u.id', 'u.username', 'u.nickname', 'u.joined_at', 'u.comment_count',
            'u.discussion_count', 'u.suspended_until', 'u.avatar_url'];
        foreach (['points', 'rank_slug'] as $c) {
            if ($this->hasColumn('users', $c)) {
                $cols[] = "u.$c";
            }
        }

        $rows = $this->db->table('users as u')->whereIn('u.id', $ids)->get($cols);
        if ($rows->isEmpty()) {
            return [];
        }

        $groupRows = $this->db->table('group_user as gu')
            ->join('groups as g', 'g.id', '=', 'gu.group_id')
            ->whereIn('gu.user_id', $rows->pluck('id')->all())
            ->get(['gu.user_id', 'g.id', 'g.name_singular', 'g.color']);
        $groupsBy = [];
        foreach ($groupRows as $g) {
            $groupsBy[$g->user_id][] = $g;
        }

        $out = [];
        foreach ($rows as $r) {
            $groups = $groupsBy[$r->id] ?? [];
            $out[$r->id] = [
                'id' => (int) $r->id,
                'username' => (string) $r->username,
                'display_name' => (string) ($r->nickname ?: $r->username),
                'bio' => '',
                'avatar_url' => (string) ($r->avatar_url ?? ''),
                'group_ids' => array_values(array_map(fn ($g) => (int) $g->id, $groups)),
                'group_names' => array_values(array_map(fn ($g) => (string) $g->name_singular, $groups)),
                'group_color' => (string) ($groups[0]->color ?? ''),
                'joined_at' => $this->ts($r->joined_at),
                'posts_count' => (int) ($r->comment_count ?? 0),
                'discussion_count' => (int) ($r->discussion_count ?? 0),
                'points' => (int) ($r->points ?? 0),
                'rank_slug' => (string) ($r->rank_slug ?? ''),
                'is_suspended' => $r->suspended_until !== null,
                'rank_score' => log1p((int) ($r->comment_count ?? 0)) + log1p((int) ($r->points ?? 0)),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------- tags

    public function tags(array $ids = []): array
    {
        $q = $this->db->table('tags as t')->leftJoin('tags as p', 'p.id', '=', 't.parent_id');
        if ($ids) {
            $q->whereIn('t.id', $ids);
        }
        $rows = $q->get([
            't.id', 't.name', 't.slug', 't.description', 't.color', 't.icon',
            't.parent_id', 't.position', 't.discussion_count', 't.is_restricted', 't.is_hidden',
            'p.name as parent_name',
        ]);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->id] = [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'slug' => (string) $r->slug,
                'description' => (string) ($r->description ?? ''),
                'color' => (string) ($r->color ?? ''),
                'icon' => (string) ($r->icon ?? ''),
                'parent_id' => $r->parent_id === null ? 0 : (int) $r->parent_id,
                'parent_name' => (string) ($r->parent_name ?? ''),
                'position' => $r->position === null ? -1 : (int) $r->position,
                'is_child' => $r->parent_id !== null,
                'is_prefix' => $r->position === null,
                'is_restricted' => (bool) $r->is_restricted,
                'is_hidden' => (bool) $r->is_hidden,
                'discussion_count' => (int) ($r->discussion_count ?? 0),
            ];
        }

        return $out;
    }

    // -------------------------------------------------------------- internals

    private function reactionCountsForPosts(array $postIds): array
    {
        if (!$postIds || !$this->hasTable('post_likes')) {
            return [];
        }

        return $this->db->table('post_likes')
            ->whereIn('post_id', $postIds)
            ->groupBy('post_id')
            ->pluck($this->db->raw('COUNT(*)'), 'post_id')
            ->all();
    }

    private function reactionCountsForDiscussions(array $discussionIds): array
    {
        if (!$discussionIds || !$this->hasTable('post_likes')) {
            return [];
        }

        return $this->db->table('post_likes as pl')
            ->join('posts as p', 'p.id', '=', 'pl.post_id')
            ->whereIn('p.discussion_id', $discussionIds)
            ->groupBy('p.discussion_id')
            ->pluck($this->db->raw('COUNT(*)'), 'p.discussion_id')
            ->all();
    }

    /**
     * The materialised quality signal.
     *
     * This is a TIE-BREAK, not a sort: it runs as the last ranking rule, after
     * `exactness`, so it only orders documents Meilisearch already considers
     * equally relevant. That is why it can be crude and still be right — it
     * never promotes an irrelevant thread, it only decides which of two equally
     * relevant threads a reader would rather have.
     *
     * Logs, not raw counts, because engagement is Zipfian: without them a
     * single 800-reply thread outranks every good answer on the board forever.
     * Recency is a half-life rather than a cliff so that a genuinely good old
     * guide stays reachable — the corpus's most valuable content is years old.
     */
    private function rankScore(int $reactions, int $comments, int $views, int $lastActivity, float $hotness): float
    {
        $ageDays = max(0, (time() - $lastActivity) / 86400);
        $recency = exp(-$ageDays / 365.0);          // half-life ≈ 253 days

        return round(
            2.2 * log1p(max(0, $reactions))
            + 1.4 * log1p(max(0, $comments))
            + 0.6 * log1p(max(0, $views))
            + 3.0 * $recency
            + 0.5 * min(10.0, $hotness),
            4
        );
    }

    private function ts($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        try {
            return (new \DateTimeImmutable((string) $value))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }
}
