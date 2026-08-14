<?php

namespace Local\UserInfo\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Fill userinfo_profiles, and fix the counters the panel is about to print.
 *
 *     php flarum userinfo:backfill [--data=…] [--counts-only] [--verify]
 *
 * ── Why the recount is in here and not left to the importer ──────────────────
 * Flarum maintains users.discussion_count and users.comment_count from posting
 * events. A bulk import fires none of them, so every imported profile reads
 * "0 discussions" while its threads sit right there on the page. The importer
 * now recomputes them at the end of its run — but this extension displays those
 * numbers on every post, so it recomputes them itself rather than trusting that
 * an import happened afterwards. Two writers of the same derived value is fine
 * when both derive it from the same rows with the same predicate; a display
 * that trusts a counter it never verified is not.
 *
 * `--verify` prints the counters next to a fresh COUNT(*) and exits non-zero on
 * any disagreement, which is what the e2e harness calls.
 *
 * Symfony configure()/setName(), never a $signature property: Flarum 1.x builds
 * the console application eagerly, so a command with an empty name throws
 * during construction and takes the entire CLI down with it — every command,
 * including migrate.
 */
class BackfillCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('userinfo:backfill')
            ->setDescription('Recompute user counters and rebuild carried-over standing from the scrape sidecar')
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'path to profiles.sqlite',
                __DIR__ . '/../../data/profiles.sqlite')
            ->addOption('counts-only', null, InputOption::VALUE_NONE, 'only recompute denormalised counters')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'check counters against the rows and exit non-zero on drift');
    }

    protected function fire()
    {
        if ($this->input->getOption('verify')) {
            return $this->verify();
        }

        $this->recount();

        if ($this->input->getOption('counts-only')) {
            return $this->verify();
        }

        $path = (string) $this->input->getOption('data');
        if (!file_exists($path)) {
            $this->error("sidecar not found at {$path} — run bin/extract-profiles.py on the host first");

            return 1;
        }

        $src = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $src->exec('PRAGMA query_only = 1');

        $this->legacy($src);
        $this->reception($src);

        return $this->verify();
    }

    // ---------------------------------------------------------------- counters

    /**
     * Recompute every denormalised per-user counter from the rows themselves.
     *
     * The predicates match Flarum core's own (see UserMetadataUpdater): a
     * discussion counts when it is not private and not hidden, a comment counts
     * when the post is a visible comment on a visible discussion. Using a looser
     * predicate here would produce a profile number that no listing can
     * reproduce, which is worse than the zero it replaces.
     */
    private function recount(): void
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
                        AND p.is_private = 0 AND p.hidden_at IS NULL
                        AND d2.is_private = 0 AND d2.hidden_at IS NULL
                   ),
                   last_seen_at = COALESCE(
                     (SELECT MAX(p2.created_at) FROM posts p2 WHERE p2.user_id = u.id),
                     u.last_seen_at, u.joined_at
                   )
        ");

        $this->syncProfileCounts();

        // A discussion's own participant_count and comment_count are denormalised
        // for exactly the same reason and are just as wrong after a bulk insert.
        // The author panel does not print them, but the profile's "recent
        // activity" list does, and a thread that says "1 reply" while showing
        // forty is the same bug wearing a different hat.
        $this->db->statement("
            UPDATE discussions d
               SET comment_count = (
                     SELECT COUNT(*) FROM posts p
                      WHERE p.discussion_id = d.id AND p.type = 'comment' AND p.hidden_at IS NULL
                   ),
                   participant_count = (
                     SELECT COUNT(DISTINCT p.user_id) FROM posts p
                      WHERE p.discussion_id = d.id AND p.type = 'comment' AND p.hidden_at IS NULL
                   )
        ");
    }

    /**
     * Bring `userinfo_profiles.posts_here` / `.discussions_here` back into
     * agreement with the columns they mirror.
     *
     * ── The two-sources bug this closes ─────────────────────────────────────
     * One card was being fed by two counters. `users.comment_count` /
     * `users.discussion_count` are maintained by Flarum's own posting events
     * and are what the profile page, the user list and this panel print.
     * `posts_here` / `discussions_here` were written once, by reception(),
     * from those same columns AT THAT MOMENT — and reception() ran before the
     * import had finished and before recount() had ever been run, so the
     * snapshot froze the pre-backfill values and then never moved.
     *
     * Measured on this install before the fix (user 3015, Notcel):
     *
     *   userinfo_profiles.posts_here       6      users.comment_count     295
     *   userinfo_profiles.discussions_here 0      users.discussion_count    1
     *   computed_at 2026-08-13 10:09:44
     *
     * The reconciliation is deliberately one-directional. `users.*` is
     * AUTHORITATIVE: it is what core writes, what every other surface reads,
     * and what `verify()` checks against a live COUNT(*). The profile columns
     * are a SNAPSHOT kept for the backfill's own reporting and for anything
     * that needs the two numbers on one row — nothing on the render path reads
     * them (see Presenter, which takes both counts off the user). Making them
     * a second opinion is exactly how they came to disagree, so instead they
     * are recomputed here in the same statement that fixes `users.*`, and
     * `--verify` now fails on their drift too.
     */
    private function syncProfileCounts(): void
    {
        $this->info('reconciling profile count snapshots…');

        // A JOIN update, not a per-row loop: 1,412 rows today, 26,436 the day
        // someone backfills a profile row for every account.
        $this->db->statement("
            UPDATE userinfo_profiles p
              JOIN users u ON u.id = p.user_id
               SET p.posts_here = u.comment_count,
                   p.discussions_here = u.discussion_count,
                   p.computed_at = NOW()
             WHERE p.posts_here <> u.comment_count
                OR p.discussions_here <> u.discussion_count
        ");
    }

    // ----------------------------------------------------------------- legacy

    /** Copy the account's carried-over standing, keyed on imported_id. */
    private function legacy(\PDO $src): void
    {
        $this->info('carrying over source standing…');

        $users = $this->db->table('users')
            ->whereNotNull('imported_id')
            ->pluck('imported_id', 'id');

        if ($users->isEmpty()) {
            $this->info('  no imported users');

            return;
        }

        $stmt = $src->prepare(
            'SELECT title, banners, style_class, badge_id, joined, post_count, reputation, threads
               FROM src_users WHERE id = ?'
        );

        $rows = [];
        $missing = 0;

        foreach ($users as $localId => $srcId) {
            $stmt->execute([$srcId]);
            $r = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$r) {
                $missing++;
                continue;
            }

            // "Oct 25, 2018" is what the member card prints. strtotime handles
            // it; anything it cannot parse stays null rather than becoming
            // today, which is how imported accounts ended up all claiming to
            // have joined during the import.
            $joined = null;
            if (!empty($r['joined']) && ($ts = strtotime((string) $r['joined']))) {
                $joined = date('Y-m-d', $ts);
            }

            $rows[] = [
                'user_id' => (int) $localId,
                'legacy_title' => $this->clean($r['title']),
                'legacy_banners' => $this->cleanBanners($r['banners']),
                'legacy_style_class' => $r['style_class'] !== null ? substr((string) $r['style_class'], 0, 16) : null,
                'legacy_badge_id' => $r['badge_id'] !== null ? substr((string) $r['badge_id'], 0, 16) : null,
                'legacy_posts' => max(0, (int) $r['post_count']),
                'legacy_reactions' => max(0, (int) $r['reputation']),
                'legacy_threads' => max(0, (int) $r['threads']),
                'legacy_joined' => $joined,
                'computed_at' => date('Y-m-d H:i:s'),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->table('userinfo_profiles')->upsert(
                $chunk,
                ['user_id'],
                ['legacy_title', 'legacy_banners', 'legacy_style_class', 'legacy_badge_id',
                    'legacy_posts', 'legacy_reactions', 'legacy_threads', 'legacy_joined', 'computed_at']
            );
        }

        $this->info('  ' . count($rows) . ' profiles' . ($missing ? ", {$missing} not present in the scrape" : ''));
    }

    // -------------------------------------------------------------- reception

    /**
     * Reactions on the posts that are ACTUALLY ON THIS FORUM.
     *
     * The obvious shortcut is to print users.reputation from the source board.
     * It is a real number, but it is a lifetime total over ~30M posts of which
     * this forum holds a fraction, so beside a local post count of 12 it reads
     * as fabricated — and a reader who divides the two gets nonsense.
     *
     * So it is recomputed over the intersection. The importer walks a thread's
     * posts in `position` order and Flarum numbers them 1..N as it inserts, so
     * for an imported discussion holding N comments the corresponding source
     * posts are the first N by position. That is the join, and it is exact for
     * every thread the importer did not truncate mid-run.
     *
     * Local reactions (flarum/likes) are added on top, so the number stays
     * correct once real users start reacting and the imported share stops being
     * the whole of it.
     */
    private function reception(\PDO $src): void
    {
        $this->info('computing reception on imported content…');

        $srcToLocal = [];
        foreach ($this->db->table('users')->whereNotNull('imported_id')->get(['id', 'imported_id']) as $u) {
            $srcToLocal[(int) $u->imported_id] = (int) $u->id;
        }

        $discussions = $this->db->table('discussions')
            ->whereNotNull('imported_id')
            ->where('is_private', 0)
            ->whereNull('hidden_at')
            ->get(['id', 'imported_id']);

        $score = [];   // local user id => total reaction score
        $best = [];    // local user id => best single post score
        $mix = [];     // local user id => [reaction name => posts that drew it]
        $matched = 0;

        $scoreStmt = $src->prepare(
            'SELECT position, author_id, score FROM src_post_scores
              WHERE thread_id = ? ORDER BY position LIMIT ?'
        );
        $mixStmt = $src->prepare(
            'SELECT position, name FROM src_post_reactions WHERE thread_id = ? AND position <= ?'
        );

        foreach ($discussions as $d) {
            $n = (int) $this->db->table('posts')
                ->where('discussion_id', $d->id)->where('type', 'comment')->whereNull('hidden_at')
                ->count();
            if ($n < 1) {
                continue;
            }

            $scoreStmt->bindValue(1, (int) $d->imported_id, \PDO::PARAM_INT);
            $scoreStmt->bindValue(2, $n, \PDO::PARAM_INT);
            $scoreStmt->execute();

            $authorAt = [];
            foreach ($scoreStmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
                $local = $srcToLocal[(int) $p['author_id']] ?? null;
                if ($local === null) {
                    continue;
                }
                $authorAt[(int) $p['position']] = $local;
                $s = max(0, (int) $p['score']);
                $score[$local] = ($score[$local] ?? 0) + $s;
                $best[$local] = max($best[$local] ?? 0, $s);
                $matched++;
            }

            if (!$authorAt) {
                continue;
            }

            $maxPos = max(array_keys($authorAt));
            $mixStmt->bindValue(1, (int) $d->imported_id, \PDO::PARAM_INT);
            $mixStmt->bindValue(2, $maxPos, \PDO::PARAM_INT);
            $mixStmt->execute();

            foreach ($mixStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $local = $authorAt[(int) $r['position']] ?? null;
                if ($local === null) {
                    continue;
                }
                $name = (string) $r['name'];
                $mix[$local][$name] = ($mix[$local][$name] ?? 0) + 1;
            }
        }

        // Real, native reactions on this forum, whatever exists so far.
        foreach ($this->db->table('post_likes')
                     ->join('posts', 'posts.id', '=', 'post_likes.post_id')
                     ->whereNotNull('posts.user_id')
                     ->groupBy('posts.user_id')
                     ->get([$this->db->raw('posts.user_id as uid'), $this->db->raw('COUNT(*) as c')]) as $r) {
            $score[(int) $r->uid] = ($score[(int) $r->uid] ?? 0) + (int) $r->c;
        }

        $localCounts = $this->db->table('users')
            ->whereNotNull('imported_id')
            ->get(['id', 'comment_count', 'discussion_count']);

        $rows = [];
        foreach ($localCounts as $u) {
            $id = (int) $u->id;
            $m = $mix[$id] ?? [];
            arsort($m);

            $rows[] = [
                'user_id' => $id,
                'reactions_here' => $score[$id] ?? 0,
                'best_post_score' => $best[$id] ?? 0,
                'reaction_mix' => $m ? json_encode($m) : null,
                'posts_here' => (int) $u->comment_count,
                'discussions_here' => (int) $u->discussion_count,
                'computed_at' => date('Y-m-d H:i:s'),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db->table('userinfo_profiles')->upsert(
                $chunk,
                ['user_id'],
                ['reactions_here', 'best_post_score', 'reaction_mix', 'posts_here', 'discussions_here', 'computed_at']
            );
        }

        $this->info('  matched ' . $matched . ' imported posts to a source reaction score across '
            . count($discussions) . ' discussions');
        $this->info('  ' . count(array_filter($score)) . ' accounts have a non-zero local reaction score');
    }

    // ----------------------------------------------------------------- verify

    /**
     * Assert the displayed numbers against the rows behind them.
     *
     * This is the check the operator's complaint is really about: a profile
     * showing 0 discussions for someone who has them is not a rendering bug,
     * it is a counter nobody verified. So it is verified, by a command, in one
     * line, and the harness runs it.
     */
    private function verify(): int
    {
        // A derived table, not HAVING-without-GROUP-BY: MySQL accepts the latter
        // but its meaning changes the moment anything else in the query
        // aggregates, and a verification query that quietly stops verifying is
        // the worst possible bug to have here.
        $bad = $this->db->select("
            SELECT * FROM (
                SELECT u.id, u.username, u.discussion_count, u.comment_count,
                       (SELECT COUNT(*) FROM discussions d
                         WHERE d.user_id = u.id AND d.is_private = 0 AND d.hidden_at IS NULL) AS real_d,
                       (SELECT COUNT(*) FROM posts p JOIN discussions d2 ON d2.id = p.discussion_id
                         WHERE p.user_id = u.id AND p.type = 'comment' AND p.is_private = 0
                           AND p.hidden_at IS NULL AND d2.is_private = 0 AND d2.hidden_at IS NULL) AS real_c
                  FROM users u
            ) x
             WHERE x.discussion_count <> x.real_d OR x.comment_count <> x.real_c
             LIMIT 25
        ");

        // The second source. `--verify` used to check only `users.*`, which is
        // why the snapshot could sit 289 posts out of date for four months and
        // still pass the gate the harness runs. A verification that does not
        // check every number the extension owns is a green light for the ones
        // it forgot.
        $stale = $this->db->select("
            SELECT p.user_id, u.username, p.posts_here, u.comment_count,
                   p.discussions_here, u.discussion_count, p.computed_at
              FROM userinfo_profiles p
              JOIN users u ON u.id = p.user_id
             WHERE p.posts_here <> u.comment_count
                OR p.discussions_here <> u.discussion_count
             LIMIT 25
        ");
        $staleTotal = (int) ($this->db->selectOne("
            SELECT COUNT(*) AS n
              FROM userinfo_profiles p
              JOIN users u ON u.id = p.user_id
             WHERE p.posts_here <> u.comment_count
                OR p.discussions_here <> u.discussion_count
        ")->n ?? 0);

        $total = (int) $this->db->table('users')->count();
        $withProfile = (int) $this->db->table('userinfo_profiles')->count();
        $withReactions = (int) $this->db->table('userinfo_profiles')->where('reactions_here', '>', 0)->count();
        $authors = (int) $this->db->table('users')->where('comment_count', '>', 0)->count();
        $starters = (int) $this->db->table('users')->where('discussion_count', '>', 0)->count();

        $this->info("users: {$total}  profiles: {$withProfile}  with local reactions: {$withReactions}");
        $this->info("accounts with >0 comments: {$authors}  with >0 discussions: {$starters}");

        $failed = false;

        if ($bad) {
            $failed = true;
            $this->error(count($bad) . ' user(s) have counters that disagree with their rows:');
            foreach ($bad as $r) {
                $this->error(sprintf(
                    '  #%d %s  discussions %d (real %d)  comments %d (real %d)',
                    $r->id, $r->username, $r->discussion_count, $r->real_d, $r->comment_count, $r->real_c
                ));
            }
        }

        if ($stale) {
            $failed = true;
            $this->error($staleTotal . ' profile snapshot(s) disagree with users.* (showing ' . count($stale) . '):');
            foreach ($stale as $r) {
                $this->error(sprintf(
                    '  #%d %s  posts_here %d (users.comment_count %d)  discussions_here %d (users.discussion_count %d)  computed_at %s',
                    $r->user_id, $r->username, $r->posts_here, $r->comment_count,
                    $r->discussions_here, $r->discussion_count, $r->computed_at ?? 'never'
                ));
            }
            $this->error('  run `php flarum userinfo:backfill --counts-only` to reconcile');
        }

        if ($failed) {
            return 1;
        }

        $this->info('counter check: every user counter matches the rows behind it');
        $this->info('snapshot check: every userinfo_profiles.posts_here/discussions_here matches users.*');

        return 0;
    }

    private function clean(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        // The board is full of zero-width and invisible titles used as spacers
        // ("‎", "⠀", "ㅤ"). They are real characters that render as an
        // empty line, so they are dropped rather than printed as a blank title.
        $v = preg_replace('/[\x{200B}-\x{200F}\x{2060}\x{FEFF}\x{2800}\x{3164}]/u', '', $v) ?? $v;
        $v = trim($v);

        return $v === '' ? null : mb_substr($v, 0, 190);
    }

    private function cleanBanners($raw): ?string
    {
        if (!$raw) {
            return null;
        }
        $list = json_decode((string) $raw, true);
        if (!is_array($list)) {
            return null;
        }
        $list = array_values(array_filter(array_map(fn ($b) => trim((string) $b), $list)));

        return $list ? json_encode($list) : null;
    }
}
