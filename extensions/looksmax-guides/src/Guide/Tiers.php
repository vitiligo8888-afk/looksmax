<?php

namespace Local\Guides\Guide;

/**
 * Evidence tiers.
 *
 * The board's subject matter runs from skincare to prescription compounds to
 * elective surgery, and on the source board "I tried this and it worked" and
 * "a randomised trial found this" render identically. That is the single most
 * consequential defect in its guides, so tier is a first-class property of a
 * claim rather than a formatting choice.
 *
 * TX (marketing) exists deliberately. Vendor claims are the dominant failure
 * mode of this subject area and the honest move is to label them, not to ban
 * them — an unlabelled vendor claim is indistinguishable from a mechanism
 * argument, which is exactly how supplement marketing works.
 */
class Tiers
{
    public const ANECDOTE      = 0;
    public const COMMUNITY     = 1;
    public const MECHANISM     = 2;
    public const OBSERVATIONAL = 3;
    public const TRIAL         = 4;
    public const GUIDELINE     = 5;
    public const MARKETING     = 9; // "TX" — a vendor's own claim about its product

    /**
     * Weight per tier, used for the aggregate evidence score.
     *
     * Marketing is worth zero rather than negative: a guide that honestly
     * labels a vendor claim should not score worse than one that hides it,
     * or labelling becomes self-punishing and nobody does it.
     */
    public const WEIGHT = [
        self::ANECDOTE      => 0.05,
        self::COMMUNITY     => 0.15,
        self::MECHANISM     => 0.35,
        self::OBSERVATIONAL => 0.65,
        self::TRIAL         => 0.90,
        self::GUIDELINE     => 1.00,
        self::MARKETING     => 0.00,
    ];

    public const LABEL = [
        self::ANECDOTE      => 'Anecdote',
        self::COMMUNITY     => 'Community report',
        self::MECHANISM     => 'Mechanism',
        self::OBSERVATIONAL => 'Observational',
        self::TRIAL         => 'Trial',
        self::GUIDELINE     => 'Guideline',
        self::MARKETING     => 'Marketing',
    ];

    public const SHORT = [
        self::ANECDOTE      => 'T0',
        self::COMMUNITY     => 'T1',
        self::MECHANISM     => 'T2',
        self::OBSERVATIONAL => 'T3',
        self::TRIAL         => 'T4',
        self::GUIDELINE     => 'T5',
        self::MARKETING     => 'TX',
    ];

    /**
     * Tiers at or above this level are a promise about a source, so they must
     * carry one. Enforced at save time, not at render time — a claim that
     * silently downgrades in the reader's browser teaches the author nothing.
     */
    public const SOURCE_REQUIRED_FROM = self::OBSERVATIONAL;

    public static function valid(int $tier): bool
    {
        return array_key_exists($tier, self::WEIGHT);
    }

    public static function normalise(int $tier): int
    {
        return self::valid($tier) ? $tier : self::ANECDOTE;
    }

    /**
     * Aggregate 0..1 score from a tier => count profile.
     *
     * Deliberately never rendered as a number. The board's own user titles
     * include "rep me for +1 PSL" — any displayed number is a target, and the
     * target here would be "put T4 on everything". The stacked bar built from
     * the same profile is much harder to fake convincingly because faking it
     * requires the claims to actually be there.
     */
    public static function score(array $profile): ?float
    {
        $n = 0;
        $sum = 0.0;

        foreach ($profile as $tier => $count) {
            $tier = (int) $tier;
            $count = (int) $count;
            if (!self::valid($tier) || $count <= 0) {
                continue;
            }
            $n += $count;
            $sum += self::WEIGHT[$tier] * $count;
        }

        return $n > 0 ? round($sum / $n, 3) : null;
    }
}
