<?php

/**
 * Create (or refresh) a single discussion containing every construct this
 * extension renders, so the pixels can be looked at deterministically.
 *
 * -------------------------------------------------------------------------
 * Why a synthetic post as well as real corpus posts
 * -------------------------------------------------------------------------
 * Real posts are the ground truth and are screenshotted too, but they are a
 * moving target: which construct appears in which thread changes with every
 * import, and rare constructs (tables, audio, deep nesting, RTL) may not be in
 * the imported slice at all. This gives one stable URL where every construct is
 * on screen at once, so a visual regression is obvious side by side.
 *
 * It is idempotent — re-running rewrites the same discussion rather than
 * creating another — and it goes through CommentPost::reply(), so the content
 * takes exactly the same parse path as an imported or user-written post. A
 * direct DB insert would prove nothing.
 *
 *   docker run --rm --network flarum_default -v flarum_app-data:/flarum/app \
 *     -v /work/flarum/extensions:/flarum/extensions --entrypoint php \
 *     flarum-app /flarum/extensions/looksmax-format/tests/showcase.php
 *
 * Prints the discussion URL on success.
 */

$appRoot = getenv('FLARUM_APP') ?: '/flarum/app';
require $appRoot.'/vendor/autoload.php';

$site = require $appRoot.'/site.php';
$app = $site->bootApp();
$container = $app->getContainer();

$db = $container->make(\Illuminate\Database\ConnectionInterface::class);

$admin = \Flarum\User\User::query()->where('username', 'admin')->first()
    ?: \Flarum\User\User::query()->orderBy('id')->first();

if (! $admin) {
    fwrite(STDERR, "no user to author the showcase as\n");
    exit(2);
}

/**
 * One entry per construct. The key becomes a heading in the post so a
 * screenshot can be read without cross-referencing this file.
 *
 * SECTION NAMES MUST NOT CONTAIN [bracketed] TAG NAMES. The key is emitted into
 * the post body as `[size=19][b]<key>[/b][/size]`, so a key like
 * "reply-gated content [hideposts]" puts a REAL, unclosed [hideposts] tag in the
 * heading — which then wraps every following section in a stray block. That is
 * what "removed content" ended up nested inside on the first render. Use
 * (parentheses) for the tag name instead.
 *
 * These are written the way the CONVERTER writes them, not the way a human
 * would — including the `title="…"` attribute form and the standalone
 * `[embed]` — so the showcase exercises the real output shape.
 */
$sections = [
    'spoiler, titled' => '[spoiler title="fotos 1 (antes)"]'
        ."\nA titled spoiler. 77% of spoilers in the corpus carry a title.\n[/spoiler]",

    'spoiler, untitled' => "[spoiler]\nNo title, so the summary must still say something clickable.\n[/spoiler]",

    'spoiler, nested' => '[spoiler title="outer"]'
        ."\nOuter body.\n[spoiler title=\"inner\"]\nInner body — this is the shape from the bug report.\n[/spoiler]\n[/spoiler]",

    'spoiler, deeply nested' => str_repeat('[spoiler title="level"]'."\n", 5)
        .'core'.str_repeat("\n".'[/spoiler]', 5),

    'quote with author and backlink' => '[quote author="UBER" post=27]'
        ."\nA quote that knows who said it and which post it came from.\n[/quote]",

    'quote without attribution' => "[quote]\nAn uncited quote — pre-2019 markup has no data-quote.\n[/quote]",

    'quote, nested two deep' => '[quote author="Alpha"]'
        ."\n[quote author=\"Beta\"]\nInnermost point.\n[/quote]\nReply to it.\n[/quote]",

    'quote containing a spoiler' => '[quote author="Gamma"]'
        ."\n[spoiler title=\"hidden inside a quote\"]\nboth at once\n[/spoiler]\n[/quote]",

    'long quote (collapse candidate)' => '[quote author="GuidePoster"]'."\n"
        .implode("\n\n", array_fill(0, 12,
            'This is the kind of full-guide quote people reply to with one line. '
            .'It runs for many paragraphs and pushes the actual reply off the screen.'
        ))
        ."\n[/quote]\nagreed",

    'unfurl link card' => '[unfurl url="https://looksmax.org/threads/tbh.1912092/" '
        .'host="looksmax.org" desc="Progress log with side-by-side photos over four years, plus the routine." '
        .'icon="https://i.looksmax.org/favicon.ico"]Definitively documenting years of bonesmashing changes[/unfurl]',

    'image, intrinsic size' => '[img alt="a test image" width=480 height=320]https://picsum.photos/480/320[/img]',

    'image, left aligned' => '[img align=left width=240 height=160]https://picsum.photos/240/160[/img]'
        .' Text flows beside a left-aligned image, which 478 posts in the corpus rely on.',

    'image inside url inside spoiler' => '[spoiler title="the operator\'s exact shape"]'
        ."\n[url=\"https://looksmax.org/\"][img width=200 height=133]https://picsum.photos/200/133[/img][/url]\n[/spoiler]",

    'mention of an imported user' => 'Ping @admin — a mention bound to a real local user.',

    'mention of a user we never imported' => '[umention name="Blackpilled" uid=17] '
        .'must render as a mention, never as a broken link and never as raw text.',

    'usergroup mention' => '[gmention name="@Mods" gid=4] is a group, not a person.',

    'sprite emote' => 'tone matters: [emote name="feelskek" label="Feels Kek"] '
        .'[emote name="ogre" label="Ogre"]',

    'media embed (click-to-play facade)' => '[embed site=youtube url="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" '
        .'thumb="https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg"]',

    'table' => "[table]\n[tr][th]construct[/th][th]count[/th][/tr]\n"
        ."[tr][td]quotes[/td][td]262,430[/td][/tr]\n"
        ."[tr][td]spoilers[/td][td]23,376[/td][/tr]\n"
        ."[tr][td]unfurl cards[/td][td]10,398[/td][/tr]\n[/table]",

    'code block with a language' => "[code=php]\n<?php\n\$x = [1, 2, 3];\n"
        ."// a code block whose contents are BBCode:\n// [spoiler=x]this must stay literal[/spoiler]\n[/code]",

    'inline code' => 'Use [c]php flarum cache:clear[/c] after changing the vocabulary.',

    'lists' => "[list]\n[*]unordered one\n[*]unordered two\n[/list]\n"
        ."[list=1]\n[*]ordered one\n[*]ordered two\n[/list]",

    'alignment, colour, size, font' => "[align=center][size=22][color=#e8c07d][b]centred, large, coloured[/b][/color][/size][/align]\n"
        .'[font=Georgia]a font run[/font], [background=#3b2f4a]a background run[/background]',

    'sup, sub, ins, strike, hr' => "x[sup]2[/sup] + H[sub]2[/sub]O, [ins]inserted[/ins], [s]struck[/s]\n[hr]\nafter the rule",

    'multilingual' => "Русский: где ты находишь такие фото\n"
        ."العربية: مرحبا بالعالم\n"
        ."Türkçe: Günaydın İstanbul\n"
        ."Español: fotos 1 (antes)\n"
        ."日本語: これはテストです\n"
        .'emoji: 😤💀🗿🤏',

    'malformed input: stray closers' => "Stray closers follow and must not be visible:\n"
        .'[spoiler title="one opener"]'."\nbody\n".'[/spoiler][/spoiler][/spoiler]',

    /*
     * NOTE: there is deliberately no "literal BBCode in prose" section here.
     *
     * Escaped BBCode rendering as visible "[spoiler]" is CORRECT — the user
     * typed it — but it is indistinguishable from a leak to any automated
     * check, and e2e/format-shots.ts asserts that no post body contains
     * visible BBCode syntax. Putting a deliberate example in the showcase
     * would permanently disarm that assertion for every real defect.
     *
     * The behaviour is covered where it can be asserted precisely instead:
     * converter.php 'literal bbcode in prose stays literal' and render.php
     * 'noparse'.
     */

    // ------------------------------------------------ looksmax.org's own addons
    'inline spoiler (ispoiler)' => 'His actual height is [ispoiler]5\'7[/ispoiler] '
        .'and he claims [ispoiler]6\'1[/ispoiler]. Click to reveal.',

    'post labels' => '[serious] [guide] [theory] [discussion] [method] [motivation] '
        ."\n[rage] [nsfw] [fake] [op] [gtfih] [hiqm] [meme]",

    'heading' => '[heading]This is a section heading[/heading]'
        ."\nBody text under the heading.",

    'reply-gated content (hideposts)' => '[hideposts]'
        ."\nOn the source board you had to reply before you could read this.\n[/hideposts]",

    'removed content (deleted)' => "[deleted]removed by staff[/deleted]\nThe reply below refers to it.",

    'mocking quote (lolquote)' => '[lolquote author="Superking, post: 1227491, member: 1927"]'
        ."\nYes this is definitely how bone remodelling works.\n[/lolquote]\nsurely",

    'bbcode-style mention (user)' => '[user uid=1927]Superking[/user] wrote this originally.',

    'quote carrying the member id' => '[quote author="UBER" post=27 member=17]'
        ."\nThe quoted member's source id is now carried, so the header can show a real\n"
        ."avatar and link to the local profile when that member was imported.\n[/quote]",

    'code block with copy button' => "[code=sh]\n"
        ."# hover the block: a copy button appears in the corner\n"
        ."docker exec flarum-app php /flarum/app/flarum cache:clear\n[/code]",
];


/*
 * This case is appended AFTER the loop, deliberately, and it is the last thing
 * in the post.
 *
 * An unclosed `[spoiler]` swallows everything that follows it to the end of the
 * post — s9e auto-closes it at the end of the block, so every later section
 * ends up INSIDE the collapsed <details>. That is faithful (XenForo behaves the
 * same way, and the converter's balance() means converted content never
 * contains an unclosed opener), but when this case sat in the middle of the
 * showcase it silently hid the nine sections after it. The per-construct
 * element clips still succeeded, because the elements were in the DOM — just
 * inside a closed spoiler. Only the full-page screenshot showed it.
 */
$sections['malformed input: an opener that is never closed'] =
    "Everything after this point is swallowed by the unclosed spoiler, which is\n"
    ."what the source board does too:\n"
    .'[spoiler title="never closed"]'."\ntrailing body";

$body = "A single post containing every construct `local/looksmax-format` renders.\n"
    ."Generated by `extensions/looksmax-format/tests/showcase.php` — re-run it to refresh.\n\n[hr]\n\n";

foreach ($sections as $label => $bb) {
    $body .= '[size=19][b]'.$label."[/b][/size]\n\n".$bb."\n\n[hr]\n\n";
}

const TITLE = 'Format showcase — every rendered construct';

$discussion = \Flarum\Discussion\Discussion::query()->where('title', TITLE)->first();

if ($discussion) {
    // Rewrite the existing first post rather than accumulating duplicates.
    $post = \Flarum\Post\CommentPost::query()
        ->where('discussion_id', $discussion->id)
        ->orderBy('number')
        ->first();

    if ($post) {
        $post->setContentAttribute($body, $admin);
        $post->save();
        echo "updated /d/{$discussion->id}-{$discussion->slug}\n";
        exit(0);
    }
}

$discussion = \Flarum\Discussion\Discussion::start(TITLE, $admin);
$discussion->save();

$post = \Flarum\Post\CommentPost::reply($discussion->id, $body, $admin->id, null);
$post->save();

$discussion->refreshCommentCount();
$discussion->refreshLastPost();
$discussion->save();

echo "created /d/{$discussion->id}-{$discussion->slug}\n";
