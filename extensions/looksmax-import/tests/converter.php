<?php

/**
 * Converter test suite.
 *
 * Every fixture under tests/fixtures/ is a real post body pulled straight out
 * of the scrape (tools/extract-fixtures.ts picks one post per construct by
 * matching on the raw html, so the inputs are markup the board actually emits
 * rather than markup someone imagined it emits). The .meta.json beside each
 * one records the source post id, so any assertion here can be checked against
 * the live board.
 *
 * Two layers:
 *
 *   1. property assertions — what must and must not be true of the output.
 *   2. golden files — the full converted body, byte for byte. A golden diff is
 *      how an unrelated change to the walker is noticed; run with --update to
 *      accept new output, and READ the diff before you do.
 *
 * Needs only ext-dom and ext-mbstring, so it runs outside the Flarum container:
 *
 *   docker run --rm -v $PWD:/w -w /w php:8.3-cli php extensions/looksmax-import/tests/converter.php
 */

require __DIR__.'/../src/HtmlToBbcode.php';

use Local\Import\HtmlToBbcode;

$dir = __DIR__.'/fixtures';
$goldenDir = __DIR__.'/golden';
$update = in_array('--update', $argv, true);
$only = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = substr($a, 7);
    }
}

@mkdir($goldenDir, 0o755, true);

$pass = 0;
$fail = 0;
$failures = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;

        return;
    }
    $fail++;
    $failures[] = $name.($detail !== '' ? "\n      ".str_replace("\n", "\n      ", substr($detail, 0, 900)) : '');
    echo "  FAIL  $name\n";
    if ($detail !== '') {
        echo '        '.str_replace("\n", "\n        ", rtrim(substr($detail, 0, 900)))."\n";
    }
}

/**
 * A small, deliberately incomplete user map. The point is to exercise BOTH
 * mention paths: 494883 and 17 are "imported", everyone else is not.
 */
$users = [
    17 => ['id' => 101, 'name' => 'UBER'],
    494883 => ['id' => 202, 'name' => 'VireziV'],
    19347 => ['id' => 303, 'name' => 'Master of the Universe'],   // a name that
    // does NOT survive username sanitisation — the exact case the old
    // name-keyed map dropped
];

$media = [
    'https://i.looksmax.org/attachments/2018/08/1408514_IJHS-12-86-g003.jpg' => '/media/post-images/ab/abcdef0123456789.jpg',
    'https://i.imgur.com/MwdS3C6.png' => '/media/post-images/cd/cdef012345678901.png',
];

$conv = new HtmlToBbcode($users, $media);

// ---------------------------------------------------------------- unit cases
// These are hand-written on purpose: they pin the exact contract for markup
// shapes that the corpus contains but that no single fixture isolates.
$units = [
    'spoiler nests once per source spoiler' => [
        '<div class="bbCodeSpoiler"><button class="bbCodeSpoiler-button button"><span class="button-text">'
        .'<span>Spoiler: <span class="bbCodeSpoiler-button-title">fotos 1 (antes)</span></span></span></button>'
        .'<div class="bbCodeSpoiler-content"><div class="bbCodeBlock bbCodeBlock--spoiler">'
        .'<div class="bbCodeBlock-content">body</div></div></div></div>',
        function (string $out) {
            check('  exactly one [spoiler', substr_count($out, '[spoiler') === 1, $out);
            check('  exactly one [/spoiler]', substr_count($out, '[/spoiler]') === 1, $out);
            check('  title preserved verbatim', str_contains($out, 'title="fotos 1 (antes)"'), $out);
            check('  body kept', str_contains($out, 'body'), $out);
        },
    ],

    'nested spoilers stay balanced' => [
        (function () {
            $inner = 'deep';
            for ($i = 3; $i >= 1; $i--) {
                $inner = '<div class="bbCodeSpoiler"><button class="bbCodeSpoiler-button"><span class="button-text">'
                    .'<span>Spoiler: <span class="bbCodeSpoiler-button-title">level '.$i.'</span></span></span></button>'
                    .'<div class="bbCodeSpoiler-content"><div class="bbCodeBlock bbCodeBlock--spoiler">'
                    .'<div class="bbCodeBlock-content">'.$inner.'</div></div></div></div>';
            }

            return $inner;
        })(),
        function (string $out) {
            check('  three opens', substr_count($out, '[spoiler') === 3, $out);
            check('  three closes', substr_count($out, '[/spoiler]') === 3, $out);
            check('  all three titles', str_contains($out, 'level 1') && str_contains($out, 'level 2') && str_contains($out, 'level 3'), $out);
        },
    ],

    'quote carries author and source post id' => [
        '<blockquote data-attributes="member: 17" data-quote="UBER" data-source="post: 27" '
        .'class="bbCodeBlock bbCodeBlock--expandable bbCodeBlock--quote js-expandWatch">'
        .'<div class="bbCodeBlock-title"><a href="/goto/post?id=27" class="bbCodeBlock-sourceJump">UBER said:</a></div>'
        .'<div class="bbCodeBlock-content"><div class="bbCodeBlock-expandContent js-expandContent">Good fam.</div>'
        .'<div class="bbCodeBlock-expandLink js-expandLink"><a role="button">Click to expand...</a></div></div>'
        .'</blockquote>after',
        function (string $out) {
            check('  author attribute', str_contains($out, 'author="UBER"'), $out);
            check('  source post id', str_contains($out, 'post=27'), $out);
            check('  body kept', str_contains($out, 'Good fam.'), $out);
            check('  "Click to expand" chrome dropped', ! str_contains($out, 'Click to expand'), $out);
            check('  "UBER said:" title not duplicated into the body', substr_count($out, 'UBER') === 1, $out);
            check('  text after the quote survives', str_contains($out, 'after'), $out);
        },
    ],

    'unfurl becomes one card tag' => [
        '<div class="bbCodeBlock bbCodeBlock--unfurl js-unfurl fauxBlockLink" data-unfurl="true" '
        .'data-url="https://looksmax.org/threads/x.1912092/" data-host="looksmax.org" data-pending="false">'
        .'<div class="contentRow"><div class="contentRow-figure js-unfurl-figure"><img src="https://looksmax.org/img/twitter.png" alt="looksmax.org"></div>'
        .'<div class="contentRow-main"><h3 class="contentRow-header js-unfurl-title">'
        .'<a href="https://looksmax.org/threads/x.1912092/" class="link link--internal">(PICS) Definitively documenting years of bonesmashing changes | All denial ends here tbh</a></h3>'
        .'<div class="contentRow-snippet js-unfurl-desc">*This is a repost with permission</div>'
        .'<div class="contentRow-minor contentRow-minor--hideLinks"><span class="js-unfurl-favicon">'
        .'<img src="https://looksmax.org/favicon.svg" alt="looksmax.org" class="bbCodeBlockUnfurl-icon"></span>looksmax.org</div>'
        .'</div></div></div>',
        function (string $out) {
            check('  one [unfurl', substr_count($out, '[unfurl') === 1, $out);
            check('  one [/unfurl]', substr_count($out, '[/unfurl]') === 1, $out);
            check('  url attribute', str_contains($out, 'url="https://looksmax.org/threads/x.1912092/"'), $out);
            check('  host attribute', str_contains($out, 'host="looksmax.org"'), $out);
            check('  title as content', str_contains($out, 'Definitively documenting'), $out);
            check('  description captured', str_contains($out, 'repost with permission'), $out);
            check('  favicon captured', str_contains($out, 'favicon.svg'), $out);
            // the exact live-site defect: a bare `url=` with no opening bracket
            check('  no bare url= residue', ! preg_match('/(?<!\[)(?<!\w)url=https/', $out), $out);
            // the favicon+domain trailer must not survive as loose text
            check('  no trailing bare domain text', ! preg_match('/\[\/unfurl\]\s*looksmax\.org/', $out), $out);
        },
    ],

    'mention resolves through the source user id' => [
        '<span class="username" data-user-id="19347" data-username="@Master">@Master</span>',
        function (string $out) {
            // 19347 maps to a display name that username sanitisation would
            // have destroyed; keying on the id is what makes this work
            check('  flarum mention syntax', str_contains($out, '#303'), $out);
            check('  local display name used', str_contains($out, '@"Master of the Universe"'), $out);
        },
    ],

    'mention of an unimported user is still a mention' => [
        '<span class="username" data-user-id="999999" data-username="@Ghost">@Ghost</span>',
        function (string $out) {
            check('  umention tag', str_contains($out, '[umention name="Ghost"'), $out);
            check('  source id kept', str_contains($out, 'uid=999999'), $out);
            check('  not bare text', ! preg_match('/^@Ghost$/', trim($out)), $out);
        },
    ],

    'usergroup mention' => [
        '<a href="https://looksmax.org/members/usergroup/4/" class="ug" data-usergroup-id="4" data-groupname="@Mods">@Mods</a> can i get sticky',
        function (string $out) {
            check('  gmention tag', str_contains($out, '[gmention name="@Mods" gid=4]'), $out);
        },
    ],

    'sprite smilie becomes a labelled emote, not a raw shortname' => [
        'lol<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" '
        .'class="smilie smilie--sprite smilie--sprite40" alt=":feelskek:" title="FeelsKek    :feelskek:" data-shortname=":feelskek:">',
        function (string $out) {
            check('  emote tag', str_contains($out, '[emote name=":feelskek:"'), $out);
            check('  human label', str_contains($out, 'label="FeelsKek"'), $out);
            check('  no bare shortname text', ! preg_match('/(?<!")\:feelskek\:(?!")/', str_replace('name=":feelskek:"', '', $out)), $out);
        },
    ],

    'unicode smilie becomes the character itself' => [
        '<img class="smilie smilie--emoji" alt="👋" title="Waving hand    :wave:" src="https://cdnjs.cloudflare.com/x.png" data-shortname=":wave:">',
        function (string $out) {
            check('  emoji kept', str_contains($out, '👋'), $out);
            check('  no img tag', ! str_contains($out, '[img'), $out);
        },
    ],

    'image is rewritten to the local copy when we have one' => [
        '<div class="bbImageWrapper js-lbImage" title="IJHS" data-src="https://i.looksmax.org/attachments/2018/08/1408514_IJHS-12-86-g003.jpg" data-type="image">'
        .'<img src="https://i.looksmax.org/attachments/2018/08/1408514_IJHS-12-86-g003.jpg" data-url="" class="bbImage" alt="IJHS" width="1178" height="1160"></div>',
        function (string $out) {
            check('  local path used', str_contains($out, '/media/post-images/ab/abcdef0123456789.jpg'), $out);
            check('  intrinsic size kept', str_contains($out, 'width=1178') && str_contains($out, 'height=1160'), $out);
            check('  remote host gone', ! str_contains($out, 'i.looksmax.org'), $out);
        },
    ],

    'image we did not download keeps its remote url' => [
        '<div class="bbImageWrapper js-lbImage" data-src="https://i.imgur.com/NOTMIRRORED.png">'
        .'<img src="https://i.imgur.com/NOTMIRRORED.png" class="bbImage" alt=""></div>',
        function (string $out) {
            check('  remote url kept', str_contains($out, 'https://i.imgur.com/NOTMIRRORED.png'), $out);
        },
    ],

    'proxied image url is unwrapped before the manifest lookup' => [
        '<div class="bbImageWrapper lazyload js-lbImage" data-src="/proxy.php?image=https%3A%2F%2Fi.imgur.com%2FMwdS3C6.png&amp;hash=5b03">'
        .'<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" '
        .'data-src="/proxy.php?image=https%3A%2F%2Fi.imgur.com%2FMwdS3C6.png&amp;hash=5b03" '
        .'data-url="https://i.imgur.com/MwdS3C6.png" class="bbImage lazyload" alt="MwdS3C6.png" width="" height=""></div>',
        function (string $out) {
            check('  resolved to the local copy', str_contains($out, '/media/post-images/cd/cdef012345678901.png'), $out);
            check('  no proxy.php', ! str_contains($out, 'proxy.php'), $out);
            check('  no data: uri', ! str_contains($out, 'data:image'), $out);
            check('  empty width/height not emitted', ! str_contains($out, 'width='), $out);
        },
    ],

    'code block keeps its language and does not re-parse its contents' => [
        '<div class="bbCodeBlock bbCodeBlock--code"><div class="bbCodeBlock-title">Code:</div>'
        .'<div class="bbCodeBlock-content"><pre class="bbCodeCode" data-lang="php"><code>[IMG]https://x/y[/IMG]</code></pre></div></div>',
        function (string $out) {
            check('  language kept', str_contains($out, '[code=php]'), $out);
            check('  contents verbatim', str_contains($out, '[IMG]https://x/y[/IMG]'), $out);
            check('  "Code:" chrome dropped', ! str_contains($out, 'Code:'), $out);
        },
    ],

    'table becomes table tags, not a markdown pipe grid' => [
        '<div class="bbTable"><table><tbody><tr><th>A</th><th>B</th></tr>'
        .'<tr><td>1</td><td><img class="bbImage" src="https://x/y.png" alt=""></td></tr></tbody></table></div>',
        function (string $out) {
            check('  table tag', str_contains($out, '[table]'), $out);
            check('  header cells', substr_count($out, '[th]') === 2, $out);
            check('  body cells', substr_count($out, '[td]') === 2, $out);
            check('  image survives inside the cell', str_contains($out, '[img]https://x/y.png[/img]'), $out);
            check('  no pipe table', ! str_contains($out, '| --- |'), $out);
        },
    ],

    'nested list keeps its structure' => [
        '<ul><li data-xf-list-type="ul">one<ul><li data-xf-list-type="ul">one-a</li></ul></li><li data-xf-list-type="ul">two</li></ul>',
        function (string $out) {
            check('  two lists', substr_count($out, '[list]') === 2, $out);
            check('  balanced', substr_count($out, '[list]') === substr_count($out, '[/list]'), $out);
            check('  three items', substr_count($out, '[*]') === 3, $out);
        },
    ],

    'ordered list' => [
        '<ol><li>first</li><li>second</li></ol>',
        function (string $out) {
            check('  ordered marker', str_contains($out, '[list=1]'), $out);
        },
    ],

    'styled span becomes colour/size/font' => [
        '<span style="font-size: 22px"><span style="color: rgb(184, 49, 47)">red and big</span></span>'
        .'<span style="font-family: \'Arial\'">arial</span>',
        function (string $out) {
            check('  size', str_contains($out, '[size=22]'), $out);
            check('  colour converted to hex', str_contains($out, '[color=#b8312f]'), $out);
            check('  font family', str_contains($out, '[font=Arial]'), $out);
        },
    ],

    'color: null is not a colour' => [
        '<span style="color: null">plain</span>',
        function (string $out) {
            check('  no colour tag', ! str_contains($out, '[color'), $out);
            check('  text kept', str_contains($out, 'plain'), $out);
        },
    ],

    'centered div' => [
        '<div style="text-align: center">middle</div>',
        function (string $out) {
            check('  align tag', str_contains($out, '[align=center]'), $out);
        },
    ],

    // [embed], not [media]: MEDIA is core's (s9e MediaEmbed), and the tag is
    // standalone because its template renders attributes only, which makes s9e
    // infer autoClose and orphan any closer. See Local\Format\Configure::media().
    's9e media embed becomes an embed tag' => [
        '<span data-s9e-mediaembed="youtube"><span><span style="background:url(https://i.ytimg.com/vi/HB3tmC2f3t0/hqdefault.jpg) 50% 50% / cover" '
        .'data-s9e-mediaembed-iframe="[&quot;allow&quot;,&quot;clipboard-write&quot;,&quot;src&quot;,&quot;https:\/\/www.youtube-nocookie.com\/embed\/HB3tmC2f3t0&quot;]"></span></span></span>',
        function (string $out) {
            check('  embed tag', str_contains($out, '[embed site=youtube'), $out);
            check('  embed url', str_contains($out, 'youtube-nocookie.com/embed/HB3tmC2f3t0'), $out);
            check('  thumbnail', str_contains($out, 'hqdefault.jpg'), $out);
            // A standalone tag: emitting a closer is what orphaned [/media].
            check('  no closing tag', ! str_contains($out, '[/embed]'), $out);
            check('  not the core MEDIA tag', ! str_contains($out, '[media'), $out);
        },
    ],

    'uploaded video' => [
        '<div class="bbMediaWrapper bbMediaWrapper--inline"><div class="bbMediaWrapper-inner">'
        .'<video controls="" data-xf-init="video-init"><source src="https://i.looksmax.org/attachments/2019/11/1553672_onnee.mp4">'
        .'<div class="bbMediaWrapper-fallback">Your browser is not able to display this video.</div></video></div></div>',
        function (string $out) {
            check('  video tag', str_contains($out, '[video]https://i.looksmax.org/attachments/2019/11/1553672_onnee.mp4[/video]'), $out);
            check('  fallback text dropped', ! str_contains($out, 'not able to display'), $out);
        },
    ],

    'uploaded audio' => [
        '<div class="bbMediaWrapper"><div class="bbMediaWrapper-inner bbMediaWrapper-inner--audio">'
        .'<audio controls=""><source src="/data/audio/2814/x.mp3"><div class="bbMediaWrapper-fallback">nope</div></audio></div></div>',
        function (string $out) {
            check('  audio tag with absolute url', str_contains($out, '[audio]https://looksmax.org/data/audio/2814/x.mp3[/audio]'), $out);
        },
    ],

    'cloudflare-obfuscated email is decoded' => [
        '<a href="/cdn-cgi/l/email-protection"><span class="__cf_email__" data-cfemail="cb83aaa5a5aa8bbba3aab9a6aaafaee5a8a4a6">[email&#160;protected]</span></a>',
        function (string $out) {
            check('  decoded', str_contains($out, '@'), $out);
            check('  no cf placeholder', ! str_contains($out, 'email protected'), $out);
        },
    ],

    'noscript duplicate of a lazyloaded image is dropped' => [
        '<div class="bbImageWrapper lazyload" data-src="https://x/y.png"><img class="bbImage lazyload" data-url="https://x/y.png" src="data:image/gif;base64,AA" alt="">'
        .'<noscript><img src="https://x/y.png" class="bbImage" alt=""></noscript></div>',
        function (string $out) {
            check('  image appears once', substr_count($out, '[img') === 1, $out);
        },
    ],

    'literal bbcode in prose stays literal' => [
        'to post an image you write [IMG]url[/IMG] like that',
        function (string $out) {
            check('  opening bracket escaped', str_contains($out, '\\[IMG]'), $out);
            check('  closing bracket escaped', str_contains($out, '\\[/IMG]'), $out);
        },
    ],

    'markdown specials in prose are escaped' => [
        'he said *This is a repost* and used _underscores_ and 2*3',
        function (string $out) {
            check('  asterisks escaped', ! preg_match('/(?<!\\\\)\*/', $out), $out);
            check('  underscores escaped', ! preg_match('/(?<!\\\\)_/', $out), $out);
        },
    ],

    'line-leading markdown is escaped' => [
        "- not a list<br># not a heading<br>&gt; not a quote",
        function (string $out) {
            check('  dash escaped', str_contains($out, '\\- not a list'), $out);
            check('  hash escaped', str_contains($out, '\\# not a heading'), $out);
            check('  gt escaped', str_contains($out, '\\> not a quote'), $out);
        },
    ],

    'bare link is left for the autolinker' => [
        '<a href="https://looksmax.org/threads/some_thread.123/" class="link link--internal">https://looksmax.org/threads/some_thread.123/</a>',
        function (string $out) {
            check('  no url tag', ! str_contains($out, '[url'), $out);
            check('  url intact and unescaped', str_contains($out, 'https://looksmax.org/threads/some_thread.123/'), $out);
        },
    ],

    'labelled link keeps both parts' => [
        '<a href="https://example.com/a" class="link link--external">click here</a>',
        function (string $out) {
            check('  url tag', str_contains($out, '[url="https://example.com/a"]click here[/url]'), $out);
        },
    ],

    'empty input' => ['', function (string $out) {
        check('  empty out', $out === '', $out);
    }],

    'malformed html does not throw' => [
        '<div class="bbCodeSpoiler"><div class="bbCodeSpoiler-content"><b>unclosed',
        function (string $out) {
            check('  balanced spoiler', substr_count($out, '[spoiler') === substr_count($out, '[/spoiler]'), $out);
            check('  balanced bold', substr_count($out, '[b]') === substr_count($out, '[/b]'), $out);
        },
    ],

    /*
     * ---------------------------------------------------------------------
     * Encoding. These are regression tests for a crash, not hypotheticals.
     * ---------------------------------------------------------------------
     * text() applies a `/u` pattern, and PCRE returns NULL (not a partial
     * result) for a subject that is not well-formed UTF-8. text() is declared
     * `: string`, so NULL was a fatal TypeError that killed the whole import.
     *
     * It fired on the first real pass over the corpus, on a Russian post, with
     * 771 fixture assertions already green — because every fixture was valid
     * UTF-8 and a 383k-post corpus in six languages is not.
     */
    'cyrillic text survives' => [
        '<div>где ты находишь такие фото</div>',
        function (string $out) {
            check('  text preserved', str_contains($out, 'где ты находишь такие фото'), $out);
            check('  valid utf-8 out', mb_check_encoding($out, 'UTF-8'), $out);
        },
    ],

    'truncated multi-byte sequence does not fatal' => [
        // A lone 0xD0 is the first byte of a two-byte Cyrillic character with
        // its continuation byte missing — exactly what a cut-off HTTP body
        // leaves behind, and what NULLed the /u pattern.
        "<div>test \xD0 tail</div>",
        function (string $out) {
            check('  did not throw', true);
            check('  valid utf-8 out', mb_check_encoding($out, 'UTF-8'), bin2hex($out));
            check('  surrounding text kept', str_contains($out, 'test') && str_contains($out, 'tail'), $out);
        },
    ],

    'lone continuation byte does not fatal' => [
        "<div>\x80\x9f broken lead</div>",
        function (string $out) {
            check('  valid utf-8 out', mb_check_encoding($out, 'UTF-8'), bin2hex($out));
            check('  text kept', str_contains($out, 'broken lead'), $out);
        },
    ],

    'rtl arabic survives' => [
        '<div>مرحبا بالعالم <b>غامق</b></div>',
        function (string $out) {
            check('  text preserved', str_contains($out, 'مرحبا بالعالم'), $out);
            check('  bold applied', str_contains($out, '[b]غامق[/b]'), $out);
        },
    ],

    'emoji and turkish dotted capitals survive' => [
        '<div>Günaydın İstanbul 😤💀🗿</div>',
        function (string $out) {
            check('  turkish preserved', str_contains($out, 'Günaydın İstanbul'), $out);
            check('  emoji preserved', str_contains($out, '😤💀🗿'), $out);
        },
    ],

    'invalid utf-8 inside a spoiler title does not fatal' => [
        '<div class="bbCodeSpoiler"><button class="bbCodeSpoiler-button">'
        ."<span class=\"bbCodeSpoiler-button-title\">титул \xD0</span></button>"
        .'<div class="bbCodeSpoiler-content">body</div></div>',
        function (string $out) {
            check('  valid utf-8 out', mb_check_encoding($out, 'UTF-8'), bin2hex($out));
            check('  spoiler still emitted', str_contains($out, '[spoiler'), $out);
            check('  body kept', str_contains($out, 'body'), $out);
        },
    ],
];

echo "== unit cases ==\n";
foreach ($units as $name => [$html, $assert]) {
    if ($only !== null && ! str_contains($name, $only)) {
        continue;
    }
    echo "$name\n";
    $out = $conv->convert($html);
    $assert($out);
}

// ------------------------------------------------------- corpus fixtures
echo "\n== corpus fixtures ==\n";

// A leak is any residue of markup the reader should never see. This is the
// same shape of regex the full-corpus scanner uses, applied to the converter's
// OUTPUT (where our own tags are legal) rather than to rendered html.
$forbidden = [
    'xenforo class name' => '/\b(?:bbCode|bbImage|bbMedia|contentRow|js-unfurl|js-expand|fauxBlockLink)/',
    'raw html tag' => '/<\/?(?:div|span|blockquote|table|img|a|button|noscript)\b/i',
    'bare url= attribute' => '/(?<![\[\w])url=https?:/',
    'xenforo expand chrome' => '/Click to expand\.\.\./',
    'undecoded proxy url' => '/proxy\.php\?image=/',
    'data uri' => '/\]data:image/',
];

$files = glob($dir.'/*.html');
sort($files);

foreach ($files as $file) {
    $name = basename($file, '.html');
    if ($only !== null && ! str_contains($name, $only)) {
        continue;
    }

    $html = file_get_contents($file);
    $out = $conv->convert($html);
    $stats = $conv->stats();

    echo str_pad($name, 26).' '.str_pad(strlen($html).'B html', 12)
        .'-> '.str_pad(strlen($out).'B bb', 11)
        .implode(' ', array_map(fn ($k, $v) => "$k=$v", array_keys($stats), $stats))."\n";

    check("$name: non-empty", trim($out) !== '' || trim(strip_tags($html)) === '', substr($out, 0, 300));

    foreach ($forbidden as $why => $re) {
        if (preg_match($re, $out, $m, PREG_OFFSET_CAPTURE)) {
            $at = max(0, $m[0][1] - 80);
            check("$name: no $why", false, '…'.substr($out, $at, 240).'…');
        } else {
            check("$name: no $why", true);
        }
    }

    // balance, per tag, on the real output
    foreach (['spoiler', 'quote', 'code', 'list', 'table', 'unfurl', 'b', 'i', 'u'] as $tag) {
        $open = preg_match_all('/(?<!\\\\)\['.$tag.'(?=[\]\s=])/i', $out);
        $close = preg_match_all('/(?<!\\\\)\[\/'.$tag.'\]/i', $out);
        check("$name: [$tag] balanced", $open === $close, "open=$open close=$close");
    }

    // golden
    $golden = $goldenDir.'/'.$name.'.bbcode';
    if ($update || ! file_exists($golden)) {
        file_put_contents($golden, $out);
        echo "        (golden written)\n";
    } else {
        $want = file_get_contents($golden);
        if ($want !== $out) {
            $diff = '';
            $a = explode("\n", $want);
            $b = explode("\n", $out);
            for ($i = 0, $n = max(count($a), count($b)); $i < $n && strlen($diff) < 700; $i++) {
                if (($a[$i] ?? null) !== ($b[$i] ?? null)) {
                    $diff .= "line $i\n  - ".substr($a[$i] ?? '(none)', 0, 200)."\n  + ".substr($b[$i] ?? '(none)', 0, 200)."\n";
                }
            }
            check("$name: matches golden", false, $diff);
        } else {
            check("$name: matches golden", true);
        }
    }
}

echo "\n".($fail ? "FAILED  " : "ok      ")."$pass passed, $fail failed\n";
if ($fail) {
    echo "\nfailures:\n  - ".implode("\n  - ", $failures)."\n";
}
exit($fail ? 1 : 0);
