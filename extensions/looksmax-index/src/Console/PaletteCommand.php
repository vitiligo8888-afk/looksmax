<?php

namespace Local\Index\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Index\Palette;
use Symfony\Component\Console\Input\InputOption;

/**
 * Write the palette into `tags.color` / `tags.icon`.
 *
 * The index renders from Palette directly and does not need this — but the
 * discussion list, the discussion hero, the tag pages, the composer's tag
 * picker and the tag admin are all CORE surfaces that read `tags.color`, and
 * none of them will ever know about a PHP class of ours. So the palette is
 * mirrored into the database and that is what makes a tag chip the right colour
 * everywhere instead of only on the front page.
 *
 * Idempotent and safe to re-run. `--force` also overwrites a colour that
 * someone set deliberately in the admin panel; without it, only the importer's
 * two monotone defaults (#7aa2f7 for every forum, #414868 for most prefixes)
 * and empty values are replaced, so an operator's choice is never silently
 * undone.
 *
 *   php flarum lmx:index:palette            # fill in the monotone ones
 *   php flarum lmx:index:palette --force    # re-apply the whole table
 *   php flarum lmx:index:palette --dry-run  # print, change nothing
 */
class PaletteCommand extends AbstractCommand
{
    /** What the importer left behind. Anything matching is not a human choice. */
    private const IMPORTER_DEFAULTS = ['#7aa2f7', '#414868', '#2a323d', '', null];

    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('lmx:index:palette')
            ->setDescription('Apply the per-forum colour and icon palette to the tags table')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'overwrite colours that were set deliberately')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'print what would change and exit');
    }

    protected function fire(): int
    {
        $force = (bool) $this->input->getOption('force');
        $dry = (bool) $this->input->getOption('dry-run');

        $tags = $this->db->table('tags')->get(['id', 'slug', 'name', 'color', 'icon']);
        $changed = 0;
        $skipped = 0;

        foreach ($tags as $tag) {
            [$color, $icon] = Palette::forSlug($tag->slug);

            $isDefault = in_array((string) $tag->color, array_map('strval', self::IMPORTER_DEFAULTS), true);
            if (! $force && ! $isDefault) {
                $skipped++;
                continue;
            }

            if ($tag->color === $color && $tag->icon === $icon) {
                continue;
            }

            $this->info(sprintf(
                '  %-18s %-28s %s -> %s   %s',
                $tag->slug,
                mb_strimwidth((string) $tag->name, 0, 28, '…'),
                str_pad((string) ($tag->color ?: '—'), 7),
                $color,
                $icon
            ));

            if (! $dry) {
                $this->db->table('tags')->where('id', $tag->id)->update(['color' => $color, 'icon' => $icon]);
            }
            $changed++;
        }

        $this->info(sprintf(
            "\n%s %d tag(s); %d left alone because they were set by hand (use --force to include them).",
            $dry ? 'Would update' : 'Updated',
            $changed,
            $skipped
        ));

        if (! $dry && $changed) {
            $this->info('Run `php flarum cache:clear` so the tag payload is re-serialized.');
        }

        return 0;
    }
}
