<?php

namespace Local\Ranks\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Economy\Ledger;
use Local\Ranks\Catalog;
use Local\Ranks\Standing;
use Symfony\Component\Console\Input\InputOption;

/**
 * Turn the standing the source board recorded into ledger rows here.
 *
 * The measured problem this solves: after the content import the forum had
 * 6,240 posts, 1,413 users, and SIX ledger rows. Every username on the board
 * rendered identically because bulk inserts fire no events. A progression
 * system whose entire population sits at rank zero is a progression system that
 * is visibly broken, and no amount of new features fixes that.
 *
 * Two independent sources, both idempotent, both ordinary ledger rows so
 * `economy:recompute` reconciles them like anything else:
 *
 *   1. `import.legacy` — carried standing at QUARTER weight. Quarter because
 *      the reputation was earned on another board: full weight means nobody who
 *      joins here can ever catch up, zero weight means a 66,000-post veteran
 *      renders as a greycel standing next to their own imported posts.
 *
 *   2. `reaction.received` — the real per-post reaction scores, mapped onto the
 *      imported posts by (source thread id, position). Applied ONLY where the
 *      source and imported post counts agree, because a partially imported
 *      thread would shift every position by one and credit the wrong author.
 *      Mismatches are counted and reported, never guessed at.
 *
 * It also recovers three things the import dropped on the floor: the custom
 * titles, the staff banners, and the named VIP colours hiding in `style_class`.
 *
 *   php flarum identity:backfill --dry-run
 */
class BackfillCommand extends AbstractCommand
{
    /**
     * Source `style_class` -> what it actually meant.
     *
     * Derived by cross-tabulating style against title across all 77,166 source
     * users (see identity-notes/DESIGN.md §1). The ladder classes are dropped —
     * rank is recomputed from the ledger here and does not need importing. Only
     * the classes that encode a PURCHASE are mapped, because those are the ones
     * that cannot be re-derived from activity.
     */
    private const STYLE_MAP = [
        '9'  => ['tier' => 'vip',   'style' => 'zephir'],
        '10' => ['tier' => 'vip',   'style' => 'mistral'],
        '11' => ['tier' => 'vip',   'style' => 'equinox'],
        '12' => ['tier' => 'elite', 'style' => 'kraken'],
        '13' => ['tier' => 'elite', 'style' => 'kraken'],
        '14' => ['tier' => 'vip',   'style' => 'apricot'],
        '15' => ['tier' => 'elite', 'style' => 'luminary'],
        '16' => ['tier' => 'elite', 'style' => 'luminary'],
        '17' => ['tier' => 'elite', 'style' => 'fuchsia'],
        '24' => ['tier' => 'elite', 'style' => 'fire'],
        '29' => ['tier' => 'elite', 'style' => 'fire'],
        '30' => ['tier' => 'elite', 'style' => 'fire'],
        '43' => ['tier' => 'vip',   'style' => 'sphinx'],
        '44' => ['tier' => 'vip',   'style' => 'solstice'],
        '45' => ['tier' => 'vip',   'style' => 'oceanic'],
    ];

    /** Titles that are really rank names, and must not become custom titles. */
    private const LADDER_TITLES = [
        'iron', 'bronze', 'silver', 'gold', 'platinum', 'diamond', 'master',
        'luminary', 'zephir', 'mistral', 'solstice', 'equinox', 'sphinx',
        'kraken', 'apricot', 'fuchsia', 'fire', 'emerald',
        'banned', 'temp. banned',
    ];

    public function __construct(
        protected ConnectionInterface $db,
        protected Ledger $ledger,
        protected Standing $standing
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('identity:backfill')
            ->setDescription('Seed ranks, tiers, titles and badges from the standing recorded on the source board')
            ->addOption('legacy', null, InputOption::VALUE_REQUIRED, 'path to the legacy sidecar sqlite',
                __DIR__ . '/../../data/legacy.sqlite')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'report what would change, write nothing')
            ->addOption('weight', null, InputOption::VALUE_REQUIRED, 'weight applied to carried standing', '0.25')
            ->addOption('skip-scores', null, InputOption::VALUE_NONE, 'skip the per-post reaction mapping');
    }

    protected function fire()
    {
        $path = (string) $this->input->getOption('legacy');
        if (!file_exists($path)) {
            $this->error("legacy sidecar not found at {$path}");
            $this->info('build it with: python3 bin/extract-legacy.py  (see the header of that file)');

            return 1;
        }

        $dry = (bool) $this->input->getOption('dry-run');
        $weight = (float) $this->input->getOption('weight');

        $src = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $src->exec('PRAGMA query_only = 1');

        $this->info($dry ? 'DRY RUN — nothing will be written' : 'writing');

        $users = $this->backfillUsers($src, $weight, $dry);
        $scores = $this->input->getOption('skip-scores') ? [0, 0, 0] : $this->backfillScores($src, $dry);

        $this->info('');
        $this->info('accounts touched      : ' . $users['touched']);
        $this->info('legacy standing rows  : ' . $users['ledger']);
        $this->info('  points seeded       : ' . number_format($users['points']));
        $this->info('custom titles restored: ' . $users['titles']);
        $this->info('staff banners restored: ' . $users['banners']);
        $this->info('tiers granted         : ' . $users['tiers']);
        $this->info('name styles granted   : ' . $users['styles']);
        $this->info('join dates repaired   : ' . $users['joined']);
        $this->info('staff promoted        : ' . $users['staff']);
        $this->info('');
        $this->info('threads with matching post counts : ' . $scores[0]);
        $this->info('threads SKIPPED (count mismatch)  : ' . $scores[1]);
        $this->info('per-post reaction awards written  : ' . $scores[2]);

        if (!$dry) {
            $this->info('');
            $this->info('recomputing ranks from the ledger…');
            $this->getApplication()
                ->find('economy:recompute')
                ->run(new \Symfony\Component\Console\Input\ArrayInput([]), $this->output);
            $this->distribution();
        }

        return 0;
    }

    // --------------------------------------------------------------- users

    private function backfillUsers(\PDO $src, float $weight, bool $dry): array
    {
        $out = ['touched' => 0, 'ledger' => 0, 'points' => 0, 'titles' => 0, 'banners' => 0,
                'tiers' => 0, 'styles' => 0, 'joined' => 0, 'staff' => 0];

        $local = $this->db->table('users')->whereNotNull('imported_id')
            ->pluck('id', 'imported_id');

        if ($local->isEmpty()) {
            $this->error('no users carry an imported_id — nothing to map');

            return $out;
        }

        $ids = $local->keys()->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $rows = $src->query(
                "SELECT id, title, banners, style_class, post_count, reputation, threads, joined
                 FROM legacy_users WHERE id IN ({$in})"
            );

            foreach ($rows as $r) {
                $uid = (int) $local[$r['id']];
                $out['touched']++;

                $rep = (int) $r['reputation'];
                $posts = (int) $r['post_count'];
                $seed = (int) round($weight * ($rep * 4 + $posts));

                $title = $this->usableTitle((string) ($r['title'] ?? ''));
                $banners = $this->banners((string) ($r['banners'] ?? '[]'));
                $map = self::STYLE_MAP[(string) $r['style_class']] ?? null;

                if ($dry) {
                    if ($seed > 0) {
                        $out['ledger']++;
                        $out['points'] += $seed;
                    }
                    $title && $out['titles']++;
                    $out['banners'] += count($banners);
                    $map && $out['tiers']++;
                    $map && $out['styles']++;
                    array_intersect($banners, ['Staff', 'Administrator', 'Moderator']) && $out['staff']++;
                    $this->joinedAt((string) ($r['joined'] ?? '')) && $out['joined']++;

                    continue;
                }

                $update = [
                    'legacy_style' => (string) $r['style_class'],
                    'legacy_title' => (string) ($r['title'] ?? '') ?: null,
                    'legacy_threads' => (int) $r['threads'],
                    'legacy_reactions' => $rep,
                    'legacy_posts' => $posts,
                    'custom_title' => $title ?: null,
                ];

                // Restore the source join date if ours is later than it.
                //
                // Measured after writing this: the importer already carries the
                // date over for all 1,412 accounts, so this is currently a
                // no-op and the tenure spread (41 accounts from 2018, 834 from
                // 2026) is real recency skew in what was crawled, not lost data.
                // It stays because an account created from a thread byline
                // rather than a user row would otherwise get NOW() and silently
                // lose every tenure badge, and that is a failure with no error.
                $joined = $this->joinedAt((string) ($r['joined'] ?? ''));
                if ($joined) {
                    $current = $this->db->table('users')->where('id', $uid)->value('joined_at');
                    if (!$current || strtotime((string) $current) > strtotime($joined)) {
                        $update['joined_at'] = $joined;
                        $out['joined']++;
                    }
                }

                $this->db->table('users')->where('id', $uid)->update($update);
                $title && $out['titles']++;

                if ($seed > 0) {
                    // credit(), not award(): the seed is already the final
                    // number and must not be multiplied by a tier bonus that
                    // this very command is about to grant.
                    $written = $this->ledger->credit($uid, $seed, 'import.legacy', 'u:' . $r['id']);
                    if ($written) {
                        $out['ledger']++;
                        $out['points'] += $written;
                    }
                }

                foreach ($banners as $b) {
                    if ($this->standing->grant($uid, 'banner', $b, 'import')) {
                        $out['banners']++;
                    }
                }

                // Staff are the one population that legitimately lands on
                // Founder: the tier that cannot be bought, held by people who
                // are not customers. They get the one style that is never for
                // sale, which is the whole point of it existing.
                if (array_intersect($banners, ['Staff', 'Administrator', 'Moderator'])) {
                    $this->standing->grantTier($uid, 'founder', 'import', 0, 0);
                    $this->standing->grant($uid, 'style', 'staff', 'award');
                    $this->db->table('users')->where('id', $uid)->update(['name_style' => 'staff']);
                    $out['staff']++;
                }

                if ($map) {
                    // The purchase is restored as a permanent grant rather than
                    // a 30-day membership: these people paid on the source
                    // board, and starting their timer at migration would expire
                    // something they already own.
                    $this->standing->grantTier($uid, $map['tier'], 'import', 0, 0);
                    $this->standing->grant($uid, 'style', $map['style'], 'import');
                    $this->db->table('users')->where('id', $uid)->update(['name_style' => $map['style']]);
                    $out['tiers']++;
                    $out['styles']++;
                }
            }
        }

        return $out;
    }

    /**
     * A title is worth restoring only if it says something.
     *
     * The source data is full of titles that are a single zero-width character,
     * a braille blank or a full stop — 70 accounts use U+200E and 21 use U+2800
     * to fake a blank line in the post header. Those are layout exploits, not
     * titles. Ladder names are dropped too: rank is computed here.
     */
    private function usableTitle(string $raw): string
    {
        $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{2800}\x{3164}\x{115F}\x{1160}]/u', '', $raw);
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));

        if (mb_strlen($s) < 2 || mb_strlen($s) > 100) {
            return '';
        }
        if (in_array(mb_strtolower($s), self::LADDER_TITLES, true)) {
            return '';
        }
        if (preg_match('/^[\.\-_=~]+$/u', $s)) {
            return '';
        }

        return $s;
    }

    /**
     * Parse the source join date.
     *
     * The scrape stores it as a free-text date ("Mar 7, 2022" and friends).
     * Anything strtotime cannot read, or that lands outside the board's actual
     * lifetime, is rejected rather than clamped — a wrong tenure is worse than
     * a missing one because it silently mints veteran badges.
     */
    private function joinedAt(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $ts = strtotime($raw);
        if ($ts === false || $ts < strtotime('2010-01-01') || $ts > time()) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function banners(string $json): array
    {
        $list = json_decode($json, true);
        if (!is_array($list)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($b) => is_string($b) && strlen($b) < 40 ? $b : null,
            $list
        )));
    }

    // -------------------------------------------------------------- scores

    /**
     * Map real per-post reaction scores onto the posts that were imported.
     *
     * Flarum posts carry no `imported_id`, only discussions do, so the join is
     * positional: source post at position N in thread T becomes the Nth post of
     * the discussion imported from T. That mapping is only sound when both
     * sides have the same number of posts, so threads where they disagree are
     * counted and skipped rather than approximated. Attributing a 400-reaction
     * post to the wrong author is worse than not attributing it at all.
     */
    private function backfillScores(\PDO $src, bool $dry): array
    {
        $matched = 0;
        $skipped = 0;
        $awards = 0;

        $discussions = $this->db->table('discussions')
            ->whereNotNull('imported_id')
            ->get(['id', 'imported_id']);

        foreach ($discussions as $d) {
            $localPosts = $this->db->table('posts')
                ->where('discussion_id', $d->id)->where('type', 'comment')
                ->orderBy('number')
                ->get(['id', 'user_id'])->values();

            $stmt = $src->prepare(
                'SELECT position, author_id, score FROM legacy_post_scores WHERE thread_id = ? ORDER BY position'
            );
            $stmt->execute([$d->imported_id]);
            $srcPosts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if (!$srcPosts || count($srcPosts) !== $localPosts->count()) {
                $skipped++;

                continue;
            }

            $matched++;

            foreach ($srcPosts as $i => $sp) {
                $score = (int) $sp['score'];
                $post = $localPosts[$i];
                if ($score <= 0 || !$post->user_id) {
                    continue;
                }

                if ($dry) {
                    $awards++;

                    continue;
                }

                // reaction.received at 4 points each, one idempotent row per
                // post carrying the whole score as the multiplier
                if ($this->ledger->credit((int) $post->user_id, 4 * $score, 'reaction.received', 'post:' . $post->id)) {
                    $awards++;
                }
            }
        }

        return [$matched, $skipped, $awards];
    }

    // -------------------------------------------------------- reporting

    /** Print the resulting ladder, because thresholds should be measured. */
    private function distribution(): void
    {
        $total = max(1, (int) $this->db->table('users')->count());
        $counts = $this->db->table('users')->groupBy('rank_slug')
            ->selectRaw('rank_slug, COUNT(*) c')->pluck('c', 'rank_slug');

        $this->info('');
        $this->info('rank distribution:');
        foreach (Catalog::RANKS as $r) {
            $n = (int) ($counts[$r['slug']] ?? 0);
            $this->info(sprintf('  %-9s >= %-8s %5d  %5.1f%%', $r['name'], number_format($r['min']), $n, 100 * $n / $total));
        }

        $p = $this->db->table('users')->orderByDesc('lifetime_points')->limit(1)->value('lifetime_points');
        $this->info('  top lifetime_points: ' . number_format((int) $p));
    }
}
