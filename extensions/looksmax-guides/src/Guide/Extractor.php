<?php

namespace Local\Guides\Guide;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads guide structure back out of a post's stored TextFormatter XML.
 *
 * Parsing the XML rather than the rendered HTML is deliberate and matters:
 * the XML is the source of truth Flarum stores, it is stable across renderer
 * changes, and it still carries the original attributes (tier, src, doi) that
 * the HTML has already collapsed into presentation. Parsing rendered HTML
 * would also mean re-running the renderer on every save for no reason.
 *
 * Everything here is total: a malformed or non-guide post yields an empty
 * result rather than throwing, because this runs inside a post save and a
 * throw would mean the member cannot post.
 */
class Extractor
{
    /** Average adult reading speed for technical prose, words/minute. */
    private const WPM = 220;

    public function extract(?string $xml): array
    {
        $empty = [
            'is_guide' => false,
            'claims' => [],
            'profile' => [],
            'toc' => [],
            'spec' => [],
            'word_count' => 0,
            'read_minutes' => 0,
            'section_count' => 0,
            'has_tldr' => false,
            'risk_level' => null,
        ];

        if (!$xml || $xml === '' || $xml[0] !== '<') {
            return $empty;
        }

        // A plain-text post is stored as <t>…</t> with no markup at all, so it
        // can never contain guide elements. Skipping it here avoids a DOM
        // parse on the overwhelming majority of the 29.7M posts.
        if (strpos($xml, '<t>') === 0) {
            return $empty;
        }

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$ok) {
            return $empty;
        }

        // s9e stores the original markup inline as <s> (start tag), <e> (end
        // tag) and <i> (ignored) elements so that unparse() can reconstruct
        // the source exactly. They are part of the document, so a naive
        // textContent read returns "[CLAIM tier=4]the actual claim[/CLAIM]"
        // and "## Who this is for". Strip them once, up front, and every
        // downstream read is clean.
        $this->stripMarkup($doc);

        $xp = new DOMXPath($doc);

        $claims = $this->claims($xp);
        $toc = $this->toc($xp);
        $spec = $this->spec($xp);
        $risk = $this->risk($xp, $spec);
        $hasTldr = $xp->query('//TLDR')->length > 0;

        // Counted from the stripped document, so BBCode tag names and markdown
        // hashes do not inflate the reading-time estimate. str_word_count is
        // ASCII-only and would score a Cyrillic guide at zero, so words are
        // counted by unicode-aware splitting instead — five of the eighteen
        // seeded sections are non-English.
        $plain = trim(preg_replace('/\s+/u', ' ', $doc->textContent));
        $words = $plain === '' ? 0 : count(preg_split('/\s+/u', $plain));

        $stackRows = $xp->query('//ITEM')->length;
        $steps = $xp->query('//STEP')->length;

        $isGuide = $hasTldr
            || count($claims) > 0
            || count($spec) > 0
            || $stackRows > 0
            || $steps > 0;

        $profile = [];
        foreach ($claims as $c) {
            $profile[$c['tier']] = ($profile[$c['tier']] ?? 0) + 1;
        }

        return [
            'is_guide' => $isGuide,
            'claims' => $claims,
            'profile' => $profile,
            'toc' => $toc,
            'spec' => $spec,
            'word_count' => $words,
            'read_minutes' => (int) max(1, ceil($words / self::WPM)),
            'section_count' => count($toc),
            'has_tldr' => $hasTldr,
            'risk_level' => $risk,
        ];
    }

    /**
     * @return array<int,array{tier:int,anchor:string,claim_text:string,source_url:?string,source_doi:?string,position:int}>
     */
    private function claims(DOMXPath $xp): array
    {
        $out = [];
        $i = 0;

        foreach ($xp->query('//CLAIM') as $node) {
            /** @var DOMElement $node */
            $tier = Tiers::normalise((int) $node->getAttribute('tier'));
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            $src = $node->getAttribute('src') ?: null;
            $doi = $node->getAttribute('doi') ?: null;

            if ($text === '') {
                continue;
            }

            // A DOI is a stronger source than a bare URL and the two often
            // appear together, so a doi with no src still counts as sourced.
            $out[] = [
                'position' => $i,
                'tier' => $tier,
                // Anchors are derived from content, not from ordinal position:
                // inserting a paragraph must not renumber every later anchor
                // and break every inbound link into the document.
                'anchor' => 'c-' . substr(sha1($text), 0, 12),
                'claim_text' => mb_substr($text, 0, 500),
                'source_url' => $src ? mb_substr($src, 0, 1024) : null,
                'source_doi' => $doi ? mb_substr($doi, 0, 128) : null,
            ];
            $i++;
        }

        return $out;
    }

    /**
     * Table of contents from the markdown heading tree.
     *
     * flarum/markdown compiles through the same s9e pipeline and emits H1..H6
     * elements, so the outline is already in the XML and does not need a
     * second parse of the rendered HTML — which is what all three of the
     * ecosystem's table-of-contents extensions do, and why all three fight
     * over DiscussionPage.render.
     */
    private function toc(DOMXPath $xp): array
    {
        $out = [];
        $seen = [];

        foreach ($xp->query('//H1|//H2|//H3|//H4') as $node) {
            /** @var DOMElement $node */
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if ($text === '') {
                continue;
            }

            $slug = $this->slug($text);
            if ($slug === '') {
                continue;
            }

            // Duplicate headings are common ("Risks" under two sections), so
            // deduplicate with a suffix rather than letting two anchors
            // collide and send every link to the first one.
            $base = $slug;
            $n = 2;
            while (isset($seen[$slug])) {
                $slug = $base . '-' . $n++;
            }
            $seen[$slug] = true;

            $out[] = [
                'level' => (int) substr($node->nodeName, 1),
                'text' => mb_substr($text, 0, 180),
                'anchor' => $slug,
            ];
        }

        return $out;
    }

    private function spec(DOMXPath $xp): array
    {
        $node = $xp->query('//SPEC')->item(0);
        if (!$node instanceof DOMElement) {
            return [];
        }

        $out = [];
        foreach (['difficulty', 'cost', 'time', 'risk', 'reversibility', 'pro'] as $key) {
            $v = $node->getAttribute($key);
            if ($v !== '') {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    /**
     * The guide's risk level is the highest of what the author declared in the
     * spec sheet and what any [RISK] block in the body actually says. Taking
     * the max rather than the declaration stops a spec sheet reading
     * "Risk: low" above a body that describes a permanent surgical procedure.
     */
    private function risk(DOMXPath $xp, array $spec): ?string
    {
        $order = ['none' => 0, 'low' => 1, 'moderate' => 2, 'high' => 3, 'medical' => 4];
        $best = null;
        $bestRank = -1;

        $candidates = [];
        if (isset($spec['risk'])) {
            $candidates[] = $spec['risk'];
        }
        foreach ($xp->query('//RISK') as $node) {
            /** @var DOMElement $node */
            $candidates[] = $node->getAttribute('level') ?: 'moderate';
        }

        foreach ($candidates as $c) {
            $c = strtolower(trim($c));
            if (isset($order[$c]) && $order[$c] > $bestRank) {
                $bestRank = $order[$c];
                $best = $c;
            }
        }

        return $best;
    }

    /**
     * Remove s9e's inline markup markers from the whole document.
     *
     * Iterated over a materialised list rather than the live NodeList, because
     * removing a node from a live DOMNodeList while iterating it silently
     * skips the next sibling — which would leave half the markers in place and
     * produce exactly the kind of intermittent, half-right output that is
     * miserable to debug later.
     */
    private function stripMarkup(DOMDocument $doc): void
    {
        $xp = new DOMXPath($doc);
        $doomed = [];

        foreach ($xp->query('//s | //e | //i') as $node) {
            $doomed[] = $node;
        }

        foreach ($doomed as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    private function slug(string $text): string
    {
        $s = mb_strtolower($text, 'UTF-8');
        // Keep unicode letters: five of this board's eighteen forums are
        // non-English and a Cyrillic or Turkish heading must still produce a
        // usable anchor rather than an empty one.
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
        $s = trim((string) $s, '-');

        return mb_substr((string) $s, 0, 60);
    }
}
