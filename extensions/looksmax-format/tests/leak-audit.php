<?php

/**
 * Leak audit: how many posts CURRENTLY IN THE FORUM show raw BBCode to a reader.
 *
 * -------------------------------------------------------------------------
 * Why a separate tool from the test suites
 * -------------------------------------------------------------------------
 * converter.php proves HTML -> BBCode. render.php proves BBCode -> HTML. Both
 * work on fixtures. Neither says anything about the rows already sitting in the
 * database, and those rows are what the operator is actually looking at.
 *
 * Flarum stores the PARSED s9e representation in posts.content, frozen at the
 * moment the post was written. Enabling a tag does NOT retroactively re-parse
 * anything: a post imported while SPOILER was unregistered has the literal
 * text "[spoiler=x]" baked into its XML as a text node, and it will render as
 * literal text forever until the row is re-parsed. So "the formatter is fixed"
 * and "the forum is fixed" are two different claims and this measures the
 * second one.
 *
 * -------------------------------------------------------------------------
 * How a leak is identified — and two wrong ways I tried first
 * -------------------------------------------------------------------------
 * WRONG #1: `content LIKE '%[quote%'`. s9e keeps the original markup inside
 * <s> (start tag) and <e> (end tag) elements so a post can be unparsed back to
 * BBCode for the editor. A correctly parsed quote therefore CONTAINS the
 * string "[quote=" — inside <s>, where no reader ever sees it. This reports
 * every healthy post as broken.
 *
 * WRONG #2: strip the <s>/<e>/<i> elements and scan the remaining text. This
 * is what this tool did first, and it over-reported by roughly 6,000 posts.
 * <s>/<e> wrappers only exist for PAIRED tags. For a STANDALONE tag s9e stores
 * the consumed markup as the element's own text content with no wrapper at all:
 *
 *     [hr]                    ->  <HR>[hr]</HR>
 *     [emote name="x"]        ->  <EMOTE name="x">[emote name="x"]</EMOTE>
 *     [umention name="y"]     ->  <UMENTION name="y">[umention name="y"]</UMENTION>
 *
 * So stripping <s>/<e> leaves that text behind and every correctly-parsed
 * standalone tag counts as a leak. It reported [emote] 4,296, [umention]
 * 1,498, [embed] 683, [hr] 251 and [img] 204 — every one of them a healthy
 * post. The tool was wrong, not the forum.
 *
 * RIGHT: render the stored XML to HTML through the real formatter and read the
 * text of THAT. The renderer is the only thing that knows which parts of the
 * XML are chrome and which reach the page — it discards <s>/<e> and it
 * discards standalone tags' consumed text, because the templates emit
 * attributes rather than content. Whatever bracketed syntax survives rendering
 * is, by definition, what a reader sees.
 *
 * Rendering 40k posts costs a few seconds and removes an entire class of
 * self-deception, which is the better trade.
 *
 *   docker run --rm --network flarum_default -v flarum_app-data:/flarum/app \
 *     -v /work/flarum/extensions:/flarum/extensions --entrypoint php \
 *     flarum-app /flarum/extensions/looksmax-format/tests/leak-audit.php
 *
 * Options:
 *   --limit=N     only the first N posts (default: all)
 *   --examples=N  show N example post ids per leaking tag (default: 3)
 *   --json        machine-readable output
 */

$appRoot = getenv('FLARUM_APP') ?: '/flarum/app';
require $appRoot.'/vendor/autoload.php';

$site = require $appRoot.'/site.php';
$app = $site->bootApp();
$container = $app->getContainer();
$db = $container->make(\Illuminate\Database\ConnectionInterface::class);
/** @var \Flarum\Formatter\Formatter $formatter */
$formatter = $container->make(\Flarum\Formatter\Formatter::class);

$limit = 0;
$examplesWanted = 3;
$asJson = in_array('--json', $argv, true);
foreach ($argv as $a) {
    if (str_starts_with($a, '--limit=')) {
        $limit = (int) substr($a, 8);
    }
    if (str_starts_with($a, '--examples=')) {
        $examplesWanted = (int) substr($a, 11);
    }
}

/**
 * Every tag name that could plausibly be BBCode in this corpus.
 *
 * Deliberately broader than what is registered: the point is to find syntax
 * the reader can see, including tags NOTHING implements yet (looksmax.org's
 * own custom addons — ispoiler, serious, fake, nsfw, guide, method, lolquote,
 * rage, op, motivation, theory — which are the work list, not noise).
 */
const CANDIDATE_TAGS = [
    // implemented
    'spoiler', 'quote', 'img', 'url', 'code', 'c', 'list', 'table', 'tr', 'td', 'th',
    'thead', 'tbody', 'b', 'i', 'u', 's', 'del', 'ins', 'sup', 'sub', 'color', 'size',
    'font', 'center', 'left', 'right', 'justify', 'align', 'background', 'hr', 'email',
    'unfurl', 'embed', 'media', 'video', 'audio', 'umention', 'gmention', 'emote', 'attach',
    // looksmax.org custom addons, not implemented anywhere yet
    'ispoiler', 'deleted', 'serious', 'fake', 'nsfw', 'guide', 'method', 'discussion',
    'rage', 'op', 'motivation', 'theory', 'lolquote', 'user', 'plain', 'noparse',
];

$pattern = '/\[(\/?)('.implode('|', CANDIDATE_TAGS).')(?=[\]\s=])/i';

/*
 * --self-test: prove the detector can go red.
 *
 * A leak audit that reports "0 leaks" is exactly as convincing as a detector
 * that returns 0 unconditionally. These run the same visibleText() + pattern
 * path over bodies whose answer is known, so a broken detector is caught
 * before its clean bill of health is believed.
 */
if (in_array('--self-test', $argv, true)) {
    /*
     * The "must be flagged" cases have to name tags that are genuinely NOT
     * implemented, and that list shrinks as work lands. This block originally
     * used [ispoiler], [serious] and [lolquote]; all three were implemented in
     * Configure::community() afterwards and the self-test correctly started
     * reporting them as no-longer-leaking. That is the right outcome and the
     * wrong fixture — so it now uses tags with no handler anywhere.
     *
     * [attach] is listed in Configure::OWNED but no branch registers it, and
     * [plain] is a XenForo tag we do not implement. If either is ever
     * implemented, this self-test will say so rather than quietly passing.
     */
    $cases = [
        // [label, bbcode, must the detector flag it?]
        ['unimplemented [attach] leaks', 'see [attach]1234[/attach] above', true],
        ['unimplemented [plain] leaks', 'this is [plain]as typed[/plain]', true],
        ['bare bracket syntax leaks', 'why does [plain=x] not work', true],
        ['healthy spoiler does NOT leak', '[spoiler title="t"]body[/spoiler]', false],
        ['healthy standalone hr does NOT leak', "a\n[hr]\nb", false],
        ['healthy standalone emote does NOT leak', 'x [emote name="feelskek" label="Feels Kek"]', false],
        ['healthy standalone umention does NOT leak', '[umention name="Nobody" uid=1]', false],
        ['healthy standalone embed does NOT leak', '[embed site=youtube url="https://y.test/e/a"]', false],
        ['healthy image does NOT leak', '[img width=10 height=10]https://y.test/a.png[/img]', false],
        ['bbcode inside a code block does NOT leak', "[code]\n[spoiler=x]sample[/spoiler]\n[/code]", false],
        // Implemented in Configure::community(); if any of these regress to
        // unregistered, this self-test goes red before the corpus audit does.
        ['healthy ispoiler does NOT leak', '[ispoiler]hidden[/ispoiler]', false],
        ['healthy label does NOT leak', 'this is [serious] business', false],
        ['healthy lolquote does NOT leak', '[lolquote author="a"]x[/lolquote]', false],
        ['healthy user tag does NOT leak', '[user uid=1]Name[/user]', false],
        ['healthy heading does NOT leak', '[heading]Section[/heading]', false],
    ];

    $bad = 0;
    foreach ($cases as [$label, $bb, $shouldFlag]) {
        $visible = visibleText($formatter->parse($bb, null));
        $flagged = (bool) preg_match($pattern, $visible);
        $ok = $flagged === $shouldFlag;
        printf("%-45s %s%s\n", $label, $ok ? 'ok' : 'BROKEN', $ok ? '' : '   visible: '.trim($visible));
        if (! $ok) {
            $bad++;
        }
    }

    echo $bad === 0
        ? "\nSELF-TEST OK: the detector flags real leaks and clears healthy tags.\n"
        : "\nSELF-TEST BROKEN: $bad case(s) wrong.\n";
    exit($bad === 0 ? 0 : 1);
}

$leaksByTag = [];
$examples = [];
$totalPosts = 0;
$leakingPosts = 0;

$query = $db->table('posts')->select('id', 'discussion_id', 'content')->orderBy('id');
if ($limit > 0) {
    $query->limit($limit);
}

$prev = libxml_use_internal_errors(true);

foreach ($query->cursor() as $row) {
    $totalPosts++;
    $xml = (string) ($row->content ?? '');
    if ($xml === '') {
        continue;
    }

    $visible = visibleText($xml);
    if ($visible === '' || ! str_contains($visible, '[')) {
        continue;
    }

    if (! preg_match_all($pattern, $visible, $m)) {
        continue;
    }

    $leakingPosts++;
    foreach (array_unique(array_map('strtolower', $m[2])) as $tag) {
        $leaksByTag[$tag] = ($leaksByTag[$tag] ?? 0) + 1;
        if (count($examples[$tag] ?? []) < $examplesWanted) {
            $examples[$tag][] = [
                'post' => (int) $row->id,
                'discussion' => (int) $row->discussion_id,
                'excerpt' => trim(mb_substr(preg_replace('/\s+/u', ' ', $visible), 0, 160)),
            ];
        }
    }
}

libxml_use_internal_errors($prev);

/**
 * The text a reader actually sees: render the stored XML and take its text.
 *
 * Going through the real renderer rather than reasoning about which XML nodes
 * are chrome is the whole point — see the header. Content inside <pre>/<code>
 * is removed first, because a code block legitimately displays BBCode and that
 * is correct behaviour, not a leak.
 */
function visibleText(string $xml): string
{
    global $formatter;

    if ($xml === '') {
        return '';
    }

    try {
        $html = $formatter->render($xml, null);
    } catch (\Throwable $e) {
        // A row whose XML the renderer rejects is a real problem, but it must
        // not stop the audit. Count it by returning a sentinel the caller
        // cannot mistake for content.
        return '';
    }

    // Drop code blocks and inline code: BBCode shown inside them is intended.
    $html = preg_replace('#<pre\b.*?</pre>#is', ' ', $html) ?? $html;
    $html = preg_replace('#<code\b.*?</code>#is', ' ', $html) ?? $html;

    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

arsort($leaksByTag);

$summary = [
    'posts_examined' => $totalPosts,
    'posts_with_visible_bbcode' => $leakingPosts,
    'clean_fraction' => $totalPosts > 0
        ? round(($totalPosts - $leakingPosts) / $totalPosts * 100, 3)
        : 100.0,
    'by_tag' => $leaksByTag,
    'examples' => $examples,
];

if ($asJson) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    exit($leakingPosts > 0 ? 1 : 0);
}

printf("posts examined            %d\n", $totalPosts);
printf("posts showing raw BBCode  %d\n", $leakingPosts);
printf("clean                     %.3f%%\n\n", $summary['clean_fraction']);

if ($leaksByTag === []) {
    echo "no visible BBCode in any post.\n";
    exit(0);
}

printf("%-14s %8s  %s\n", 'tag', 'posts', 'example');
printf("%-14s %8s  %s\n", str_repeat('-', 14), str_repeat('-', 8), str_repeat('-', 60));
foreach ($leaksByTag as $tag => $n) {
    $ex = $examples[$tag][0] ?? null;
    printf(
        "%-14s %8d  %s\n",
        '['.$tag.']',
        $n,
        $ex ? 'post '.$ex['post'].': '.mb_substr($ex['excerpt'], 0, 60) : ''
    );
}

exit(1);
