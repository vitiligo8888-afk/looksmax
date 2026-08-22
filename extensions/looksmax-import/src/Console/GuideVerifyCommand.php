<?php

namespace Local\Import\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use s9e\TextFormatter\Unparser;
use Symfony\Component\Console\Input\InputOption;

/**
 * Gate a directory of translated guides before any of it touches the forum.
 *
 *   php flarum lmx:guide:verify --dir=/flarum/app/storage/tr-wave1
 *
 * Exits non-zero if ANY file fails, so a wave can be applied only after it
 * passes. Every check here exists because the failure it catches already
 * happened on this forum:
 *
 *  1. TAG PARITY. A translation must carry exactly the BBCode of the original,
 *     tag for tag. Translating inside tag names or dropping a [/spoiler] gives
 *     a guide that renders as a wall of literal brackets. Compared as a
 *     multiset against the ORIGINAL SOURCE recovered from the database, not
 *     against a sidecar copy that can itself be stale.
 *
 *  2. URLS AND ATTRIBUTES UNTOUCHED. Image and link targets, colour hexes and
 *     size numbers are not language. A translator that "helpfully" localises
 *     a URL silently breaks an image on a 200-image guide.
 *
 *  3. NO INDENTED BBCODE. Four leading spaces makes the markdown parser treat
 *     the line as a CODE BLOCK, so the images on it render as literal source
 *     inside a grey box. Four of the first five guides translated by hand hit
 *     this, and it is invisible until you look at the rendered page.
 *
 *  4. ACTUALLY TRANSLATED. A body byte-identical to the source means the file
 *     was exported and handed back untouched.
 */
class GuideVerifyCommand extends AbstractCommand
{
    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('lmx:guide:verify')
            ->setDescription('Check translated guides against the original source before applying')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory of translated .md files')
            ->addOption('quiet-ok', null, InputOption::VALUE_NONE, 'Only print failures');
    }

    protected function fire(): void
    {
        $dir = rtrim((string) $this->input->getOption('dir'), '/');
        $files = glob($dir . '/*.md') ?: [];
        $files = array_values(array_filter($files, fn ($f) => ! str_ends_with($f, '.src.md')));

        if (! $files) {
            $this->error("no translated .md files in $dir (note: *.src.md are exports and are skipped)");

            return;
        }

        $bad = 0;
        $ok = 0;

        foreach ($files as $file) {
            $doc = $this->parse($file);
            $name = basename($file);

            if (! $doc) {
                $this->error("  $name: unparseable front matter");
                $bad++;
                continue;
            }

            $id = (int) $doc['discussion_id'];
            $row = $this->db->table('posts')->where('discussion_id', $id)->where('number', 1)->first();
            if (! $row) {
                $this->error("  $name: discussion $id has no opening post");
                $bad++;
                continue;
            }

            // The original may already have been overwritten by an earlier
            // apply, so prefer the pristine backup when one exists.
            $backup = $this->db->table('lmx_translation_backup')->where('discussion_id', $id)->first();
            // The two places an "original" can come from store DIFFERENT things,
            // and treating them alike produced false failures.
            //
            //   posts.content                     -> TextFormatter XML
            //   lmx_translation_backup.original_content -> BBCode SOURCE
            //
            // The backup is filled from CommentPost::content, whose ACCESSOR
            // returns unparsed source. Unparsing that a second time strips the
            // markup and every URL with it, so d/13455 was reported as having
            // "INVENTED 10 links" when its translation was in fact identical to
            // the original. Sniff which one we have instead of assuming.
            $stored = $backup->original_content ?? $row->content;
            $isXml = str_starts_with(ltrim($stored), '<');

            try {
                $source = $isXml ? Unparser::unparse($stored) : $stored;
                // realTags() needs the parsed form to read authoritative element
                // names, so parse the source when that is all we have.
                $originalXml = $isXml
                    ? $stored
                    : resolve(\Flarum\Formatter\Formatter::class)->parse($source);
            } catch (\Throwable $e) {
                $this->error("  $name: cannot read original — " . $e->getMessage());
                $bad++;
                continue;
            }

            $problems = $this->check($source, $doc['body'], $doc, $this->realTags($originalXml));

            if ($problems) {
                $this->error("  $name (d/$id) FAILED");
                foreach ($problems as $p) {
                    $this->error("      - $p");
                }
                $bad++;
            } else {
                $ok++;
                if (! $this->input->getOption('quiet-ok')) {
                    $this->info("  $name (d/$id) ok");
                }
            }
        }

        $this->info('');
        $this->info("verify: {$ok} passed, {$bad} failed");

        if ($bad > 0) {
            // Non-zero so a wrapper script cannot apply a failing wave.
            exit(1);
        }
    }

    /**
     * The BBCode tags this post actually uses, taken from the PARSED XML.
     *
     * Authoritative, and the reason this is not a regex over the source: the
     * XML element names are what TextFormatter itself recognised as tags, so
     * prose that merely contains brackets — "[antes] y [después]" in a
     * translation, a literal "[1]" citation — cannot be mistaken for markup.
     * An earlier version of this command counted every `[word` in the text and
     * duly reported Spanish prose as an invented BBCode tag.
     *
     * @return string[] lowercase tag names
     */
    private function realTags(string $xml): array
    {
        if (! preg_match_all('/<([A-Z][A-Z0-9_]*)\b/', $xml, $m)) {
            return [];
        }

        $tags = array_map('strtolower', array_unique($m[1]));

        // Structural elements TextFormatter adds itself — never written by a
        // human and never present in the source text.
        return array_values(array_diff($tags, ['r', 't', 'p', 'br', 'e', 's', 'i']));
    }

    /**
     * @param  string[]  $realTags
     * @return string[] list of problems, empty means good
     */
    private function check(string $src, string $out, array $doc, array $realTags = []): array
    {
        $p = [];

        if (trim($out) === '') {
            return ['body is empty'];
        }

        if (trim($out) === trim($src)) {
            $p[] = 'body is identical to the source — not translated';
        }

        if (trim((string) ($doc['translated_title'] ?? '')) === '') {
            $p[] = 'translated_title is empty';
        }

        // 1. tag parity, over the tags this post genuinely uses
        foreach ($realTags as $tag) {
            foreach ([$tag => "[$tag]", '/' . $tag => "[/$tag]"] as $key => $label) {
                $na = $this->countTag($src, $key);
                $nb = $this->countTag($out, $key);
                if ($na !== $nb) {
                    $p[] = "$label: original $na, translation $nb";
                }
            }
        }

        // 2. urls / attribute payloads untouched
        $ua = $this->payloads($src);
        $ub = $this->payloads($out);
        $missing = array_diff($ua, $ub);
        $added = array_diff($ub, $ua);
        if ($missing) {
            $p[] = 'dropped ' . count($missing) . ' link/image target(s), e.g. ' . substr((string) reset($missing), 0, 70);
        }
        if ($added) {
            $p[] = 'INVENTED ' . count($added) . ' link(s) the original does not contain, e.g. '
                . substr((string) reset($added), 0, 70)
                . ' — a translation must not add sources';
        }

        // 3. indented bbcode -> accidental code block
        foreach (explode("\n", $out) as $i => $line) {
            if (preg_match('/^[ \t]{4,}\[(img|url|color|size|b|i|quote|spoiler)/i', $line)) {
                $p[] = 'line ' . ($i + 1) . ' is indented 4+ spaces before BBCode — renders as a code block';
                break;
            }
        }

        return $p;
    }

    /** Occurrences of one specific bbcode tag: `[tag]`, `[tag=x]`, `[tag x=y]`. */
    private function countTag(string $s, string $tag): int
    {
        return preg_match_all('/\[' . preg_quote($tag, '/') . '(?=[\]\s=])/i', $s);
    }

    /**
     * The link and image targets in a guide. These are NOT language and must
     * survive translation byte for byte: a translator that drops one breaks an
     * image, and one that ADDS one has written a citation the author never
     * made — which is fabrication, not translation, and is how a "translated"
     * guide ends up asserting things its author never claimed.
     *
     * Two normalisations, both for false positives this check produced on its
     * first run against real files:
     *
     *  - The character class excludes `[`. Without it `...photo.png[/img]`
     *    captured the closing tag as part of the URL, so the same image was
     *    reported as both lost AND introduced whenever nearby markup shifted.
     *  - Entities are decoded. One translation carried `&amp;` where the
     *    original had a bare `&` in a query string; identical links, and 22 of
     *    them reported as changed.
     *
     * Colour hexes are deliberately NOT compared. The count of [color] tags is
     * already verified above, and the VALUES legitimately move: these guides
     * open with letter-by-letter rainbow headings, so a Spanish word of a
     * different length redistributes the same gradient across different
     * letters. Flagging that buried the real findings under 228 lines of noise.
     */
    private function payloads(string $s): array
    {
        if (! preg_match_all('~https?://[^\s\[\]"\'<>]+~i', $s, $m)) {
            return [];
        }

        $out = array_map(
            fn ($u) => rtrim(html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '.,;:)'),
            $m[0]
        );

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /** Same front-matter shape TranslateCommand reads. */
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
        $out = ['body' => ltrim(substr($raw, $end + 4), "\r\n")];

        foreach (explode("\n", $head) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $out[trim($k)] = trim(trim($v), " \"'");
        }

        return isset($out['discussion_id']) ? $out : null;
    }
}
