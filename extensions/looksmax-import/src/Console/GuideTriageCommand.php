<?php

namespace Local\Import\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use s9e\TextFormatter\Unparser;
use Symfony\Component\Console\Input\InputOption;

/**
 * Triage the guide corpus: what is a real guide, and where does it belong.
 *
 *   php flarum lmx:guide:triage --batch=/…/triage-01.json --limit=150 --offset=0
 *   php flarum lmx:guide:triage --apply=/…/triage-01.done.json --dry-run
 *   php flarum lmx:guide:triage --apply=/…/triage-01.done.json
 *   php flarum lmx:guide:triage --revert
 *
 * ── Two jobs, one pass ──────────────────────────────────────────────────────
 *
 * The corpus carries 4,296 discussions tagged `p-guide`, and a sample shows
 * roughly half the short ones are not guides at all: questions ("Mk677 worth
 * it?"), troll images, off-topic crypto, bodies that were never scraped. They
 * have to go. The survivors then have to sit in the right section, which is the
 * same decision made from the same reading — so one judgement produces both.
 *
 * ── Nothing here is a one-way door ──────────────────────────────────────────
 *
 * "Delete" sets `hidden_at`. That removes the thread from every listing, every
 * search index and every tag page, which is what deleting means to a reader,
 * and it is one UPDATE away from being undone. A hard DELETE of a thread with
 * replies would also destroy other people's posts, and this is a judgement call
 * being made in bulk by a machine — exactly the situation that wants an undo.
 * `lmx_guide_triage` records every decision with its reason, and --revert walks
 * it back.
 */
class GuideTriageCommand extends AbstractCommand
{
    /**
     * The five TOPICAL sections, which are mutually exclusive: a guide belongs
     * to exactly one, because a guide filed under three of them is, to someone
     * browsing, filed under none.
     */
    private const SECTIONS = ['looksmaxing', 'softmaxing', 'hardmaxing', 'peptides', 'anabolicos'];

    /**
     * Mejores Guías is NOT a sixth topic — it is a curated best-of layer that
     * sits on top of a topical tag (d/291 carries `mejores-guias` AND `f-9`).
     * Treating it as exclusive would have stripped the curation off every guide
     * the moment it was given its real section.
     */
    private const FEATURED = 'mejores-guias';

    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('lmx:guide:triage')
            ->setDescription('Classify guides (keep/delete) and place them in the right section')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Write a batch of guides to judge, as JSON')
            ->addOption('apply', null, InputOption::VALUE_REQUIRED, 'Apply a completed verdict file')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Batch size', '150')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Batch offset', '0')
            ->addOption('snippet', null, InputOption::VALUE_REQUIRED, 'Chars of body per guide in a batch', '600')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change, write nothing')
            ->addOption('revert', null, InputOption::VALUE_NONE, 'Undo every applied triage decision')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Apply even if the batch is stale (guides already triaged)')
            ->addOption('stats', null, InputOption::VALUE_NONE, 'Show progress across the corpus');
    }

    protected function fire(): void
    {
        $this->ensureTable();

        if ($this->input->getOption('revert')) {
            $this->revert();

            return;
        }
        if ($this->input->getOption('stats')) {
            $this->stats();

            return;
        }
        if ($f = $this->input->getOption('batch')) {
            $this->batch((string) $f);

            return;
        }
        if ($f = $this->input->getOption('apply')) {
            $this->apply((string) $f);

            return;
        }

        $this->error('need one of --batch, --apply, --revert, --stats');
    }

    /** Write the next N un-triaged guides for a reviewer to judge. */
    private function batch(string $file): void
    {
        $rows = $this->db->table('discussions as d')
            ->join('posts as p', function ($j) {
                $j->on('p.discussion_id', '=', 'd.id')->where('p.number', '=', 1);
            })
            ->whereNull('d.hidden_at')
            ->where('d.is_private', 0)
            ->whereIn('d.id', function ($q) {
                $q->from('discussion_tag as dt')
                    ->join('tags as t', 't.id', '=', 'dt.tag_id')
                    ->where('t.slug', 'p-guide')
                    ->select('dt.discussion_id');
            })
            ->whereNotIn('d.id', function ($q) {
                $q->from('lmx_guide_triage')->select('discussion_id');
            })
            ->orderBy('d.id')
            ->offset((int) $this->input->getOption('offset'))
            ->limit((int) $this->input->getOption('limit'))
            ->get(['d.id', 'd.title', 'd.view_count', 'd.comment_count', 'p.content']);

        $snip = (int) $this->input->getOption('snippet');
        $out = [];

        foreach ($rows as $r) {
            try {
                $src = Unparser::unparse($r->content);
            } catch (\Throwable $e) {
                $src = '';
            }
            // Collapse whitespace and strip markup for the snippet: the
            // reviewer is judging SUBSTANCE, and 600 chars of [color] noise
            // says nothing about whether this is a guide.
            $plain = trim(preg_replace('/\s+/', ' ', preg_replace('/\[[^\]]{0,120}\]/', ' ', $src)));

            $out[] = [
                'discussion_id' => (int) $r->id,
                'title' => $r->title,
                'views' => (int) $r->view_count,
                'replies' => (int) $r->comment_count,
                'chars' => strlen($src),
                'tags' => $this->tagsOf((int) $r->id),
                'snippet' => mb_substr($plain, 0, $snip),
                'verdict' => '',
                'section' => '',
                'featured' => false,
                'reason' => '',
            ];
        }

        file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info('wrote ' . count($out) . " guides to $file");
    }

    private function apply(string $file): void
    {
        $rows = json_decode((string) file_get_contents($file), true);
        if (! is_array($rows)) {
            $this->error("cannot parse $file");

            return;
        }

        // STALENESS GUARD.
        //
        // Batches are generated from "guides not yet triaged", so two batches
        // generated at different times against an unchanged database contain
        // the SAME guides. Applying an older one silently overwrites decisions
        // already made — measured: a stale batch re-judged 50 guides and
        // flipped 3 of them, while the triaged total did not move at all,
        // which is exactly the kind of work that looks like progress and is not.
        //
        // Refuse a file whose guides are overwhelmingly already decided. An
        // intentional re-judgement is still possible with --force.
        $ids = array_values(array_filter(array_map(
            fn ($r) => (int) ($r['discussion_id'] ?? 0), $rows
        )));
        $already = $this->db->table('lmx_guide_triage')->whereIn('discussion_id', $ids)->count();
        if ($ids && $already > count($ids) * 0.5 && ! $this->input->getOption('force')) {
            $this->error(sprintf(
                '  %s: STALE — %d of %d guides are already triaged. Refusing; regenerate the batch (or pass --force).',
                basename($file), $already, count($ids)
            ));

            return;
        }

        $dry = (bool) $this->input->getOption('dry-run');
        $del = $placed = $kept = $skipped = 0;

        foreach ($rows as $r) {
            $id = (int) ($r['discussion_id'] ?? 0);
            $verdict = strtolower(trim((string) ($r['verdict'] ?? '')));
            $section = strtolower(trim((string) ($r['section'] ?? '')));
            $reason = mb_substr(trim((string) ($r['reason'] ?? '')), 0, 240);

            if (! $id || ! in_array($verdict, ['keep', 'delete'], true)) {
                $skipped++;
                continue;
            }
            if ($section !== '' && ! in_array($section, self::SECTIONS, true)) {
                $this->error("  $id: unknown section '$section' — skipped");
                $skipped++;
                continue;
            }

            $d = $this->db->table('discussions')->where('id', $id)->first();
            if (! $d) {
                $skipped++;
                continue;
            }

            if ($dry) {
                $verdict === 'delete' ? $del++ : $kept++;
                if ($verdict === 'keep' && $section !== '') {
                    $placed++;
                }
                continue;
            }

            $this->db->table('lmx_guide_triage')->updateOrInsert(
                ['discussion_id' => $id],
                [
                    'verdict' => $verdict,
                    'section' => $section,
                    'reason' => $reason,
                    'was_hidden_at' => $d->hidden_at,
                    'decided_at' => date('Y-m-d H:i:s'),
                ]
            );

            if ($verdict === 'delete') {
                $this->db->table('discussions')->where('id', $id)->update([
                    'hidden_at' => date('Y-m-d H:i:s'),
                    'hidden_user_id' => 1,
                ]);
                $del++;
                continue;
            }

            $kept++;
            if ($section !== '' && $this->place($id, $section)) {
                $placed++;
            }
            $this->feature($id, (bool) ($r['featured'] ?? false));
        }

        $this->info($dry
            ? "dry run: {$del} would be hidden, {$kept} kept, {$placed} re-placed, {$skipped} skipped"
            : "applied: {$del} hidden, {$kept} kept, {$placed} placed, {$skipped} skipped");

        if (! $dry) {
            $this->info('retag/recount: run `php flarum cache:clear`; tag counts refresh on next write');
        }
    }

    /**
     * Put the guide in exactly one section, leaving its other tags alone.
     *
     * The six sections are mutually exclusive by design — a guide that is in
     * three of them is in none of them as far as a reader browsing is
     * concerned — so the other five are removed if present.
     */
    private function place(int $id, string $section): bool
    {
        $ids = $this->db->table('tags')->whereIn('slug', self::SECTIONS)->pluck('id', 'slug')->all();
        $want = $ids[$section] ?? null;
        if (! $want) {
            return false;
        }

        $current = $this->db->table('discussion_tag')->where('discussion_id', $id)->pluck('tag_id')->all();
        $others = array_values(array_diff(array_values($ids), [$want]));

        $changed = false;

        $remove = array_intersect($current, $others);
        if ($remove) {
            $this->db->table('discussion_tag')->where('discussion_id', $id)->whereIn('tag_id', $remove)->delete();
            $changed = true;
        }

        if (! in_array($want, $current)) {
            $this->db->table('discussion_tag')->insert(['discussion_id' => $id, 'tag_id' => $want]);
            $changed = true;
        }

        return $changed;
    }

    /**
     * PROMOTE into the curated best-of section. Additive only.
     *
     * This used to also demote — `featured: false` removed the tag. Applying
     * that to a single batch of 50 stripped `mejores-guias` off 32 guides in
     * one command, including "How I WHITENED my teeth BETTER than a DENTIST"
     * (240,000 views), because the reviewer's featured test keyed partly on
     * length and that guide is under 10KB.
     *
     * Existing curation is human-made signal and a per-batch machine judgement
     * is not a good enough reason to throw it away. Promotion is cheap and
     * reversible by hand; silent mass demotion is neither. Demoting is now a
     * deliberate, separate act, not a side effect of not being nominated.
     */
    private function feature(int $id, bool $on): void
    {
        if (! $on) {
            return;
        }

        $tag = $this->db->table('tags')->where('slug', self::FEATURED)->value('id');
        if (! $tag) {
            return;
        }

        $has = $this->db->table('discussion_tag')
            ->where('discussion_id', $id)->where('tag_id', $tag)->exists();

        if (! $has) {
            $this->db->table('discussion_tag')->insert(['discussion_id' => $id, 'tag_id' => $tag]);
        }
    }

    private function revert(): void
    {
        $rows = $this->db->table('lmx_guide_triage')->where('verdict', 'delete')->get();
        $n = 0;
        foreach ($rows as $r) {
            $this->db->table('discussions')->where('id', $r->discussion_id)->update([
                'hidden_at' => $r->was_hidden_at,
                'hidden_user_id' => null,
            ]);
            $n++;
        }
        $this->db->table('lmx_guide_triage')->delete();
        $this->info("reverted {$n} hidden guides; triage table cleared");
        $this->info('note: section re-tagging is NOT reverted — tags are additive and safe to leave');
    }

    private function stats(): void
    {
        $total = $this->db->table('discussion_tag as dt')
            ->join('tags as t', 't.id', '=', 'dt.tag_id')->where('t.slug', 'p-guide')->count();
        $done = $this->db->table('lmx_guide_triage')->count();
        $del = $this->db->table('lmx_guide_triage')->where('verdict', 'delete')->count();
        $translated = $this->db->table('lmx_translation_backup')->count();

        printf("  guides tagged p-guide : %d\n", $total);
        printf("  triaged               : %d  (%.1f%%)\n", $done, $total ? $done / $total * 100 : 0);
        printf("    kept                : %d\n", $done - $del);
        printf("    hidden as junk      : %d\n", $del);
        printf("  translated            : %d\n", $translated);
        printf("  remaining to translate: %d\n", max(0, ($done - $del) - $translated));
    }

    private function tagsOf(int $id): array
    {
        return $this->db->table('discussion_tag as dt')
            ->join('tags as t', 't.id', '=', 'dt.tag_id')
            ->where('dt.discussion_id', $id)
            ->pluck('t.slug')->all();
    }

    private function ensureTable(): void
    {
        $this->db->statement('
            CREATE TABLE IF NOT EXISTS lmx_guide_triage (
                discussion_id INT UNSIGNED NOT NULL PRIMARY KEY,
                verdict VARCHAR(16) NOT NULL,
                section VARCHAR(32) NOT NULL DEFAULT "",
                reason VARCHAR(255) NOT NULL DEFAULT "",
                was_hidden_at DATETIME NULL,
                decided_at DATETIME NOT NULL,
                INDEX (verdict)
            ) DEFAULT CHARSET=utf8mb4
        ');
    }
}
