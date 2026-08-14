<?php

namespace Local\Reactions\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Reactions\Reaction;
use Symfony\Component\Console\Input\InputOption;

/**
 * Carry the imported board's reaction history into the new system.
 *
 *   php flarum lmx:reactions:backfill [--db=...] [--fresh] [--chunk=2000]
 *
 * ── What the source actually contains, and what that permits ────────────────
 *
 * The scrape's `post_reactions` is PRIMARY KEY (post_id, reaction_id) with no
 * user column and no timestamp: 381,716 rows saying THAT a post drew a type.
 * `posts.reaction_score` is the total across all types. `posts.reaction_summary`
 * is XenForo's byline, at most three display names then "and N others".
 *
 * So three facts survive the import and one does not:
 *
 *   survives  which types a post drew          (post_reactions)
 *   survives  how many reactions in total      (reaction_score)
 *   survives  the byline as written            (reaction_summary)
 *   LOST      who reacted, when, and the per-type split
 *
 * The per-type split is recoverable in exactly one case and it is not a guess:
 * a post that drew exactly ONE type has all of its reaction_score on that type.
 * That case is written with count set and exact=1. Every other post gets
 * count=NULL, exact=0, meaning "present, quantity not recorded", and the total
 * is kept whole on legacy_post_totals.
 *
 * The alternative — apportioning a 926-reaction total across three present
 * types by global frequency — would fill the table with numbers that look
 * authoritative and were invented here. It is not done.
 *
 * ── The join ────────────────────────────────────────────────────────────────
 * flarum.posts.imported_id -> scrape post id. Exact, and indexed on the Flarum
 * side by looksmax-import's 2026_08_13_000003 migration. Reaction types join
 * through reactions.xf_id, which holds XenForo's sparse ids (1,2,3,4,7,8,9,
 * 10,13 — there is no 5, 6, 11 or 12).
 *
 * The import is running continuously, so this is written to be re-runnable: it
 * upserts, and a second pass picks up whatever arrived since the first.
 */
class BackfillCommand extends AbstractCommand
{
    protected $signature = 'lmx:reactions:backfill';

    public function __construct(private ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('lmx:reactions:backfill')
            ->setDescription('Import XenForo reaction history onto the new reaction tables')
            ->addOption('db', null, InputOption::VALUE_REQUIRED,
                'SQLite scrape database', '/data/looksmax-import.db')
            ->addOption('chunk', null, InputOption::VALUE_REQUIRED, 'Posts per batch', '2000')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Wipe legacy tables first')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N posts', '0');
    }

    protected function fire(): int
    {
        $path = (string) $this->input->getOption('db');
        if (!is_readable($path)) {
            $this->error("scrape db not readable: $path");

            return 1;
        }

        // Read-only, and immutable=0 because the importer is writing to the
        // original concurrently. Opening it read-write would take a lock the
        // live import needs.
        $src = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $src->exec('PRAGMA query_only = 1');

        $xfToLocal = Reaction::query()->whereNotNull('xf_id')->pluck('id', 'xf_id')->all();
        if (!$xfToLocal) {
            $this->error('no reactions carry an xf_id — run migrations first');

            return 1;
        }
        $this->info(sprintf('mapping %d XenForo reaction ids: %s',
            count($xfToLocal), implode(',', array_keys($xfToLocal))));

        $this->absorbLikes();

        if ($this->input->getOption('fresh')) {
            $this->db->table('legacy_post_reactions')->delete();
            $this->db->table('legacy_post_totals')->delete();
            $this->info('wiped legacy tables');
        }

        $chunk = max(100, (int) $this->input->getOption('chunk'));
        $limit = (int) $this->input->getOption('limit');

        $seen = $withTypes = $withScore = $exact = $typeRows = $unmapped = 0;
        $lastId = 0;

        while (true) {
            $posts = $this->db->table('posts')
                ->whereNotNull('imported_id')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('imported_id', 'id')
                ->all();
            if (!$posts) {
                break;
            }
            $lastId = array_key_last($posts);

            $srcIds = array_map('intval', array_values($posts));
            $localBySrc = [];
            foreach ($posts as $localId => $srcId) {
                $localBySrc[(int) $srcId] = (int) $localId;
            }

            $in = implode(',', array_fill(0, count($srcIds), '?'));

            // types present per post
            $types = [];
            $st = $src->prepare("SELECT post_id, reaction_id FROM post_reactions WHERE post_id IN ($in)");
            $st->execute($srcIds);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $types[(int) $r['post_id']][] = (int) $r['reaction_id'];
            }

            // totals
            $scores = [];
            $st = $src->prepare(
                "SELECT id, reaction_score, reaction_summary FROM posts
                 WHERE id IN ($in) AND reaction_score > 0"
            );
            $st->execute($srcIds);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $scores[(int) $r['id']] = [
                    'score' => (int) $r['reaction_score'],
                    'summary' => $r['reaction_summary'],
                ];
            }

            $typeInsert = $totalInsert = [];
            foreach ($srcIds as $srcId) {
                $seen++;
                $localId = $localBySrc[$srcId];
                $present = $types[$srcId] ?? [];
                $tot = $scores[$srcId] ?? null;

                if ($tot) {
                    $withScore++;
                    $totalInsert[] = [
                        'post_id' => $localId,
                        'score' => $tot['score'],
                        'summary' => $tot['summary'] === null
                            ? null : mb_substr((string) $tot['summary'], 0, 512),
                    ];
                }

                if (!$present) {
                    continue;
                }
                $withTypes++;

                // The only derivable split: one type present means that type
                // holds the entire score.
                $single = count($present) === 1 && $tot !== null;
                foreach ($present as $xfId) {
                    if (!isset($xfToLocal[$xfId])) {
                        $unmapped++;
                        continue;
                    }
                    $typeInsert[] = [
                        'post_id' => $localId,
                        'reaction_id' => (int) $xfToLocal[$xfId],
                        'count' => $single ? $tot['score'] : null,
                        'exact' => $single ? 1 : 0,
                    ];
                    $typeRows++;
                    if ($single) {
                        $exact++;
                    }
                }
            }

            foreach (array_chunk($typeInsert, 500) as $c) {
                $this->db->table('legacy_post_reactions')->upsert($c,
                    ['post_id', 'reaction_id'], ['count', 'exact']);
            }
            foreach (array_chunk($totalInsert, 500) as $c) {
                $this->db->table('legacy_post_totals')->upsert($c,
                    ['post_id'], ['score', 'summary']);
            }

            $this->output->write(sprintf("\r  %d posts, %d type rows, %d totals",
                $seen, $typeRows, $withScore));

            if ($limit && $seen >= $limit) {
                break;
            }
        }

        $this->output->writeln('');
        $this->info(sprintf(
            "posts scanned      %d\n" .
            "with reaction types %d\n" .
            "with a score        %d\n" .
            "legacy type rows    %d  (%d exact, %d count-unknown)\n" .
            "unmapped xf ids     %d",
            $seen, $withTypes, $withScore, $typeRows, $exact, $typeRows - $exact, $unmapped
        ));

        return 0;
    }

    /**
     * Absorb flarum/likes.
     *
     * flarum/likes is being turned off in favour of this extension — a binary
     * like and a multi-reaction strip in the same post footer are two competing
     * controls for the same gesture. But `post_likes` is not empty (21 rows at
     * the time of writing) and looksmax-userinfo's reception metric still reads
     * that table, so the rows are COPIED into `post_reactions` as `plus1` — the
     * catalogue entry that maps to XenForo's own +1 — and the original table is
     * left exactly as it is. Nothing is deleted and nothing is orphaned.
     *
     * Idempotent: the (post_id, user_id, reaction_id) unique key means a second
     * run inserts nothing, so this is safe to call on every backfill.
     */
    private function absorbLikes(): int
    {
        if (!$this->db->getSchemaBuilder()->hasTable('post_likes')) {
            return 0;
        }

        $plus1 = Reaction::query()->where('slug', 'plus1')->first();
        if (!$plus1) {
            return 0;
        }

        $rows = $this->db->table('post_likes as pl')
            ->join('posts as p', 'p.id', '=', 'pl.post_id')
            ->join('users as u', 'u.id', '=', 'pl.user_id')
            ->leftJoin('post_reactions as pr', function ($j) use ($plus1) {
                $j->on('pr.post_id', '=', 'pl.post_id')
                  ->on('pr.user_id', '=', 'pl.user_id')
                  ->where('pr.reaction_id', '=', $plus1->id);
            })
            ->whereNull('pr.id')
            ->select('pl.post_id', 'pl.user_id', 'pl.created_at')
            ->get();

        $n = 0;
        foreach (array_chunk($rows->all(), 500) as $chunk) {
            $this->db->table('post_reactions')->insertOrIgnore(array_map(fn ($r) => [
                'post_id' => (int) $r->post_id,
                'user_id' => (int) $r->user_id,
                'reaction_id' => (int) $plus1->id,
                'created_at' => $r->created_at,
                'updated_at' => $r->created_at,
            ], $chunk));
            $n += count($chunk);
        }

        $this->info("absorbed $n flarum/likes rows as +1 (post_likes left untouched)");

        return $n;
    }
}
