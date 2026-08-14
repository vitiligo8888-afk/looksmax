<?php

namespace Local\Search\Meili;

/**
 * Turning a stored post into indexable text.
 *
 * `posts.content` is s9e/TextFormatter XML, not HTML and not markdown. Three
 * things in it will poison an index if you take the naive `strip_tags` route,
 * and all three are visible in the live data:
 *
 *   1. `<s>` and `<e>` hold the LITERAL markup the author typed — a quote
 *      begins `<s>[quote=20]</s>`. Keeping them indexes `quote 20` as content
 *      words, so searching `quote` matches every quoting post on the forum.
 *   2. `<QUOTE>` subtrees are another post's words. Indexing them means the
 *      most-quoted post on the board matches everything, and a search for a
 *      phrase returns the twenty posts that quoted it above the post that
 *      said it. They are dropped: the original is indexed in its own right.
 *   3. `<i>` is TextFormatter's "ignored" node for whitespace and syntax
 *      fragments, and `<URL>`/`<IMG>` carry href/src attributes whose token
 *      soup (`https`, `com`, cdn hostnames) outweighs prose in a short post.
 *
 * The result is the words a human wrote, which is what a person is searching
 * for. Everything here is defensive: a malformed document degrades to
 * `strip_tags`, never to an exception, because one bad post must not abort a
 * batch of a thousand.
 */
class Text
{
    /** Nodes whose entire subtree is discarded. */
    private const DROP_SUBTREE = ['QUOTE', 'BLOCKQUOTE', 'SPOILER', 'CODE', 'PRE', 'SCRIPT', 'STYLE'];

    /** Nodes that hold literal markup rather than content. */
    private const DROP_SELF = ['s', 'e', 'i'];

    public static function plain(?string $xml, int $maxLength = 0): string
    {
        if ($xml === null || $xml === '') {
            return '';
        }

        // Plain-text posts are stored as <t>…</t> with no markup at all.
        $text = self::viaDom($xml);

        if ($text === null) {
            // Never let a malformed document cost us the post entirely.
            $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = preg_replace('/\s+/u', ' ', $text ?? '');
        $text = trim($text ?? '');

        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            // Cut on a word boundary so the excerpt does not end mid-token, and
            // so a cropped excerpt never invents a word that is not in the post.
            $cut = mb_substr($text, 0, $maxLength);
            $sp = mb_strrpos($cut, ' ');
            $text = ($sp !== false && $sp > $maxLength * 0.6 ? mb_substr($cut, 0, $sp) : $cut) . '…';
        }

        return $text;
    }

    private static function viaDom(string $xml): ?string
    {
        $prev = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            // The stored XML is a fragment with a single root (<r> or <t>).
            // LIBXML_NOENT would expand entities; we do not want that on
            // untrusted content, so entities are decoded from the text later.
            if (!$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
                return null;
            }

            $xpath = new \DOMXPath($doc);
            foreach (array_merge(self::DROP_SUBTREE, self::DROP_SELF) as $name) {
                $nodes = $xpath->query('//' . $name);
                if ($nodes === false) {
                    continue;
                }
                // Snapshot before removing: DOMNodeList is live.
                foreach (iterator_to_array($nodes) as $node) {
                    $node->parentNode?->removeChild($node);
                }
            }

            // Block-level nodes must not weld words together across a break.
            foreach (['p', 'br', 'LIST', 'LI', 'H1', 'H2', 'H3', 'TR'] as $name) {
                foreach (iterator_to_array($xpath->query('//' . $name) ?: []) as $node) {
                    $node->parentNode?->insertBefore($doc->createTextNode(' '), $node);
                }
            }

            return $doc->textContent;
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    /**
     * The two normalisations Meilisearch provably does not do, measured against
     * this corpus rather than assumed.
     *
     * charabia's NFKD + NonspacingMark pass folds accents for Latin scripts
     * (`niño`≡`nino`, `français`≡`francais`) and its Turkish normaliser folds
     * the dotted/dotless i (`ışık`≡`isik`). Two gaps remain, and both were
     * confirmed live on the 2.2M-document index:
     *
     *   `ё` / `е`  — Cyrillic is NOT in NonspacingMarkNormalizer's script list,
     *                so NFKD splits `ё` into `е` + U+0308 and KEEPS the
     *                combining diaeresis. Measured: `всё` → 4,766 hits,
     *                `все` → 4,768 hits, **overlap 0**. Two spellings of the
     *                same word reaching two disjoint document sets. Russian
     *                orthography treats the two as interchangeable, so this is
     *                a straight recall loss on roughly half of all Russian
     *                queries.
     *   `ß` / `ss` — no sharp-s normaliser exists at all. Measured:
     *                `straße` → 1,673 hits, `strasse` → 428 hits, **overlap 0**.
     *
     * Applied to build the SEARCHABLE copy of a field only. The displayed copy
     * keeps the author's spelling, because rewriting what somebody wrote to
     * make the index cheaper is not a trade this forum makes.
     */
    public static function fold(string $s): string
    {
        return strtr($s, [
            'ё' => 'е', 'Ё' => 'Е',
            'ß' => 'ss', 'ẞ' => 'SS',
        ]);
    }

    /**
     * Cheap language guess, used only to populate a facet and to give
     * `localizedAttributes` a hint. Script detection, not statistics: it
     * answers "which alphabet is this" with certainty, and falls back to a
     * small stop-word vote for the Latin-script languages where the alphabet
     * alone cannot decide. A wrong guess costs a facet value, never a match,
     * because nothing filters on it unless the user asks.
     */
    public static function guessLang(string $text): string
    {
        if ($text === '') {
            return 'und';
        }
        $sample = mb_substr($text, 0, 400);

        if (preg_match('/\p{Cyrillic}/u', $sample)) {
            return 'ru';
        }
        if (preg_match('/[ğĞşŞıİçÇöÖüÜ]/u', $sample) && preg_match('/\b(bir|ve|için|çok|daha|ama|gibi|ile|bu|şu)\b/iu', $sample)) {
            return 'tr';
        }

        $votes = [
            'es' => '/\b(que|para|como|pero|porque|más|muy|todo|hacer|tiene|está|nariz|cara)\b/iu',
            'de' => '/\b(und|nicht|auch|aber|schon|noch|sehr|eine|einen|dass|über|können)\b/iu',
            'fr' => '/\b(que|pour|avec|dans|mais|plus|très|être|cette|comme|nez|visage)\b/iu',
            'tr' => '/\b(bir|ve|için|çok|daha|ama|gibi|ile|değil|olarak)\b/iu',
            'en' => '/\b(the|and|you|that|have|this|with|your|about|would|just|like)\b/iu',
        ];
        $best = 'en';
        $bestN = 0;
        foreach ($votes as $lang => $re) {
            $n = preg_match_all($re, $sample);
            if ($n > $bestN) {
                $bestN = $n;
                $best = $lang;
            }
        }

        // Falling back to `und` sounded conservative and made the language
        // facet useless: measured on the live index, almost every English post
        // scored fewer than two stop-word hits (short posts, heavy slang) and
        // landed in `und`, so the facet offered one value that meant "unknown".
        // Script is the honest signal we DO have — Cyrillic was already decided
        // above, so anything reaching here is Latin, and on this board Latin
        // with no other evidence is English.
        return $bestN >= 2 ? $best : ($bestN === 1 ? $best : 'en');
    }
}
