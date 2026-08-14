<?php

/**
 * Render-layer test: BBCode -> HTML, through the REAL Flarum formatter.
 *
 * -------------------------------------------------------------------------
 * Why this file exists
 * -------------------------------------------------------------------------
 * looksmax-import/tests/converter.php had 812 green assertions while the live
 * site was rendering
 *
 *     [spoiler=fotos 1 (antes)]
 *     [/spoiler][/spoiler][/spoiler]
 *
 * as literal text on every page. Every one of those 812 assertions was true.
 * They test HTML -> BBCode and stop there, and the defect was one layer
 * further down: `local-looksmax-format` was never `composer require`d into the
 * app and never appeared in `extensions_enabled`, so s9e/TextFormatter had no
 * SPOILER tag to parse into and emitted the source text verbatim. A converter
 * test cannot see that. Nothing could have caught it except running BBCode
 * through the formatter the site actually uses.
 *
 * So this harness boots Flarum, resolves the container's Formatter — the same
 * object the post pipeline uses, with the same extension set, the same
 * Extend\Formatter configuration and the same on-disk formatter cache — and
 * asserts on the HTML that comes out. If the extension is disabled, the tag is
 * misconfigured, the cache is stale, or a nesting/tag limit silently truncates
 * to literal text, this goes red.
 *
 * It must run INSIDE the app container, where Flarum is installed:
 *
 *   docker exec flarum-app php /flarum/extensions/looksmax-format/tests/render.php
 *
 * -------------------------------------------------------------------------
 * What "parse" means here
 * -------------------------------------------------------------------------
 * Flarum stores the PARSED representation (s9e XML) in posts.content and
 * renders that to HTML on read. Both halves are exercised:
 *
 *   parse()  BBCode -> XML   — a tag that is not registered stays as text and
 *                              the XML comes back as <t>…</t> (plain) instead
 *                              of <r>…</r> (rich). That distinction is the
 *                              single most reliable leak detector available
 *                              and is asserted directly.
 *   render() XML   -> HTML   — what the browser receives.
 *
 * A consequence worth stating: enabling the extension does NOT retroactively
 * fix posts already in the database, because their XML was frozen at import
 * time with the tags unparsed. Those rows have to be re-parsed. The importer's
 * idempotency on imported_id is what makes that safe.
 */

$appRoot = getenv('FLARUM_APP') ?: '/flarum/app';

if (! is_file($appRoot.'/vendor/autoload.php')) {
    fwrite(STDERR, "not a flarum install: $appRoot\n");
    fwrite(STDERR, "run this inside the app container:\n");
    fwrite(STDERR, "  docker exec flarum-app php /flarum/extensions/looksmax-format/tests/render.php\n");
    exit(2);
}

require $appRoot.'/vendor/autoload.php';

$site = require $appRoot.'/site.php';

// bootApp() runs every extender, including this extension's Extend\Formatter,
// and returns the InstalledApp whose container the request handler uses. Going
// through it rather than constructing a Configurator by hand is the whole
// point: a hand-built configurator would have been green while the extension
// sat disabled.
/** @var \Flarum\Foundation\InstalledApp $app */
$app = $site->bootApp();
$container = $app->getContainer();

/** @var \Flarum\Formatter\Formatter $formatter */
$formatter = $container->make(\Flarum\Formatter\Formatter::class);

// Guard against the exact failure this file exists to catch: if the extension
// is not enabled, every assertion below would fail with a confusing diff.
// Say so plainly instead.
$enabled = $container->make(\Flarum\Extension\ExtensionManager::class)->isEnabled('local-looksmax-format');
if (! $enabled) {
    fwrite(STDERR, "local-looksmax-format is NOT enabled — the formatter has none of its tags.\n");
    fwrite(STDERR, "  php flarum extension:enable local-looksmax-format && php flarum cache:clear\n");
    exit(2);
}

// ---------------------------------------------------------------- harness

$pass = 0;
$fail = 0;
$failures = [];
$only = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = substr($a, 7);
    }
}
$selfTest = in_array('--self-test', $argv, true);

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;

        return;
    }
    $fail++;
    $failures[] = $name;
    echo "  FAIL  $name\n";
    if ($detail !== '') {
        echo '        '.str_replace("\n", "\n        ", rtrim(substr($detail, 0, 1200)))."\n";
    }
}

/** Parse BBCode the way a post body is parsed, with no actor-specific context. */
function parseBb(string $bb): string
{
    global $formatter;

    return $formatter->parse($bb, null);
}

function renderBb(string $bb): string
{
    global $formatter;

    return $formatter->render(parseBb($bb), null);
}

/**
 * The leak detector.
 *
 * s9e emits <t> when it parsed NOTHING rich and <r> when it did. A body that
 * contains a bracketed tag but comes back as <t> was not understood at all —
 * that is the live-site bug in its purest form. Separately, any residual
 * "[name" or "[/name" in the RENDERED html is a tag that leaked through as
 * text even though something else in the body parsed.
 */
function assertNoLeak(string $label, string $bb, array $tags): void
{
    $xml = parseBb($bb);
    $html = renderBb($bb);

    check(
        "$label: parsed rich (not <t>)",
        str_starts_with($xml, '<r'),
        "xml came back plain, nothing was recognised:\n".substr($xml, 0, 600)
    );

    foreach ($tags as $tag) {
        $leaked = preg_match('/\[\/?'.preg_quote($tag, '/').'\b/i', strip_tags($html));
        check(
            "$label: [$tag] does not leak as literal text",
            ! $leaked,
            "rendered html still contains a literal [$tag]:\n".substr($html, 0, 900)
        );
    }
}

function assertHtml(string $label, string $bb, array $needles, array $absent = []): void
{
    $html = renderBb($bb);
    foreach ($needles as $n) {
        check("$label: html contains ".$n, str_contains($html, $n), "got:\n".substr($html, 0, 1200));
    }
    foreach ($absent as $n) {
        check("$label: html does NOT contain ".$n, ! str_contains($html, $n), "got:\n".substr($html, 0, 1200));
    }
}

function group(string $name): bool
{
    global $only;
    if ($only !== null && $only !== $name) {
        return false;
    }
    echo "== $name\n";

    return true;
}

// ---------------------------------------------------------------- the tests

/*
 * 1. SPOILERS — the reported defect.
 *
 * The operator's paste contained all four shapes at once: a titled spoiler, a
 * bare spoiler, one nested inside the other, and a trailing run of closers
 * with no opener. Each is asserted separately so a regression names itself.
 */
if (group('spoiler')) {
    // The exact literal text from the live site, as the old converter emitted
    // it: [spoiler=title] with the title as the default attribute.
    assertNoLeak('spoiler-eq', '[spoiler=fotos 1 (antes)]body[/spoiler]', ['spoiler']);
    assertHtml('spoiler-eq', '[spoiler=fotos 1 (antes)]body[/spoiler]', [
        '<details', 'lmxSpoiler', 'fotos 1 (antes)', 'body',
    ]);

    // …and as the current converter emits it: title as a named attribute.
    assertNoLeak('spoiler-attr', '[spoiler title="fotos 1 (antes)"]body[/spoiler]', ['spoiler']);
    assertHtml('spoiler-attr', '[spoiler title="fotos 1 (antes)"]body[/spoiler]', [
        '<details', 'fotos 1 (antes)',
    ]);

    // Untitled spoilers must still render a summary, or there is nothing to
    // click and the body is unreachable.
    assertNoLeak('spoiler-bare', '[spoiler]hidden[/spoiler]', ['spoiler']);
    assertHtml('spoiler-bare', '[spoiler]hidden[/spoiler]', ['<summary', 'Spoiler', 'hidden']);

    // Nesting: the operator's example is a titled spoiler wrapping a bare one.
    $nested = '[spoiler=outer][spoiler]inner[/spoiler][/spoiler]';
    assertNoLeak('spoiler-nested', $nested, ['spoiler']);
    check(
        'spoiler-nested: two <details> elements',
        substr_count(renderBb($nested), '<details') === 2,
        renderBb($nested)
    );

    /*
     * Depth. s9e's default nestingLimit is 10 and everything past it is
     * emitted as literal text — a silent recreation of the exact bug being
     * fixed. Configure::limits() raises it to 30; this proves the raise took
     * effect rather than trusting the assignment.
     */
    $deep = str_repeat('[spoiler=d]', 12).'core'.str_repeat('[/spoiler]', 12);
    assertNoLeak('spoiler-depth-12', $deep, ['spoiler']);
    check(
        'spoiler-depth-12: all 12 levels survive',
        substr_count(renderBb($deep), '<details') === 12,
        substr_count(renderBb($deep), '<details').' details elements'
    );

    // Unbalanced input must not emit stray closers into the text.
    assertHtml('spoiler-extra-closers', '[spoiler=t]body[/spoiler][/spoiler][/spoiler]', [], ['[/spoiler]']);
}

/*
 * 2. THE OPERATOR'S FRAGMENT, VERBATIM.
 *
 * Reproduced byte for byte from the bug report, including the eaten opening
 * bracket on `url=` and the three unbalanced closers. Nothing here may reach
 * the reader as raw markup.
 */
if (group('operator-fragment')) {
    $bb = "[spoiler=fotos 1 (antes)]\n"
        ."[spoiler]\n"
        ."url=https://looksmax.org/threads/tbh.1912092/ Definitively documenting years of bonesmashing changes...[/url]\n"
        ."[img]https://looksmax.org/favicon.svg[/img]   looksmax.org\n"
        .'[/spoiler][/spoiler][/spoiler]';

    $html = renderBb($bb);
    check('operator-fragment: no literal [spoiler', ! str_contains(strip_tags($html), '[spoiler'), substr($html, 0, 900));
    check('operator-fragment: no literal [/spoiler]', ! str_contains(strip_tags($html), '[/spoiler]'), substr($html, 0, 900));
    check('operator-fragment: no literal [img]', ! str_contains(strip_tags($html), '[img]'), substr($html, 0, 900));
    check('operator-fragment: no literal [/url]', ! str_contains(strip_tags($html), '[/url]'), substr($html, 0, 900));
    // The image must render, but NOT from looksmax.org: RewriteMedia sends
    // every third-party media url through our own proxy.
    check('operator-fragment: renders the image through the proxy', str_contains($html, '/media/p/'), substr($html, 0, 900));
    check('operator-fragment: no looksmax.org src reaches the page', ! str_contains($html, 'src="https://looksmax.org'), substr($html, 0, 900));
    check('operator-fragment: renders two spoilers', substr_count($html, '<details') === 2, substr($html, 0, 900));
}

/*
 * 3. QUOTES — 13,126 blocks in a 20k-post sample, the most common construct.
 */
if (group('quote')) {
    assertNoLeak('quote-author', '[quote author="UBER"]said this[/quote]', ['quote']);
    assertHtml('quote-author', '[quote author="UBER"]said this[/quote]', [
        '<blockquote', 'lmxQuote', 'UBER', 'said this',
    ]);

    // An uncited quote must still render as a quote, not vanish.
    assertHtml('quote-bare', '[quote]anon[/quote]', ['<blockquote', 'anon']);

    // Source post id survives parsing so ResolveQuoteLinks can turn it into a
    // local jump link at render time.
    check(
        'quote-post: source post id survives into the xml',
        str_contains(parseBb('[quote author="A" post=27]x[/quote]'), '27'),
        parseBb('[quote author="A" post=27]x[/quote]')
    );

    assertNoLeak('quote-nested', '[quote author="A"][quote author="B"]inner[/quote]outer[/quote]', ['quote']);

    // Same silent-truncation risk as spoilers.
    $deep = str_repeat('[quote author="x"]', 12).'core'.str_repeat('[/quote]', 12);
    check(
        'quote-depth-12: all 12 levels survive',
        substr_count(renderBb($deep), '<blockquote') === 12,
        substr_count(renderBb($deep), '<blockquote').' blockquotes'
    );

    // Mixed nesting is what the corpus actually contains.
    assertNoLeak('quote-in-spoiler', '[spoiler=s][quote author="A"]q[/quote][/spoiler]', ['spoiler', 'quote']);
    assertNoLeak('spoiler-in-quote', '[quote author="A"][spoiler=s]q[/spoiler][/quote]', ['spoiler', 'quote']);
}

/*
 * 4. UNFURL CARDS — 623 in a 20k sample, and the direct cause of the
 *    `[img]…favicon.svg[/img] looksmax.org` trailer in the bug report.
 */
if (group('unfurl')) {
    $bb = '[unfurl url="https://looksmax.org/threads/tbh.1912092/" host="looksmax.org" '
        .'desc="Definitively documenting years of bonesmashing changes" '
        .'icon="https://looksmax.org/favicon.svg"]TBH[/unfurl]';
    assertNoLeak('unfurl', $bb, ['unfurl']);
    assertHtml('unfurl', $bb, ['lmxUnfurl', 'looksmax.org', 'TBH']);
    // The card's ICON is a subresource and must be proxied; the card's HREF
    // is a navigation target the reader chooses, and stays as-is.
    assertHtml('unfurl', $bb, ['/media/p/'], ['src="https://looksmax.org/favicon.svg"']);
    // A card is a link; without an href it is decoration.
    assertHtml('unfurl', $bb, ['href="https://looksmax.org/threads/tbh.1912092/"']);
}

/*
 * 5. IMAGES — intrinsic size and lazy loading. Missing width/height is the
 *    cause of thread-wide reflow as images stream in.
 */
if (group('image')) {
    assertNoLeak('img', '[img]https://looksmax.org/a.png[/img]', ['img']);
    assertHtml('img', '[img]https://looksmax.org/a.png[/img]', ['<img', 'loading="lazy"', '/media/p/']);
    // The whole point: no reader's browser ever requests their host.
    assertHtml('img', '[img]https://looksmax.org/a.png[/img]', [], ['https://looksmax.org/a.png']);
    assertHtml(
        'img-sized',
        '[img width=640 height=480]https://looksmax.org/a.png[/img]',
        ['width="640"', 'height="480"']
    );
    assertHtml(
        'img-aligned',
        '[img align=left]https://looksmax.org/a.png[/img]',
        ['lmxImageWrap--left']
    );
    // [img] inside [url] inside [spoiler] — the operator's exact shape.
    assertNoLeak(
        'img-in-url-in-spoiler',
        '[spoiler=s][url="https://x.test/"][img]https://x.test/a.png[/img][/url][/spoiler]',
        ['spoiler', 'url', 'img']
    );
}

/*
 * 6. MENTIONS — "people tagging eachother and shit with @ that shit isn't
 *    carried over properly".
 *
 * A mention of a user who was never imported must render as a styled mention,
 * never as a broken link and never as raw markup. flarum/mentions invalidates
 * a USERMENTION with no matching row, which is why those fell back to text;
 * UMENTION carries no id, so nothing can invalidate it.
 */
if (group('mention')) {
    assertNoLeak('umention', '[umention name="Blackpilled" uid=17]', ['umention']);
    assertHtml('umention', '[umention name="Blackpilled" uid=17]', ['UserMention', '@Blackpilled'], ['<a ']);
    assertNoLeak('gmention', '[gmention name="@Mods" gid=4]', ['gmention']);
    assertHtml('gmention', '[gmention name="@Mods" gid=4]', ['GroupMention', '@Mods']);
}

/*
 * 7. The rest of the vocabulary. Every tag Configure::OWNED registers gets at
 *    least a leak assertion, so adding a tag without registering it fails here
 *    rather than on the live site.
 */
if (group('vocabulary')) {
    $cases = [
        'c' => '[c]inline code[/c]',
        'font' => '[font=Arial]text[/font]',
        'align' => '[align=center]text[/align]',
        'background' => '[background=#ff0000]text[/background]',
        'hr' => 'above[hr]below',
        'sup' => 'x[sup]2[/sup]',
        'sub' => 'H[sub]2[/sub]O',
        'ins' => '[ins]added[/ins]',
        'emote' => '[emote name="feelskek" label="Feels Kek"]',
        // EMBED, not MEDIA: core's s9e MediaEmbed owns the MEDIA tag and
        // declares it autoClose, which orphaned every [/media].
        'embed' => '[embed site=youtube url="https://youtube.com/watch?v=abc"]',
        'video' => '[video]https://looksmax.org/v.mp4[/video]',
        'audio' => '[audio]https://looksmax.org/a.mp3[/audio]',
        'table' => '[table][tr][td]a[/td][td]b[/td][/tr][/table]',
        'noparse' => '[noparse][b]not bold[/b][/noparse]',
    ];
    foreach ($cases as $tag => $bb) {
        assertNoLeak("vocab-$tag", $bb, [$tag]);
    }

    // Legacy content converted before [embed] became standalone carries a
    // [/embed] that s9e can never match. BalanceTags must eat it.
    assertNoLeak(
        'vocab-embed-legacy-closer',
        '[embed site=youtube url="https://youtube.com/watch?v=abc"]youtube[/embed]',
        ['embed']
    );

    // Table cells must land inside a real table, not float away.
    assertHtml('table', '[table][tr][td]a[/td][/tr][/table]', ['<table', '<tr', '<td', 'a']);
    // noparse means noparse.
    assertHtml('noparse', '[noparse][b]not bold[/b][/noparse]', ['[b]not bold[/b]'], ['<b>not bold']);
}

/*
 * 7b. looksmax.org's OWN BBCode addons.
 *
 * These were the largest class of visible raw BBCode left on the forum after
 * the standard vocabulary was fixed, because nothing anywhere implements them.
 * Every one is asserted, so adding a label to community() without registering
 * it fails here rather than on a guide thread.
 */
if (group('community')) {
    assertNoLeak('ispoiler', '[ispoiler]hidden inline[/ispoiler]', ['ispoiler']);
    assertHtml('ispoiler', 'before [ispoiler]hidden inline[/ispoiler] after', [
        'lmxISpoiler', 'hidden inline',
        // click-to-reveal must be reachable without a mouse
        'tabindex="0"', 'role="button"',
    ]);

    // Every label the corpus contains.
    $labels = [
        'serious' => 'Serious', 'guide' => 'Guide', 'theory' => 'Theory',
        'discussion' => 'Discussion', 'method' => 'Method', 'motivation' => 'Motivation',
        'rage' => 'Rage', 'nsfw' => 'NSFW', 'fake' => 'Fake', 'op' => 'OP',
        'gtfih' => 'GTFIH', 'hiqm' => 'High IQ', 'meme' => 'Meme',
    ];
    foreach ($labels as $tag => $text) {
        assertNoLeak("label-$tag", "prefix [$tag] suffix", [$tag]);
        assertHtml("label-$tag", "[$tag]", ['lmxLabel--'.$tag, $text]);
    }

    assertNoLeak('deleted', '[deleted]gone[/deleted]', ['deleted']);
    assertHtml('deleted', '[deleted]gone[/deleted]', ['lmxDeleted', 'Content removed']);

    assertNoLeak('heading', '[heading]A section[/heading]', ['heading']);
    assertHtml('heading', '[heading]A section[/heading]', ['<h3', 'lmxHeading', 'A section']);

    assertNoLeak('hideposts', '[hideposts]gated body[/hideposts]', ['hideposts']);
    assertHtml('hideposts', '[hideposts]gated body[/hideposts]', [
        'lmxHidden', 'gated body', 'Was reply-gated',
    ]);

    assertNoLeak('uwsl', '[uwsl]one line[/uwsl]', ['uwsl']);
    assertHtml('uwsl', '[uwsl]one line[/uwsl]', ['lmxUwsl', 'one line']);

    assertNoLeak('lolquote', '[lolquote author="Superking, post: 1227491"]cope[/lolquote]', ['lolquote']);
    assertHtml('lolquote', '[lolquote author="Superking, post: 1227491"]cope[/lolquote]', [
        'lmxQuote--lol', 'cope', 'posted to mock',
        // the packed "Name, post: N, member: N" string must show only the name
        'Superking',
    ]);
    assertHtml('lolquote', '[lolquote author="Superking, post: 1227491"]cope[/lolquote]', [], ['post: 1227491']);

    // [user=id]Name[/user] — a mention written as BBCode. Must not become a
    // link, because the id is a SOURCE id and means nothing here.
    assertNoLeak('user-tag', '[user uid=1927]Superking[/user]', ['user']);
    assertHtml('user-tag', '[user uid=1927]Superking[/user]', ['UserMention', '@Superking'], ['<a ']);
}

/*
 * 7c. QUOTE carries the quoted member's SOURCE user id.
 *
 * 298,957 of 300,179 quotes in the corpus have it and nothing read it before —
 * the single largest gap in the census. It is what lets the quote header show
 * a real avatar and link to the local profile.
 */
if (group('quote-member')) {
    $bb = '[quote author="UBER" post=27 member=17]body[/quote]';
    assertNoLeak('quote-member', $bb, ['quote']);
    check(
        'quote-member: member id survives parsing',
        str_contains(parseBb($bb), 'member="17"'),
        parseBb($bb)
    );
    // A member who was never imported must still render the plain name — not a
    // dead link and not a missing header.
    assertHtml('quote-member-unimported', $bb, ['lmxQuote', 'UBER', 'body']);
}

/*
 * 7d. NO THIRD-PARTY MEDIA URL REACHES A RENDERED PAGE.
 *
 * The operator's requirement, and the reason RewriteMedia exists. Measured
 * before it: one thread page made 48 references to looksmax.org, 8 of them to
 * their image host, every one fetched by the reader's browser -- handing them
 * our traffic volume, our readers' IPs and user agents, and the exact posts
 * being read, plus the ability to break or substitute every image here.
 *
 * The forum does send Referrer-Policy: no-referrer, but that is one config
 * change from being lost and it would be lost silently. Not making the request
 * at all is the only property that cannot regress by accident.
 */
if (group('media-proxy')) {
    $cases = [
        'img' => '[img]https://i.looksmax.org/data/attachments/1/1-abc.jpg[/img]',
        'img-sized' => '[img width=100 height=100]https://i.ytimg.com/vi/x/hq.jpg[/img]',
        'unfurl-icon' => '[unfurl url="https://x.test/a" host="x.test" icon="https://x.test/f.ico"]T[/unfurl]',
        'embed-thumb' => '[embed site=youtube url="https://youtube.com/e/a" thumb="https://i.ytimg.com/vi/a/hq.jpg"]',
        'video-poster' => '[video poster="https://i.looksmax.org/p.jpg"]https://i.looksmax.org/v.mp4[/video]',
        'quote-avatar' => '[quote author="A" avatar="https://i.looksmax.org/av/1.jpg"]x[/quote]',
    ];

    foreach ($cases as $label => $bb) {
        $html = renderBb($bb);
        check(
            "media-proxy-$label: routed through /media/p/",
            str_contains($html, '/media/p/'),
            substr($html, 0, 500)
        );
        // No absolute third-party url may survive anywhere in a fetchable
        // attribute. Checked by host, not by exact string, so a partial rewrite
        // cannot slip through.
        foreach (['i.looksmax.org', 'i.ytimg.com', 'x.test/f.ico'] as $host) {
            $leaked = preg_match('#(?:src|poster|href)="https?://'.preg_quote($host, '#').'#i', $html);
            check(
                "media-proxy-$label: no fetchable attribute points at $host",
                ! $leaked,
                substr($html, 0, 500)
            );
        }
    }

    // A LOCAL path must be left alone -- proxying our own origin through our own
    // proxy would be a pointless extra hop on every image.
    $html = renderBb('[img]/media/post-images/abc.jpg[/img]');
    check(
        'media-proxy: local paths are not proxied',
        str_contains($html, '/media/post-images/abc.jpg') && ! str_contains($html, '/media/p/'),
        substr($html, 0, 400)
    );

    // Code blocks must not pull a third-party script. s9e's stock CODE template
    // embeds a <script src="https://cdn.jsdelivr.net/gh/s9e/hljs-loader...">,
    // which is executable third-party code running on this origin.
    $html = renderBb("[code]\nx = 1\n[/code]");
    check(
        'media-proxy: code block loads no third-party script',
        ! str_contains($html, 'jsdelivr') && ! str_contains($html, '<script'),
        substr($html, 0, 500)
    );
}

/*
 * 8. PATHOLOGICAL CONTENT. The corpus is 383k posts of user-generated text in
 *    six languages; anything that can be in a string is in it somewhere.
 */
if (group('pathological')) {
    // RTL, Cyrillic, CJK and emoji must survive parse+render byte-identical.
    $texts = [
        'rtl' => 'مرحبا بالعالم',
        'cyrillic' => 'Привет мир, это тест',
        'turkish' => 'Günaydın, nasılsın? İstanbul',
        'emoji' => 'gonna mog 😤💀🗿 fr',
        'cjk' => '日本語のテスト',
        'mixed' => 'Привет 😤 مرحبا test',
    ];
    foreach ($texts as $label => $t) {
        $html = renderBb('[spoiler=t]'.$t.'[/spoiler]');
        check("pathological-$label: text survives", str_contains($html, $t), $html);
        check("pathological-$label: valid utf-8 out", mb_check_encoding($html, 'UTF-8'), $label);
    }

    // A code block whose CONTENTS are BBCode must not be parsed as BBCode.
    $code = "[code]\n[spoiler=x]this is a code sample[/spoiler]\n[/code]";
    $html = renderBb($code);
    check(
        'pathological-code-holds-bbcode: no <details> from inside a code block',
        ! str_contains($html, '<details'),
        substr($html, 0, 700)
    );
    check(
        'pathological-code-holds-bbcode: the bbcode is shown verbatim',
        str_contains($html, '[spoiler=x]'),
        substr($html, 0, 700)
    );

    // Stray closers with no opener.
    $html = renderBb('just text[/spoiler][/quote][/b]');
    check('pathological-stray-closers: keeps the prose', str_contains($html, 'just text'), $html);

    // An unclosed opener must not swallow the remainder of the thread body.
    $html = renderBb('[spoiler=t]dangling body with no closer');
    check('pathological-unclosed: body still reaches the reader', str_contains($html, 'dangling body'), $html);

    // Empty and whitespace-only bodies must not fatal.
    foreach (['[spoiler][/spoiler]', '[spoiler=t]   [/spoiler]', '[quote][/quote]'] as $bb) {
        $ok = true;
        try {
            renderBb($bb);
        } catch (\Throwable $e) {
            $ok = false;
        }
        check('pathological-empty: '.$bb.' does not throw', $ok);
    }

    // A very wide image must not be trusted to size itself.
    assertHtml(
        'pathological-oversized-image',
        '[img width=6000 height=4000]https://looksmax.org/huge.png[/img]',
        ['width="6000"', 'height="4000"']
    );
}

/*
 * 9. SELF-TEST — "a green check is not evidence until it has gone red
 *    deliberately".
 *
 * `--self-test` inverts a handful of assertions that MUST fail. If this block
 * reports anything as passing, the harness is not actually asserting and every
 * green above is worthless.
 */
if ($selfTest) {
    echo "== self-test (these MUST fail)\n";
    $before = $GLOBALS['fail'];

    /*
     * Six deliberate failures, one per assertion mechanism, so that a helper
     * silently degrading to a no-op is caught rather than reported as green:
     *
     *   assertNoLeak   2 (the <t>/<r> richness check and the literal-text scan)
     *   assertHtml     2 (one impossible needle each)
     *   assertHtml     1 (an `absent` needle that IS present)
     *   check          1 (a bare false)
     *
     * The exact count is asserted, not a lower bound: if a future edit makes
     * one of these accidentally pass, "at least N" would hide it.
     */
    assertNoLeak('SELFTEST-unregistered-tag', '[definitelynotatag]x[/definitelynotatag]', ['definitelynotatag']);
    assertHtml('SELFTEST-impossible-needle', '[spoiler=t]body[/spoiler]', ['THIS-STRING-IS-NOT-IN-THE-OUTPUT']);
    assertHtml('SELFTEST-wrong-element', '[spoiler=t]body[/spoiler]', ['<marquee']);
    assertHtml('SELFTEST-absent-that-is-present', '[spoiler=t]body[/spoiler]', [], ['<details']);
    check('SELFTEST-bare-false', false, 'this assertion is hard-coded to fail');

    $expected = 6;
    $newFails = $GLOBALS['fail'] - $before;
    echo "\nself-test produced $newFails failures (expected $expected)\n";
    echo $newFails === $expected
        ? "SELF-TEST OK: every assertion helper can go red.\n"
        : "SELF-TEST BROKEN: assertions are not being evaluated as expected.\n";
    exit($newFails === $expected ? 0 : 1);
}

echo "\n";
if ($fail === 0) {
    echo "ok\t$pass passed, 0 failed\n";
    exit(0);
}
echo "FAILED\t$pass passed, $fail failed\n";
foreach ($failures as $f) {
    echo "  - $f\n";
}
exit(1);
