<?php

namespace Local\Search\Search;

/**
 * How much semantics does THIS query want?
 *
 * Hybrid search is not free. Meilisearch's `semanticRatio` blends a keyword
 * ranking and a vector ranking, and the vector side cannot represent three
 * things a forum reader relies on constantly:
 *
 *   - **an exact string.** A username (`@Vitruvian`), a product (`Kirkland 5%`),
 *     a model number (`NW3`). The nearest neighbours of "NW3" in embedding
 *     space are NW2 and NW4, which are different diagnoses.
 *   - **negation.** `-mogging` means "not that". A vector has no NOT.
 *   - **a quoted phrase.** The user has already told us they want those words,
 *     adjacent, in that order. Semantics can only dilute that.
 *
 * So the ratio is chosen per query rather than configured once. The rule is
 * deliberately conservative in one direction: when there is any evidence of
 * exact-match intent, the ratio goes to zero and behaviour is byte-identical to
 * the keyword-only engine that already worked. Semantics is a widening
 * operation applied to queries that are asking a QUESTION, which is exactly the
 * class of query that keyword search is worst at and that a forum gets most of
 * ("como recuperarse de una rinoplastia" matches no title on this board).
 *
 * Every decision returns a `reason`, and the API echoes it, because "why did
 * this query get different results than yesterday" must be answerable without
 * reading this file.
 */
class SemanticPolicy
{
    /**
     * Tokens that look like an identifier rather than a word: anything holding
     * a digit (`nw3`, `1.5mg`, `2024`), or the site's short jargon codes where
     * one edit is a different meaning.
     */
    private const CODE_LIKE = '/[0-9]/u';

    /** @see IndexSettings::DICTIONARY — the same list, lowercased. */
    private const JARGON = [
        'psl', 'nw', 'ipd', 'fwhr', 'es', 'pfl', 'msv', 'll', 'nt', 'mtn',
        'smv', 'htn', 'jb', 'gh', 'bmi',
    ];

    /**
     * @param array  $parsed  QueryParser::parse() output
     * @param string $terms   the folded term string actually sent to the engine
     * @param float  $default the configured ratio for an ordinary query
     * @param float|null $override an explicit client request, if any
     *
     * @return array{ratio: float, reason: string}
     */
    public function decide(array $parsed, string $terms, float $default, ?float $override = null): array
    {
        // An explicit request wins. The UI lane owns a "more semantic" control
        // and a debugging client needs to be able to pin the value; both would
        // be defeated by a heuristic quietly overriding them.
        if ($override !== null) {
            return ['ratio' => max(0.0, min(1.0, $override)), 'reason' => 'explicit'];
        }

        $words = preg_split('/\s+/u', trim($terms), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($terms === '') {
            // A pure browse (filters only, no words). There is no query to
            // embed, and Meilisearch would return the whole index by distance
            // from an empty string.
            return ['ratio' => 0.0, 'reason' => 'no-terms'];
        }

        if (!empty($parsed['phrases'])) {
            return ['ratio' => 0.0, 'reason' => 'exact-phrase'];
        }

        if (!empty($parsed['negatives'])) {
            return ['ratio' => 0.0, 'reason' => 'negation'];
        }

        // A sort is a hard ordering. Blending two relevance rankings and then
        // throwing the ranking away is pure cost.
        if (!empty($parsed['sort'])) {
            return ['ratio' => 0.0, 'reason' => 'explicit-sort'];
        }

        // `by:someone` is a lookup, not a question.
        if (!empty($parsed['authors'])) {
            return ['ratio' => 0.0, 'reason' => 'author-filter'];
        }

        foreach ($words as $w) {
            if (preg_match(self::CODE_LIKE, $w)) {
                return ['ratio' => 0.0, 'reason' => 'code-like-token'];
            }
        }

        // One or two words, at least one of which is site jargon: the reader
        // knows the term and wants the threads that use it. Keyword search is
        // already excellent at this and synonyms already cover the variants.
        if (count($words) <= 2) {
            foreach ($words as $w) {
                if (in_array(mb_strtolower($w), self::JARGON, true)) {
                    return ['ratio' => 0.0, 'reason' => 'jargon-lookup'];
                }
            }
        }

        // A single short word is usually a topic jump ("mewing"), and the
        // keyword index plus synonyms handles it precisely. Give semantics a
        // small share so a term with no exact hits still lands somewhere, but
        // do not let it reorder a term that does match.
        if (count($words) === 1) {
            return ['ratio' => min($default, 0.2), 'reason' => 'single-term'];
        }

        // A natural-language question — the case keyword search is worst at.
        // Four or more words on a forum is almost always a sentence, and the
        // exact sentence appears in no title.
        if (count($words) >= 4) {
            return ['ratio' => min(1.0, $default + 0.25), 'reason' => 'question-like'];
        }

        return ['ratio' => $default, 'reason' => 'default'];
    }
}
