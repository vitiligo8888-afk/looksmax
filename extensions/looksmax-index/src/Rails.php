<?php

namespace Local\Index;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The block registry — the contribution point promised in HANDOFF-UI.md §6.
 *
 * ── Why a registry and not markup ───────────────────────────────────────────
 *
 * The front page is three columns plus a full-width band, and every lane on this
 * project has something that wants a slot in one of them: the store has featured
 * items and a balance, ranks has a leaderboard (currently injected into
 * `.LmxIndex-side` by DOM surgery, because until now there was nowhere to
 * register it), search has saved searches, guides has a tier index. Hardcoding
 * the rails would mean each of those either patches this file or keeps doing DOM
 * surgery, and DOM surgery loses every time two lanes do it at once.
 *
 * So: a block declares itself, the admin decides where it goes and whether it
 * runs, and the renderer only composes. Nothing about the layout is in the
 * markup of any block.
 *
 * ── Registering from another extension ──────────────────────────────────────
 *
 *   // in your extend.php, at file scope
 *   \Local\Index\Rails::register(fn () => new \Your\Ext\LeaderboardBlock());
 *
 * The callable is invoked lazily during a render, once, and may resolve
 * dependencies from the container. A block that throws is skipped and logged
 * rather than taking the page down — the front door renders even when a
 * contributed block is broken, which is the whole reason blocks are isolated.
 *
 * ── Placement and persistence ───────────────────────────────────────────────
 *
 * Each block declares a default side and position. The admin's overrides live in
 * the `looksmax-index.rails` setting as JSON:
 *
 *   {"news":{"side":"top","position":10,"enabled":true}, "ad":{"enabled":false}}
 *
 * Absent keys mean "use the block's own default", so a new block appears
 * immediately without the setting having to be rewritten, and disabling a block
 * is one key rather than a re-serialisation of the whole layout.
 */
class Rails
{
    public const SIDE_TOP = 'top';
    public const SIDE_LEFT = 'left';
    public const SIDE_MAIN = 'main';
    public const SIDE_RIGHT = 'right';

    public const SETTING = 'looksmax-index.rails';

    /** @var array<int, callable():BlockInterface> */
    private static array $contributed = [];

    /** The blocks this extension ships. Order here is only a tiebreak. */
    private const BUILTIN = [
        Blocks\FeedBlock::class,
        Blocks\HeroBlock::class,
        Blocks\NewsBlock::class,
        Blocks\SectionsBlock::class,
        Blocks\BrowseBlock::class,
        Blocks\NavBlock::class,
        Blocks\OnboardingBlock::class,
        Blocks\TrendingBlock::class,
        Blocks\ActivityBlock::class,
        Blocks\StatsBlock::class,
        Blocks\AdBlock::class,
    ];

    public static function register(callable $factory): void
    {
        self::$contributed[] = $factory;
    }

    /**
     * @return BlockInterface[] keyed by id, in render order, placement applied
     */
    public static function all(Context $ctx): array
    {
        $blocks = [];

        foreach (self::BUILTIN as $class) {
            $blocks[] = new $class();
        }

        foreach (self::$contributed as $factory) {
            try {
                $b = $factory();
                if ($b instanceof BlockInterface) {
                    $blocks[] = $b;
                }
            } catch (\Throwable $e) {
                // a contributed block must never be able to blank the index
            }
        }

        $overrides = self::overrides($ctx->settings);

        $out = [];
        foreach ($blocks as $b) {
            $o = $overrides[$b->id()] ?? [];
            if (array_key_exists('enabled', $o) && ! $o['enabled']) {
                continue;
            }
            $out[$b->id()] = $b;
        }

        uasort($out, function (BlockInterface $a, BlockInterface $b) use ($overrides) {
            $pa = $overrides[$a->id()]['position'] ?? $a->defaultPosition();
            $pb = $overrides[$b->id()]['position'] ?? $b->defaultPosition();

            return $pa <=> $pb;
        });

        return $out;
    }

    public static function side(BlockInterface $b, SettingsRepositoryInterface $settings): string
    {
        $o = self::overrides($settings)[$b->id()] ?? [];

        return in_array($o['side'] ?? '', [self::SIDE_TOP, self::SIDE_LEFT, self::SIDE_MAIN, self::SIDE_RIGHT], true)
            ? $o['side']
            : $b->defaultSide();
    }

    private static array $cache = [];

    private static function overrides(SettingsRepositoryInterface $settings): array
    {
        $raw = (string) ($settings->get(self::SETTING) ?: '');
        if (! isset(self::$cache[$raw])) {
            $decoded = json_decode($raw, true);
            self::$cache[$raw] = is_array($decoded) ? $decoded : [];
        }

        return self::$cache[$raw];
    }
}
