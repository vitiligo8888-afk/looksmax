<?php

namespace Local\Import;

/**
 * XenForo post HTML to the BBCode vocabulary local/looksmax-format registers.
 *
 * Importing `text` instead of converting `html` discards roughly 63% of each
 * post: measured across the scrape, 66.2% contain a link, 65.3% a quote, 16.2%
 * smilies, 12.7% attachments, 7.7% inline images. Average html is 1,445 chars
 * against 537 of text.
 *
 * The construct list this handles is not a guess. It comes from a blind census
 * of every element, class token and data-* attribute in 370,667 scraped posts
 * (tools/survey-html.ts); the counts in the comments below are from that run,
 * and every branch here corresponds to something the census actually found.
 *
 * ------------------------------------------------------------------------
 * Two structural rules, both learned from real leaks on the live site:
 *
 *  1. CLASS MATCHING IS TOKEN-EXACT. The previous version used str_contains,
 *     so `bbCodeSpoiler` matched `bbCodeSpoiler-content` and `bbCodeSpoiler-
 *     button` as well. Walking a spoiler therefore re-entered the spoiler
 *     handler on its own children and emitted a fresh `[spoiler]` per level of
 *     internal markup — the "nested spoilers several levels deep, all leaking
 *     as raw tags" the operator reported. `bbCodeBlock--spoiler`, XenForo's
 *     inner wrapper, was a second independent copy of the same bug.
 *
 *  2. A HANDLER CONSUMES ITS SUBTREE. Every branch either returns without
 *     descending or descends through an explicitly named child, never through
 *     `.//` (which reaches into nested blocks and steals their content).
 *
 * Output is BBCode, parsed downstream by s9e/TextFormatter through the normal
 * post pipeline, so what gets stored is real formatter XML.
 */
class HtmlToBbcode
{
    /**
     * Source user id => ['id' => local user id, 'name' => display name].
     *
     * Keyed on the SOURCE id, not the username: usernames are sanitised on
     * import (spaces to underscores, non-alphanumerics dropped, truncated to
     * 28 chars), so a name-keyed map missed every user whose display name
     * contained a space or a symbol, and those mentions rendered as raw text.
     *
     * @var array<int,array{id:int,name:string}>
     */
    private array $userMap;

    /** url => forum-relative path of the local copy, for images we downloaded. */
    private array $mediaMap;

    /** Set when convert() runs, for diagnostics. @var array<string,int> */
    private array $stats = [];

    /**
     * Class tokens whose element is a pure wrapper: descend, emit nothing.
     * Everything here was verified against the census — e.g. bbCodeBlock
     * appears 296,489 times and is *always* one of quote/spoiler/code/unfurl,
     * never meaningful on its own.
     */
    private const TRANSPARENT = [
        'bbCodeBlock-content', 'bbCodeBlock-expandContent', 'bbCodeSpoiler-content',
        'bbCodeBlock--spoiler', 'bbTable', 'bbMediaWrapper-inner', 'contentRow',
        'contentRow-main', 'button-text',
    ];

    /**
     * Class tokens whose element is chrome: emit nothing, do not descend.
     * bbCodeBlock-title is consumed by the quote/code handlers themselves.
     */
    private const CHROME = [
        'bbCodeBlock-expandLink', 'bbCodeSpoiler-button', 'bbCodeBlock-title',
        'bbMediaWrapper-fallback', 'js-unfurl-figure', 'js-unfurl-favicon',
    ];

    public function __construct(array $userMap = [], array $mediaMap = [])
    {
        $this->userMap = $userMap;
        $this->mediaMap = $mediaMap;
    }

    public function setMediaMap(array $map): void
    {
        $this->mediaMap = $map;
    }

    public function setUserMap(array $map): void
    {
        $this->userMap = $map;
    }

    /** @return array<string,int> construct counts from the last convert() call */
    public function stats(): array
    {
        return $this->stats;
    }

    public function convert(?string $html): string
    {
        $this->stats = [];

        if ($html === null || trim($html) === '') {
            return '';
        }

        // Scraped html contains invalid utf-8 byte sequences; DOMDocument
        // rejects the whole document on the first one, so drop the bad bytes
        // rather than losing the post.
        if (! mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8');
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // meta charset rather than an xml declaration: the latter is ignored
        // for html fragments and the content comes back mojibaked
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div id="__root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__root');
        if (! $root) {
            return trim(strip_tags($html));
        }

        $out = $this->walk($root);

        return $this->tidy($out);
    }

    // ------------------------------------------------------------------ walk

    private function walk(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= $this->node($child);
        }

        return $out;
    }

    private function node(\DOMNode $n): string
    {
        if ($n->nodeType === XML_TEXT_NODE) {
            /*
             * XenForo indents its markup, so raw text nodes carry that
             * indentation as newlines mid-sentence. Collapse it.
             *
             * -------------------------------------------------------------
             * The line-break class is spelled out. `\R` here was corrupting
             * every Cyrillic and Arabic post in the corpus.
             * -------------------------------------------------------------
             * PCRE's `\R` matches NEL (U+0085) among its line separators, and
             * without the `/u` modifier the pattern runs BYTE-wise — so it
             * matched the raw byte 0x85 wherever it appeared, including as the
             * CONTINUATION byte of a perfectly valid two-byte character.
             *
             * 0x85 is a legal continuation byte (the range is 0x80–0xBF), so
             * this silently ate half of, among many others:
             *
             *   U+0445  х   Cyrillic "ha"    (D1 85)
             *   U+0645  م   Arabic "meem"    (D9 85)
             *   U+05C5  ׅ    Hebrew point     (D7 85)
             *
             * leaving an orphaned lead byte that the encoding scrub then
             * replaced with "?". Measured: "где ты находишь" came out as
             * "где ты на? одишь", and "مرحبا" as "? رحبا".
             *
             * Every fixture in the suite was English, so 771 assertions were
             * green while a large share of a six-language corpus was being
             * mangled. That is why the encoding cases below it are now unit
             * tests rather than something to remember to check.
             *
             * Only real line terminators are wanted, so they are listed
             * explicitly and the pattern is UTF-8 aware.
             */
            $raw = $n->nodeValue ?? '';
            $collapsed = preg_replace('/[ \t]*(?:\r\n|\r|\n|\x{2028}|\x{2029})[ \t]*/u', ' ', $raw);

            return $this->text($collapsed ?? $raw);
        }

        if ($n->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        /** @var \DOMElement $n */
        $tag = strtolower($n->tagName);

        // --- classes first: XenForo carries meaning on the class, not the tag
        foreach (self::CHROME as $c) {
            if ($this->hasClass($n, $c)) {
                return '';
            }
        }

        if ($this->hasClass($n, 'bbCodeSpoiler')) {
            return $this->spoiler($n);
        }
        if ($this->hasClass($n, 'bbCodeBlock--quote')) {
            return $this->quote($n);
        }
        if ($this->hasClass($n, 'bbCodeBlock--unfurl')) {
            return $this->unfurl($n);
        }
        if ($this->hasClass($n, 'bbCodeBlock--code')) {
            return $this->codeBlock($n);
        }
        if ($this->hasClass($n, 'bbImageWrapper')) {
            return $this->imageWrapper($n);
        }
        if ($this->hasClass($n, 'bbMediaWrapper')) {
            return $this->mediaWrapper($n);
        }
        if ($this->hasClass($n, '__cf_email__')) {
            return $this->cfEmail($n);
        }
        // 102,237 mentions: a span, not an anchor, on this board
        if ($this->hasClass($n, 'username') && $n->getAttribute('data-user-id') !== '') {
            // data-username and the text both carry a leading "@"; the mention
            // templates add their own, and "@@name" is what that looks like.
            $label = ltrim(trim($n->getAttribute('data-username') ?: $n->textContent), '@');

            return $this->mention($label, (int) $n->getAttribute('data-user-id'));
        }
        if ($n->hasAttribute('data-s9e-mediaembed')) {
            return $this->s9eEmbed($n);
        }

        foreach (self::TRANSPARENT as $c) {
            if ($this->hasClass($n, $c)) {
                return $this->walk($n);
            }
        }

        // --- then the element name
        switch ($tag) {
            case 'br':
                return "\n";

            case 'noscript':
            case 'script':
            case 'style':
            case 'button':
                // <noscript> duplicates the lazyloaded <img> above it; keeping
                // both doubles every image in 725 posts.
                return '';

            case 'p':
                $inner = trim($this->walk($n));

                return $inner === '' ? '' : "\n".$inner."\n\n";

            case 'div':
                return $this->block($n);

            case 'span':
                return $this->styledInline($n, $this->walk($n));

            case 'b':
            case 'strong':
                return $this->wrapInline('b', $this->walk($n));

            case 'i':
            case 'em':
                return $this->wrapInline('i', $this->walk($n));

            case 'u':
                return $this->wrapInline('u', $this->walk($n));

            case 's':
            case 'strike':
            case 'del':
                return $this->wrapInline('s', $this->walk($n));

            case 'ins':
                return $this->wrapInline('ins', $this->walk($n));

            case 'sup':
                return $this->wrapInline('sup', $this->walk($n));

            case 'sub':
                return $this->wrapInline('sub', $this->walk($n));

            case 'code':
                $t = trim($n->textContent);

                return $t === '' ? '' : '[c]'.$t.'[/c]';

            case 'pre':
                return $this->codeBlock($n);

            case 'h1': case 'h2': case 'h3':
            case 'h4': case 'h5': case 'h6':
                // Headings map to [size], not markdown: 19,537 <h3> exist and a
                // markdown "#" is only a heading at the start of a line, which
                // inline XenForo headings frequently are not.
                $sizes = ['h1' => 26, 'h2' => 22, 'h3' => 19, 'h4' => 17, 'h5' => 15, 'h6' => 14];
                $inner = trim($this->walk($n));

                return $inner === '' ? '' : "\n[size=".$sizes[$tag].'][b]'.$inner."[/b][/size]\n\n";

            case 'hr':
                return "\n[hr]\n";

            case 'ul':
            case 'ol':
                return $this->list($n, $tag === 'ol');

            case 'li':
                // an <li> reached outside a list wrapper
                return '[*]'.trim($this->walk($n))."\n";

            case 'img':
                return $this->img($n);

            case 'iframe':
                $src = $this->absolute($n->getAttribute('src'));

                return $src ? $this->mediaTag($this->siteOf($src), $src, null, null) : '';

            case 'video':
                return $this->videoTag($n, 'video');

            case 'audio':
                return $this->videoTag($n, 'audio');

            case 'source':
                return '';

            case 'a':
                return $this->anchor($n);

            case 'table':
                return $this->table($n);

            case 'tbody': case 'thead': case 'tr': case 'td': case 'th':
                // reached outside a <table>; treat as plain content
                return $this->walk($n);
        }

        return $this->walk($n);
    }

    /** A block-level div: honours text-align, otherwise just a paragraph break. */
    private function block(\DOMElement $n): string
    {
        $inner = $this->walk($n);
        if (trim($inner) === '') {
            return '';
        }

        $align = $this->alignOf($n);
        if ($align !== null) {
            $this->count('align');

            return "\n[align=".$align.']'.trim($inner)."[/align]\n";
        }

        return $inner."\n";
    }

    // -------------------------------------------------------------- blocks

    /**
     * XenForo spoiler. 23,376 blocks across 3,934 posts, 19,593 of them titled.
     *
     *   <div class="bbCodeSpoiler">
     *     <button class="bbCodeSpoiler-button">… <span class="bbCodeSpoiler-button-title">T</span></button>
     *     <div class="bbCodeSpoiler-content">
     *       <div class="bbCodeBlock bbCodeBlock--spoiler">
     *         <div class="bbCodeBlock-content"> … body … </div>
     *
     * The title is read from THIS spoiler's own button (a direct child), never
     * with `.//`, or a nested spoiler's title is stolen for the outer one.
     */
    private function spoiler(\DOMElement $n): string
    {
        $this->count('spoiler');

        $title = '';
        $button = $this->child($n, 'button');
        if ($button) {
            $t = $this->find($button, './/*[contains(concat(" ",normalize-space(@class)," ")," bbCodeSpoiler-button-title ")]');
            $title = $t ? trim($t->textContent) : '';
        }

        $body = $this->childByClass($n, 'bbCodeSpoiler-content');
        $inner = trim($this->walk($body ?? $n));

        return "\n[spoiler".($title !== '' ? ' title="'.$this->attr($title).'"' : '')."]\n".$inner."\n[/spoiler]\n";
    }

    /**
     * XenForo quote. 262,430 blocks across 248,496 posts — the single most
     * common construct in the corpus after plain text.
     *
     *   <blockquote data-attributes="member: 17" data-quote="UBER"
     *               data-source="post: 27" class="… bbCodeBlock--quote">
     *
     * data-quote is the quoted member's display name and data-source the
     * quoted post's SOURCE id; local/looksmax-format resolves that id to a
     * jump link at render time, because the quoted post may not be imported
     * yet when this runs.
     */
    private function quote(\DOMElement $n): string
    {
        $this->count('quote');

        $author = trim($n->getAttribute('data-quote'));
        if ($author === '') {
            // pre-2019 markup has no data-quote; the title reads "Name said:"
            $title = $this->childByClass($n, 'bbCodeBlock-title');
            if ($title) {
                $author = $this->squash(preg_replace('/\s+said:\s*$/iu', '', $this->squash($title->textContent)) ?? '');
            }
        }

        $post = 0;
        if (preg_match('/post:\s*(\d+)/', $n->getAttribute('data-source'), $m)) {
            $post = (int) $m[1];
        }

        /*
         * data-attributes="member: 17" — the quoted member's SOURCE user id.
         *
         * The whole-corpus survey found this on 298,957 of 300,179 quotes
         * (99.6%), across 283,739 posts and 14,714 threads, and NOTHING read
         * it: the largest single gap in the census, and 67% of the strict
         * lossless failures (reports/CORPUS-SURVEY.md §8).
         *
         * It is worth carrying because it is the only reliable way to identify
         * the quoted person. `data-quote` is a DISPLAY NAME, which is not
         * unique, changes over time, and is sanitised on import — the same
         * reason the mention map is keyed on the source id rather than the
         * name. With the id, ResolveQuoteLinks can put the real avatar and a
         * working profile link on the quote header at render time, and it
         * degrades to the plain name when the member was never imported.
         */
        $member = 0;
        if (preg_match('/member:\s*(\d+)/', $n->getAttribute('data-attributes'), $m)) {
            $member = (int) $m[1];
        }

        $content = $this->childByClass($n, 'bbCodeBlock-content');
        $inner = trim($this->walk($content ?? $n));

        $attrs = '';
        if ($author !== '') {
            $attrs .= ' author="'.$this->attr($author).'"';
        }
        if ($post > 0) {
            $attrs .= ' post='.$post;
        }
        if ($member > 0) {
            $attrs .= ' member='.$member;
        }

        return "\n[quote".$attrs."]\n".$inner."\n[/quote]\n";
    }

    /**
     * XenForo url unfurl. 10,398 cards across 5,159 posts.
     *
     * This is the construct that was leaking to the live site as
     * `url=https://…` with no opening bracket and a stray favicon+domain
     * trailer: the old converter had no branch for it, so the card's internal
     * anchor, snippet and <img> were each converted separately and the result
     * was an unbalanced soup of inline tags. It is one card and converts to
     * one tag.
     */
    private function unfurl(\DOMElement $n): string
    {
        $url = $this->absolute($n->getAttribute('data-url'));
        if ($url === '') {
            $a = $this->find($n, './/a[@href]');
            $url = $a ? $this->absolute($a->getAttribute('href')) : '';
        }
        if ($url === '') {
            return '';
        }

        // [unfurl url={URL}] is mandatory-attribute; if s9e rejects it the card
        // prints as raw markup. Fall back to the plain link text.
        if (! $this->usableUrl($url)) {
            $this->count('unfurl_unusable');

            return $this->text($url);
        }

        $this->count('unfurl');

        $host = trim($n->getAttribute('data-host'));
        if ($host === '') {
            $host = (string) parse_url($url, PHP_URL_HOST);
        }

        $titleEl = $this->find($n, './/*[contains(concat(" ",normalize-space(@class)," ")," js-unfurl-title ")]');
        $title = $titleEl ? $this->squash($titleEl->textContent) : '';
        if ($title === '') {
            $title = $url;
        }

        $descEl = $this->find($n, './/*[contains(concat(" ",normalize-space(@class)," ")," js-unfurl-desc ")]');
        $desc = $descEl ? $this->squash($descEl->textContent) : '';

        $figEl = $this->find($n, './/*[contains(concat(" ",normalize-space(@class)," ")," js-unfurl-figure ")]//img');
        $image = $figEl ? $this->mediaUrl($figEl->getAttribute('src')) : '';

        $iconEl = $this->find($n, './/img[contains(concat(" ",normalize-space(@class)," ")," bbCodeBlockUnfurl-icon ")]');
        $icon = $iconEl ? $this->mediaUrl($iconEl->getAttribute('src')) : '';

        $out = "\n[unfurl url=\"".$this->attr($url).'"';
        if ($host !== '') {
            $out .= ' host="'.$this->attr($host).'"';
        }
        // XenForo frequently sets the unfurl snippet to the page title it
        // already put in the heading, so the card renders the same sentence
        // twice in two sizes and looks broken. Drop a description that only
        // repeats the title.
        $sameAsTitle = $desc !== ''
            && mb_strtolower(rtrim($desc, '.… ')) === mb_strtolower(rtrim($title, '.… '));

        if ($desc !== '' && ! $sameAsTitle) {
            // the snippet is one long line; 400 chars is well past the two
            // lines the card clamps to
            $out .= ' desc="'.$this->attr(mb_substr($desc, 0, 400)).'"';
        }
        if ($image !== '') {
            $out .= ' image="'.$this->attr($image).'"';
        }
        if ($icon !== '') {
            $out .= ' icon="'.$this->attr($icon).'"';
        }

        return $out.']'.$this->text($title)."[/unfurl]\n";
    }

    /** [CODE] block, with the language when XenForo recorded one (285 blocks). */
    private function codeBlock(\DOMElement $n): string
    {
        $this->count('code');

        $pre = strtolower($n->tagName) === 'pre' ? $n : $this->find($n, './/pre');
        $lang = $pre ? trim($pre->getAttribute('data-lang')) : '';
        if ($lang === '') {
            // "PHP:" / "Code:" title is the only other place the language lives
            $title = $this->childByClass($n, 'bbCodeBlock-title');
            $label = $title ? trim($title->textContent) : '';
            if ($label !== '' && ! preg_match('/^code:?$/i', $label)) {
                $lang = strtolower(rtrim($label, ':'));
            }
        }
        $lang = preg_replace('/[^a-z0-9_+-]/', '', strtolower($lang));

        $body = $pre ?? $n;

        return "\n[code".($lang !== '' ? '='.$lang : '')."]\n".trim($body->textContent)."\n[/code]\n";
    }

    /**
     * Inline image. 55,192 wrappers; the wrapper holds the full-size url and
     * the <img> may hold only a 1x1 placeholder when XenForo lazyloads it.
     */
    private function imageWrapper(\DOMElement $n): string
    {
        $img = $this->find($n, './/img');
        $src = '';
        if ($img) {
            foreach (['data-url', 'data-src', 'src'] as $a) {
                $v = $img->getAttribute($a);
                if ($v !== '' && ! str_starts_with($v, 'data:')) {
                    $src = $v;
                    break;
                }
            }
        }
        if ($src === '') {
            $src = $n->getAttribute('data-src');
        }
        if ($src === '' || str_starts_with($src, 'data:')) {
            return '';
        }

        $alt = $img ? ($img->getAttribute('alt') ?: $img->getAttribute('title')) : '';
        if ($alt === '') {
            $alt = $n->getAttribute('title');
        }

        return $this->imgTag(
            $src,
            $alt,
            $img ? $img->getAttribute('width') : '',
            $img ? $img->getAttribute('height') : '',
            $this->imageAlign($n) ?? ($img ? $this->imageAlign($img) : null)
        );
    }

    /** A bare <img>: smilie, or an image outside a wrapper. */
    private function img(\DOMElement $n): string
    {
        // 144,903 smilies. Unicode ones carry the actual character in @alt and
        // flarum/emoji renders it natively; sprite ones are drawn from a CSS
        // sprite sheet we do not have, so they become a labelled chip.
        if ($this->hasClass($n, 'smilie')) {
            $short = trim($n->getAttribute('data-shortname'));
            $alt = trim($n->getAttribute('alt'));

            if ($this->hasClass($n, 'smilie--emoji') || ($alt !== '' && ! str_starts_with($alt, ':'))) {
                $this->count('emoji');

                return $alt !== '' ? $alt : $short;
            }

            $this->count('emote');
            $label = trim(preg_replace('/\s*:[a-z0-9_+-]+:\s*$/i', '', $n->getAttribute('title')));
            $name = $short !== '' ? $short : $alt;
            if ($name === '') {
                return '';
            }

            return '[emote name="'.$this->attr($name).'"'
                .($label !== '' ? ' label="'.$this->attr($label).'"' : '').']';
        }

        $src = '';
        foreach (['data-url', 'data-src', 'src'] as $a) {
            $v = $n->getAttribute($a);
            if ($v !== '' && ! str_starts_with($v, 'data:')) {
                $src = $v;
                break;
            }
        }
        if ($src === '') {
            return '';
        }

        return $this->imgTag(
            $src,
            $n->getAttribute('alt') ?: $n->getAttribute('title'),
            $n->getAttribute('width'),
            $n->getAttribute('height'),
            $this->imageAlign($n)
        );
    }

    private function imgTag(string $src, string $alt, string $w, string $h, ?string $align): string
    {
        $src = $this->mediaUrl($src);
        if ($src === '') {
            return '';
        }

        // Same rule as anchors: a src s9e will reject makes the entire [img]
        // print as literal markup. A placeholder like
        // `http://YOUR_IMGUR_LINK_ANDROGENIC.jpg` (underscores are illegal in a
        // hostname) was already a broken image on the source board; dropping it
        // is what the reader expects, raw BBCode is not.
        // mediaUrl() may return a site-relative path for a locally stored copy,
        // which is valid and must not go through the absolute-URL check.
        if (! str_starts_with($src, '/') && ! $this->usableUrl($src)) {
            $this->count('image_unusable');

            return '';
        }

        $this->count('image');

        $out = '[img';
        if (trim($alt) !== '') {
            $out .= ' alt="'.$this->attr(trim($alt)).'"';
        }
        // XenForo writes width="" height="" when it does not know them
        if (ctype_digit($w) && (int) $w > 0) {
            $out .= ' width='.(int) $w;
        }
        if (ctype_digit($h) && (int) $h > 0) {
            $out .= ' height='.(int) $h;
        }
        if ($align !== null) {
            $out .= ' align='.$align;
        }

        return $out.']'.$src.'[/img]';
    }

    /**
     * bbMediaWrapper: either a site embed (iframe with data-media-site-id) or
     * an uploaded <video>/<audio> with a <source>.
     */
    private function mediaWrapper(\DOMElement $n): string
    {
        $site = trim($n->getAttribute('data-media-site-id'));
        $iframe = $this->find($n, './/iframe');
        if ($iframe) {
            $src = $this->absolute($iframe->getAttribute('src'));
            if ($src === '') {
                return '';
            }
            $height = null;
            if (preg_match('/(\d+)/', $iframe->getAttribute('height'), $m)) {
                $height = (int) $m[1];
            }

            return $this->mediaTag($site !== '' ? $site : $this->siteOf($src), $src, null, $height);
        }

        $media = $this->find($n, './/video') ?? $this->find($n, './/audio');
        if ($media) {
            return $this->videoTag($media, strtolower($media->tagName));
        }

        return $this->walk($n);
    }

    private function videoTag(\DOMElement $n, string $kind): string
    {
        $src = $n->getAttribute('src');
        if ($src === '') {
            $source = $this->find($n, './/source');
            $src = $source ? $source->getAttribute('src') : '';
        }
        $src = $this->mediaUrl($src);
        if ($src === '') {
            return '';
        }

        $this->count($kind);
        $poster = $kind === 'video' ? $this->mediaUrl($n->getAttribute('poster')) : '';

        return "\n[".$kind.($poster !== '' ? ' poster="'.$this->attr($poster).'"' : '')
            .']'.$src.'[/'.$kind."]\n";
    }

    /**
     * The embeds XenForo itself rendered through s9e (12,164 of them). The
     * iframe attributes are a JSON array of alternating name/value pairs, and
     * the poster image is a css background on the placeholder span.
     */
    private function s9eEmbed(\DOMElement $n): string
    {
        $site = trim($n->getAttribute('data-s9e-mediaembed'));
        $holder = $this->find($n, './/*[@data-s9e-mediaembed-iframe]');
        $url = '';
        $thumb = '';

        if ($holder) {
            $pairs = json_decode($holder->getAttribute('data-s9e-mediaembed-iframe'), true);
            if (is_array($pairs)) {
                for ($i = 0; $i + 1 < count($pairs); $i += 2) {
                    if ($pairs[$i] === 'src') {
                        $url = (string) $pairs[$i + 1];
                        break;
                    }
                }
            }
            if (preg_match('#url\((https?://[^)\s]+)\)#', $holder->getAttribute('style'), $m)) {
                $thumb = $m[1];
            }
        }

        if ($url === '') {
            return $this->walk($n);
        }

        return $this->mediaTag($site !== '' ? $site : $this->siteOf($url), $this->absolute($url), $thumb, null);
    }

    /**
     * `[embed]`, standalone — no closing tag, and not called `[media]`.
     *
     * MEDIA is Flarum core's (s9e MediaEmbed). And the tag has to be standalone
     * because its template renders attributes only, which makes s9e infer
     * `autoClose` and orphan any closer we write. Both were measured on the
     * live formatter, see Local\Format\Configure::media().
     */
    private function mediaTag(string $site, string $url, ?string $thumb, ?int $height): string
    {
        if ($url === '') {
            return '';
        }
        $this->count('media');

        $site = preg_replace('/[^a-z0-9_]/', '', strtolower($site)) ?: 'link';
        $out = "\n[embed site=".$site.' url="'.$this->attr($url).'"';
        if ($thumb) {
            $out .= ' thumb="'.$this->attr($this->mediaUrl($thumb)).'"';
        }
        if ($height) {
            $out .= ' height='.$height;
        }

        return $out."]\n";
    }

    /** Nested lists: BBCode, because markdown indentation does not survive. */
    private function list(\DOMElement $n, bool $ordered): string
    {
        $items = '';
        foreach ($n->childNodes as $li) {
            if ($li->nodeType !== XML_ELEMENT_NODE || strtolower($li->nodeName) !== 'li') {
                continue;
            }
            $inner = trim($this->walk($li));
            if ($inner !== '') {
                $items .= '[*]'.$inner."\n";
            }
        }
        if ($items === '') {
            return '';
        }
        $this->count('list');

        return "\n[list".($ordered ? '=1' : '')."]\n".$items."[/list]\n";
    }

    /** 3,893 tables. Real table tags: cells hold images and links. */
    private function table(\DOMElement $t): string
    {
        $rows = '';
        foreach ($t->getElementsByTagName('tr') as $tr) {
            $cells = '';
            foreach ($tr->childNodes as $c) {
                if ($c->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                $name = strtolower($c->nodeName);
                if ($name !== 'td' && $name !== 'th') {
                    continue;
                }
                /** @var \DOMElement $c */
                $attrs = '';
                foreach (['colspan', 'rowspan'] as $a) {
                    $v = $c->getAttribute($a);
                    if (ctype_digit($v) && (int) $v > 1) {
                        $attrs .= ' '.$a.'='.(int) $v;
                    }
                }
                $cells .= '['.$name.$attrs.']'.trim($this->walk($c)).'[/'.$name.']';
            }
            if ($cells !== '') {
                $rows .= '[tr]'.$cells."[/tr]\n";
            }
        }
        if ($rows === '') {
            return '';
        }
        $this->count('table');

        return "\n[table]\n".$rows."[/table]\n";
    }

    // -------------------------------------------------------------- inline

    /** <span style> carries [COLOR], [SIZE] and [FONT]: 501,842 occurrences. */
    private function styledInline(\DOMElement $n, string $inner): string
    {
        if (trim($inner) === '') {
            return '';
        }

        $style = $n->getAttribute('style');
        $out = $inner;

        if ($style !== '') {
            if (preg_match('/font-family:\s*([^;]+)/i', $style, $m)) {
                $family = trim(str_replace(['"', "'"], '', $m[1]));
                if ($family !== '' && strcasecmp($family, 'null') !== 0) {
                    $this->count('font');
                    $out = '[font='.$family.']'.$out.'[/font]';
                }
            }
            if (preg_match('/font-size:\s*(\d+)\s*px/i', $style, $m)) {
                $px = max(8, min(72, (int) $m[1]));
                $this->count('size');
                $out = '[size='.$px.']'.$out.'[/size]';
            }
            if (preg_match('/(?<!-)\bcolor:\s*([^;]+)/i', $style, $m)) {
                $colour = $this->colour(trim($m[1]));
                if ($colour !== null) {
                    $this->count('color');
                    $out = '[color='.$colour.']'.$out.'[/color]';
                }
            }
            if (preg_match('/background(?:-color)?:\s*(#[0-9a-f]{3,8}|rgba?\([^)]+\))/i', $style, $m)) {
                $colour = $this->colour(trim($m[1]));
                if ($colour !== null) {
                    $this->count('background');
                    $out = '[background='.$colour.']'.$out.'[/background]';
                }
            }
        }

        if ($this->hasClass($n, 'uw_large_emoji')) {
            $out = '[size=32]'.$out.'[/size]';
        }

        return $out;
    }

    private function wrapInline(string $tag, string $inner): string
    {
        // Leading/trailing whitespace has to stay OUTSIDE the tag or "a [b] b[/b]"
        // renders with the space swallowed and the words run together.
        if (trim($inner) === '') {
            return $inner;
        }
        preg_match('/^(\s*)(.*?)(\s*)$/su', $inner, $m);

        return $m[1].'['.$tag.']'.$m[2].'[/'.$tag.']'.$m[3];
    }

    private function anchor(\DOMElement $a): string
    {
        $href = $a->getAttribute('href');

        // 157 usergroup mentions: <a class="ug" data-usergroup-id="4" data-groupname="@Mods">
        if ($a->hasAttribute('data-usergroup-id')) {
            $name = trim($a->getAttribute('data-groupname')) ?: trim($a->textContent);
            if ($name !== '') {
                $this->count('gmention');

                return '[gmention name="'.$this->attr($name).'" gid='.(int) $a->getAttribute('data-usergroup-id').']';
            }
        }

        // a link to a member profile is a mention, not a link
        if (preg_match('#/members/[^/]*\.(\d+)#', $href, $m)) {
            return $this->mention(ltrim(trim($a->textContent), '@'), (int) $m[1]);
        }

        $text = trim($this->walk($a));
        if ($href === '' || $text === '') {
            return $text;
        }

        $href = $this->mediaUrl($href) ?: $this->absolute($href);
        if ($href === '') {
            return $text;
        }

        $this->count('link');

        // A bare url reads better through the autolinker, and an [url] whose
        // label is the url is exactly what produced the malformed
        // `url=https://… title[/url]` on the live site. Compare on the
        // UNESCAPED label: 384,491 hrefs contain `_` or `~`, which text()
        // escapes, so an escaped comparison never matches.
        $plain = preg_replace('/\\\\([*_`~\[])/', '$1', $text);
        if ($plain === $href || $plain === rtrim($href, '/') || $plain.'/' === $href) {
            return $href;
        }

        // An href s9e's {URL} filter will reject takes the whole [url] tag down
        // with it and prints the markup at the reader. Keep the label instead:
        // the link was already dead on the source board.
        if (! $this->usableUrl($href)) {
            $this->count('link_unusable');

            return $text;
        }

        // An image-only link: [url=…][img]…[/img][/url] is valid and keeps the
        // click target, which stripping the anchor would lose.
        return '[url="'.$this->attr($href).'"]'.$text.'[/url]';
    }

    /**
     * Flarum's mention syntax embeds the LOCAL user id, and flarum/mentions
     * invalidates any mention whose id has no row — which is why an unmatched
     * mention used to fall back to bare "@name" and render as plain text.
     *
     * So: resolve through imported_id where we can, and emit a [umention] tag
     * (which nothing can invalidate) where we cannot.
     */
    private function mention(string $name, int $sourceId): string
    {
        $name = trim($name);
        $local = $this->userMap[$sourceId] ?? null;

        if ($local) {
            $this->count('mention');
            // The display name has to be the LOCAL one: flarum/mentions
            // rewrites it at render time anyway, and a stale name in the stored
            // text is what shows in the editor when someone quotes the post.
            $display = str_replace(['"', '#', '[', ']'], '', (string) $local['name']);

            return '@"'.$display.'"#'.(int) $local['id'];
        }

        if ($name === '') {
            return '';
        }

        $this->count('mention_unimported');

        return '[umention name="'.$this->attr($name).'" uid='.max(0, $sourceId).']';
    }

    /** Cloudflare email obfuscation, 78 occurrences. Decodable, so decode it. */
    private function cfEmail(\DOMElement $n): string
    {
        $hex = $n->getAttribute('data-cfemail');
        if (! preg_match('/^[0-9a-f]+$/i', $hex) || strlen($hex) < 4) {
            return '';
        }
        $key = hexdec(substr($hex, 0, 2));
        $out = '';
        for ($i = 2; $i < strlen($hex); $i += 2) {
            $out .= chr(hexdec(substr($hex, $i, 2)) ^ $key);
        }
        $this->count('cfemail');

        return $this->text($out);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Point an image at the local copy when we downloaded one, and unwrap
     * XenForo's image proxy so the manifest lookup sees the real url.
     *
     * 43,324 distinct image urls were scraped and 33,946 downloaded; the rest
     * keep their remote url rather than becoming a broken image.
     */
    private function mediaUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:')) {
            return '';
        }

        // /proxy.php?image=<urlencoded>&hash=… — 12,783 links and every
        // lazyloaded image on the board
        if (str_contains($url, 'proxy.php?image=')) {
            $qs = parse_url($url, PHP_URL_QUERY) ?? '';
            parse_str($qs, $q);
            if (! empty($q['image'])) {
                $url = (string) $q['image'];
            }
        }

        $url = $this->absolute($url);

        if (isset($this->mediaMap[$url])) {
            $this->count('image_local');

            return $this->mediaMap[$url];
        }

        // the scrape recorded some urls without their query string
        $bare = strtok($url, '?');
        if ($bare !== false && $bare !== $url && isset($this->mediaMap[$bare])) {
            $this->count('image_local');

            return $this->mediaMap[$bare];
        }

        return $url;
    }

    /**
     * Will s9e's {URL} filter accept this? If not, do not emit a URL-typed tag.
     *
     * -------------------------------------------------------------------
     * This is a leak class, not a cosmetic concern.
     * -------------------------------------------------------------------
     * When an attribute declared `{URL}` fails validation, s9e does not drop
     * the attribute or render the tag without it — it rejects the whole tag and
     * leaves the ORIGINAL MARKUP as literal text. So one unusable href turns
     *
     *     [url="http://physical height in pedophilic…"]Source[/url]
     *
     * into exactly that string, brackets and all, in the middle of a sentence.
     * Measured on the live forum: 26 posts leaking `[url`, 20 leaking `[img`,
     * every one of them a source link that was already broken on looksmax.org
     * (placeholder hosts like `YOUR_IMGUR_LINK_ANDROGENIC.jpg`, hrefs that are
     * a urlencoded sentence rather than an address).
     *
     * The source board rendered these as dead links. Showing raw BBCode is
     * strictly worse than showing the label, so an unusable URL degrades to
     * plain text here rather than being handed to a filter that will reject it.
     *
     * The rules mirror s9e's UrlFilter: an allowed scheme, a host that is a
     * real domain or IP literal, and no whitespace or control characters.
     * Underscores are invalid in a hostname and s9e enforces that, which is
     * what rejected the imgur placeholder.
     */
    private function usableUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];

        // IPv6 literal, e.g. [::1]
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Domain: dot-separated labels of alphanumerics and hyphens, no
        // leading/trailing hyphen, and no underscore.
        return (bool) preg_match(
            '/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*\.?$/i',
            $host
        );
    }

    private function absolute(string $href): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return '';
        }
        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }
        if (str_starts_with($href, '/')) {
            return 'https://looksmax.org'.$href;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return 'https://looksmax.org/'.ltrim($href, '/');
        }

        return $href;
    }

    private function colour(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || strcasecmp($raw, 'null') === 0 || strcasecmp($raw, 'inherit') === 0) {
            return null;
        }
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $raw)) {
            return $raw;
        }
        if (preg_match('/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i', $raw, $m)) {
            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }
        if (preg_match('/^[a-z]{3,20}$/i', $raw)) {
            return strtolower($raw);
        }

        return null;
    }

    private function alignOf(\DOMElement $n): ?string
    {
        if (preg_match('/text-align:\s*(left|right|center|justify)/i', $n->getAttribute('style'), $m)) {
            return strtolower($m[1]);
        }
        if ($this->hasClass($n, 'centered')) {
            return 'center';
        }
        if ($this->hasClass($n, 'right')) {
            return 'right';
        }

        return null;
    }

    private function imageAlign(\DOMElement $n): ?string
    {
        if ($this->hasClass($n, 'bbImageAligned--left')) {
            return 'left';
        }
        if ($this->hasClass($n, 'bbImageAligned--right')) {
            return 'right';
        }

        return null;
    }

    /**
     * Escape text that would otherwise be re-parsed as markup.
     *
     * Two parsers see this text downstream: s9e's BBCodes plugin and Litedown
     * (flarum/markdown). A post whose prose contains "[b]" or "*" is content,
     * not markup — XenForo already turned every real tag into html, so
     * anything still bracketed in a text node was literal on the source board
     * and has to stay literal here.
     *
     * -------------------------------------------------------------------
     * Every preg_* result here is checked, and that is not defensive padding.
     * -------------------------------------------------------------------
     * The `/u` modifier makes PCRE return NULL — not a partial result, not a
     * warning — for a subject that is not well-formed UTF-8. This method is
     * declared `: string`, so that NULL was a fatal TypeError that aborted the
     * entire run.
     *
     * It fired on a Russian post ("где ты н…"), on the FIRST full pass over
     * real rows, after 771 fixture assertions had gone green. The fixtures are
     * all valid UTF-8; a 383k-post corpus in six languages is not. The scrape
     * carries truncated multi-byte sequences from cut-off HTTP bodies, and
     * mb_convert_encoding($s,'UTF-8','UTF-8') at the document level does not
     * catch every one that reaches an individual text node.
     *
     * So: scrub here as well, at the only place the /u pattern is applied, and
     * treat a NULL from either pass as "leave this fragment unescaped" rather
     * than as a reason to lose the post.
     */
    private function text(string $s): string
    {
        if ($s === '') {
            return '';
        }

        // A malformed sequence at this level would make the /u pattern below
        // return null. Substituting rather than dropping keeps byte offsets
        // meaningful for anything reading the output alongside the source.
        if (! mb_check_encoding($s, 'UTF-8')) {
            $this->count('bad_utf8');
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
            if (! mb_check_encoding($s, 'UTF-8')) {
                // Last resort: strip anything still invalid byte by byte.
                $s = mb_scrub($s, 'UTF-8');
            }
        }

        // Only sequences that could actually open or close a registered tag.
        // A callback, not a replacement string: PHP's replacement syntax gives
        // "\\[" no defined meaning and it does not reliably survive.
        // This pattern has no /u, so it cannot fail on encoding — but it can
        // still return null on a backtrack limit, so it is checked too.
        $escaped = preg_replace_callback(
            '/\[(\/?)(b|i|u|s|del|ins|sup|sub|url|img|email|code|c|quote|spoiler|unfurl|list|\*|table|tr|td|th|thead|tbody|color|size|font|center|left|right|justify|align|background|hr|embed|media|video|audio|umention|gmention|emote|noparse)\b/i',
            fn (array $m) => '\\'.$m[0],
            $s
        );
        if ($escaped === null) {
            $this->count('escape_failed');
        } else {
            $s = $escaped;
        }

        // Litedown specials. Escaping is removed again on render, so this is
        // invisible unless flarum/markdown is disabled.
        $escaped = preg_replace_callback('/[*_`~]/u', fn (array $m) => '\\'.$m[0], $s);
        if ($escaped === null) {
            $this->count('escape_failed');

            return $s;
        }

        return $escaped;
    }

    /**
     * Collapse all whitespace to single spaces, UTF-8 safely.
     *
     * Every `/u` pattern in this class goes through here or is guarded the same
     * way, because PCRE returns NULL — not a partial result — for a subject
     * that is not well-formed UTF-8, and the corpus carries truncated
     * multi-byte sequences from cut-off HTTP bodies. An unguarded
     * `trim(preg_replace('/\s+/u', …))` on such a string is a TypeError in
     * PHP 8 that aborts the whole import; before PHP 8 it silently produced an
     * empty quote author or unfurl title, which is arguably worse.
     */
    private function squash(string $s): string
    {
        if (! mb_check_encoding($s, 'UTF-8')) {
            $this->count('bad_utf8');
            $s = mb_scrub($s, 'UTF-8');
        }

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** Escape a value going into a quoted BBCode attribute. */
    private function attr(string $s): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $this->squash($s));
    }

    private function count(string $k): void
    {
        $this->stats[$k] = ($this->stats[$k] ?? 0) + 1;
    }

    /** Token-exact class test. `str_contains` here was a live-site bug. */
    private function hasClass(\DOMElement $n, string $needle): bool
    {
        $class = $n->getAttribute('class');
        if ($class === '') {
            return false;
        }

        return in_array($needle, preg_split('/\s+/', trim($class)) ?: [], true);
    }

    private function child(\DOMElement $n, string $tag): ?\DOMElement
    {
        foreach ($n->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && strtolower($c->nodeName) === $tag) {
                return $c;
            }
        }

        return null;
    }

    /** Direct child by class token — never `.//`, which reaches into nested blocks. */
    private function childByClass(\DOMElement $n, string $class): ?\DOMElement
    {
        foreach ($n->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && $this->hasClass($c, $class)) {
                return $c;
            }
        }

        return null;
    }

    private function find(\DOMNode $ctx, string $xpath): ?\DOMElement
    {
        $x = new \DOMXPath($ctx->ownerDocument);
        $r = $x->query($xpath, $ctx);

        return $r && $r->length ? $r->item(0) : null;
    }

    /**
     * Final pass: collapse the whitespace conversion introduces, and make
     * absolutely sure no block tag escapes unbalanced.
     */
    private function tidy(string $out): string
    {
        $out = preg_replace("/[ \t]+\n/", "\n", $out);
        $out = preg_replace("/\n{3,}/", "\n\n", $out);
        $out = str_replace("\u{200B}", '', $out);

        // Line-leading markdown. text() cannot see line context because it
        // works on fragments, so the block-level Litedown markers are escaped
        // here. Lines that begin with our own markup are left alone.
        $out = preg_replace('/^(?![\[\\\\])([#>+-])(?= )/m', '\\\\$1', $out);

        return trim($this->balance($out));
    }

    /**
     * Close anything left open and drop closers with no opener.
     *
     * The walker is structural so it should always emit balanced output, but
     * "should" is what produced `[/spoiler][/spoiler][/spoiler]` on the live
     * site. This is cheap and makes the leak impossible by construction rather
     * than by argument.
     */
    // `embed` is deliberately absent: it is a standalone tag with no closer.
    private const BALANCED = [
        'spoiler', 'quote', 'code', 'list', 'table', 'tr', 'td', 'th', 'unfurl',
        'url', 'b', 'i', 'u', 's',
    ];

    private function balance(string $s): string
    {
        $pattern = '/(?<!\\\\)\[(\/?)('.implode('|', self::BALANCED).')(?=[\]\s=])[^\]]*\]/i';
        if (! preg_match_all($pattern, $s, $m, PREG_OFFSET_CAPTURE)) {
            return $s;
        }

        $stack = [];
        $drop = [];
        foreach ($m[0] as $i => $hit) {
            $closing = $m[1][$i][0] === '/';
            $name = strtolower($m[2][$i][0]);

            if (! $closing) {
                $stack[] = [$name, $i];
                continue;
            }

            // find the nearest matching opener
            $at = null;
            for ($j = count($stack) - 1; $j >= 0; $j--) {
                if ($stack[$j][0] === $name) {
                    $at = $j;
                    break;
                }
            }
            if ($at === null) {
                $drop[$i] = true;          // closer with no opener
                continue;
            }
            // anything opened after it was never closed: close it here
            $stack = array_slice($stack, 0, $at);
        }

        $append = '';
        foreach (array_reverse($stack) as [$name, $i]) {
            $append .= '[/'.$name.']';
        }

        if ($drop) {
            // rebuild right-to-left so the offsets stay valid
            foreach (array_reverse(array_keys($drop)) as $i) {
                $off = $m[0][$i][1];
                $len = strlen($m[0][$i][0]);
                $s = substr($s, 0, $off).substr($s, $off + $len);
            }
        }

        return $s.$append;
    }
}
