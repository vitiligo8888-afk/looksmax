<?php

namespace Local\Format;

use s9e\TextFormatter\Parser;

/**
 * Drop BBCode closing tags that have no matching opener, before parsing.
 *
 * -------------------------------------------------------------------------
 * Why this is needed at the render layer and not only in the importer
 * -------------------------------------------------------------------------
 * s9e/TextFormatter emits an unmatched `[/spoiler]` as literal text. That is
 * the correct default for a forum where a user might genuinely type it — but
 * it is exactly the tail of the operator's bug report:
 *
 *     [/spoiler][/spoiler][/spoiler]
 *
 * Three closers, one opener. Two of them reach the reader as raw markup no
 * matter how good the converter is, because by the time the text is parsed the
 * damage is already in the string. The importer's HtmlToBbcode::balance()
 * prevents it for content converted from source HTML, but that only covers
 * content this pipeline produced. This covers everything: hand-written posts,
 * quotes of a broken post, content imported before the converter was fixed,
 * and anything a future import path emits.
 *
 * -------------------------------------------------------------------------
 * What it deliberately does NOT do
 * -------------------------------------------------------------------------
 * It never touches the inside of `[code]` or `[noparse]`. A code block whose
 * contents are BBCode is a real thing in this corpus (people paste BBCode to
 * ask why it broke), and silently editing the sample would be a worse bug than
 * the one being fixed. Regions between `[code]…[/code]` and
 * `[noparse]…[/noparse]` are masked out before the scan and restored after.
 *
 * It also never inserts closers. An unclosed `[spoiler]` is left alone: s9e
 * auto-closes it at the end of the block, which already produces sane output,
 * and inventing a closing position risks moving content into or out of a
 * spoiler — changing what the post says.
 *
 * Only block tags this extension is responsible for are considered. A stray
 * `[/b]` is harmless and cheap to leave; a stray `[/spoiler]` is the reported
 * defect.
 */
class BalanceTags
{
    /**
     * Tags whose stray closers are worth removing. Deliberately narrow: these
     * are the block constructs whose leaked closer is visually loud and
     * meaningless to a reader.
     */
    private const TAGS = [
        'spoiler', 'quote', 'unfurl', 'table', 'tr', 'td', 'th',
        'thead', 'tbody', 'list', 'url',
    ];

    /**
     * Standalone tags: they never have a closer, so ANY closer is an orphan.
     *
     * `[embed]` is standalone because its template renders attributes only and
     * s9e consequently infers autoClose. Content converted before that was
     * understood carries `[/embed]`, and without this list the scanner would
     * see the preceding `[embed …]` as a matching opener and leave it in place.
     */
    private const STANDALONE = ['embed'];

    /** Regions whose contents are verbatim and must never be rewritten. */
    private const VERBATIM = ['code', 'noparse'];

    public function __invoke(Parser $parser, $context, string $text): string
    {
        // Cheap bail-out: the overwhelming majority of posts have no closers
        // at all, and this runs on every parse.
        if (! str_contains($text, '[/')) {
            return $text;
        }

        [$masked, $vault] = $this->mask($text);
        $cleaned = $this->dropOrphanClosers($masked);

        return $this->unmask($cleaned, $vault);
    }

    /**
     * Replace verbatim regions with placeholders so the scanner cannot see
     * inside them.
     *
     * @return array{0:string,1:array<string,string>}
     */
    private function mask(string $text): array
    {
        $vault = [];
        $i = 0;

        foreach (self::VERBATIM as $tag) {
            $pattern = '/\['.$tag.'\b[^\]]*\].*?\[\/'.$tag.'\]/is';
            $text = preg_replace_callback(
                $pattern,
                function (array $m) use (&$vault, &$i) {
                    // \x00 cannot appear in valid post text, so a placeholder
                    // built from it cannot collide with content.
                    $key = "\x00lmxv".($i++)."\x00";
                    $vault[$key] = $m[0];

                    return $key;
                },
                $text
            ) ?? $text;
        }

        return [$text, $vault];
    }

    private function unmask(string $text, array $vault): string
    {
        return $vault === [] ? $text : strtr($text, $vault);
    }

    /**
     * One left-to-right pass with a stack, removing closers that cannot match.
     *
     * Offsets are captured on the way through and the removals applied
     * right-to-left, so earlier offsets stay valid as the string shrinks.
     */
    private function dropOrphanClosers(string $text): string
    {
        $names = implode('|', array_merge(self::TAGS, self::STANDALONE));
        // (?<!\\) so an escaped \[/spoiler] — which the importer writes when the
        // source post contained that text literally — is left alone.
        $pattern = '/(?<!\\\\)\[(\/?)('.$names.')(?=[\]\s=])[^\]]*\]/i';

        if (! preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        $open = [];
        $drop = [];

        foreach ($m[0] as $i => $hit) {
            $name = strtolower($m[2][$i][0]);
            $standalone = in_array($name, self::STANDALONE, true);

            if ($m[1][$i][0] !== '/') {
                // A standalone tag opens nothing, so it can never satisfy a
                // later closer.
                if (! $standalone) {
                    $open[$name] = ($open[$name] ?? 0) + 1;
                }

                continue;
            }

            if (! $standalone && ($open[$name] ?? 0) > 0) {
                $open[$name]--;

                continue;
            }

            $drop[] = [$hit[1], strlen($hit[0])];
        }

        foreach (array_reverse($drop) as [$offset, $length]) {
            $text = substr($text, 0, $offset).substr($text, $offset + $length);
        }

        return $text;
    }
}
