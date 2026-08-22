<?php

namespace Local\Import\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use s9e\TextFormatter\Unparser;
use Symfony\Component\Console\Input\InputOption;

/**
 * Export guide source text for translation.
 *
 *   php flarum lmx:guide:export --out=/flarum/app/storage/export --limit=40
 *   php flarum lmx:guide:export --out=... --limit=40 --offset=40 --max-len=20000
 *
 * ── Why a command and not a query ───────────────────────────────────────────
 *
 * posts.content holds s9e/TextFormatter XML, never the source a human typed.
 * Recovering the source with regex over that XML is what the first translation
 * pass did, and it is guesswork: the XML interleaves <s>/<e> tag markers with
 * text, so a stripped version is *close* to the BBCode but not identical.
 *
 * Unparser::unparse() is TextFormatter's own exact inverse of the parse that
 * produced the column. Round-tripping through it is lossless by construction,
 * which is the only acceptable basis for "translate it exactly": the translator
 * edits real source, and re-parsing that source reproduces the original
 * structure tag for tag.
 *
 * ── Ranking ─────────────────────────────────────────────────────────────────
 *
 * 4,296 guides cannot all be done at once, so they come out worth-most-first:
 * substance (length) and durable appreciation (reactions) weigh more than raw
 * views, which mostly measure how long a thread has existed. Every wave is
 * therefore the most valuable guides not yet done.
 */
class GuideExportCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('lmx:guide:export')
            ->setDescription('Export untranslated guide source (exact BBCode) for translation')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many guides', '40')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Skip N ranked guides', '0')
            ->addOption('max-len', null, InputOption::VALUE_REQUIRED, 'Skip guides longer than this', '0')
            ->addOption('min-len', null, InputOption::VALUE_REQUIRED, 'Skip guides shorter than this', '400')
            ->addOption('ids', null, InputOption::VALUE_REQUIRED, 'Comma-separated discussion ids instead of ranking')
            ->addOption('include-translated', null, InputOption::VALUE_NONE, 'Also export guides already translated (exports the ORIGINAL from the backup)')
            ->addOption('manifest', null, InputOption::VALUE_NONE, 'Also write manifest.json listing the batch');
    }

    protected function fire(): void
    {
        $out = rtrim((string) $this->input->getOption('out'), '/');
        if (! $out) {
            $this->error('--out is required');

            return;
        }
        if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
            $this->error("cannot create $out");

            return;
        }

        $rows = $this->select();
        if (! $rows) {
            $this->info('nothing to export — every ranked guide in range is already translated');

            return;
        }

        $manifest = [];
        $n = 0;

        foreach ($rows as $r) {
            // Re-exporting an already-translated guide must yield the ENGLISH
            // original, not the Spanish that is currently in the column —
            // otherwise a guide whose translation needs redoing gets "translated"
            // from its own bad translation, compounding the error.
            $xml = $r->content;
            $title = $r->title;
            $backup = $this->db->table('lmx_translation_backup')->where('discussion_id', $r->id)->first();
            if ($backup) {
                // The TITLE has to come from the backup too. discussions.title
                // was overwritten by the translation, so exporting it produced
                // a file whose body was English and whose `original_title` was
                // already Spanish — a re-translation would then "translate" the
                // Spanish title again and drift further from the original.
                $xml = $backup->original_content;
                $title = $backup->original_title;
            }

            // The exact source. This is the whole point of the command.
            try {
                $source = Unparser::unparse($xml);
            } catch (\Throwable $e) {
                $this->error("  {$r->id}: unparse failed — " . $e->getMessage());
                continue;
            }

            $tags = $this->db->table('discussion_tag as dt')
                ->join('tags as t', 't.id', '=', 'dt.tag_id')
                ->where('dt.discussion_id', $r->id)
                ->pluck('t.slug')->all();

            $file = $out . '/' . $r->id . '.src.md';

            // Front matter is deliberately the SAME shape TranslateCommand
            // parses, so a translated file is the original with the body
            // replaced and translated_title filled in — nothing to reformat.
            $head = "---\n"
                . "discussion_id: {$r->id}\n"
                . 'original_title: ' . $this->q($title) . "\n"
                . "translated_title: \"\"\n"
                . "source_language: en\n"
                . 'tags: ' . implode(',', $tags) . "\n"
                . "char_count: " . strlen($source) . "\n"
                . "reactions: " . (int) $r->reaction_score . "\n"
                . "views: " . (int) $r->view_count . "\n"
                . "replies: " . (int) $r->comment_count . "\n"
                . "---\n\n";

            file_put_contents($file, $head . $source);

            $manifest[] = [
                'discussion_id' => (int) $r->id,
                'title' => $r->title,
                'chars' => strlen($source),
                'tags' => $tags,
                'file' => basename($file),
            ];
            $n++;
        }

        if ($this->input->getOption('manifest')) {
            file_put_contents($out . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $this->info("exported {$n} guides to {$out}");
        $this->info('total chars: ' . number_format(array_sum(array_column($manifest, 'chars'))));
    }

    private function select(): array
    {
        $ids = (string) $this->input->getOption('ids');

        $q = $this->db->table('discussions as d')
            ->join('posts as p', function ($j) {
                // first_post_id is NULL on virtually every imported row, so the
                // (discussion_id, number) index is the only reliable route to
                // the opening post — same constraint TranslateCommand documents.
                $j->on('p.discussion_id', '=', 'd.id')->where('p.number', '=', 1);
            })
            ->leftJoin('legacy_post_totals as lpt', 'lpt.post_id', '=', 'p.id')
            ->whereNull('d.hidden_at')
            ->where('d.is_private', 0)
            ->select('d.id', 'd.title', 'd.view_count', 'd.comment_count', 'p.content')
            ->selectRaw('COALESCE(lpt.score, 0) as reaction_score');

        // Ranked waves must never re-issue work that is already done; an
        // explicit --ids list or --include-translated is the deliberate escape
        // hatch for redoing a specific guide whose translation failed verify.
        if (! $this->input->getOption('include-translated') && $ids === '') {
            $q->whereNotIn('d.id', function ($sub) {
                $sub->from('lmx_translation_backup')->select('discussion_id');
            });
        }

        if ($ids !== '') {
            $list = array_filter(array_map('intval', explode(',', $ids)));
            $q->whereIn('d.id', $list);

            return $q->get()->all();
        }

        // guides only
        $q->whereIn('d.id', function ($sub) {
            $sub->from('discussion_tag as dt')
                ->join('tags as t', 't.id', '=', 'dt.tag_id')
                ->where('t.slug', 'p-guide')
                ->select('dt.discussion_id');
        });

        $min = (int) $this->input->getOption('min-len');
        $max = (int) $this->input->getOption('max-len');
        if ($min > 0) {
            $q->whereRaw('CHAR_LENGTH(p.content) >= ?', [$min]);
        }
        if ($max > 0) {
            $q->whereRaw('CHAR_LENGTH(p.content) <= ?', [$max]);
        }

        // Substance first, then durable appreciation, then reach.
        $q->orderByRaw('(0.45 * LOG(1 + CHAR_LENGTH(p.content))
                       + 0.35 * LOG(1 + COALESCE(lpt.score,0))
                       + 0.20 * LOG(1 + d.view_count)) DESC');

        return $q->offset((int) $this->input->getOption('offset'))
            ->limit((int) $this->input->getOption('limit'))
            ->get()->all();
    }

    /** Quote a title for YAML-ish front matter without pulling in a YAML dep. */
    private function q(string $s): string
    {
        return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', ' ', ''], $s) . '"';
    }
}
