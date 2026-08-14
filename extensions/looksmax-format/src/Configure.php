<?php

namespace Local\Format;

use s9e\TextFormatter\Configurator;

/**
 * The BBCode vocabulary the imported corpus actually needs.
 *
 * flarum/bbcode registers 15 tags (B I U S URL IMG EMAIL CODE QUOTE LIST DEL
 * COLOR CENTER SIZE *). Everything else that XenForo emits — measured blind
 * across 370,667 scraped posts — has no tag to land in, and s9e leaves an
 * unrecognised `[tag]` as literal text. That is the entire "renders as raw
 * BBCode" class of bug:
 *
 *   spoilers            23,376 blocks in   3,934 posts
 *   unfurl link cards   10,398 blocks in   5,159 posts
 *   media embeds        12,164 in 7,878 posts
 *   tables               3,893 in   899 posts
 *   inline code            221, code blocks 285 (with data-lang)
 *   usergroup mentions     157
 *   video/audio          1,930 <source> + 37 <audio>
 *   font/align/background  — 501,842 styled <span>, 65,078 styled <div>
 *
 * Two further failure modes are fixed here rather than in the converter:
 *
 *  1. s9e's default nestingLimit is 10. The corpus contains spoiler and quote
 *     stacks deeper than that, and everything past the limit is emitted as
 *     literal text — proven by probe, see tests/ConverterTest 'deep-nest'.
 *  2. tagLimit defaults to 5000 per tag name; long image dumps exceed it.
 */
class Configure
{
    /** Every tag name this extension is responsible for, for the leak detector. */
    public const OWNED = [
        'SPOILER', 'UNFURL', 'QUOTE', 'IMG', 'EMBED', 'VIDEO', 'AUDIO',
        'UMENTION', 'GMENTION', 'EMOTE', 'ATTACH',
        'C', 'FONT', 'RIGHT', 'LEFT', 'JUSTIFY', 'ALIGN', 'BACKGROUND', 'HR',
        'SUP', 'SUB', 'INS', 'TABLE', 'TR', 'TD', 'TH', 'THEAD', 'TBODY', 'NOPARSE',
        // looksmax.org's own addons — see community()
        'ISPOILER', 'LOLQUOTE', 'USER', 'DELETED', 'HEADING', 'HIDEPOSTS', 'UWSL',
        'SERIOUS', 'GUIDE', 'THEORY', 'DISCUSSION', 'METHOD', 'MOTIVATION',
        'RAGE', 'NSFW', 'FAKE', 'OP', 'GTFIH', 'HIQM', 'MEME',
    ];

    public function __invoke(Configurator $config): void
    {
        $this->fromRepository($config);
        $this->spoiler($config);
        $this->quote($config);
        $this->unfurl($config);
        $this->image($config);
        $this->media($config);
        $this->mentions($config);
        $this->emote($config);
        $this->tables($config);
        $this->community($config);
        $this->code($config);
        $this->limits($config);
    }

    /**
     * Replace the CODE template, because the stock one loads a third-party
     * script on every code block.
     *
     * -------------------------------------------------------------------
     * What it was doing
     * -------------------------------------------------------------------
     * s9e's own BBCode repository renders `[code]` with a
     *
     *     <script src="https://cdn.jsdelivr.net/gh/s9e/hljs-loader@1.0.34/…">
     *
     * embedded in the block, which then pulls highlight.js from
     * `cdn.jsdelivr.net/gh/highlightjs/…`. Measured over five real thread pages
     * with CDP: 10 requests to jsdelivr.
     *
     * That is the same exposure as a hotlinked image and strictly worse in
     * kind: it is EXECUTABLE code, fetched from a host we do not control, and
     * it runs with this origin's privileges. It also tells a third party which
     * of our pages are being read, which is precisely what the media proxy work
     * exists to stop — fixing the images while leaving this in place would have
     * been a half-measure that measured as a success.
     *
     * The TEMPLATE is replaced rather than the tag redefined: the tag's parsing
     * rules are what make a code block hold BBCode verbatim (asserted by
     * tests/render.php 'pathological-code-holds-bbcode'), and redefining it
     * would put that at risk for a purely visual change.
     *
     * The language is preserved as `data-lang` so the label and the eventual
     * locally-hosted highlighter have it. Highlighting itself is deliberately
     * dropped for now rather than shipped insecurely — see HANDOFF-FORMAT.md.
     */
    private function code(Configurator $config): void
    {
        if (! isset($config->tags['CODE'])) {
            return;
        }

        $config->tags['CODE']->template =
            '<pre class="lmxCodeBlock">
                <xsl:if test="@lang">
                    <xsl:attribute name="data-lang"><xsl:value-of select="@lang"/></xsl:attribute>
                </xsl:if>
                <code><xsl:apply-templates/></code>
            </pre>';

        /*
         * Emoji: render the actual character, not a hotlinked twemoji image.
         *
         * s9e's EMOJI template emits
         *   <img src="//cdn.jsdelivr.net/gh/twitter/twemoji@14/assets/72x72/{seq}.png">
         * so EVERY emoji in a post is a separate request to jsdelivr. The
         * corpus has 52,180 unicode smilies across 18,845 posts, and a single
         * rant thread can carry thirty in one post — measured over five real
         * threads with CDP: 12 jsdelivr requests, all twemoji.
         *
         * Same exposure as any other hotlink: a third party learns which of our
         * pages are being read, and can break every emoji on the forum. Routing
         * them through our own media proxy would fix the exposure but would
         * mean thousands of proxied 72x72 PNGs and a cache entry each, to
         * reproduce glyphs that every current OS already ships.
         *
         * So: render the character. The tag's own content IS the original
         * emoji text, so <xsl:apply-templates/> restores it exactly. Zero
         * requests, no cache, correct text on copy/paste and for screen
         * readers — which the <img> was not.
         *
         * The trade is cross-platform consistency of the artwork. If that
         * matters later, the answer is a self-hosted emoji sheet, not a CDN.
         */
        if (isset($config->tags['EMOJI'])) {
            $config->tags['EMOJI']->template = '<span class="lmxEmoji"><xsl:apply-templates/></span>';
        }
    }

    /**
     * looksmax.org's OWN custom BBCode addons.
     *
     * -------------------------------------------------------------------
     * These are not standard XenForo and they are not in any Flarum extension.
     * -------------------------------------------------------------------
     * They are semantic post labels this community invented and uses, and they
     * survive in the scraped html as LITERAL text — XenForo rendered them on
     * the source board through an addon we do not have, so the scrape captured
     * the raw `[serious]` / `[ispoiler]` / `[lolquote]` rather than markup.
     *
     * Measured on the live forum after the main conversion fixes, these were
     * the largest remaining class of visible raw BBCode:
     *
     *   [ispoiler]    35 posts   inline spoiler — blur in place, reveal on click
     *   [serious]      7          "this is a serious thread, no jokes"
     *   [user=id]      7          a mention written in bbcode rather than markup
     *   [deleted]      3          content removed by staff
     *   [guide]        3          thread label
     *   [fake]         3          thread label: claim is fabricated
     *   [lolquote]     2          a quote posted to mock it
     *   [method]       2 [rage] 2 [nsfw] 1 [motivation] 1 [op] 1 [discussion] 1
     *
     * Frequency is low and concentration is high: they appear in exactly the
     * long, high-effort guide threads, which are the posts most worth reading.
     * Stripping them would lose the author's own framing, so they are rendered
     * as real chips and, for ispoiler, as real behaviour.
     */
    private function community(Configurator $config): void
    {
        /*
         * Inline spoiler. Unlike [spoiler] this is mid-sentence, so it must not
         * be a block element — it blurs in place and reveals on click.
         * `tabindex` and `role` because a click-to-reveal that only works with
         * a mouse hides content from keyboard users permanently.
         */
        $config->BBCodes->addCustom(
            '[ISPOILER]{TEXT1}[/ISPOILER]',
            '<span class="lmxISpoiler" tabindex="0" role="button"
                   aria-label="Hidden text, activate to reveal"
                   data-lmx-ispoiler="1"><xsl:apply-templates/></span>'
        );

        /*
         * Thread/post labels. One tag definition each rather than a single
         * generic [label=x], because the source posts spell them individually
         * and a generic tag would not match what is actually in the corpus.
         *
         * They carry no content on the source board — `[Serious]` is written
         * inline as a marker — so each is standalone, which also avoids the
         * autoClose trap that bit [embed]: a template with no
         * <xsl:apply-templates/> makes s9e infer autoClose, and a paired
         * definition would then orphan its closer.
         */
        $labels = [
            'SERIOUS' => ['Serious', 'serious'],
            'GUIDE' => ['Guide', 'guide'],
            'THEORY' => ['Theory', 'theory'],
            'DISCUSSION' => ['Discussion', 'discussion'],
            'METHOD' => ['Method', 'method'],
            'MOTIVATION' => ['Motivation', 'motivation'],
            'RAGE' => ['Rage', 'rage'],
            'NSFW' => ['NSFW', 'nsfw'],
            'FAKE' => ['Fake', 'fake'],
            'OP' => ['OP', 'op'],
            // From the whole-corpus survey (reports/CORPUS-SURVEY.md §4.2),
            // which found these alongside the ones above:
            'GTFIH' => ['GTFIH', 'gtfih'],   // "get the fuck in here" — a summons
            'HIQM' => ['High IQ', 'hiqm'],   // high-quality-post marker
            'MEME' => ['Meme', 'meme'],
        ];

        foreach ($labels as $tag => [$text, $modifier]) {
            $config->BBCodes->addCustom(
                '['.$tag.']',
                '<span class="lmxLabel lmxLabel--'.$modifier.'">'.$text.'</span>'
            );
        }

        /*
         * [heading]…[/heading] — 22 occurrences. A real heading element rather
         * than a [size][b] pair, so the guide table of contents can find it and
         * screen readers get the structure.
         */
        $config->BBCodes->addCustom(
            '[HEADING]{TEXT1}[/HEADING]',
            '<h3 class="lmxHeading"><xsl:apply-templates/></h3>'
        );

        /*
         * [hideposts]…[/hideposts] — content the source board hid until the
         * reader replied. That gate does not exist here and inventing one would
         * be worse than showing it, so the content is shown with an explicit
         * note about what it used to be. Silently unhiding it without saying so
         * would misrepresent the author's intent.
         */
        $config->BBCodes->addCustom(
            '[HIDEPOSTS]{TEXT1}[/HIDEPOSTS]',
            '<div class="lmxHidden">
                <div class="lmxHidden-note">Was reply-gated on the source board</div>
                <div class="lmxHidden-body"><xsl:apply-templates/></div>
            </div>'
        );

        /*
         * [uwsl] — the source board\'s "unwrapped single line" wrapper, used to
         * stop a line being reflowed. Transparent: keep the text, drop the tag.
         */
        $config->BBCodes->addCustom('[UWSL]{TEXT1}[/UWSL]', '<span class="lmxUwsl"><xsl:apply-templates/></span>');

        /*
         * Content a moderator removed. Rendered as an explicit placeholder
         * rather than dropped: "this was deleted" is information, and silently
         * omitting it makes the surrounding replies read as non-sequiturs.
         */
        $config->BBCodes->addCustom(
            '[DELETED]{TEXT1?}[/DELETED]',
            '<div class="lmxDeleted">
                <span class="lmxDeleted-mark" aria-hidden="true"/>
                <span class="lmxDeleted-text">Content removed</span>
            </div>'
        );

        /*
         * A quote posted in order to mock it. Same structure as [quote] so it
         * inherits the collapse behaviour, with its own styling and an explicit
         * label — otherwise a reader cannot tell it from ordinary agreement.
         *
         * The corpus spells it `[LolQUOTE="Name, post: 123, member: 45"]`, one
         * packed string rather than separate attributes, so the whole thing is
         * taken as TEXT1 and the pieces are pulled apart in the template.
         */
        $config->BBCodes->addCustom(
            '[LOLQUOTE author={TEXT1?}]{TEXT2}[/LOLQUOTE]',
            '<blockquote class="lmxQuote lmxQuote--lol">
                <div class="lmxQuote-head">
                    <span class="lmxQuote-lolMark" aria-hidden="true"/>
                    <xsl:if test="@author">
                        <span class="lmxQuote-author"><xsl:value-of select="substring-before(concat(@author, \',\'), \',\')"/></span>
                    </xsl:if>
                    <span class="lmxQuote-said"> — posted to mock</span>
                </div>
                <div class="lmxQuote-body"><xsl:apply-templates/></div>
            </blockquote>'
        );

        /*
         * `[user=1927]Name[/user]` — a mention written in BBCode instead of as
         * markup. It carries the SOURCE user id, which is meaningless locally,
         * so it renders through the same unimported-mention affordance as
         * [umention] rather than pretending to be a working link.
         */
        $config->BBCodes->addCustom(
            '[USER uid={UINT?}]{TEXT1}[/USER]',
            '<span class="UserMention UserMention--unimported"
                   title="Member of the source board, not imported here">
                <xsl:text>@</xsl:text><xsl:apply-templates/>
            </span>'
        );
    }

    /**
     * Tags s9e already ships a sane definition for. Adding them is enough;
     * their appearance is handled by less/forum.less.
     *
     * The guard here has to be on BBCodes, NOT on tags, and that distinction
     * was a real bug: flarum/markdown's Litedown plugin creates the tags C, HR,
     * SUP and SUB for its own markdown syntax (`` `x` ``, `---`, …) without
     * registering any BBCode for them. Guarding on `isset($config->tags[$name])`
     * therefore skipped exactly those four, and `[c]`, `[hr]`, `[sup]`, `[sub]`
     * rendered as literal text — caught by tests/render.php 'vocab-*', which
     * went red the moment the extension load order was corrected and Litedown
     * started running first.
     *
     * When the tag exists but the BBCode does not, bind a BBCode to the tag
     * that is already there. addFromRepository() would try to create the tag a
     * second time and throw.
     */
    private function fromRepository(Configurator $config): void
    {
        foreach (['C', 'FONT', 'RIGHT', 'LEFT', 'JUSTIFY', 'ALIGN', 'BACKGROUND',
                  'HR', 'SUP', 'SUB', 'INS', 'NOPARSE'] as $name) {
            if (isset($config->BBCodes[$name])) {
                continue;
            }

            if (isset($config->tags[$name])) {
                $config->BBCodes->add($name, ['tagName' => $name]);

                continue;
            }

            $config->BBCodes->addFromRepository($name);
        }
    }

    /**
     * XenForo spoilers carry an optional title on the reveal button. Rendered
     * as a real <details> so it works with JS disabled and is keyboard- and
     * screen-reader-accessible for free; the chevron and animation are CSS.
     */
    private function spoiler(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[SPOILER title={TEXT1?}]{TEXT2}[/SPOILER]',
            '<details class="lmxSpoiler">
                <summary class="lmxSpoiler-summary">
                    <span class="lmxSpoiler-chevron" aria-hidden="true"/>
                    <span class="lmxSpoiler-label">
                        <xsl:choose>
                            <xsl:when test="@title"><xsl:value-of select="@title"/></xsl:when>
                            <xsl:otherwise>Spoiler</xsl:otherwise>
                        </xsl:choose>
                    </span>
                </summary>
                <div class="lmxSpoiler-body"><xsl:apply-templates/></div>
            </details>'
        );
    }

    /**
     * Quotes replace flarum/bbcode's, which renders author-only. XenForo
     * carries the quoted member and the id of the quoted post, so the block
     * gets real attribution and a jump link. `post` holds the SOURCE post id;
     * ResolveQuoteLinks turns it into a local url at render time, because at
     * import time the quoted post may not exist yet (forward references).
     */
    private function quote(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[QUOTE author={TEXT1?} post={UINT?} member={UINT?} url={URL?} avatar={URL?} profile={URL?}]{TEXT2}[/QUOTE]',
            '<blockquote class="lmxQuote">
                <xsl:if test="not(@author)"><xsl:attribute name="class">lmxQuote lmxQuote--uncited</xsl:attribute></xsl:if>
                <xsl:if test="@author">
                    <div class="lmxQuote-head">
                        <xsl:if test="@avatar">
                            <img class="lmxQuote-avatar" src="{@avatar}" alt="" loading="lazy" decoding="async"/>
                        </xsl:if>
                        <xsl:choose>
                            <xsl:when test="@profile">
                                <a class="lmxQuote-author" href="{@profile}"><xsl:value-of select="@author"/></a>
                            </xsl:when>
                            <xsl:otherwise>
                                <span class="lmxQuote-author"><xsl:value-of select="@author"/></span>
                            </xsl:otherwise>
                        </xsl:choose>
                        <span class="lmxQuote-said"> said:</span>
                        <xsl:if test="@url">
                            <a class="lmxQuote-jump" href="{@url}" title="Go to the quoted post">
                                <span class="lmxQuote-jumpIcon" aria-hidden="true"/>
                            </a>
                        </xsl:if>
                    </div>
                </xsl:if>
                <div class="lmxQuote-body"><xsl:apply-templates/></div>
            </blockquote>'
        );
    }

    /**
     * XenForo's url-unfurl block: a link preview with favicon, host, title,
     * description and an optional thumbnail. This is the construct that was
     * leaking as `url=https://…` with no opening bracket. Rendered as a real
     * card, which is what it is.
     */
    private function unfurl(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[UNFURL url={URL} host={TEXT1?} desc={TEXT2?} image={URL?} icon={URL?}]{TEXT3}[/UNFURL]',
            '<a class="lmxUnfurl" href="{@url}" rel="nofollow ugc noopener" target="_blank">
                <xsl:if test="@image">
                    <span class="lmxUnfurl-figure">
                        <img src="{@image}" alt="" loading="lazy" decoding="async"/>
                    </span>
                </xsl:if>
                <span class="lmxUnfurl-main">
                    <span class="lmxUnfurl-title"><xsl:apply-templates/></span>
                    <xsl:if test="@desc">
                        <span class="lmxUnfurl-desc"><xsl:value-of select="@desc"/></span>
                    </xsl:if>
                    <span class="lmxUnfurl-minor">
                        <xsl:if test="@icon">
                            <img class="lmxUnfurl-icon" src="{@icon}" alt="" loading="lazy" decoding="async"/>
                        </xsl:if>
                        <span class="lmxUnfurl-host"><xsl:value-of select="@host"/></span>
                    </span>
                </span>
            </a>'
        );
    }

    /**
     * Images replace flarum/bbcode's IMG. Three things the stock tag does not
     * do and the corpus needs: intrinsic width/height (64,230 <img> carry
     * them — without them every image reflows the thread as it loads),
     * lazy loading, and float alignment (478 aligned images).
     *
     * A caption becomes a <figure>, so attachment titles survive.
     */
    private function image(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[IMG src={URL;useContent} alt={TEXT1?} width={UINT?} height={UINT?} align={CHOICE=left,right,center;optional;caseSensitive;preFilter=strtolower} full={URL?}]',
            '<span class="lmxImageWrap">
                <xsl:if test="@align"><xsl:attribute name="class">lmxImageWrap lmxImageWrap--<xsl:value-of select="@align"/></xsl:attribute></xsl:if>
                <img class="lmxImage" src="{@src}" loading="lazy" decoding="async">
                    <xsl:attribute name="alt"><xsl:value-of select="@alt"/></xsl:attribute>
                    <xsl:if test="@alt"><xsl:attribute name="title"><xsl:value-of select="@alt"/></xsl:attribute></xsl:if>
                    <xsl:copy-of select="@width"/>
                    <xsl:copy-of select="@height"/>
                </img>
            </span>'
        );
    }

    /**
     * Media. Two sources in the corpus:
     *   - s9e mediaembed spans (12,164) that XenForo itself produced
     *   - bbMediaWrapper iframes with data-media-site-id (apple music etc.)
     *
     * Rendered as a click-to-play facade rather than an eager iframe: a thread
     * with fifteen YouTube embeds otherwise loads fifteen third-party players
     * before the reader has asked for one. js/dist/forum.js swaps in the real
     * iframe on click.
     *
     * ---------------------------------------------------------------------
     * Two naming/shape decisions here, both forced by measurement.
     *
     * 1. The tag is EMBED, not MEDIA. `MEDIA` is already taken — Flarum core
     *    enables s9e's MediaEmbed plugin, which owns a MEDIA tag. Core keeps it
     *    and keeps working for pasted URLs; this is a separate construct.
     *
     * 2. EMBED is STANDALONE — no closing tag. s9e's RulesGenerator infers
     *    `autoClose` for any tag whose template does not contain
     *    `<xsl:apply-templates/>`, and this template deliberately renders only
     *    its attributes (the body text is just the site name, which @site
     *    already carries). Declared as a paired BBCode it therefore closed
     *    itself at the start tag and dropped `[/embed]` into the reader's text.
     *
     *    That was measured, not reasoned: tests/render.php 'vocab-embed' failed
     *    with the card rendered correctly AND `youtube[/embed]` sitting beside
     *    it, and it survived renaming the tag away from the collision — which
     *    is what ruled out the collision as the cause and pointed at the
     *    template shape instead.
     *
     * HtmlToBbcode::mediaTag() emits the matching standalone form, and
     * BalanceTags drops any `[/embed]` left over from content converted before
     * this change.
     */
    private function media(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[EMBED site={IDENTIFIER} url={URL} thumb={URL?} height={UINT?}]',
            '<span class="lmxMedia" data-lmx-media="{@url}" data-lmx-site="{@site}">
                <xsl:if test="@height"><xsl:attribute name="data-lmx-height"><xsl:value-of select="@height"/></xsl:attribute></xsl:if>
                <span class="lmxMedia-frame">
                    <xsl:if test="@thumb">
                        <img class="lmxMedia-thumb" src="{@thumb}" alt="" loading="lazy" decoding="async"/>
                    </xsl:if>
                    <span class="lmxMedia-play" aria-hidden="true"/>
                </span>
                <span class="lmxMedia-label"><xsl:value-of select="@site"/></span>
            </span>'
        );

        $config->BBCodes->addCustom(
            '[VIDEO src={URL;useContent} poster={URL?}]',
            '<video class="lmxVideo" controls="controls" preload="none" playsinline="playsinline" src="{@src}">
                <xsl:if test="@poster"><xsl:attribute name="poster"><xsl:value-of select="@poster"/></xsl:attribute></xsl:if>
            </video>'
        );

        $config->BBCodes->addCustom(
            '[AUDIO src={URL;useContent}]',
            '<audio class="lmxAudio" controls="controls" preload="none" src="{@src}"/>'
        );
    }

    /**
     * Mentions of source members we never imported, and of usergroups.
     *
     * flarum/mentions invalidates a USERMENTION whose id has no row, which
     * drops it back to plain text — that is why "@somebody" was rendering
     * unstyled. These two tags carry no id, so nothing can invalidate them,
     * and they are styled as mentions with a "not on this forum" affordance.
     */
    private function mentions(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[UMENTION name={TEXT1} uid={UINT?}]',
            '<span class="UserMention UserMention--unimported" title="Member of the source board, not imported here">
                <xsl:text>@</xsl:text><xsl:value-of select="@name"/>
            </span>'
        );

        $config->BBCodes->addCustom(
            '[GMENTION name={TEXT1} gid={UINT?}]',
            '<span class="UserMention GroupMention">
                <xsl:value-of select="@name"/>
            </span>'
        );
    }

    /**
     * Sprite smilies. The board renders these from a CSS sprite sheet we do
     * not have (the <img> src in the html is a 1x1 transparent gif), so the
     * only faithful thing available is the emote's human name — shown as a
     * chip rather than as the raw `:feelskek:` the old converter emitted.
     * Unicode smilies are not routed here: their alt attribute is the actual
     * emoji character, which flarum/emoji renders natively.
     */
    private function emote(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[EMOTE name={TEXT1} label={TEXT2?}]',
            '<span class="lmxEmote" title="{@name}">
                <xsl:choose>
                    <xsl:when test="@label"><xsl:value-of select="@label"/></xsl:when>
                    <xsl:otherwise><xsl:value-of select="@name"/></xsl:otherwise>
                </xsl:choose>
            </span>'
        );
    }

    /**
     * Tables. Markdown pipe tables cannot hold block content, and 899 posts
     * put images and links inside table cells, so these are real tags.
     */
    private function tables(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[TABLE]{ANYTHING}[/TABLE]',
            '<div class="lmxTableWrap"><table class="lmxTable"><xsl:apply-templates/></table></div>'
        );
        $config->BBCodes->addCustom('[THEAD]{ANYTHING}[/THEAD]', '<thead><xsl:apply-templates/></thead>');
        $config->BBCodes->addCustom('[TBODY]{ANYTHING}[/TBODY]', '<tbody><xsl:apply-templates/></tbody>');
        $config->BBCodes->addCustom('[TR]{ANYTHING}[/TR]', '<tr><xsl:apply-templates/></tr>');
        $config->BBCodes->addCustom(
            '[TD colspan={UINT?} rowspan={UINT?} #createParagraphs=false]{ANYTHING}[/TD]',
            '<td><xsl:copy-of select="@colspan"/><xsl:copy-of select="@rowspan"/><xsl:apply-templates/></td>'
        );
        $config->BBCodes->addCustom(
            '[TH colspan={UINT?} rowspan={UINT?} #createParagraphs=false]{ANYTHING}[/TH]',
            '<th><xsl:copy-of select="@colspan"/><xsl:copy-of select="@rowspan"/><xsl:apply-templates/></th>'
        );

        // Cells only make sense inside a row, rows only inside a table. Without
        // this, a stray [td] in prose becomes a floating <td> the browser drops
        // and its text disappears from the post.
        $config->tags['TR']->rules->requireParent('TABLE');
        $config->tags['TR']->rules->requireParent('THEAD');
        $config->tags['TR']->rules->requireParent('TBODY');
        foreach (['TD', 'TH'] as $cell) {
            $config->tags[$cell]->rules->requireParent('TR');
        }
    }

    /**
     * s9e defaults that the corpus exceeds. Both failures are silent: content
     * past the limit is emitted as literal BBCode text, which is exactly the
     * bug being fixed, so leaving them at the default would have re-created it
     * for the deepest posts.
     */
    private function limits(Configurator $config): void
    {
        /*
         * Measured, not chosen: the whole-corpus survey found spoilers nesting
         * to depth 28 and quotes to depth 9 across 423,268 posts
         * (reports/CORPUS-SURVEY.md §5). 30 left two levels of headroom on the
         * deepest post in the corpus, which is not headroom — and the failure
         * mode past the limit is silent emission as literal text, i.e. exactly
         * the bug this extension exists to fix.
         */
        foreach (['SPOILER', 'QUOTE', 'ISPOILER', 'LOLQUOTE'] as $name) {
            $config->tags[$name]->nestingLimit = 60;
            $config->tags[$name]->tagLimit = 5000;
        }
        foreach (['IMG', 'UNFURL', 'UMENTION', 'EMOTE', 'EMBED', 'TR', 'TD'] as $name) {
            $config->tags[$name]->tagLimit = 20000;
        }
        // 20 levels of list/table markup is well past anything in the corpus
        // but costs nothing and prevents the same silent truncation.
        foreach (['TABLE', 'TD', 'TH'] as $name) {
            $config->tags[$name]->nestingLimit = 20;
        }
    }
}
