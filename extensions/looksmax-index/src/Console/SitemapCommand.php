<?php

namespace Local\Index\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Rebuild public/sitemap.xml from what is actually visible right now.
 *
 *   php flarum lmx:sitemap              # write it
 *   php flarum lmx:sitemap --dry-run    # count what it would write, touch nothing
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * The sitemap on this install was a STATIC file. Nothing regenerated it, so it
 * drifted the moment content changed: after the translated corpus came down it
 * still advertised ~2,300 URLs that had become 404s, and none of the guides
 * published afterwards. A sitemap that lists dead URLs is worse than no sitemap
 * — it is the one file where a crawler takes our word for what exists.
 *
 * ── What goes in it ─────────────────────────────────────────────────────────
 *
 * Exactly the pages a logged-out reader can reach: the home page, every tag
 * that currently holds at least one visible discussion, and every discussion
 * that is neither hidden nor private. Hidden discussions are excluded by the
 * same predicate the front page uses, so the sitemap cannot disagree with the
 * site about what exists.
 *
 * Tags with zero visible discussions are left out on purpose. They render as an
 * empty listing, which is a soft-404 to a crawler and a dead end to a reader —
 * the same reason SectionsBlock stops drawing a section once it empties.
 *
 * ── Safety ──────────────────────────────────────────────────────────────────
 *
 * The previous file is copied to `sitemap.xml.bak` before the new one lands, and
 * the new one is written to a temporary file and renamed into place, so a crawler
 * hitting mid-write never sees a half-written document. `--dry-run` is the
 * default posture for checking; it prints the counts and writes nothing.
 */
class SitemapCommand extends AbstractCommand
{
    /** Where Flarum serves public files from, inside the container. */
    private const PUBLIC_DIR = '/flarum/app/public';

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('lmx:sitemap')
            ->setDescription('Rebuild public/sitemap.xml from currently visible content')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the counts and write nothing');
    }

    protected function fire(): void
    {
        $dry = (bool) $this->input->getOption('dry-run');

        $base = rtrim((string) $this->settings->get('url'), '/');
        if ($base === '') {
            $base = 'https://looksmax.lat';
        }

        // Visible = exactly what a logged-out reader can open.
        $discussions = $this->db->table('discussions')
            ->whereNull('hidden_at')
            ->where('is_private', 0)
            ->orderBy('id')
            ->get(['id', 'slug', 'last_posted_at']);

        // A tag earns a URL by holding something, not by existing.
        $tags = $this->db->table('tags')
            ->where('discussion_count', '>', 0)
            ->orderBy('slug')
            ->pluck('slug');

        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        $lines[] = '  <url><loc>' . $this->esc($base . '/') . '</loc></url>';

        foreach ($tags as $slug) {
            $lines[] = '  <url><loc>' . $this->esc($base . '/t/' . $slug) . '</loc></url>';
        }

        foreach ($discussions as $d) {
            $loc = $base . '/d/' . $d->id . ($d->slug !== null && $d->slug !== '' ? '-' . $d->slug : '');
            $mod = $d->last_posted_at ? substr((string) $d->last_posted_at, 0, 10) : null;
            $lines[] = '  <url><loc>' . $this->esc($loc) . '</loc>'
                . ($mod ? '<lastmod>' . $mod . '</lastmod>' : '')
                . '</url>';
        }

        $lines[] = '</urlset>';
        $xml = implode("\n", $lines) . "\n";

        $this->info(sprintf(
            'sitemap: %d discussions + %d tags + home = %d URLs (%s)',
            count($discussions),
            count($tags),
            count($discussions) + count($tags) + 1,
            $dry ? 'dry run, nothing written' : 'writing'
        ));

        if ($dry) {
            return;
        }

        $target = self::PUBLIC_DIR . '/sitemap.xml';

        if (is_file($target)) {
            @copy($target, $target . '.bak');
        }

        // Write-then-rename: a crawler mid-write gets the old file, never half
        // of the new one.
        $tmp = $target . '.tmp';
        if (@file_put_contents($tmp, $xml) === false) {
            $this->error('could not write ' . $tmp);

            return;
        }
        @chmod($tmp, 0644);
        if (! @rename($tmp, $target)) {
            @unlink($tmp);
            $this->error('could not move the new sitemap into place');

            return;
        }

        $this->info('wrote ' . $target . ' (' . strlen($xml) . ' bytes)');
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
