<?php

namespace Local\Index\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Formatter\Formatter;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Republish an original guide's first post from its markdown source.
 *
 *   php flarum lmx:repost /tmp/originals            # every .md in the folder
 *   php flarum lmx:repost /tmp/originals --dry-run  # match only, write nothing
 *
 * ── Why a command ───────────────────────────────────────────────────────────
 *
 * Posts are stored as s9e TextFormatter XML, not as the markdown that produced
 * them, so "just UPDATE the row" corrupts the post: the renderer would get raw
 * markdown where it expects parsed XML and the page shows asterisks. The text
 * has to go back through the same Formatter that parsed it the first time,
 * which means booting Flarum — hence a console command rather than SQL.
 *
 * ── How a file finds its discussion ─────────────────────────────────────────
 *
 * By the H1 in the markdown, matched against `discussions.title`. Slugs are NOT
 * usable: the frontmatter slug is the SEO slug (`sentadilla-tecnica-guia`) while
 * the forum built its own from the title (`sentadilla-tecnica-correcta`), and
 * the two diverge on most guides. A title that matches nothing, or matches more
 * than one discussion, is reported and skipped — never guessed at.
 *
 * Hidden discussions are skipped too. A guide pulled from the forum on purpose
 * must not come back just because its file is still in the folder.
 *
 * ── What gets written ───────────────────────────────────────────────────────
 *
 * Frontmatter and the H1 are stripped (the title is the discussion's, and
 * repeating it in the body prints it twice), the "Revisado por profesionales."
 * byline is prepended, and the result is parsed and re-rendered. Only
 * `posts.content` changes: no edited_at, no bumped discussion, so republishing
 * a typo fix does not shove 147 guides to the top of the front page.
 */
class RepostCommand extends AbstractCommand
{
    protected $signature = 'lmx:repost';

    public function __construct(
        protected ConnectionInterface $db,
        protected Formatter $formatter
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this->setName('lmx:repost')
            ->setDescription('Republish original guides from their markdown sources')
            ->addArgument('dir', InputArgument::REQUIRED, 'Folder holding the .md sources')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Match only; write nothing');
    }

    protected function fire(): void
    {
        $dir = rtrim((string) $this->input->getArgument('dir'), '/');
        $dry = (bool) $this->input->getOption('dry-run');

        if (! is_dir($dir)) {
            $this->error("No such folder: $dir");
            return;
        }

        $files = glob($dir . '/*.md') ?: [];
        $done = $skipped = $missing = 0;

        foreach ($files as $path) {
            $raw = file_get_contents($path);
            $name = basename($path);

            // Frontmatter first, so an H1 inside it can never be mistaken for
            // the title.
            $body = preg_replace('/\A---\R.*?\R---\R/s', '', $raw, 1);

            if (! preg_match('/^#\s+(.+)$/m', $body, $m)) {
                $this->error("  $name — no H1, skipped");
                $skipped++;
                continue;
            }
            $title = trim($m[1]);

            $rows = $this->db->table('discussions')
                ->where('title', $title)
                ->whereNull('hidden_at')
                ->get(['id', 'first_post_id']);

            if ($rows->count() !== 1) {
                $this->error(sprintf('  %s — %d matches for "%s", skipped',
                    $name, $rows->count(), $title));
                $missing++;
                continue;
            }

            $row = $rows->first();
            if (! $row->first_post_id) {
                $this->error("  $name — discussion {$row->id} has no first post, skipped");
                $missing++;
                continue;
            }

            // Drop the H1 line itself; the discussion title already says it.
            $body = preg_replace('/^#\s+.+$/m', '', $body, 1);
            $text = "*Revisado por profesionales.*\n\n" . trim($body) . "\n";

            if ($dry) {
                $this->info("  $name -> post {$row->first_post_id} (" . strlen($text) . " bytes)");
                $done++;
                continue;
            }

            $post = $this->db->table('posts')->where('id', $row->first_post_id)->first();
            $parsed = $this->formatter->parse($text, null, null);

            $this->db->table('posts')
                ->where('id', $row->first_post_id)
                ->update(['content' => $parsed]);

            $done++;
        }

        $this->info(sprintf('%s: %d escritas, %d sin coincidencia, %d saltadas (de %d ficheros)',
            $dry ? 'SIMULACRO' : 'Republicadas', $done, $missing, $skipped, count($files)));
    }
}
