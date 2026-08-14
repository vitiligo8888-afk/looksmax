<?php

namespace Local\Search\Meili;

/**
 * The index settings, as data.
 *
 * Kept declarative and in one file so the settings that are actually live can
 * be diffed against the settings we intended — `php flarum search:settings
 * --diff` does exactly that. Settings drift is the classic way a search index
 * quietly stops matching what the code assumes.
 *
 * Reasoning that is not obvious from the values:
 *
 * - **No stop words.** Meilisearch's `stopWords` is a hard removal at both
 *   index and query time, so a stop-worded term stops existing: `"the who"`
 *   and `"to be or not to be"` become unsearchable, and a phrase query cannot
 *   recover them. The default ranking already demotes ubiquitous terms via the
 *   `words` and `proximity` rules, which is the behaviour we want, so the list
 *   stays empty deliberately rather than by omission.
 *
 * - **`localizedAttributes` over one global language.** The corpus is genuinely
 *   mixed — English, Russian, Turkish, Spanish, German, French all appear, and
 *   frequently inside the same thread. Meilisearch auto-detects language per
 *   document, and on short documents auto-detection is unreliable; declaring
 *   the candidate locale set narrows detection to languages we actually have
 *   instead of letting a two-word Turkish title be classified as something with
 *   different segmentation rules.
 *
 * - **`rank_score` as the final ranking rule, not `sort`.** `sort` is a hard
 *   ordering that overrides textual relevance from the position it occupies.
 *   A custom ranking rule placed after `exactness` only breaks ties between
 *   documents that already matched equally well, which is what "surface the
 *   better thread when relevance is equal" actually means. The score itself is
 *   materialised at index time (see DocumentBuilder) so no query pays for it.
 *
 * - **`attribute` before `sort`/`exactness`, with an ordered
 *   `searchableAttributes`.** A hit in the title should outrank a hit in the
 *   body of a 400-post thread. That is entirely a function of attribute order.
 */
class IndexSettings
{
    /**
     * Domain vocabulary. Two-way unless the mapping is genuinely directional.
     * These come from the corpus, not from imagination: every left-hand term
     * appears in the scraped tag names, prefixes or thread titles.
     */
    public const SYNONYMS = [
        'looksmax' => ['looksmaxing', 'looksmaxxing', 'looksmaxx'],
        'looksmaxing' => ['looksmax', 'looksmaxxing', 'looksmaxx'],
        'looksmaxxing' => ['looksmax', 'looksmaxing', 'looksmaxx'],
        'mog' => ['mogging', 'mogged', 'mogger'],
        'mogging' => ['mog', 'mogged'],
        'psl' => ['pslgod', 'psl god'],
        'nt' => ['normie tier', 'normietier'],
        'jaw' => ['jawline', 'mandible', 'gonial'],
        'jawline' => ['jaw', 'mandible'],
        'chin' => ['mentum', 'genio', 'genioplasty'],
        'nose' => ['rhinoplasty', 'nosejob', 'nose job'],
        'rhinoplasty' => ['nose job', 'nosejob', 'nose'],
        'eyes' => ['canthal', 'canthoplasty', 'eye area', 'pfl'],
        'hair' => ['hairline', 'norwood', 'nw', 'balding'],
        'minox' => ['minoxidil'],
        'minoxidil' => ['minox'],
        'fin' => ['finasteride', 'finas'],
        'finasteride' => ['fin', 'finas'],
        'tret' => ['tretinoin', 'retin-a', 'retina'],
        'tretinoin' => ['tret', 'retin-a'],
        'acc' => ['accutane', 'isotretinoin'],
        'accutane' => ['acc', 'isotretinoin', 'roaccutane'],
        'gym' => ['lifting', 'training', 'workout'],
        'bulk' => ['bulking', 'gaining'],
        'cut' => ['cutting', 'shredding'],
        'height' => ['limb lengthening', 'll', 'lengthening'],
        'll' => ['limb lengthening', 'lengthening'],
        'bonesmash' => ['bone smashing', 'bonesmashing'],
        'sunscreen' => ['spf', 'sunblock'],
        'spf' => ['sunscreen', 'sunblock'],
        'guide' => ['tutorial', 'how to', 'howto'],
        'blackpill' => ['black pill', 'blackpilled'],
        'whitepill' => ['white pill', 'whitepilled'],
        'bluepill' => ['blue pill', 'bluepilled'],
        'jfl' => ['lmao', 'lol'],
        'ratings' => ['rate', 'rate me', 'rating'],
        'surgery' => ['surgical', 'ops', 'operation'],
    ];

    /**
     * The locales present in the corpus, ISO 639-3 as Meilisearch expects.
     * Kept short: every extra locale widens auto-detection's search space and
     * makes a wrong classification more likely, not less.
     */
    public const LOCALES = ['eng', 'rus', 'tur', 'spa', 'deu', 'fra'];

    /**
     * German is the only one of our six languages that needs its own rule, and
     * it needs one for a reason that is not in the documentation.
     *
     * charabia's `GermanSegmenter` — which splits `Hautpflege` into `haut` +
     * `pflege` — only runs when the detected language is exactly `Deu`. And
     * `StrDetection::language()` gives up on Latin-script text when the
     * allow-list has more than one candidate: with six locales in one rule,
     * German is never *certainly* German, so the segmenter never fires.
     *
     * Measured on the 2.2M-document index with a single six-locale rule:
     *
     *     hautpflege → 4,237 hits        pflege → 19 hits
     *
     * If compounds were being split, every one of those 4,237 documents would
     * also answer `pflege`. Nineteen do. So German search was, in practice,
     * exact-compound-match only — which for German is most of the language.
     *
     * The fix is a dedicated `*_de` field carrying the same text, pinned to a
     * SINGLE locale so detection is skipped entirely and the segmenter is
     * reached. Rule order matters and is load-bearing: milli stops at the first
     * matching pattern, so the specific `*_de` rule must precede the general
     * `*_s` one or it would never be consulted.
     */
    public const LOCALIZED = [
        ['attributePatterns' => ['*_de'], 'locales' => ['deu']],
        ['attributePatterns' => ['*_s'], 'locales' => self::LOCALES],
    ];

    /**
     * Tokens that must not split a term. Without these, `retin-a` becomes two
     * words and `-a` becomes noise; `nw3` and `1.5mg` stay whole.
     */
    public const NON_SEPARATOR_TOKENS = ['-', '_', '+', '.', "'"];

    /**
     * Terms Meilisearch must keep whole rather than segment. Short, uppercase
     * jargon is exactly what its segmenter gets wrong.
     */
    public const DICTIONARY = [
        'PSL', 'NW', 'IPD', 'FWHR', 'ES', 'PFL', 'MSV', 'LL', 'NT', 'MTN',
        'SMV', 'HTN', 'JB', 'GH', 'BMI', 'V-taper', 'retin-a', 'e-girl',
    ];

    public static function discussions(): array
    {
        return [
            // Order IS the attribute ranking rule. Title first, then the terms
            // an author chose (tags), then the opening post, then everything.
            // No `body` field, deliberately. Concatenating every post into the
            // discussion document would store the entire corpus twice and make
            // the largest index the one that has to stay hot. The post index
            // already holds that text; a discussion whose *body* matches is
            // found by searching posts with `distinct: discussion_id`, which
            // returns one hit per thread ranked by its best post. Federated
            // multi-search then merges title hits and body hits into one list.
            // The `_s` fields are the FOLDED copies (see Text::fold): they are
            // what gets tokenised, and the un-suffixed originals are displayed
            // but never searched. One tokenisation, author's spelling preserved
            // on screen. `_de` carries the same text again for German-detected
            // documents only — see `localizedAttributes` below for why that is
            // the only way to get compound splitting.
            'searchableAttributes' => [
                'title_s',
                'title_de',
                'tag_names',
                'author',
                'excerpt_s',
                'excerpt_de',
            ],
            // Enumerated rather than `['*']` so the folded copies never travel
            // over the wire. They are an indexing artefact; shipping them would
            // roughly double every search response for no reader benefit.
            'displayedAttributes' => [
                'id', 'title', 'slug', 'excerpt', 'tag_ids', 'tag_slugs', 'tag_names',
                'tag_colors', 'primary_tag', 'prefixes', 'restricted_tag_ids',
                'author_id', 'author', 'created_at', 'last_post_at', 'comment_count',
                'participant_count', 'views', 'reactions', 'length', 'is_sticky',
                'is_locked', 'is_private', 'is_hidden', 'is_approved', 'is_guide',
                'has_images', 'has_best_answer', 'lang', 'rank_score',
            ],
            'filterableAttributes' => [
                // `id` is NOT filterable by default just because it is the
                // primary key — Meilisearch requires it to be declared like any
                // other attribute. Three things need it and all three were
                // broken or impossible without it: the `discussion:` search
                // operator (Engine::filtersFor emits `id = N` for the
                // discussions index), excluding the seed thread from its own
                // related-topics strip, and `id IN [...]` for reading stored
                // vectors back out for recommendations.
                'id',
                'tag_ids', 'tag_slugs', 'tag_names', 'primary_tag', 'prefixes',
                'author_id', 'author',
                'created_at', 'last_post_at',
                'reactions', 'comment_count', 'participant_count', 'views', 'length',
                'is_sticky', 'is_locked', 'is_private', 'is_hidden', 'is_approved',
                // Advanced-search facets. Dates are stored as unix timestamps
                // (integers) rather than formatted strings precisely so that
                // `created_at > 1750000000` is a range filter rather than a
                // lexicographic comparison that silently does the wrong thing
                // across a year boundary.
                'is_guide', 'has_images', 'has_best_answer', 'lang', 'rank_score',
            ],
            'sortableAttributes' => [
                'created_at', 'last_post_at', 'reactions', 'comment_count',
                'participant_count', 'views', 'length', 'rank_score',
            ],
            'rankingRules' => [
                'words', 'typo', 'proximity', 'attribute', 'sort', 'exactness',
                'rank_score:desc',
            ],
            'stopWords' => [],
            'synonyms' => self::SYNONYMS,
            'nonSeparatorTokens' => self::NON_SEPARATOR_TOKENS,
            'dictionary' => self::DICTIONARY,
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                // Jargon where a "typo" is a different thing entirely. `nw2`
                // and `nw3` are one edit apart and mean different diagnoses.
                'disableOnWords' => ['psl', 'nw', 'nw1', 'nw2', 'nw3', 'nw4', 'nw5', 'nw6', 'nw7', 'ipd', 'es', 'pfl', 'nt', 'jfl'],
                'disableOnNumbers' => true,
            ],
            'faceting' => [
                'maxValuesPerFacet' => 200,
                'sortFacetValuesBy' => ['*' => 'count'],
            ],
            'pagination' => ['maxTotalHits' => 10000],
            'localizedAttributes' => self::LOCALIZED,
            // Word-level proximity is materially more expensive to index than
            // attribute-level and only changes ordering within one attribute.
            // Kept exact for discussions (few, short) — see posts() for why the
            // post index makes the opposite trade.
            'proximityPrecision' => 'byWord',
            'searchCutoffMs' => 1500,
        ];
    }

    public static function posts(): array
    {
        return [
            'searchableAttributes' => ['content_s', 'content_de', 'discussion_title_s', 'author'],
            'displayedAttributes' => [
                'id', 'discussion_id', 'discussion_title', 'discussion_slug', 'number',
                'content', 'author_id', 'author', 'created_at', 'reactions', 'length',
                'tag_ids', 'tag_slugs', 'prefixes', 'restricted_tag_ids',
                'is_hidden', 'is_private', 'is_approved', 'is_first', 'lang', 'rank_score',
            ],
            'filterableAttributes' => [
                'discussion_id', 'tag_ids', 'tag_slugs', 'prefixes',
                'author_id', 'author', 'created_at', 'reactions', 'length',
                'number', 'is_hidden', 'is_approved', 'is_private', 'is_first', 'lang',
            ],
            'sortableAttributes' => ['created_at', 'reactions', 'length', 'number', 'rank_score'],
            'rankingRules' => [
                'words', 'typo', 'proximity', 'attribute', 'sort', 'exactness',
                'rank_score:desc',
            ],
            'stopWords' => [],
            'synonyms' => self::SYNONYMS,
            'nonSeparatorTokens' => self::NON_SEPARATOR_TOKENS,
            'dictionary' => self::DICTIONARY,
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                'disableOnNumbers' => true,
            ],
            'faceting' => ['maxValuesPerFacet' => 200, 'sortFacetValuesBy' => ['*' => 'count']],
            'pagination' => ['maxTotalHits' => 10000],
            'localizedAttributes' => self::LOCALIZED,
            // NOTE: `distinctAttribute` is deliberately NOT set here. Collapsing
            // to one post per thread is right for the discussion-oriented view
            // and wrong for the Posts tab and for search-within-thread, where
            // the user is asking for every matching post. It is applied as the
            // per-query `distinct` search parameter instead, so one index can
            // serve both. An index-level setting could not.
            // The post index is the one that reaches tens of millions of
            // documents. `byAttribute` drops the positional index that `byWord`
            // maintains, which is the single biggest lever on index size and
            // indexing throughput here, and costs only intra-attribute
            // proximity ordering — a tie-break we can afford to lose on posts
            // because the discussion index still ranks by word proximity.
            'proximityPrecision' => 'byAttribute',
            'searchCutoffMs' => 1500,
        ];
    }

    public static function users(): array
    {
        return [
            'searchableAttributes' => ['username', 'display_name', 'bio'],
            'filterableAttributes' => ['group_ids', 'group_names', 'joined_at', 'posts_count', 'is_suspended'],
            'sortableAttributes' => ['joined_at', 'posts_count', 'points', 'rank_score'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'rank_score:desc'],
            'typoTolerance' => ['enabled' => true, 'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8]],
            'searchCutoffMs' => 800,
        ];
    }

    public static function tags(): array
    {
        return [
            'searchableAttributes' => ['name', 'slug', 'description', 'parent_name'],
            'filterableAttributes' => ['parent_id', 'is_child', 'is_prefix', 'is_restricted'],
            'sortableAttributes' => ['discussion_count', 'position'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'discussion_count:desc'],
            'searchCutoffMs' => 500,
        ];
    }

    public static function for(string $kind): array
    {
        return match ($kind) {
            'discussions' => self::discussions(),
            'posts' => self::posts(),
            'users' => self::users(),
            'tags' => self::tags(),
            default => throw new \InvalidArgumentException("unknown index kind: $kind"),
        };
    }

    public const KINDS = ['discussions', 'posts', 'users', 'tags'];
}
