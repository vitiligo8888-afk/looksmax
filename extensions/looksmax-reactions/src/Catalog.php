<?php

namespace Local\Reactions;

/**
 * The reaction catalogue: the single source of truth for what can be reacted
 * with, in what order, and what it looks like.
 *
 * Three groups, and the split is not cosmetic:
 *
 *   CHRIGGER  the 13 custom faces. These are the house set and sort first.
 *
 *   CLASSIC   the nine reaction types the board carried over from XenForo:
 *             +1, JFL, Love it, Hmm..., So Sad, Woah, Ugh.., WTF, Nerd.
 *             381,716 rows of history point at these, so each one keeps its
 *             XenForo reaction id in `xf_id` and that is the join key the
 *             backfill uses. Renaming or reordering them is safe; changing an
 *             `xf_id` silently reassigns history and is not.
 *
 *   EXTRA     new reactions this board never had. The brief called 13 a floor
 *             on the catalogue rather than the whole of it.
 *
 * Two icon kinds:
 *   image  the chrigger faces, as a 24/48/96/256 ladder in avif+webp+png.
 *   svg    Twemoji (CC-BY 4.0), one file, resolution-independent. The classic
 *          nine use these because that is what the source board renders today,
 *          so an imported "JFL" still shows the 🤣 a returning user expects.
 *
 * `tint` exists because of a measurement, not a whim. bin/legibility.py scores
 * every icon by its smallest perceptual distance to any other icon in the set
 * at chip size; neutral and sad come out at 9.36 ΔLab at 24px, which is not
 * enough for a reader to tell them apart at a glance. The tint drives the chip
 * ring and the active fill, so a chip is identifiable by colour and position
 * even when the face inside it is not.
 */
class Catalog
{
    public const GROUP_CHRIGGER = 'chrigger';
    public const GROUP_CLASSIC = 'classic';
    public const GROUP_EXTRA = 'extra';

    /**
     * slug => [display, kind, asset, tint, group, position, xf_id, points]
     *
     * `points` is what one reaction of this type is worth to the receiving
     * user. Nothing in this extension spends it; it is published on the API so
     * the economy lane can consume it without needing to know the catalogue.
     */
    public const ITEMS = [
        // ---------------------------------------------------------- chrigger
        'happy'       => ['Happy',       'image', 'chrigger/happy',       '#ffd166', self::GROUP_CHRIGGER, 10, null,  1],
        'chrigga'     => ['Chrigga',     'image', 'chrigger/chrigga',     '#d9a066', self::GROUP_CHRIGGER, 11, null,  1],
        'angry'       => ['Angry',       'image', 'chrigger/angry',       '#ff6b5e', self::GROUP_CHRIGGER, 12, null, -1],
        'sad'         => ['Sad',         'image', 'chrigger/sad',         '#6fa8dc', self::GROUP_CHRIGGER, 13, null,  0],
        'surprised'   => ['Surprised',   'image', 'chrigger/surprised',   '#c4a2f5', self::GROUP_CHRIGGER, 14, null,  1],
        'thinking'    => ['Thinking',    'image', 'chrigger/thinking',    '#e6b169', self::GROUP_CHRIGGER, 15, null,  0],
        'nerd'        => ['Nerd',        'image', 'chrigger/nerd',        '#7ab5ff', self::GROUP_CHRIGGER, 16, null,  1],
        'devil'       => ['Devil',       'image', 'chrigger/devil',       '#ff4d3d', self::GROUP_CHRIGGER, 17, null,  0],
        'angel'       => ['Angel',       'image', 'chrigger/angel',       '#cfe3ff', self::GROUP_CHRIGGER, 18, null,  1],
        'rich'        => ['Rich',        'image', 'chrigger/rich',        '#7fd18b', self::GROUP_CHRIGGER, 19, null,  1],
        'chrishammed' => ['Chrishammed', 'image', 'chrigger/chrishammed', '#e8d7c3', self::GROUP_CHRIGGER, 20, null,  1],
        'neutral'     => ['Neutral',     'image', 'chrigger/neutral',     '#9aa7ba', self::GROUP_CHRIGGER, 21, null,  0],
        'error'       => ['Error',       'image', 'chrigger/error',       '#ff3b5c', self::GROUP_CHRIGGER, 22, null, -1],

        // ----------------------------------------------------------- classic
        // xf_id values are XenForo's sparse reaction ids as recorded by the
        // scraper (no 5, 6, 11, 12 — do not assume 1..N).
        'plus1'  => ['+1',      'svg', 'emoji/1f44d', '#7fd18b', self::GROUP_CLASSIC, 100,  1,  1],
        'jfl'    => ['JFL',     'svg', 'emoji/1f923', '#ffd166', self::GROUP_CLASSIC, 101,  3,  1],
        'love'   => ['Love it', 'svg', 'emoji/2764',  '#ff5f8f', self::GROUP_CLASSIC, 102,  2,  1],
        'hmm'    => ['Hmm...',  'svg', 'emoji/1f914', '#c4a2f5', self::GROUP_CLASSIC, 103,  9,  0],
        'sosad'  => ['So Sad',  'svg', 'emoji/1f622', '#6fa8dc', self::GROUP_CLASSIC, 104,  8,  0],
        'woah'   => ['Woah',    'svg', 'emoji/1f62e', '#7ab5ff', self::GROUP_CLASSIC, 105,  4,  1],
        'ugh'    => ['Ugh..',   'svg', 'emoji/1f612', '#a3b18a', self::GROUP_CLASSIC, 106, 10, -1],
        'wtf'    => ['WTF',     'svg', 'emoji/1f633', '#ff8fa3', self::GROUP_CLASSIC, 107,  7,  0],
        'geek'   => ['Nerd',    'svg', 'emoji/1f913', '#8fd0ff', self::GROUP_CLASSIC, 108, 13,  0],

        // ------------------------------------------------------------- extra
        'based'   => ['Based',   'svg', 'emoji/1f525', '#ff7a3d', self::GROUP_EXTRA, 200, null,  1],
        'dead'    => ['Dead',    'svg', 'emoji/1f480', '#dfe6f0', self::GROUP_EXTRA, 201, null,  0],
        'brain'   => ['Brain',   'svg', 'emoji/1f9e0', '#ff9ec7', self::GROUP_EXTRA, 202, null,  1],
        'chad'    => ['Chad',    'svg', 'emoji/1f451', '#e6b169', self::GROUP_EXTRA, 203, null,  1],
        'clown'   => ['Clown',   'svg', 'emoji/1f921', '#ff6fae', self::GROUP_EXTRA, 204, null, -1],
        'ascend'  => ['Ascend',  'svg', 'emoji/1f4c8', '#7fd18b', self::GROUP_EXTRA, 205, null,  1],
        'blessed' => ['Blessed', 'svg', 'emoji/1f64f', '#ffd9a0', self::GROUP_EXTRA, 206, null,  1],
        'ice'     => ['Ice',     'svg', 'emoji/1f9ca', '#9fe3ff', self::GROUP_EXTRA, 207, null,  0],
        'eyes'    => ['Eyes',    'svg', 'emoji/1f440', '#c9d4e3', self::GROUP_EXTRA, 208, null,  0],
    ];

    /** Sizes the image ladder is built at. Must match bin/process-assets.py. */
    public const IMAGE_SIZES = [24, 48, 96, 256];

    /** Where Flarum's asset publisher puts this extension's assets/ dir. */
    public const ASSET_PREFIX = 'assets/extensions/local-looksmax-reactions';

    /** @return array<int,string> XenForo reaction id => catalogue slug */
    public static function xenforoMap(): array
    {
        $map = [];
        foreach (self::ITEMS as $slug => $i) {
            if ($i[6] !== null) {
                $map[(int) $i[6]] = $slug;
            }
        }

        return $map;
    }

    public static function rows(): array
    {
        $rows = [];
        foreach (self::ITEMS as $slug => $i) {
            $rows[] = [
                'slug' => $slug,
                'display' => $i[0],
                'kind' => $i[1],
                'asset' => $i[2],
                'tint' => $i[3],
                'grp' => $i[4],
                'position' => $i[5],
                'xf_id' => $i[6],
                'points' => $i[7],
                'enabled' => 1,
            ];
        }

        return $rows;
    }
}
