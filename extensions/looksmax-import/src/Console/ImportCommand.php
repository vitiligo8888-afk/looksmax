<?php

namespace Local\Import\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;
use Local\Import\HtmlToBbcode;

/**
 * Import scraped XenForo content into Flarum.
 *
 * Runs inside Flarum's container on purpose. Post content in Flarum is
 * s9e/TextFormatter XML, not HTML or markdown, so anything that writes rows
 * directly ends up with a forum full of visible markup. Going through
 * CommentPost::reply() means the formatter, the slug generator and the
 * denormalised counters are all handled by the same code paths the forum uses
 * at runtime.
 *
 * Every row is keyed on its source id, so re-running is safe and resumable.
 *
 *   php flarum lmx:import --db=/data/looksmax.db --forum=2 --discussions=500
 */
class ImportCommand extends AbstractCommand
{
    private \PDO $src;
    private array $userMap = [];   // source user id => flarum user id
    private array $tagMap = [];    // slug => Tag

    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('lmx:import')
            ->setDescription('Import scraped forum content into Flarum')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'path to the scrape sqlite file', '/data/looksmax.db')
            ->addOption('forum', null, InputOption::VALUE_REQUIRED, 'only this source forum id')
            ->addOption('discussions', null, InputOption::VALUE_REQUIRED, 'max discussions to import', 200)
            ->addOption('with-posts', null, InputOption::VALUE_NONE, 'import post bodies where scraped')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'wipe imported content first')
            ->addOption('media-manifest', null, InputOption::VALUE_REQUIRED,
                'tsv of "source image url<TAB>local path", used to serve images from our own copy')
            ->addOption('media-base', null, InputOption::VALUE_REQUIRED,
                'url prefix the manifest paths are served under', '/media')
            ->addOption('reconvert', null, InputOption::VALUE_NONE,
                're-run the converter over already-imported posts instead of importing new ones')
            ->addOption('backfill-imported-id', null, InputOption::VALUE_NONE,
                'recover posts.imported_id for rows imported before that column existed')
            ->addOption('order', null, InputOption::VALUE_REQUIRED,
                'thread selection order: "replies" (busiest first, non-resumable) or '
                .'"id" (ascending, resumable via a persisted cursor)', 'replies')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED,
                'index of this shard, 0-based; shards take disjoint thread slices', 0)
            ->addOption('shards', null, InputOption::VALUE_REQUIRED,
                'total number of parallel shards (1 = the original single-cursor behaviour)', 1)
            ->addOption('reset-cursor', null, InputOption::VALUE_NONE,
                'restart --order=id from the beginning of the thread table')
            ->addOption('posts-per-thread', null, InputOption::VALUE_REQUIRED,
                'max posts to import per thread (0 = all)', '0');
    }

    protected function fire()
    {
        $path = $this->input->getOption('db');
        if (!file_exists($path)) {
            $this->error("scrape db not found at {$path}");
            return 1;
        }

        $this->src = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $this->src->exec('PRAGMA query_only = 1');

        if ($this->input->getOption('fresh')) {
            $this->wipe();
        }

        if ($this->input->getOption('backfill-imported-id')) {
            $n = $this->backfillImportedIds();
            $this->info("done: {$n} posts linked to their source row");

            return 0;
        }

        if ($this->input->getOption('reconvert')) {
            $n = $this->reconvert();
            $this->info("done: {$n} posts rewritten");

            return 0;
        }

        $this->importTags();
        $count = $this->importDiscussions();

        $this->recountUsers();

        $this->info("done: {$count} discussions");
        $this->info('users: ' . User::query()->count() . ', discussions: ' . Discussion::query()->count()
            . ', posts: ' . CommentPost::query()->count() . ', tags: ' . Tag::query()->count());
        $this->reportConversionFailures();

        return 0;
    }

    /**
     * Categories become parent tags, forums become children, prefixes become
     * secondary tags. XenForo allows one prefix per thread and Flarum allows
     * many secondary tags, so this is the one place the mirror is strictly
     * richer than the source.
     */
    private function importTags(): void
    {
        $this->info('tags…');
        $position = 0;

        $cats = $this->src->query('SELECT id, title FROM categories ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $catTag = [];
        foreach ($cats as $c) {
            $tag = $this->tag('c-' . $c['id'], $c['title'], null, $position++, '#2a323d');
            $catTag[$c['id']] = $tag;
        }

        $forums = $this->src->query(
            'SELECT id, title, slug, description, category_id, parent_id FROM forums ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($forums as $f) {
            $parent = $catTag[$f['category_id']] ?? null;
            $this->tag('f-' . $f['id'], $f['title'], $parent?->id, $position++, '#7aa2f7', $f['description']);
        }

        // prefixes, coloured to match the theme's tag classes
        $colours = [
            'Guide' => '#9ece6a', 'Blackpill' => '#1f2430', 'LifeFuel' => '#e8c07d',
            'JFL' => '#3b2f4a', 'Rage' => '#f7768e', 'Serious' => '#7aa2f7',
            'NSFW' => '#5a1f2b', 'Theory' => '#bb9af7', 'Discussion' => '#414868',
        ];
        $prefixes = $this->src->query(
            'SELECT DISTINCT prefix FROM threads WHERE prefix IS NOT NULL'
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($prefixes as $p) {
            // position MUST stay null: in Flarum a tag with a position is a
            // PRIMARY tag and renders as a top-level tile on the index. These
            // are prefixes, so they belong as secondary tags applied alongside
            // a forum, not as 25 empty cards on the front page.
            $this->tag('p-' . $this->slugify($p), $p, null, null, $colours[$p] ?? '#414868');
        }

        $this->info('  ' . count($this->tagMap) . ' tags');
    }

    /**
     * Per-forum icons. A forum index where every entry shares one glyph carries
     * no information; these are chosen per section so the structure is
     * scannable at a glance.
     */
    private const ICONS = [
        'f-2' => 'fas fa-dumbbell',        'f-3' => 'fas fa-comments',
        'f-7' => 'fas fa-star-half-alt',   'f-8' => 'fas fa-sack-dollar',
        'f-9' => 'fas fa-award',           'f-11' => 'fas fa-bullhorn',
        'f-16' => 'fas fa-circle-question','f-17' => 'fab fa-bitcoin',
        'f-19' => 'fas fa-language',       'f-20' => 'fas fa-language',
        'f-21' => 'fas fa-globe',          'f-22' => 'fas fa-language',
        'f-24' => 'fas fa-earth-americas', 'f-25' => 'fas fa-language',
        'f-26' => 'fas fa-user-lock',      'f-27' => 'fas fa-user-doctor',
        'f-28' => 'fas fa-heart-pulse',    'f-29' => 'fas fa-language',
        'c-1' => 'fas fa-layer-group',     'c-10' => 'fas fa-circle-info',
        'c-18' => 'fas fa-globe',
    ];

    private function tag(string $slug, string $name, ?int $parentId, ?int $position, string $colour, ?string $desc = null): Tag
    {
        $tag = Tag::query()->where('slug', $slug)->first() ?: new Tag();
        if (isset(self::ICONS[$slug])) {
            $tag->icon = self::ICONS[$slug];
        } elseif (str_starts_with($slug, 'p-')) {
            $tag->icon = 'fas fa-tag';
        }
        $tag->name = $name;
        $tag->slug = $slug;
        $tag->description = $desc ?? '';
        $tag->color = $colour;
        $tag->position = $position;
        $tag->parent_id = $parentId;
        $tag->is_hidden = false;
        $tag->save();

        return $this->tagMap[$slug] = $tag;
    }

    private function importDiscussions(): int
    {
        $limit = (int) $this->input->getOption('discussions');
        $forum = $this->input->getOption('forum');
        $withPosts = (bool) $this->input->getOption('with-posts');

        /*
         * ---------------------------------------------------------------
         * Two selection modes, because "busiest first" cannot bulk-import.
         * ---------------------------------------------------------------
         * The original query was `ORDER BY t.replies DESC LIMIT n`, with
         * already-imported threads skipped in PHP afterwards. That is fine for
         * seeding a demo forum and structurally incapable of importing the
         * corpus: every run re-reads the SAME top-n threads by reply count, so
         * once those n are in, every subsequent run imports zero and thread
         * n+1 is never reached. Measured: the loop reported "done: 0
         * discussions" cycle after cycle at 481/2,205,192 threads and backed
         * off, which reads exactly like "nothing left to do".
         *
         * --order=id walks the thread table by primary key from a persisted
         * cursor, so each batch is O(batch) rather than O(corpus), the work is
         * strictly monotonic, and it resumes correctly after a crash or a
         * restart. The cursor advances past threads that are SKIPPED as well as
         * imported — otherwise a run of unscraped threads pins it forever.
         *
         * The 2.2M-thread / ~28M-post target only works this way.
         */
        $order = strtolower((string) $this->input->getOption('order'));
        if (! in_array($order, ['replies', 'id'], true)) {
            $this->error("--order must be 'replies' or 'id'");

            return 0;
        }

        if ($this->input->getOption('reset-cursor')) {
            $this->setCursor(0);
            $this->info('thread cursor reset to 0');
        }

        $sql = 'SELECT t.*, f.id AS fid FROM threads t LEFT JOIN forums f ON f.id = t.forum_id';
        $where = [];
        $args = [];
        if ($forum) {
            $where[] = 't.forum_id = :forum';
            $args['forum'] = (int) $forum;
        }
        // With --with-posts, only take threads whose bodies were actually
        // scraped; otherwise the busiest threads import as empty placeholders.
        if ($withPosts) {
            $where[] = 'EXISTS (SELECT 1 FROM posts p WHERE p.thread_id = t.id)';
        }

        /*
         * Disjoint slice per shard, so parallel importers never contend.
         *
         * The two integers are interpolated, NOT bound. PDO's SQLite driver
         * binds them as TEXT, and SQLite compares across storage classes by
         * class first, so `t.id % '6' = '0'` is false for every row — the query
         * returned 0 candidates against 189,598 eligible threads and reported
         * "reached the end of the thread table". Both values are cast to int
         * immediately above, so there is nothing to inject.
         */
        $shards = max(1, (int) $this->input->getOption('shards'));
        if ($shards > 1) {
            $shard = ((int) $this->input->getOption('shard')) % $shards;
            $where[] = "t.id % $shards = $shard";
        }

        $cursor = 0;
        if ($order === 'id') {
            $cursor = $this->cursor();
            $where[] = 't.id > :cursor';
            $args['cursor'] = $cursor;
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= $order === 'id'
            ? ' ORDER BY t.id ASC LIMIT ' . $limit
            // busiest first: better test data than a random slice
            : ' ORDER BY t.replies DESC LIMIT ' . $limit;

        $stmt = $this->src->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($order === 'id') {
            $this->info('discussions… (cursor '.$cursor.', '.count($rows).' candidate threads)');
        } else {
            $this->info('discussions…');
        }
        $n = 0;
        $maxThreadId = $cursor;

        foreach ($rows as $r) {
            // Advance the cursor over EVERY row examined, imported or not.
            $maxThreadId = max($maxThreadId, (int) $r['id']);

            if (Discussion::query()->where('imported_id', $r['id'])->exists()) {
                continue;
            }

            $author = $this->user((int) ($r['author_id'] ?? 0), $r['author_name'] ?? null);

            $posts = $withPosts ? $this->postsFor((int) $r['id']) : [];
            $body = $this->bodyOf($posts[0] ?? null)
                ?: ($r['title'] . "\n\n" . resolve('translator')->trans('local-looksmax-import.lib.no_body'));

            $title = (string) ($r['title'] ?: resolve('translator')->trans('local-looksmax-import.lib.untitled'));
            if (!mb_check_encoding($title, 'UTF-8')) {
                $title = mb_convert_encoding($title, 'UTF-8', 'UTF-8');
            }
            $discussion = Discussion::start($title, $author);
            $discussion->created_at = $this->ts($r['created_ts']);
            $discussion->save();

            $post = CommentPost::reply($discussion->id, $body, $author->id, null);
            $post->created_at = $this->ts($r['created_ts']);
            // provenance, so a quote of this post can be resolved to a jump link
            $post->imported_id = isset($posts[0]['id']) ? (int) $posts[0]['id'] : null;
            $post->save();

            // remaining posts, when we have them
            foreach (array_slice($posts, 1) as $p) {
                $pa = $this->user((int) ($p['author_id'] ?? 0), $p['author_name'] ?? null);
                $reply = CommentPost::reply($discussion->id, $this->bodyOf($p) ?: resolve('translator')->trans('local-looksmax-import.lib.empty_post'), $pa->id, null);
                $reply->created_at = $this->ts($p['posted_ts']);
                $reply->imported_id = isset($p['id']) ? (int) $p['id'] : null;
                $reply->save();
            }

            // tags: forum + prefix
            $tagIds = [];
            if (isset($this->tagMap['f-' . $r['forum_id']])) {
                $tagIds[] = $this->tagMap['f-' . $r['forum_id']]->id;
            }
            if (!empty($r['prefix']) && isset($this->tagMap['p-' . $this->slugify($r['prefix'])])) {
                $tagIds[] = $this->tagMap['p-' . $this->slugify($r['prefix'])]->id;
            }
            if ($tagIds) {
                $discussion->tags()->sync($tagIds);
            }

            $discussion->refreshCommentCount();
            $discussion->refreshParticipantCount();
            $discussion->refreshLastPost();
            $discussion->is_sticky = (bool) $r['sticky'];
            $discussion->is_locked = (bool) $r['locked'];
            $discussion->imported_id = $r['id'];
            $discussion->view_count = (int) ($r['views'] ?? 0);
            $discussion->save();

            if (++$n % 25 === 0) {
                $this->info("  {$n}…");
            }
        }

        /*
         * The cursor is written AFTER the batch, not per row.
         *
         * If the process dies mid-batch the cursor stays where it was and the
         * batch is replayed. That is safe precisely because every write in the
         * loop is keyed on imported_id and skipped when it already exists —
         * replaying costs a few existence checks, whereas advancing early would
         * silently skip threads forever.
         */
        if ($order === 'id') {
            $this->setCursor($maxThreadId);
            $exhausted = count($rows) < $limit;
            $this->info(
                'cursor now '.$maxThreadId
                .($exhausted ? ' — reached the end of the thread table' : '')
            );
        }

        return $n;
    }

    /**
     * The resume point for --order=id, kept in Flarum's settings table.
     *
     * Deliberately NOT derived from MAX(discussions.imported_id): threads that
     * are skipped (no scraped posts, filtered out by --forum) never produce a
     * discussion, so a derived cursor would stall on the first such run and
     * re-scan it on every subsequent cycle.
     */
    private const CURSOR_KEY = 'lmx.import.thread_cursor';

    /**
     * Cursor key for THIS shard.
     *
     * One global cursor is what makes the importer single-threaded: two
     * processes reading the same key both take the same window, do the same
     * work, and then race each other writing the resume point backwards. So the
     * cursor is per shard, and each shard owns a disjoint slice of thread ids
     * (`id % shards = shard`), which means N processes make N times the
     * progress with no overlap and no coordination.
     *
     * `--shards 1` keeps the original key, so an existing deployment resumes
     * exactly where it was rather than restarting the corpus.
     */
    private function cursorKey(): string
    {
        $shards = max(1, (int) $this->input->getOption('shards'));

        if ($shards === 1) {
            return self::CURSOR_KEY;
        }

        return self::CURSOR_KEY.'.s'.((int) $this->input->getOption('shard')).'of'.$shards;
    }

    private function cursor(): int
    {
        $row = $this->db->table('settings')->where('key', $this->cursorKey())->first();

        return $row ? (int) $row->value : 0;
    }

    private function setCursor(int $value): void
    {
        $this->db->table('settings')->updateOrInsert(
            ['key' => $this->cursorKey()],
            ['value' => (string) $value]
        );
    }

    /**
     * Convert the stored html rather than using the plain text column. Measured
     * across the corpus, text discards ~63% of each post: 66% of posts carry a
     * link, 65% a quote, 16% smilies, 13% attachments, 8% inline images, and
     * 18,625 contain an @mention.
     */
    private function bodyOf(?array $p): string
    {
        if (!$p) {
            return '';
        }

        /*
         * One bad post must never take down a batch.
         *
         * This ran against 383,370 rows with a `: string` return type in the
         * converter that could return null on malformed UTF-8, and a single
         * Russian post with a truncated multi-byte sequence killed an entire
         * 2,000-discussion batch with a fatal TypeError. The import loop then
         * retried the same batch, hit the same row, and died again — a hard
         * stop, not a slowdown.
         *
         * The underlying converter bug is fixed (HtmlToBbcode::text() and
         * ::squash() now scrub and guard every /u pattern), but the structural
         * problem is that ANY future converter defect has the same blast
         * radius. So: catch per post, record the source id, fall back to the
         * plain-text column, and keep going. A degraded post is recoverable
         * with --reconvert; an aborted batch is not recoverable at all.
         */
        try {
            $converted = $this->converter()->convert($p['html'] ?? null);
        } catch (\Throwable $e) {
            $this->failedConversions++;
            if (count($this->failedIds) < 200) {
                $this->failedIds[] = (int) ($p['id'] ?? 0);
            }
            $this->error(sprintf(
                'convert failed for source post %s: %s (%s:%d) — falling back to plain text',
                $p['id'] ?? '?',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine()
            ));
            $converted = '';
        }

        $body = $converted !== '' ? $converted : (string) ($p['text'] ?? '');

        // s9e/TextFormatter throws "Invalid UTF-8 input" on the first bad byte
        // and aborts the whole import. Scraped content contains them, so scrub
        // on the way out as well as on the way in.
        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        }

        return $body;
    }

    private ?HtmlToBbcode $conv = null;

    /** Posts whose conversion threw and fell back to plain text. */
    private int $failedConversions = 0;

    /** @var int[] source post ids of the first 200 failures, for triage */
    private array $failedIds = [];

    /** source user id => ['id' => local id, 'name' => display name] */
    private array $mentionMap = [];

    /** source image url => local path we serve it from */
    private array $mediaMap = [];

    /**
     * The converter, with both of its lookup tables.
     *
     * The mention map is keyed on the SOURCE user id, not on the username.
     * Keying it on the name was the mention bug: usernames are sanitised on
     * import (spaces become underscores, symbols are dropped, the result is
     * truncated to 28 characters), so every member whose display name was not
     * already a bare identifier failed to match and their mentions rendered as
     * plain text. The source id survives that transformation untouched, and
     * users.imported_id stores it.
     *
     * It is also kept live: user() feeds every account it creates back in, so
     * a mention of somebody first seen halfway through the run resolves for
     * every post after that. Posts written before that point are repaired by
     * --reconvert, which runs with the finished map.
     */
    private function converter(): HtmlToBbcode
    {
        if (!$this->conv) {
            foreach (User::query()->whereNotNull('imported_id')->get(['id', 'username', 'nickname', 'imported_id']) as $u) {
                $this->mentionMap[(int) $u->imported_id] = [
                    'id' => (int) $u->id,
                    'name' => (string) ($u->nickname ?: $u->username),
                ];
            }
            $this->info('  mention map: ' . count($this->mentionMap) . ' imported users');

            $this->loadMediaManifest();

            $this->conv = new HtmlToBbcode($this->mentionMap, $this->mediaMap);
        }

        return $this->conv;
    }

    private function rememberUser(int $srcId, User $user): void
    {
        if ($srcId <= 0) {
            return;
        }
        $this->mentionMap[$srcId] = ['id' => (int) $user->id, 'name' => (string) $user->display_name];
        if ($this->conv) {
            $this->conv->setUserMap($this->mentionMap);
        }
    }

    /**
     * url -> local path for the images we downloaded.
     *
     * The manifest is produced by tools/media-manifest.ts on the scraper host,
     * because the on-disk filenames are Bun.hash() of the url and that hash is
     * not reproducible from PHP. Without it every post image is hotlinked from
     * looksmax.org, which is both slow and a live dependency on the board we
     * are mirroring.
     */
    private function loadMediaManifest(): void
    {
        $path = $this->input->getOption('media-manifest');
        if (!$path) {
            return;
        }
        if (!is_readable($path)) {
            $this->error("media manifest not readable at {$path}");
            return;
        }

        $base = rtrim((string) $this->input->getOption('media-base'), '/');
        $fh = fopen($path, 'r');
        $n = 0;
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $this->mediaMap[$parts[0]] = $base . '/' . ltrim($parts[1], '/');
            $n++;
        }
        fclose($fh);
        $this->info("  media manifest: {$n} local images under {$base}");
    }

    /**
     * Make degraded posts loud rather than silent.
     *
     * A fallback to the plain-text column loses images, quotes, mentions and
     * emphasis for that post. It is the right call in the moment — far better
     * than aborting — but it must never pass unnoticed, because the whole
     * defect class being fixed here is "content quietly arrives flattened".
     */
    private function reportConversionFailures(): void
    {
        if ($this->failedConversions === 0) {
            return;
        }

        $this->error(sprintf(
            '%d post(s) fell back to plain text after a converter exception. '
            .'Fix the converter and re-run with --reconvert to repair them.',
            $this->failedConversions
        ));
        $this->error('  source post ids: '.implode(',', $this->failedIds)
            .($this->failedConversions > count($this->failedIds) ? ' …' : ''));
    }

    /**
     * Recover posts.imported_id for rows written before that column existed.
     *
     * -------------------------------------------------------------------
     * Why this is needed
     * -------------------------------------------------------------------
     * imported_id is the whole idempotency and repair story: --reconvert finds
     * work by it, re-import skips by it, and quote backlinks resolve through
     * it. The first 6,240 posts were imported before the migration that adds
     * it, so every one of them was NULL — which meant --reconvert examined 0
     * rows and reported success. A converter fix could not reach a single
     * existing post. Silent, and it read as green.
     *
     * -------------------------------------------------------------------
     * How the match is made
     * -------------------------------------------------------------------
     * Ordinal alignment alone would be a guess, so it is corroborated:
     *
     *   discussion.imported_id  gives the source thread id
     *   posts within a discussion, ordered by number, were written in exactly
     *     the order postsFor() returned them — ORDER BY position
     *   created_at was stamped from posts.posted_ts
     *   user.imported_id gives the source author id
     *
     * A pair is accepted only when the timestamp agrees to the second AND the
     * author agrees. Anything else is left NULL and counted, because a wrong
     * link is far worse than a missing one: it would rewrite a post with some
     * other post's body.
     */
    private function backfillImportedIds(): int
    {
        $this->info('backfilling posts.imported_id…');

        // source author id => local user id, to compare authors
        $localToSource = [];
        foreach (User::query()->whereNotNull('imported_id')->get(['id', 'imported_id']) as $u) {
            $localToSource[(int) $u->id] = (int) $u->imported_id;
        }

        $srcPosts = $this->src->prepare(
            'SELECT id, author_id, posted_ts FROM posts WHERE thread_id = ? ORDER BY position'
        );

        $linked = 0;
        $ambiguous = 0;
        $noThread = 0;
        $discussions = 0;

        Discussion::query()
            ->whereNotNull('imported_id')
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (
                $srcPosts, $localToSource, &$linked, &$ambiguous, &$noThread, &$discussions
            ) {
                foreach ($chunk as $discussion) {
                    $discussions++;

                    $srcPosts->execute([(int) $discussion->imported_id]);
                    $source = $srcPosts->fetchAll(\PDO::FETCH_ASSOC);
                    if (! $source) {
                        $noThread++;
                        continue;
                    }

                    $local = CommentPost::query()
                        ->where('discussion_id', $discussion->id)
                        ->whereNull('imported_id')
                        ->orderBy('number')
                        ->orderBy('id')
                        ->get(['id', 'user_id', 'created_at', 'number']);

                    foreach ($local as $i => $post) {
                        if (! isset($source[$i])) {
                            break;
                        }
                        $row = $source[$i];

                        // Corroboration 1: the timestamp the importer stamped.
                        $ts = (int) $row['posted_ts'];
                        $localTs = $post->created_at ? $post->created_at->getTimestamp() : 0;
                        if ($ts > 0 && abs($localTs - $ts) > 1) {
                            $ambiguous++;
                            continue;
                        }

                        // Corroboration 2: the author.
                        $srcAuthor = (int) ($row['author_id'] ?? 0);
                        $mapped = $localToSource[(int) $post->user_id] ?? null;
                        if ($srcAuthor > 0 && $mapped !== null && $mapped !== $srcAuthor) {
                            $ambiguous++;
                            continue;
                        }

                        $this->db->table('posts')
                            ->where('id', $post->id)
                            ->update(['imported_id' => (int) $row['id']]);
                        $linked++;
                    }
                }

                $this->info("  {$discussions} discussions, {$linked} posts linked…");
            });

        $this->info(
            "backfill: {$linked} linked, {$ambiguous} rejected on timestamp/author mismatch, "
            ."{$noThread} discussions whose thread has no scraped posts"
        );

        return $linked;
    }

    /**
     * Re-run the converter over posts already in the forum.
     *
     * This is how a converter fix reaches 200k existing posts without a
     * re-import, and how mentions to users imported later than the post that
     * mentions them get resolved. It reads the html straight back out of the
     * scrape by posts.imported_id, so it is exact rather than a text-level
     * patch of what is already stored.
     */
    private function reconvert(): int
    {
        $this->converter();

        $stmt = $this->src->prepare('SELECT html, text FROM posts WHERE id = ?');
        $changed = 0;
        $seen = 0;
        $missing = 0;

        CommentPost::query()
            ->whereNotNull('imported_id')
            ->orderBy('id')
            ->chunkById(500, function ($posts) use ($stmt, &$changed, &$seen, &$missing) {
                foreach ($posts as $post) {
                    $seen++;
                    $stmt->execute([$post->imported_id]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$row) {
                        $missing++;
                        continue;
                    }

                    $body = $this->bodyOf($row);
                    if ($body === '') {
                        continue;
                    }

                    $before = $post->getAttributes()['content'] ?? '';

                    // Parsing is a second place that can throw on hostile
                    // input (s9e rejects invalid UTF-8 outright). Same rule as
                    // conversion: record it, leave the existing row alone, keep
                    // going. Aborting here would strand the rest of the corpus
                    // on the old converter.
                    try {
                        $post->setContentAttribute($body, $post->user);
                    } catch (\Throwable $e) {
                        $this->failedConversions++;
                        if (count($this->failedIds) < 200) {
                            $this->failedIds[] = (int) $post->imported_id;
                        }
                        $this->error(sprintf(
                            'parse failed for source post %d: %s — left unchanged',
                            (int) $post->imported_id,
                            $e->getMessage()
                        ));
                        continue;
                    }

                    if (($post->getAttributes()['content'] ?? '') !== $before) {
                        $post->save();
                        $changed++;
                    }
                }

                if ($seen % 5000 < 500) {
                    $this->info("  {$seen} posts, {$changed} rewritten…");
                }
            });

        $this->info("reconvert: {$seen} posts examined, {$changed} rewritten, {$missing} with no scrape row");
        $this->reportConversionFailures();

        return $changed;
    }

    /**
     * Every scraped post in the thread, in source order.
     *
     * This was `LIMIT 60`, which silently truncated every busy thread — and
     * "busy" is exactly what --order=replies selects for, so the default import
     * dropped the majority of the content it was pointed at. The corpus has
     * threads in the thousands of posts; a reply at position 900 quoting a post
     * at position 40 also lost its backlink target.
     *
     * --posts-per-thread is kept as an escape hatch (0 = all) rather than a
     * hardcoded cap, so a deliberate sample stays possible and an accidental
     * one does not.
     */
    private function postsFor(int $threadId): array
    {
        $cap = (int) $this->input->getOption('posts-per-thread');

        $sql = 'SELECT id, author_id, author_name, text, html, posted_ts FROM posts '
            .'WHERE thread_id = ? ORDER BY position';
        if ($cap > 0) {
            $sql .= ' LIMIT '.$cap;
        }

        $stmt = $this->src->prepare($sql);
        $stmt->execute([$threadId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Source users have no real email address, so one is synthesized in a
     * reserved domain and the account is left unactivated. The content is the
     * point; the accounts exist for attribution.
     */
    private function user(int $srcId, ?string $name): User
    {
        $key = $srcId ?: 'anon';
        if (isset($this->userMap[$key])) {
            return $this->userMap[$key];
        }

        $name = trim((string) $name) ?: ('user' . $srcId);
        $username = preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $name)) ?: ('user' . $srcId);
        $username = mb_substr($username, 0, 28);

        $existing = User::query()->where('imported_id', $srcId)->first()
            ?: User::query()->where('username', $username)->first();

        if ($existing) {
            $this->rememberUser($srcId, $existing);

            return $this->userMap[$key] = $existing;
        }

        $user = User::register($username, "u{$srcId}@import.invalid", bin2hex(random_bytes(12)));
        $user->is_email_confirmed = false;
        $user->imported_id = $srcId;

        // Backfill the real profile. Without this every imported member reads
        // "Joined a few seconds ago, Posts 0, Discussions 0", because register()
        // stamps joined_at with now and the counters are denormalised columns
        // that nothing recomputes for content inserted underneath them.
        $meta = $this->srcUser($srcId);
        if ($meta) {
            if (!empty($meta['joined']) && ($ts = strtotime((string) $meta['joined']))) {
                $user->joined_at = new \DateTime('@' . $ts);
            }
            if (!empty($meta['post_count'])) {
                $user->comment_count = (int) $meta['post_count'];
            }
            $user->last_seen_at = $user->joined_at ?? null;
        }

        $user->save();
        $this->rememberUser($srcId, $user);

        return $this->userMap[$key] = $user;
    }

    /**
     * Recompute per-user counters from actual rows.
     *
     * Flarum maintains discussion_count and comment_count through posting
     * events, which bulk inserts never fire, so every imported profile reads
     * "0 discussions" no matter how much they wrote. Carrying the source
     * board's counts over is worse: the number then disagrees with what is
     * actually clickable on this forum.
     */
    private function recountUsers(): void
    {
        $this->info('recomputing user counters…');
        $this->db->statement("
            UPDATE users u
               SET discussion_count = (
                    SELECT COUNT(*) FROM discussions d
                     WHERE d.user_id = u.id AND d.is_private = 0 AND d.hidden_at IS NULL
                   ),
                   comment_count = (
                    SELECT COUNT(*) FROM posts p
                      JOIN discussions d2 ON d2.id = p.discussion_id
                     WHERE p.user_id = u.id AND p.type = 'comment'
                       AND p.hidden_at IS NULL AND d2.is_private = 0 AND d2.hidden_at IS NULL
                   ),
                   last_seen_at = COALESCE(
                    (SELECT MAX(p2.created_at) FROM posts p2 WHERE p2.user_id = u.id),
                    u.last_seen_at, u.joined_at
                   )
        ");

        // tag counters and last activity are denormalised for the same reason
        $this->db->statement("
            UPDATE tags t
               SET discussion_count = (
                    SELECT COUNT(*) FROM discussion_tag dt
                      JOIN discussions d ON d.id = dt.discussion_id
                     WHERE dt.tag_id = t.id AND d.is_private = 0 AND d.hidden_at IS NULL
                   ),
                   last_posted_discussion_id = (
                    SELECT d.id FROM discussion_tag dt
                      JOIN discussions d ON d.id = dt.discussion_id
                     WHERE dt.tag_id = t.id AND d.is_private = 0 AND d.hidden_at IS NULL
                     ORDER BY d.last_posted_at DESC LIMIT 1
                   ),
                   last_posted_user_id = (
                    SELECT d.last_posted_user_id FROM discussion_tag dt
                      JOIN discussions d ON d.id = dt.discussion_id
                     WHERE dt.tag_id = t.id AND d.is_private = 0 AND d.hidden_at IS NULL
                     ORDER BY d.last_posted_at DESC LIMIT 1
                   )
        ");
    }

    /** Scraped profile row for a source user id. */
    private function srcUser(int $srcId): ?array
    {
        if ($srcId <= 0) {
            return null;
        }
        static $stmt = null;
        $stmt ??= $this->src->prepare(
            'SELECT name, title, joined, post_count, reputation, avatar FROM users WHERE id = ?'
        );
        $stmt->execute([$srcId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function ts($value): \DateTime
    {
        $t = (int) $value;
        if ($t < 1000000000) {
            $t = time();
        }

        return new \DateTime('@' . $t);
    }

    private function slugify(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    }

    private function wipe(): void
    {
        $this->info('wiping imported content…');
        $this->db->table('posts')->whereIn('discussion_id',
            $this->db->table('discussions')->whereNotNull('imported_id')->pluck('id'))->delete();
        $this->db->table('discussions')->whereNotNull('imported_id')->delete();
        $this->db->table('users')->whereNotNull('imported_id')->where('id', '!=', 1)->delete();
    }
}
