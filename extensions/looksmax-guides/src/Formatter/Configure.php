<?php

namespace Local\Guides\Formatter;

use s9e\TextFormatter\Configurator;

/**
 * The guide formatter elements.
 *
 * Registered through Extend\Formatter::configure, which is the one genuinely
 * non-conflicting extension point in this area: the ecosystem sweep counts 175
 * Formatter::configure users and zero override conflicts, against 10
 * extensions fully overriding Post.contentHtml and 15 overriding
 * s9e.TextFormatter.preview. So all structure and all per-viewer behaviour is
 * server-side in the formatter pipeline, and the JS layer only decorates what
 * the server already rendered. That is the design constraint that makes this
 * feature survivable next to third-party extensions.
 *
 * Every element is also a data source: the extractor reads these tags back out
 * of the stored XML to populate guide_meta and guide_claims, so the author
 * writes prose once and the queryable metadata falls out of it. That is the
 * whole trick — asking an author to fill in a form *and* write the guide is
 * how you get no guides.
 */
class Configure
{
    public function __invoke(Configurator $config): void
    {
        $this->tldr($config);
        $this->claim($config);
        $this->callouts($config);
        $this->spec($config);
        $this->steps($config);
        $this->stack($config);
        $this->term($config);
        $this->beforeAfter($config);
    }

    /**
     * An attribute with a fallback, as an XSL fragment.
     *
     * Optional s9e attributes are simply absent when unset, and an absent
     * attribute in an attribute-value template produces an empty string —
     * which silently turns data-tier="0" into data-tier="". Getting this wrong
     * is invisible until a CSS selector quietly stops matching, so it is done
     * explicitly everywhere rather than by AVT.
     */
    private function attr(string $name, string $from, string $default): string
    {
        return '<xsl:attribute name="' . $name . '">'
            . '<xsl:choose>'
            . '<xsl:when test="@' . $from . '"><xsl:value-of select="@' . $from . '"/></xsl:when>'
            . '<xsl:otherwise>' . $default . '</xsl:otherwise>'
            . '</xsl:choose>'
            . '</xsl:attribute>';
    }

    /**
     * The summary block. Required for publication, because a 4,000-word guide
     * whose first paragraph is throat-clearing gets abandoned in the first
     * screen and the board's own read-depth data will show it.
     */
    private function tldr(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[TLDR]{TEXT}[/TLDR]',
            '<div class="GuideBlock GuideBlock--tldr">'
            . '<div class="GuideBlock-label">TL;DR</div>'
            . '<div class="GuideBlock-body"><xsl:apply-templates/></div>'
            . '</div>'
        );
    }

    /**
     * The core primitive: a statement carrying an evidence tier and a source.
     *
     * Rendered inline at the end of the sentence rather than as a block,
     * because a block breaks reading flow and an author who finds the markup
     * annoying to read will stop using it.
     */
    private function claim(Configurator $config): void
    {
        $labels = '';
        foreach (\Local\Guides\Guide\Tiers::SHORT as $tier => $short) {
            $labels .= '<xsl:when test="@tier = ' . $tier . '">' . $short . '</xsl:when>';
        }

        $titles = '';
        foreach (\Local\Guides\Guide\Tiers::LABEL as $tier => $label) {
            $titles .= '<xsl:when test="@tier = ' . $tier . '">' . htmlspecialchars($label, ENT_XML1) . '</xsl:when>';
        }

        $template =
            '<span class="GuideClaim">'
            . $this->attr('data-tier', 'tier', '0')
            . '<span class="GuideClaim-text"><xsl:apply-templates/></span>'
            . '<xsl:choose>'
            . '<xsl:when test="@src">'
            . '<a class="GuideClaim-chip" rel="nofollow noopener" target="_blank">'
            . '<xsl:attribute name="href"><xsl:value-of select="@src"/></xsl:attribute>'
            . '<xsl:attribute name="title"><xsl:choose>' . $titles
            . '<xsl:otherwise>Anecdote</xsl:otherwise></xsl:choose> — source attached</xsl:attribute>'
            . '<xsl:choose>' . $labels . '<xsl:otherwise>T0</xsl:otherwise></xsl:choose>'
            . '</a>'
            . '</xsl:when>'
            . '<xsl:otherwise>'
            . '<span class="GuideClaim-chip GuideClaim-chip--bare">'
            . '<xsl:attribute name="title"><xsl:choose>' . $titles
            . '<xsl:otherwise>Anecdote</xsl:otherwise></xsl:choose></xsl:attribute>'
            . '<xsl:choose>' . $labels . '<xsl:otherwise>T0</xsl:otherwise></xsl:choose>'
            . '</span>'
            . '</xsl:otherwise>'
            . '</xsl:choose>'
            . '</span>';

        $config->BBCodes->addCustom(
            // doi is {TEXT?}, not {SIMPLETEXT?}: s9e's SIMPLETEXT filter is
            // [- +,.0-9A-Za-z_]+ and every real DOI contains a slash, so
            // SIMPLETEXT silently drops the attribute and the citation
            // vanishes with no error anywhere.
            '[CLAIM tier={UINT?} src={URL?} doi={TEXT?}]{TEXT}[/CLAIM]',
            $template
        );
    }

    /**
     * Callouts. One tag, five BBCode spellings, because [WARN] reads better in
     * a composer than [CALLOUT type=warn] and authors write what is cheap.
     */
    private function callouts(Configurator $config): void
    {
        $kinds = [
            'NOTE' => ['note', 'Note'],
            'TIP' => ['tip', 'Tip'],
            'WARN' => ['warn', 'Warning'],
            'DANGER' => ['danger', 'Danger'],
            'KEY' => ['key', 'Key takeaway'],
        ];

        foreach ($kinds as $bb => [$slug, $label]) {
            $config->BBCodes->addCustom(
                "[$bb]{TEXT}[/$bb]",
                '<div class="GuideCallout GuideCallout--' . $slug . '" data-kind="' . $slug . '">'
                . '<div class="GuideCallout-label">' . $label . '</div>'
                . '<div class="GuideCallout-body"><xsl:apply-templates/></div>'
                . '</div>'
            );
        }

        // Risk is separate from a plain danger callout: it carries a level
        // that the extractor reads back out to set the guide's risk floor, so
        // an author cannot write "moderate risk" in the spec sheet while the
        // body says the procedure is permanent and medical.
        $config->BBCodes->addCustom(
            '[RISK level={SIMPLETEXT?}]{TEXT}[/RISK]',
            '<div class="GuideCallout GuideCallout--risk">'
            . $this->attr('data-level', 'level', 'moderate')
            . '<div class="GuideCallout-label">Risk<xsl:if test="@level"> — '
            . '<xsl:value-of select="@level"/></xsl:if></div>'
            . '<div class="GuideCallout-body"><xsl:apply-templates/></div>'
            . '</div>'
        );
    }

    /**
     * The spec sheet. This is what turns a pile of guides into a comparable
     * set: difficulty, cost, time to result, risk, reversibility. Without it
     * "which of these is the cheap low-risk one" is unanswerable, which is the
     * question every reader actually arrives with.
     */
    private function spec(Configurator $config): void
    {
        $row = function (string $attr, string $label, string $icon) {
            return '<xsl:if test="@' . $attr . '">'
                . '<div class="GuideSpec-item" data-field="' . $attr . '">'
                . '<span class="GuideSpec-icon" data-icon="' . $icon . '"></span>'
                . '<span class="GuideSpec-label">' . $label . '</span>'
                . '<span class="GuideSpec-value"><xsl:value-of select="@' . $attr . '"/></span>'
                . '</div></xsl:if>';
        };

        // "Needs a professional: no" is noise — a spec sheet is a list of
        // things that are true, not a questionnaire with the answers filled
        // in. The flag renders only when it is actually set, so the reader's
        // eye goes to the fields that carry information. Authors write
        // pro=no freely, so the negative forms have to be recognised
        // explicitly rather than treated as truthy strings.
        $proTruthy = "@pro and @pro != 'no' and @pro != 'No' and @pro != 'false'"
            . " and @pro != '0' and @pro != 'none' and @pro != 'n'";

        $config->BBCodes->addCustom(
            '[SPEC difficulty={UINT?} cost={TEXT?} time={TEXT?} risk={SIMPLETEXT?}'
            . ' reversibility={SIMPLETEXT?} pro={SIMPLETEXT?}]',
            '<div class="GuideSpec">'
            . $row('difficulty', 'Difficulty', 'gauge')
            . $row('cost', 'Cost', 'coins')
            . $row('time', 'Results in', 'hourglass')
            . $row('risk', 'Risk', 'shield')
            . $row('reversibility', 'Reversibility', 'arrows')
            . '<xsl:if test="' . $proTruthy . '">'
            . '<div class="GuideSpec-item" data-field="pro">'
            . '<span class="GuideSpec-icon" data-icon="stethoscope"></span>'
            . '<span class="GuideSpec-label">Needs a professional</span>'
            . '<span class="GuideSpec-value">Yes</span>'
            . '</div></xsl:if>'
            . '</div>'
        );
    }

    private function steps(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[STEPS]{TEXT}[/STEPS]',
            '<div class="GuideSteps"><xsl:apply-templates/></div>'
        );

        // Steps are checkable and the checked state persists per reader. A
        // protocol you are three weeks into is useless if it forgets where you
        // are every time you reload.
        $config->BBCodes->addCustom(
            '[STEP title={TEXT?}]{TEXT}[/STEP]',
            '<div class="GuideStep">'
            . '<span class="GuideStep-check" role="checkbox" aria-checked="false" tabindex="0"></span>'
            . '<div class="GuideStep-main">'
            . '<xsl:if test="@title"><div class="GuideStep-title"><xsl:value-of select="@title"/></div></xsl:if>'
            . '<div class="GuideStep-body"><xsl:apply-templates/></div>'
            . '</div></div>'
        );
    }

    /**
     * The regimen table. On this board a protocol is almost always a list of
     * (thing, dose, frequency, duration) and the source board renders it as
     * free prose, which is why every such thread has forty replies asking what
     * the dose was.
     */
    private function stack(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[STACK title={TEXT?}]{TEXT}[/STACK]',
            '<div class="GuideStack">'
            . '<xsl:if test="@title"><div class="GuideStack-title"><xsl:value-of select="@title"/></div></xsl:if>'
            . '<div class="GuideStack-head">'
            . '<span>Item</span><span>Dose</span><span>Frequency</span><span>Duration</span>'
            . '</div>'
            . '<xsl:apply-templates/>'
            . '</div>'
        );

        $config->BBCodes->addCustom(
            '[ITEM name={TEXT} dose={TEXT?} freq={TEXT?} dur={TEXT?} note={TEXT?}]',
            '<div class="GuideStack-row">'
            . '<span class="GuideStack-name"><xsl:value-of select="@name"/></span>'
            . '<span class="GuideStack-dose"><xsl:value-of select="@dose"/></span>'
            . '<span class="GuideStack-freq"><xsl:value-of select="@freq"/></span>'
            . '<span class="GuideStack-dur"><xsl:value-of select="@dur"/></span>'
            . '<xsl:if test="@note"><span class="GuideStack-note"><xsl:value-of select="@note"/></span></xsl:if>'
            . '</div>'
        );
    }

    /**
     * Glossary link. The board's vocabulary — PSL, mogging, canthal tilt,
     * gonial angle, failo — makes its best content unreadable to exactly the
     * newcomers who would benefit most from it. Auto-linking every occurrence
     * across 29.7M posts would be noise, so linking is explicit and per-term.
     */
    private function term(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[TERM slug={SIMPLETEXT?}]{TEXT}[/TERM]',
            '<span class="GuideTerm" tabindex="0">'
            . '<xsl:attribute name="data-term">'
            . '<xsl:choose><xsl:when test="@slug"><xsl:value-of select="@slug"/></xsl:when>'
            . '<xsl:otherwise><xsl:value-of select="translate(., '
            . "'ABCDEFGHIJKLMNOPQRSTUVWXYZ ', 'abcdefghijklmnopqrstuvwxyz-')"
            . '"/></xsl:otherwise></xsl:choose>'
            . '</xsl:attribute>'
            . '<xsl:apply-templates/>'
            . '</span>'
        );
    }

    /**
     * Before/after pair. Ratings is 183,700 threads of image content and
     * Looksmaxing is image-first; a comparison slider is the native idiom of
     * this board and rendering two loose images is throwing the comparison
     * away.
     */
    private function beforeAfter(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[BA before={URL} after={URL} label={TEXT?}]',
            '<div class="GuideBA">'
            . '<div class="GuideBA-pair">'
            . '<img class="GuideBA-before" loading="lazy" alt="Before">'
            . '<xsl:attribute name="src"><xsl:value-of select="@before"/></xsl:attribute>'
            . '</img>'
            . '<img class="GuideBA-after" loading="lazy" alt="After">'
            . '<xsl:attribute name="src"><xsl:value-of select="@after"/></xsl:attribute>'
            . '</img>'
            . '</div>'
            . '<xsl:if test="@label"><div class="GuideBA-label"><xsl:value-of select="@label"/></div></xsl:if>'
            . '</div>'
        );
    }
}
