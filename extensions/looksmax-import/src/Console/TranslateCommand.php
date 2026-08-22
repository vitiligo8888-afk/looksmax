<?php

namespace Local\Import\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Apply a translated guide over its original post.
 *
 *   php flarum lmx:translate --dir=/srv/looksmax/translations --dry-run
 *   php flarum lmx:translate --dir=/srv/looksmax/translations
 *   php flarum lmx:translate --revert
 *
 * ── Why this is a command and not an UPDATE ─────────────────────────────────
 *
 * posts.content holds s9e/TextFormatter XML, not the source text. Writing the
 * translation straight into the column would put visible `[size=22]` and
 * `[color=#ff9100]` in front of every reader — the same trap ImportCommand's
 * header warns about. Going through CommentPost means the formatter parses it
 * exactly as it would a post typed into the composer, so a translated guide
 * renders with the same spoilers, tables and colour headers as the original.
 *
 * ── Reversibility ───────────────────────────────────────────────────────────
 *
 * This edits OTHER PEOPLE'S posts — 84KB guides that their authors wrote. The
 * original content and title are copied into `lmx_translation_backup` BEFORE
 * anything is written, and `--revert` puts every one of them back. Nothing here
 * is a one-way door.
 *
 * Re-running is safe: a discussion already present in the backup table is
 * skipped unless --force, so a partial run can simply be run again.
 */
class TranslateCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('lmx:translate')
            ->setDescription('Apply translated guides from a directory of markdown files')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory of translation files', '/srv/looksmax/translations')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-apply even if already translated')
            ->addOption('revert', null, InputOption::VALUE_NONE, 'Restore every original from the backup table');
    }

    protected function fire(): void
    {
        $this->ensureBackupTable();

        if ($this->input->getOption('revert')) {
            $this->revert();

            return;
        }

        $dir = rtrim((string) $this->input->getOption('dir'), '/');
        $dry = (bool) $this->input->getOption('dry-run');
        $force = (bool) $this->input->getOption('force');

        $files = glob($dir . '/*.md') ?: [];
        if (! $files) {
            $this->error("no .md files in $dir");

            return;
        }

        // The edit is attributed to the acting admin rather than to the original
        // author: a translation is an edit BY the forum, and Flarum stamps
        // edited_user_id, so the post history stays honest about who changed it.
        $actor = User::where('id', 1)->first();

        $applied = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $doc = $this->parse($file);
            if (! $doc) {
                $this->error('  cannot parse front-matter: ' . basename($file));
                continue;
            }

            $id = (int) $doc['discussion_id'];
            $discussion = Discussion::find($id);
            if (! $discussion) {
                $this->error("  discussion $id not found");
                continue;
            }

            // The opening post, located the way everything else in this codebase
            // has to: first_post_id is NULL on 99.99% of imported rows, so the
            // (discussion_id, number) unique index is the only reliable route.
            $post = CommentPost::where('discussion_id', $id)->where('number', 1)->first();
            if (! $post) {
                $this->error("  discussion $id has no opening post");
                continue;
            }

            $already = $this->db->table('lmx_translation_backup')->where('discussion_id', $id)->exists();
            if ($already && ! $force) {
                $this->info("  skip $id (already translated; --force to redo)");
                $skipped++;
                continue;
            }

            $title = trim((string) ($doc['translated_title'] ?? $discussion->title));
            $chars = strlen($doc['body']);

            if ($dry) {
                $this->info("  would translate $id  \"" . mb_strimwidth($discussion->title, 0, 48, '…') . '"');
                $this->info("            -> \"" . mb_strimwidth($title, 0, 48, '…') . "\"  ({$chars} chars)");
                $applied++;
                continue;
            }

            if (! $already) {
                $this->db->table('lmx_translation_backup')->insert([
                    'discussion_id' => $id,
                    'post_id' => $post->id,
                    'original_title' => $discussion->title,
                    'original_content' => $post->content,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            // The formatter runs here. CommentPost's content mutator parses the
            // source into the stored XML, which is the whole reason this is a
            // command rather than SQL.
            $post->content = $doc['body'];
            $post->edited_at = date('Y-m-d H:i:s');
            if ($actor) {
                $post->edited_user_id = $actor->id;
            }
            $post->save();

            $discussion->title = $title;
            $discussion->save();

            $this->info("  translated $id  \"" . mb_strimwidth($title, 0, 56, '…') . "\"");
            $applied++;
        }

        $this->info('');
        $this->info($dry
            ? "dry run: {$applied} would change, {$skipped} skipped"
            : "done: {$applied} translated, {$skipped} skipped");
    }

    private function revert(): void
    {
        $rows = $this->db->table('lmx_translation_backup')->get();
        $n = 0;

        foreach ($rows as $row) {
            $post = CommentPost::find($row->post_id);
            $discussion = Discussion::find($row->discussion_id);
            if (! $post || ! $discussion) {
                continue;
            }

            // The backup holds the original SOURCE, not parsed XML — so it has
            // to go back through the formatter, exactly like the translation did.
            //
            // The comment that used to sit here claimed the opposite ("the
            // backup holds the ORIGINAL PARSED XML ... re-parsing would corrupt
            // it") and the code wrote it straight into the column. That is
            // wrong, and provably so: the backup is filled from `$post->content`
            // ABOVE, and CommentPost's content ACCESSOR returns unparsed source,
            // not the raw column. So every backup row is BBCode source.
            //
            // Writing source into a column that holds TextFormatter XML makes
            // the post render as a wall of literal `[b]`/`[size]` markup. It was
            // found exactly that way: reverting d/801 by hand left it the only
            // post out of 1.9 million whose content was not XML.
            //
            // Assigning through the model runs the mutator, which parses the
            // source back into XML — the inverse of how the backup was taken.
            $post->content = $row->original_content;
            $post->save();
            $discussion->title = $row->original_title;
            $discussion->save();
            $n++;
        }

        $this->db->table('lmx_translation_backup')->delete();
        $this->info("reverted {$n} discussions");
    }

    /** @return array{discussion_id:int, translated_title:string, body:string}|null */
    private function parse(string $file): ?array
    {
        $raw = file_get_contents($file);
        if ($raw === false || ! str_starts_with($raw, '---')) {
            return null;
        }

        $end = strpos($raw, "\n---", 3);
        if ($end === false) {
            return null;
        }

        $head = substr($raw, 3, $end - 3);
        $body = ltrim(substr($raw, $end + 4), "\r\n");

        $out = ['body' => $body];
        foreach (explode("\n", $head) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $out[trim($k)] = trim(trim($v), " \"'");
        }

        return isset($out['discussion_id']) ? $out : null;
    }

    private function ensureBackupTable(): void
    {
        $this->db->statement('
            CREATE TABLE IF NOT EXISTS lmx_translation_backup (
                discussion_id INT UNSIGNED NOT NULL PRIMARY KEY,
                post_id INT UNSIGNED NOT NULL,
                original_title VARCHAR(255) NOT NULL,
                original_content LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL
            ) DEFAULT CHARSET=utf8mb4
        ');
    }
}
