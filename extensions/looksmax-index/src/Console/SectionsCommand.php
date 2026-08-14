<?php

namespace Local\Index\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Index\Classifier;
use Local\Index\Sections;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create the six section tags and file the board into them.
 *
 *   php flarum lmx:index:sections                 # create tags + classify
 *   php flarum lmx:index:sections --dry-run       # measure, change nothing
 *   php flarum lmx:index:sections --threshold=3   # looser: more recall, less precision
 *   php flarum lmx:index:sections --tags-only     # just make sure the tags exist
 *   php flarum lmx:index:sections --explain=1234  # why did THIS thread land there
 *
 * ── The two rules this command exists to obey ───────────────────────────────
 *
 * ADDITIVE. It creates six new tags and adds them to discussions. It never
 * renames, re-parents, hides or deletes an existing tag, never removes a tag
 * from a discussion that it did not itself add, and never touches
 * `discussion_tag` rows it does not own — ownership is recorded in
 * `lmx_index_sections`. The imported 47-tag tree is left exactly as it was and
 * stays reachable.
 *
 * IDEMPOTENT. Running it twice changes nothing the second time. Running it after
 * the rules improve moves only the discussions whose answer changed, including
 * REMOVING a section it previously assigned and no longer would — which is the
 * one deletion it performs, and it is only ever of its own rows.
 *
 * New tags are appended at the end of `tags.position` so that not one existing
 * row is written to. The front page renders the sections in the operator's order
 * regardless of position; position only affects core's own /tags page.
 */
class SectionsCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('lmx:index:sections')
            ->setDescription('Create the six front-page section tags and classify discussions into them')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'measure and print, write nothing')
            ->addOption('tags-only', null, InputOption::VALUE_NONE, 'create/refresh the tags, skip classification')
            ->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'score a discussion needs to join a section', (string) Classifier::DEFAULT_THRESHOLD)
            ->addOption('explain', null, InputOption::VALUE_REQUIRED, 'print the evidence for one discussion id and exit')
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'print N classified titles per section with their matched terms', 0);
    }

    protected function fire(): int
    {
        $dry = (bool) $this->input->getOption('dry-run');
        $threshold = max(1, (int) $this->input->getOption('threshold'));
        $classifier = new Classifier($threshold);

        if ($id = $this->input->getOption('explain')) {
            return $this->explain($classifier, (int) $id);
        }

        $tagIds = $this->ensureTags($dry);

        if ($this->input->getOption('tags-only')) {
            return 0;
        }

        return $this->classifyAll($classifier, $tagIds, $dry, (int) $this->input->getOption('sample'));
    }

    /**
     * @return array<string, int> section key => tag id
     */
    private function ensureTags(bool $dry): array
    {
        // Appended after everything that exists, so no existing row is written.
        $maxPosition = (int) $this->db->table('tags')->max('position');
        $ids = [];
        $created = 0;
        $updated = 0;

        foreach (Sections::SECTIONS as $key => $def) {
            $slug = $def['slug'];
            $color = Sections::color($key);
            $icon = Sections::icon($key);

            $row = $this->db->table('tags')->where('slug', $slug)->first();

            if ($row) {
                $ids[$key] = (int) $row->id;
                // The NAME is a translation key rendered by the front page, but
                // core surfaces (the tag picker, the discussion hero, /tags)
                // read tags.name directly and know nothing about i18n, so the
                // operator's string is mirrored there too. It is the same string
                // in both locales, so there is nothing to choose between.
                $want = ['color' => $color, 'icon' => $icon];
                $diff = array_filter($want, fn ($v, $k) => ($row->$k ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
                if ($diff && ! $dry) {
                    $this->db->table('tags')->where('id', $row->id)->update($diff);
                }
                if ($diff) {
                    $updated++;
                }
                continue;
            }

            $this->info(sprintf('  + tag %-18s %s  %s', $slug, $color, $icon));
            $created++;

            if ($dry) {
                $ids[$key] = 0;
                continue;
            }

            $ids[$key] = (int) $this->db->table('tags')->insertGetId([
                // Placeholder until first render; the front page never reads it.
                'name' => $this->defaultName($key),
                'slug' => $slug,
                'color' => $color,
                'icon' => $icon,
                'position' => ++$maxPosition,
                'parent_id' => null,
                'is_restricted' => 0,
                'is_hidden' => 0,
                'discussion_count' => 0,
            ]);
        }

        $this->info(sprintf('  tags: %d created, %d refreshed, %d already correct', $created, $updated, count($ids) - $created - $updated));

        return $ids;
    }

    /**
     * The operator's six strings, verbatim, in the operator's order.
     *
     * These are NOT display copy — the front page reads
     * `local-looksmax-index.forum.section.*.title` and never this. They exist so
     * that core surfaces that read `tags.name` straight out of the database
     * (composer tag picker, discussion hero, admin) show the right word instead
     * of a slug. They are identical in every locale by design: the operator gave
     * them as names, not as English to be translated.
     */
    private function defaultName(string $key): string
    {
        return [
            'peptides' => 'Peptides',
            'anabolicos' => 'Anabólicos',
            'softmaxing' => 'Softmaxing',
            'looksmaxing' => 'Looksmaxing',
            'hardmaxing' => 'Hardmaxing',
            'mejores_guias' => 'Mejores Guías',
        ][$key] ?? $key;
    }

    private function classifyAll(Classifier $classifier, array $tagIds, bool $dry, int $sample): int
    {
        $sectionTagIds = array_values(array_filter($tagIds));
        if (count($sectionTagIds) !== count(Sections::SECTIONS) && ! $dry) {
            $this->error('not every section tag exists — aborting rather than half-filing the board');

            return 1;
        }

        // Everything this command previously decided, so the diff is exact.
        $existing = [];
        foreach ($this->db->table('lmx_index_sections')->get(['discussion_id', 'section', 'score']) as $r) {
            $existing[(int) $r->discussion_id][$r->section] = (int) $r->score;
        }

        $counts = array_fill_keys(array_keys(Sections::SECTIONS), 0);
        $samples = array_fill_keys(array_keys(Sections::SECTIONS), []);
        $added = $removed = $unchanged = 0;
        $seen = 0;

        $slugById = $this->db->table('tags')->pluck('slug', 'id')->all();

        $this->db->table('discussions')
            ->orderBy('id')
            ->select(['id', 'title'])
            ->chunk(400, function ($rows) use (
                $classifier, $tagIds, $slugById, $dry, &$existing, &$counts,
                &$added, &$removed, &$unchanged, &$seen, &$samples, $sample
            ) {
                $ids = $rows->pluck('id')->all();

                $firstPosts = $this->db->table('posts')
                    ->whereIn('discussion_id', $ids)
                    ->where('type', 'comment')
                    ->where('number', 1)
                    ->pluck('content', 'discussion_id');

                $tagsByDiscussion = [];
                foreach ($this->db->table('discussion_tag')->whereIn('discussion_id', $ids)->get() as $dt) {
                    $tagsByDiscussion[(int) $dt->discussion_id][] = $slugById[$dt->tag_id] ?? '';
                }

                foreach ($rows as $d) {
                    $seen++;
                    $did = (int) $d->id;
                    $slugs = $tagsByDiscussion[$did] ?? [];
                    $body = mb_substr((string) ($firstPosts[$did] ?? ''), 0, 2000);

                    $result = $classifier->classify((string) $d->title, $body, $slugs);
                    $was = $existing[$did] ?? [];

                    foreach ($result as $section => $ev) {
                        $counts[$section]++;
                        if ($sample && count($samples[$section]) < $sample) {
                            $samples[$section][] = sprintf('%3d  %-70s  %s', $ev['score'], mb_strimwidth((string) $d->title, 0, 70, '…'), implode(' ', array_slice($ev['hits'], 0, 4)));
                        }

                        if (isset($was[$section])) {
                            $unchanged++;
                            unset($was[$section]);
                            continue;
                        }

                        $added++;
                        if (! $dry) {
                            $this->attach($did, $tagIds[$section], $section, $ev);
                        }
                    }

                    // Anything left in $was is a section this command assigned
                    // before and would not assign now. It is ours, so removing
                    // it is not destructive — but it is the ONLY removal.
                    foreach (array_keys($was) as $stale) {
                        $removed++;
                        if (! $dry) {
                            $this->detach($did, $tagIds[$stale], $stale);
                        }
                    }
                }
            });

        $this->info('');
        $this->info(sprintf('  scanned %s discussions at threshold %d', number_format($seen), (int) $this->input->getOption('threshold')));
        foreach ($counts as $section => $n) {
            $this->info(sprintf('  %-18s %6s  (%s%%)', Sections::slug($section), number_format($n), $seen ? number_format($n / $seen * 100, 1) : '0'));
        }
        $this->info(sprintf('  %s added, %s already correct, %s withdrawn%s', number_format($added), number_format($unchanged), number_format($removed), $dry ? ' [DRY RUN — nothing written]' : ''));

        if ($sample) {
            foreach ($samples as $section => $lines) {
                $this->info("\n  === " . Sections::slug($section));
                foreach ($lines as $l) {
                    $this->info('  ' . $l);
                }
            }
        }

        if (! $dry) {
            $this->refreshTagCounters(array_filter($tagIds));
            $this->info("\n  Run `php flarum cache:clear` so the tag payload is re-serialized.");
        }

        return 0;
    }

    private function attach(int $discussionId, int $tagId, string $section, array $ev): void
    {
        // insertOrIgnore, because a moderator may have added the tag by hand —
        // in which case the row already exists and is not ours to duplicate.
        $this->db->table('discussion_tag')->insertOrIgnore([
            'discussion_id' => $discussionId,
            'tag_id' => $tagId,
        ]);

        $this->db->table('lmx_index_sections')->updateOrInsert(
            ['discussion_id' => $discussionId, 'section' => $section],
            [
                'tag_id' => $tagId,
                'score' => min(65535, (int) $ev['score']),
                'hits' => mb_substr(implode(' ', $ev['hits']), 0, 500),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    private function detach(int $discussionId, int $tagId, string $section): void
    {
        $this->db->table('discussion_tag')
            ->where('discussion_id', $discussionId)->where('tag_id', $tagId)->delete();
        $this->db->table('lmx_index_sections')
            ->where('discussion_id', $discussionId)->where('section', $section)->delete();
    }

    /**
     * `tags.discussion_count` and the last-post columns are denormalised by core
     * and nothing recomputes them when rows are inserted underneath it. Without
     * this the six sections render "0 threads" and no latest activity while
     * holding thousands of discussions — which looked, in review, exactly like a
     * broken classifier.
     */
    private function refreshTagCounters(array $tagIds): void
    {
        foreach ($tagIds as $tagId) {
            $count = $this->db->table('discussion_tag')
                ->join('discussions', 'discussions.id', '=', 'discussion_tag.discussion_id')
                ->where('discussion_tag.tag_id', $tagId)
                ->whereNull('discussions.hidden_at')
                ->whereNotNull('discussions.last_posted_at')
                ->count();

            $last = $this->db->table('discussion_tag')
                ->join('discussions', 'discussions.id', '=', 'discussion_tag.discussion_id')
                ->where('discussion_tag.tag_id', $tagId)
                ->whereNull('discussions.hidden_at')
                ->orderByDesc('discussions.last_posted_at')
                ->first(['discussions.id', 'discussions.last_posted_at', 'discussions.last_posted_user_id']);

            $this->db->table('tags')->where('id', $tagId)->update([
                'discussion_count' => $count,
                'last_posted_at' => $last->last_posted_at ?? null,
                'last_posted_discussion_id' => $last->id ?? null,
                'last_posted_user_id' => $last->last_posted_user_id ?? null,
            ]);
        }
    }

    /**
     * The review tool. A classifier you cannot interrogate is a classifier you
     * cannot fix — this prints every section's score for one discussion and the
     * exact terms that produced it, `*` marking a title hit.
     */
    private function explain(Classifier $classifier, int $id): int
    {
        $d = $this->db->table('discussions')->where('id', $id)->first(['id', 'title']);
        if (! $d) {
            $this->error("no discussion $id");

            return 1;
        }

        $body = (string) $this->db->table('posts')->where('discussion_id', $id)
            ->where('type', 'comment')->where('number', 1)->value('content');
        $slugs = $this->db->table('discussion_tag')
            ->join('tags', 'tags.id', '=', 'discussion_tag.tag_id')
            ->where('discussion_id', $id)->pluck('tags.slug')->all();

        $this->info(sprintf("  #%d  %s", $d->id, $d->title));
        $this->info('  tags: ' . implode(', ', $slugs));
        $this->info('');

        $result = $classifier->classify((string) $d->title, mb_substr($body, 0, 2000), $slugs);
        if (! $result) {
            $this->info('  no section reached the threshold.');
        }
        foreach ($result as $section => $ev) {
            $this->info(sprintf('  %-18s score %-4d  %s', Sections::slug($section), $ev['score'], implode(' ', $ev['hits'])));
        }

        $rows = $this->db->table('lmx_index_sections')->where('discussion_id', $id)->get();
        foreach ($rows as $r) {
            $this->info(sprintf('  stored: %-18s score %-4d  %s', $r->section, $r->score, $r->hits));
        }

        return 0;
    }
}
