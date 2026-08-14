<?php

namespace Local\Index;

/**
 * The classifier's vocabulary — DERIVED FROM THE CORPUS, not written by hand.
 *
 * ── Why this file is generated rather than typed ────────────────────────────
 *
 * A hand-written keyword list is the known failure mode on this project: it
 * encodes what somebody assumed the board talks about, and every term it misses
 * is invisible. So none of the terms below were chosen by looking at the section
 * names. They were extracted from the board itself, over 7,144 discussions
 * (title + the first 2,000 characters of the opening post, s9e markup stripped,
 * URLs removed, lowercased and accent-folded), by this procedure:
 *
 *  1. ANCHOR. For each section, the smallest set of terms that IS the section by
 *     definition — `peptid*` for Peptides, `softmax*` for Softmaxing, `steroid|
 *     anabolic|esteroide` for Anabólicos — plus, where the board already has a
 *     matching room, the tag itself (`f-27 Cosmetic Surgery` for
 *     Peligrosomaxing, `p-guide`/`f-9` for Mejores Guías). Nothing beyond that
 *     was assumed.
 *
 *  2. LIFT. Document-level log-odds of every unigram and bigram inside the
 *     anchor set against the rest of the corpus, +0.5 smoothed. This is what
 *     produced terms nobody would have guessed and that carry the section:
 *     `hexarelin`, `dac`, `cjc-1295`, `ghk-cu` (Peptides); `bsso`, `chin wing`,
 *     `orbital box`, `ramieri`, `ccw` (Peligrosomaxing); `mastic gum`,
 *     `tongue posture`, `hard mewing` (Softmaxing).
 *
 *  3. SECOND ROUND. The top-lift terms became a new anchor and the pass was
 *     repeated, which is where the long tail came from.
 *
 *  4. PURITY, MEASURED. For every candidate term, the fraction of documents
 *     containing it whose highest-scoring section is this one. Terms at >= 0.85
 *     purity over >= 4 documents were promoted to tier 4 — "definitional", one
 *     body mention is enough. Everything else needs a partner. Three terms were
 *     dropped on this evidence and are listed at the bottom of this comment.
 *
 * ── Weights ────────────────────────────────────────────────────────────────
 *
 * A term scores its tier when it appears in the BODY and double when it appears
 * in the TITLE, because a title is a claim about what the thread is and a body
 * mention is not. A term is counted once, at its highest tier. The default
 * threshold is 4, so exactly one of these is enough:
 *
 *   - one definitional term, anywhere;
 *   - one strong term in the title;
 *   - two strong terms in the body;
 *   - a strong term plus supporting evidence.
 *
 * ── What it measures out at ────────────────────────────────────────────────
 *
 * On the 7,144-discussion snapshot this was derived from, at threshold 4:
 *
 *   Peptides 133 · Anabólicos 155 · Softmaxing 320 · Looksmaxing 2,019 ·
 *   Peligrosomaxing 350 · Mejores Guías 1,035 — 3,343 of 7,144 discussions (47%)
 *
 * Recall, against every document mentioning that section's anchor terms
 * ANYWHERE (a lower bound: many of those legitimately belong to a neighbouring
 * section and are counted as misses here):
 *
 *   Softmaxing 70% · Peptides 64% · Anabólicos 70% · Peligrosomaxing 83%
 *
 * Precision, by reading 25 sampled titles per section with the matched terms
 * printed beside them: 88–92%. The residue is real overlap, not noise — a
 * thread about running HGH alongside trenbolone is genuinely both.
 *
 * The other 53% is the slice boundary and it is correct: it is dominated by
 * `f-3 Offtopic` (1,821), `f-8 Moneymaking` (821) and `f-7 Ratings` (604), none
 * of which belongs in any of the six sections. Those tags keep their own rooms.
 *
 * ── Terms deliberately removed, with the reason ────────────────────────────
 *
 *   `fin`            — Spanish is the default locale and `fin` is a Spanish word
 *   `melanotan`      — measured purity 0.00 over 3 documents
 *   `ratings`,       — these are the `f-7 Ratings` forum, which keeps its own
 *   `rate me`          room; pulling them into Looksmaxing moved 274 threads
 *                      out of the forum they were posted in
 *   `psl`            — demoted from definitional to supporting: it is the
 *                      board's rating scale and appears everywhere
 *
 * Re-derive with the pass in this file's commit message; re-run the classifier
 * with `php flarum lmx:index:sections --classify`. It is idempotent.
 */
class Lexicon
{
    /** section key => tier (4 highest) => terms */
    public const TERMS = [
        'peptides' => [
            // DEFINITIONAL — measured term purity >= 0.85 (see header). One body mention alone clears the threshold.
            4 => ['bpc 157', 'bpc-157', 'cjc 1295', 'cjc-1295', 'ghrp', 'ghrp-2', 'growth hormone',
                'hexarelin', 'igf 1', 'igf-1', 'igf1', 'mk 677', 'mk-677'],
            // STRONG — high log-odds lift, but shares docs with a neighbouring section. Needs a partner or a title hit.
            3 => ['epitalon', 'ghk-cu', 'ghrp-6', 'hgh', 'ipamorelin', 'mk677', 'peptide',
                'peptides', 'peptido', 'peptidos', 'semaglutide', 'sermorelin', 'somatropin',
                'tb-500', 'tb500', 'tesamorelin', 'tirzepatide'],
            // SUPPORTING — real signal, materially ambiguous on its own.
            2 => ['acromegaly', 'bacteriostatic', 'growth plates', 'heightmax', 'heightmaxxing',
                'pituitary', 'pubertymaxxing', 'reconstitute'],
            // WEAK — corroboration only. Four of these still only reach the threshold together.
            1 => ['iu per', 'mcg', 'stack', 'subcutaneous'],
        ],
        'anabolicos' => [
            // DEFINITIONAL — measured term purity >= 0.85 (see header). One body mention alone clears the threshold.
            4 => ['anabolic', 'steroid', 'trenbolone'],
            // STRONG — high log-odds lift, but shares docs with a neighbouring section. Needs a partner or a title hit.
            3 => ['aas', 'anabolico', 'anabolicos', 'anabolics', 'anavar', 'arimidex', 'aromasin',
                'aromatase', 'ciclo de', 'clomid', 'dbol', 'deca', 'dianabol', 'enclomiphene',
                'esteroide', 'esteroides', 'hcg', 'letrozole', 'lgd-4033', 'masteron',
                'nandrolone', 'nolvadex', 'ostarine', 'oxandrolone', 'pct', 'primobolan',
                'rad-140', 'rad140', 'roiding', 'roids', 'sarm', 'sarms', 'stanozolol', 'steroids',
                'sustanon', 'testosterona', 'testosterone', 'trembolona', 'trt', 'winstrol'],
            // SUPPORTING — real signal, materially ambiguous on its own.
            2 => ['aromatase inhibitor', 'blast and cruise', 'first cycle', 'injectable',
                'my cycle', 'on cycle', 'total testosterone', 'tren'],
            // WEAK — corroboration only. Four of these still only reach the threshold together.
            1 => ['androgenic', 'compound', 'dht', 'estrogen', 'free test', 'gear', 'natty'],
        ],
        'softmaxing' => [
            // DEFINITIONAL — measured term purity >= 0.85 (see header). One body mention alone clears the threshold.
            4 => ['cleanser', 'dermaroller', 'finasteride', 'hair regrowth', 'hard mewing',
                'mastic gum', 'microneedling', 'minox', 'minoxidil', 'moisturizer', 'retin-a',
                'retinol', 'salicylic', 'sebum', 'skin care', 'skincare routine', 'spf',
                'sunscreen', 'tongue posture', 'tretinoin'],
            // STRONG — high log-odds lift, but shares docs with a neighbouring section. Needs a partner or a title hit.
            3 => ['accutane', 'androgenic alopecia', 'benzoyl', 'chewing gum',
                'cuidado de la piel', 'derma roller', 'dermarolling', 'dutasteride',
                'isotretinoin', 'mew', 'mewing', 'niacinamide', 'red light therapy', 'skincare',
                'softmax', 'softmaxing', 'softmaxx', 'softmaxxed', 'softmaxxes', 'softmaxxing',
                'teeth whitening'],
            // SUPPORTING — real signal, materially ambiguous on its own.
            2 => ['braces', 'clear skin', 'eyebrows', 'eyelashes', 'grooming', 'hairline',
                'hygiene', 'oily skin', 'pores', 'retainer', 'sunbathing', 'tanning'],
            // WEAK — corroboration only. Four of these still only reach the threshold together.
            1 => ['bulking', 'cutting', 'diet', 'fashion', 'frame', 'gym', 'haircut', 'posture',
                'serum', 'sleep'],
        ],
        'looksmaxing' => [
            // DEFINITIONAL — measured term purity >= 0.85 (see header). One body mention alone clears the threshold.
            4 => ['canthal tilt', 'looksmax', 'looksmaxxed'],
            // STRONG — high log-odds lift, but shares docs with a neighbouring section. Needs a partner or a title hit.
            3 => ['facial aesthetics', 'halo effect', 'harmony', 'looksmaxeo', 'looksmaxing',
                'looksmaxx', 'looksmaxxer', 'looksmaxxing'],
            // SUPPORTING — real signal, materially ambiguous on its own.
            2 => ['ascension', 'cheekbones', 'eye area', 'htn', 'ltn', 'maxxing', 'mtn', 'psl',
                'subhuman'],
            // WEAK — corroboration only. Four of these still only reach the threshold together.
            1 => ['chad', 'facial', 'frame', 'jaw', 'mogged', 'mogs'],
        ],
        'peligrosomaxing' => [
            // DEFINITIONAL — measured term purity >= 0.85 (see header). One body mention alone clears the threshold.
            4 => ['anesthesia', 'bimax', 'bone smashing', 'botched', 'box osteotomy', 'bsso',
                'chin implant', 'chin wing', 'filler', 'genio', 'genioplasty', 'infraorbital',
                'jaw implants', 'jaw surgery', 'le fort', 'lefort', 'orbital box',
                'orbital decompression', 'osteotomy', 'surgeon'],
            // STRONG — high log-odds lift, but shares docs with a neighbouring section. Needs a partner or a title hit.
            3 => ['blepharoplasty', 'bonesmash', 'bonesmashing', 'botox', 'canthopexy',
                'canthoplasty', 'cirugia', 'cirujano', 'custom implant', 'eyelid retraction',
                'fillers', 'leg lengthening', 'lengthening surgery', 'limb lengthening', 'malar',
                'marpe', 'mentoplastia', 'rhinoplasty', 'rinoplastia', 'thumbpulling', 'zygomatic'],
            // SUPPORTING — real signal, materially ambiguous on its own.
            2 => ['ccw', 'consultation', 'implant', 'implants', 'post op', 'surgeries', 'surgery'],
            // WEAK — corroboration only. Four of these still only reach the threshold together.
            1 => ['advancement', 'procedure', 'recovery time', 'scar'],
        ],
    ];

    /**
     * Tag rules, which are evidence of a different kind: somebody already filed
     * the thread in a room that means the same thing.
     *
     * `f-27 Cosmetic Surgery` and `f-28 Fitness & Health` are scored like terms
     * because they are topical. `f-2`/`f-16`/`p-looksmax*` are NOT: they are the
     * general Looksmaxing forum, which is where most of the board lives, and
     * scoring them buried the specific sections underneath their own parent —
     * measured, 79 of 305 softmaxing-anchor threads landed in Looksmaxing
     * instead. They are a FALLBACK instead: applied only when no section reached
     * the threshold on its own, which is exactly "f-2 plus f-16 minus what the
     * other sections claim".
     */
    public const TAG_SCORES = [
        'f-27' => ['peligrosomaxing', 4],
        'f-28' => ['softmaxing', 3],
    ];

    public const FALLBACK_TAGS = ['f-2', 'f-16', 'p-looksmax', 'p-looksmaxxing'];

    public const FALLBACK_SECTION = 'looksmaxing';

    /**
     * Mejores Guías is orthogonal to the other five: a guide about peptides is
     * both, so this is scored separately and ADDS a second section rather than
     * competing. The board already answers most of it — `p-guide` (1,278 live)
     * and `f-9 Best of the Best` (151) are the source board's own judgement.
     *
     * Title-only for the word list, because "…a guide to…" in the body of a rant
     * is not a guide. Generic openers (`ultimate`, `compilation`, `how to`) were
     * tried and removed: they pulled in threads like "Ultimate Jews are Evil
     * Compilation", which is not a guide by any reading.
     */
    public const GUIDE_TAGS = ['p-guide' => 5, 'f-9' => 5];

    public const GUIDE_TITLE_TERMS = [
        'guide', 'guia', 'megathread', 'mega thread', 'tutorial', 'masterclass',
        'definitive guide', 'ultimate guide', 'complete guide',
    ];

    public const GUIDE_SECTION = 'mejores_guias';
}
