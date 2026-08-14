<?php

namespace Local\Index;

/**
 * Scores one discussion against the six sections.
 *
 * Pure: no database, no state. `Lexicon` holds the vocabulary and where it came
 * from; this holds only the arithmetic, so the two can be re-derived and
 * re-tested independently. The command drives it in batches.
 */
class Classifier
{
    /** Matches the derivation pass exactly — change one and the counts move. */
    public const DEFAULT_THRESHOLD = 4;

    /** @var array<string, array<int, string[]>> */
    private array $terms;

    /** @var array<string, string> pattern cache, term => regex */
    private array $re = [];

    public function __construct(private int $threshold = self::DEFAULT_THRESHOLD)
    {
        $this->terms = Lexicon::TERMS;
    }

    /**
     * @param string[] $tagSlugs slugs already on the discussion
     *
     * @return array<string, array{score:int, hits:string[]}> section key => evidence
     */
    public function classify(string $title, string $body, array $tagSlugs): array
    {
        $nTitle = self::normalise($title);
        $nBody = self::normalise($body);

        $scores = [];
        $hits = [];

        foreach ($this->terms as $section => $tiers) {
            foreach ($tiers as $weight => $terms) {
                foreach ($terms as $term) {
                    $inTitle = $this->match($term, $nTitle);
                    $inBody = $inTitle ? false : $this->match($term, $nBody);

                    if (! $inTitle && ! $inBody) {
                        continue;
                    }

                    // A title hit is a claim about what the thread IS; a body
                    // mention is a passing reference. Same term, double weight.
                    $scores[$section] = ($scores[$section] ?? 0) + $weight * ($inTitle ? 2 : 1);
                    $hits[$section][] = $term . ($inTitle ? '*' : '');
                }
            }
        }

        foreach (Lexicon::TAG_SCORES as $slug => [$section, $points]) {
            if (in_array($slug, $tagSlugs, true)) {
                $scores[$section] = ($scores[$section] ?? 0) + $points;
                $hits[$section][] = '#' . $slug;
            }
        }

        $out = [];

        // One topical section: the sections are meant to be somewhere to GO, and
        // a thread that appears in three of them is a thread the reader cannot
        // place. Ties break on the order the sections ship in.
        if ($scores) {
            arsort($scores);
            $best = array_key_first($scores);
            if ($scores[$best] >= $this->threshold) {
                $out[$best] = ['score' => $scores[$best], 'hits' => $hits[$best] ?? []];
            }
        }

        if (! $out) {
            foreach (Lexicon::FALLBACK_TAGS as $slug) {
                if (in_array($slug, $tagSlugs, true)) {
                    $out[Lexicon::FALLBACK_SECTION] = ['score' => 2, 'hits' => ['fallback:' . $slug]];
                    break;
                }
            }
        }

        // Guides are additive, not competitive — see Lexicon::GUIDE_TAGS.
        $g = 0;
        $gh = [];
        foreach (Lexicon::GUIDE_TAGS as $slug => $points) {
            if (in_array($slug, $tagSlugs, true)) {
                $g += $points;
                $gh[] = '#' . $slug;
            }
        }
        foreach (Lexicon::GUIDE_TITLE_TERMS as $term) {
            if ($this->match($term, $nTitle)) {
                $g += 3;
                $gh[] = $term . '*';
            }
        }
        if ($g >= $this->threshold) {
            $out[Lexicon::GUIDE_SECTION] = ['score' => $g, 'hits' => $gh];
        }

        return $out;
    }

    /**
     * Lowercase, strip s9e/XenForo markup and accents, keep word characters.
     *
     * Accent folding matters in both directions: the corpus mixes English,
     * Spanish, German, Turkish and Russian, and `Anabólicos`/`anabolicos` and
     * `cirugía`/`cirugia` must be the same token. The lexicon is stored folded,
     * so the two sides always agree.
     */
    public static function normalise(?string $s): string
    {
        $s = (string) $s;

        // s9e stores bbcode remnants in <s>/<e> and raw source in <CODE>; both
        // duplicate text that is already elsewhere and inflate every count.
        $s = preg_replace('#<s>.*?</s>|<e>.*?</e>|<CODE>.*?</CODE>#su', ' ', $s) ?? $s;
        $s = preg_replace('#<[^>]+>#u', ' ', $s) ?? $s;
        $s = preg_replace('#https?://\S+#u', ' ', $s) ?? $s;
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = mb_strtolower($s, 'UTF-8');

        if (function_exists('transliterator_transliterate')) {
            $t = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $s);
            if ($t !== false && $t !== null) {
                $s = $t;
            }
        } else {
            // intl is not guaranteed in the container; this covers the accents
            // that actually occur in the six languages present in the corpus.
            $s = strtr($s, [
                'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
                'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
                'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
                'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
                'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
                'ñ' => 'n', 'ç' => 'c', 'ş' => 's', 'ğ' => 'g', 'ı' => 'i',
            ]);
        }

        return ' ' . preg_replace('/\s+/u', ' ', $s) . ' ';
    }

    /**
     * Whole-token match. `(?<![a-z0-9])` rather than `\b` because the vocabulary
     * is full of hyphenated and numbered tokens — `mk-677`, `igf-1`, `rad-140`,
     * `lgd-4033` — where `\b` matches inside the token and would let `677` alone
     * count as `mk-677`. Space in a term also matches a hyphen, so `bone
     * smashing` and `bone-smashing` are one entry.
     */
    private function match(string $term, string $haystack): bool
    {
        if (! isset($this->re[$term])) {
            $this->re[$term] = '/(?<![a-z0-9])' . str_replace('\ ', '[\s-]', preg_quote($term, '/')) . '(?![a-z0-9])/u';
        }

        return (bool) preg_match($this->re[$term], $haystack);
    }
}
