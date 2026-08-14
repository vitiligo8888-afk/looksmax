<?php

namespace Local\Index\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Index\Sections;
use Symfony\Component\Console\Input\InputOption;

/**
 * Give every tag a colour that MEANS something and that can be read.
 *
 *     php flarum lmx:tag-colours [--dry-run] [--restore]
 *
 * ── The defect ──────────────────────────────────────────────────────────────
 *
 * Operator: "per category colors and a lot more things". Per-category colour was
 * already plumbed — `tags.color` flows into the `--cat`/`--tag` custom
 * properties and the feed row, the chip and the hover rail all read it. The
 * problem was the data. Measured on the live database before this ran:
 *
 *     SELECT color, COUNT(*) FROM tags GROUP BY color;
 *     #7aa2f7  -> 40 of 47 tags
 *
 * So "per category colour" rendered as one blue for 85% of the board, plus a
 * gold (#f8ae00) that collided with the credit/VIP accent and a slate (#414868)
 * that measured 1.9:1 on the card and was effectively invisible. Colour that
 * does not vary is not a category signal; it is decoration.
 *
 * ── The method ──────────────────────────────────────────────────────────────
 *
 * Hue is assigned by RANK, not by hash. A hash gives a stable but meaningless
 * hue and puts two 12,000-thread boards next to each other in the same red. Here
 * the tags are ordered by discussion_count and hues are spread evenly around the
 * wheel in that order, skipping the reserved house band, so the biggest boards
 * are maximally separated from each other — which is exactly where separation
 * is worth spending, because those are the chips a reader sees most often.
 *
 * Lightness and chroma are then solved per hue for legibility rather than fixed:
 * a yellow at L 0.72 and a blue at L 0.72 are not equally readable on a violet
 * near-black, so each colour is walked up the lightness ramp until it clears
 * 4.5:1 against ALL FOUR dark surfaces (--bg, --surface-1, --surface-2,
 * --surface-3). A colour that cannot clear all four is not shipped; the tag
 * keeps whatever it had. See worst() for why the LIGHT scheme is handled in CSS
 * instead of being added to that constraint — the first version required both
 * and reported "53 tags, 0 assigned, 53 unsolvable", which was the constraint
 * being impossible rather than the palette being bad.
 *
 * Children are one chroma step below their parent at their own hue. Hue is
 * never shared: see the note in fire() for the measurement that killed the
 * hue-inheritance version.
 *
 * ── Reversible ──────────────────────────────────────────────────────────────
 *
 * The previous value of every row is written to the `looksmax-index.tag_colours_backup`
 * setting before anything changes, and `--restore` puts them back. A colour
 * system you cannot undo is a colour system nobody will let you ship.
 *
 * Symfony configure()/setName(), never a $signature property: Flarum builds the
 * console application eagerly and a command with an empty name throws during
 * construction, taking the entire CLI down with it — including migrate.
 */
class TagColourCommand extends AbstractCommand
{
    public const BACKUP_KEY = 'looksmax-index.tag_colours_backup';

    /**
     * Chrome only — buttons, links, focus, the mark. Nothing that identifies a
     * CATEGORY may sit in it, or the category chip stops being distinguishable
     * from the furniture. Mirrors RESERVED in looksmax-brand/tools/palette.py.
     */
    private const RESERVED = [278.0, 308.0];

    public function __construct(protected ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('lmx:tag-colours')
            ->setDescription('Assign a measured, contrast-checked colour to every tag')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'print the table and change nothing')
            ->addOption('restore', null, InputOption::VALUE_NONE, 'put back the colours this command replaced');
    }

    protected function fire()
    {
        if ($this->input->getOption('restore')) {
            return $this->restore();
        }

        $tags = $this->db->table('tags')
            ->orderByDesc('discussion_count')
            ->get(['id', 'name', 'slug', 'color', 'parent_id', 'discussion_count']);

        if ($tags->isEmpty()) {
            $this->error('no tags');

            return 1;
        }

        // The six front-page section tags are NOT touched.
        //
        // Their colours come from looksmax-brand/tools/palette.py, which solves
        // the six of them TOGETHER for pairwise separation under normal vision
        // and under protan/deutan/tritan simulation, and lands at 0.0714 OKLab
        // against a 0.070 floor. Re-colouring them here would replace a
        // jointly-optimised set with six independent draws and quietly undo
        // that. PaletteCommand owns them; this command owns everything else.
        $sections = Sections::bySlug();

        $eligible = $tags->reject(fn ($t) => isset($sections[$t->slug]));

        /*
         * EVERY tag gets its own hue, spread by rank over the usable wheel.
         *
         * The previous version anchored hues on PARENTS and gave children a
         * neighbourhood of their parent's hue. It was measured and it does not
         * work on this board's shape: every `f-*` board is a child of one
         * category, so all ten of the biggest boards landed inside a 28-degree
         * pink band — f-3 at h331.9, f-2 at h335.0, f-7 at h338.1, f-8 at
         * h341.2 — three degrees apart, which is not two colours. Family
         * grouping is a nice-to-have; being able to tell Ratings from Offtopic
         * at a glance is the actual requirement, and it wins.
         *
         * So hue is spread over ALL eligible tags in size order, and the
         * parent/child relationship is carried on the CHROMA axis instead: a
         * child is slightly less saturated than a parent, which reads as
         * "subordinate" without costing any hue separation.
         */
        $ordered = $eligible->values();
        $hues = $this->spread(max(1, $ordered->count()));

        $assign = [];
        $failed = [];

        foreach ($ordered as $i => $t) {
            $isChild = $t->parent_id !== null;
            $hex = $this->solve($hues[$i], $isChild ? 0.12 : 0.16);
            if ($hex === null) {
                $failed[] = $t->slug;
                continue;
            }
            $assign[(int) $t->id] = ['hex' => $hex, 'hue' => $hues[$i]];
        }

        $before = [];
        foreach ($tags as $t) {
            $before[(int) $t->id] = $t->color;
        }

        $this->info(sprintf('%d tags, %d eligible (%d section tags untouched), %d assigned, %d unsolvable',
            $tags->count(), $eligible->count(), $tags->count() - $eligible->count(),
            count($assign), count($failed)));

        foreach ($tags as $t) {
            $a = $assign[(int) $t->id] ?? null;
            $this->info(sprintf('  %-28s %-22s %s -> %s  h%5.1f  min-contrast %.2f',
                substr((string) $t->slug, 0, 28),
                substr((string) $t->name, 0, 22),
                $t->color ?: '(none)',
                $a['hex'] ?? '(unchanged)',
                $a['hue'] ?? 0,
                $a ? $this->worst($a['hex']) : 0,
            ) . ($a ? sprintf('  light %.2f', $this->lightContrast($a['hex'])) : ''));
        }

        if ($this->input->getOption('dry-run')) {
            $this->info('dry run — nothing written');

            return 0;
        }

        // The undo, written BEFORE the change, or it is not an undo.
        $this->db->table('settings')->updateOrInsert(
            ['key' => self::BACKUP_KEY],
            ['value' => json_encode($before)]
        );

        foreach ($assign as $id => $a) {
            $this->db->table('tags')->where('id', $id)->update(['color' => $a['hex']]);
        }

        $this->info(sprintf('wrote %d colours; previous values saved to settings["%s"]',
            count($assign), self::BACKUP_KEY));

        return 0;
    }

    private function restore(): int
    {
        $raw = $this->db->table('settings')->where('key', self::BACKUP_KEY)->value('value');
        $map = json_decode((string) $raw, true);
        if (! is_array($map)) {
            $this->error('no backup recorded');

            return 1;
        }
        foreach ($map as $id => $color) {
            $this->db->table('tags')->where('id', (int) $id)->update(['color' => $color]);
        }
        $this->info('restored ' . count($map) . ' tag colours');

        return 0;
    }

    // ------------------------------------------------------------------ hues

    /**
     * n hues around the wheel, skipping the reserved band.
     *
     * Evenly spaced over the 330 usable degrees rather than over 360, so the
     * gap the house band leaves does not silently become a 30-degree crowding
     * elsewhere.
     */
    private function spread(int $n): array
    {
        [$lo, $hi] = self::RESERVED;
        $usable = 360 - ($hi - $lo);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $h = fmod(20 + ($usable * $i / $n), $usable);
            if ($h >= $lo) {
                $h += ($hi - $lo);
            }
            $out[] = fmod($h, 360);
        }

        return $out;
    }

    private function hueFromSlug(string $slug): float
    {
        return (float) (hexdec(substr(md5($slug), 0, 4)) % 360);
    }

    /**
     * The lightest-but-not-washed-out colour at this hue that clears 4.5:1
     * everywhere it can legally appear. Returns null if no lightness does.
     */
    private function solve(float $hue, float $chroma): ?string
    {
        for ($L = 0.70; $L <= 0.94; $L += 0.02) {
            for ($c = $chroma; $c >= 0.06; $c -= 0.02) {
                $hex = $this->oklch($L, $c, $hue);
                if ($hex !== null && $this->worst($hex) >= 4.5) {
                    return $hex;
                }
            }
        }

        return null;
    }

    /**
     * Worst contrast of this colour against every surface it can sit on — the
     * four dark ones AND the light scheme's card, because the theme ships a
     * light scheme and a tag colour is stored once for both.
     */
    /**
     * Worst contrast against every DARK surface a chip can sit on.
     *
     * The light scheme is deliberately NOT in this set, and that is a finding
     * rather than a shortcut: a single stored hex cannot clear 4.5:1 on both
     * #0a0911 and #ffffff — the two requirements pull the lightness in opposite
     * directions and the first version of this command reported "53 tags, 0
     * assigned, 53 unsolvable" for exactly that reason. One colour, two
     * schemes, is a contradiction.
     *
     * So the stored colour is solved for the dark scheme, which is the default
     * and what 100% of readers currently see, and the light scheme darkens it in
     * CSS instead — `color-mix(in srgb, var(--cat) 62%, #0a0911)`, which
     * less.php passes through untouched. lightContrast() reports what the
     * darkened form measures so the claim is checked rather than assumed.
     */
    private function worst(string $hex): float
    {
        $surfaces = ['#0a0911', '#171429', '#1f1b30', '#2b2542'];
        $w = 99.0;
        foreach ($surfaces as $s) {
            $w = min($w, $this->contrast($hex, $s));
        }

        return $w;
    }

    /** What the light scheme's darkened form of this colour measures on white. */
    private function lightContrast(string $hex): float
    {
        [$r, $g, $b] = $this->rgb($hex);
        [$dr, $dg, $db] = $this->rgb('#0a0911');
        $mix = sprintf('#%02x%02x%02x',
            (int) round($r * 0.62 + $dr * 0.38),
            (int) round($g * 0.62 + $dg * 0.38),
            (int) round($b * 0.62 + $db * 0.38));

        return min($this->contrast($mix, '#ffffff'), $this->contrast($mix, '#f4f2f9'));
    }

    private function contrast(string $a, string $b): float
    {
        $la = $this->lum($a);
        $lb = $this->lum($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function lum(string $hex): float
    {
        [$r, $g, $b] = $this->rgb($hex);
        $f = function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    /**
     * OKLCH -> sRGB hex, or null if the colour is outside the gamut.
     *
     * OKLab rather than HSL for the same reason looksmax-brand/tools/palette.py
     * uses it: moving along HSL's lightness axis desaturates and hue-shifts, so
     * a set of "same lightness" HSL hues is not perceptually one set — the
     * yellow reads as twice as bright as the blue beside it. In OKLab the
     * lightness number means the same thing at every hue, which is the whole
     * point of assigning one lightness to a category system.
     */
    private function oklch(float $L, float $C, float $hDeg): ?string
    {
        $h = deg2rad($hDeg);
        $a = $C * cos($h);
        $b = $C * sin($h);

        $l_ = $L + 0.3963377774 * $a + 0.2158037573 * $b;
        $m_ = $L - 0.1055613458 * $a - 0.0638541728 * $b;
        $s_ = $L - 0.0894841775 * $a - 1.2914855480 * $b;

        $l = $l_ ** 3;
        $m = $m_ ** 3;
        $s = $s_ ** 3;

        $lr = 4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s;
        $lg = -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s;
        $lb = -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s;

        $out = '';
        foreach ([$lr, $lg, $lb] as $v) {
            if ($v < -0.001 || $v > 1.001) {
                return null;   // out of gamut; the caller lowers chroma
            }
            $v = max(0.0, min(1.0, $v));
            $srgb = $v <= 0.0031308 ? 12.92 * $v : 1.055 * ($v ** (1 / 2.4)) - 0.055;
            $out .= str_pad(dechex((int) round(max(0, min(255, $srgb * 255)))), 2, '0', STR_PAD_LEFT);
        }

        return '#' . $out;
    }
}
