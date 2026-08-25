<?php

namespace Local\Economy;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Every number the ledger pays or refuses, as a setting instead of a constant.
 *
 * Before this file existed, an operator who wanted to reprice `guide.published`
 * from 25 to 30, or raise the `post.created` daily cap because a content push
 * was under way, needed a deploy: the numbers lived in `Ledger::AWARDS` and
 * `Ledger::DAILY_CAP`, both `private const`. A points economy is exactly the
 * kind of thing that needs to move same-day — the store's own Catalogue.php
 * carries the identical argument for why prices live in a table and not in
 * code ("an item that is being farmed has to be repriced the same hour") —
 * and an award rate is a price, just one nobody pays with a card.
 *
 * Same shape as looksmax-userinfo's Config: a KEYS map of
 * `key => [default, cast]`, one Flarum setting per key under the `economy.`
 * namespace, read through SettingsRepositoryInterface with the shipped
 * constant as the fallback when nothing has been set. The defaults below are
 * copied byte-for-byte from the constants Ledger.php and Streaks.php carried
 * before this file existed, so installing this change alone changes nothing
 * for an operator who has not opened the admin panel.
 *
 * What is deliberately NOT here:
 *
 *   - The rank ladder (`Ledger::RANKS`) — it stays in code because the real
 *     ladder lives in `Local\Ranks\Catalog`, versioned with the CSS that
 *     paints each rank colour. A threshold that can drift from its colour
 *     is a worse bug than a threshold that needs a deploy.
 *   - Anything store-side (the boost multiplier ceiling) — that lives in
 *     `Local\Store\Config` under `store.*`, because looksmax-store owns
 *     commerce and this extension only ever READS its boosts() output
 *     (see Ledger::tierModifiers()). Two extensions writing into one
 *     settings namespace is how a setting's owner becomes ambiguous.
 */
class Config
{
    /**
     * @var array<string, array{0: mixed, 1: string}> key => [default, cast]
     */
    public const KEYS = [
        // --- awards, one row per Ledger::AWARDS reason ---------------------
        // Every default here is the literal number Ledger::AWARDS shipped
        // with; see that file's header for the measurement each one is
        // weighted against (guide threads averaging 3,262 views vs. 13 replies
        // for an unprefixed thread, etc.) — the reasoning does not move to the
        // admin panel, only the number does.
        'award.discussionStarted'  => [5, 'int'],
        'award.postCreated'        => [2, 'int'],
        'award.reactionReceived'   => [4, 'int'],
        'award.reactionGiven'      => [1, 'int'],
        'award.bestAnswerAwarded'  => [40, 'int'],
        'award.guidePublished'     => [25, 'int'],
        'award.postReadThrough'    => [3, 'int'],
        'award.threadHeld'         => [6, 'int'],
        'award.guideSourced'       => [15, 'int'],
        'award.streakDay'          => [5, 'int'],
        'award.streakWeek'         => [40, 'int'],
        'award.importLegacy'       => [1, 'int'],
        'award.badgeEarned'        => [1, 'int'],

        // A NEW award, not a migrated one — see Ledger::refreshRank(). Paid
        // once per rank, the first time an account reaches it, as spendable
        // credit that deliberately does NOT count toward lifetime_points
        // (Ledger::credit(..., countsForRank: false)), because a bonus for
        // reaching a rank must not be able to buy the next one. 0 disables it
        // without disabling ranks themselves.
        'award.rankUp'              => [20, 'int'],

        // Also new — see Streaks::pay(). Paid once per milestone length, ever,
        // per account; see 'streak.milestones' below for which lengths.
        'award.streakMilestone'     => [50, 'int'],

        // --- daily caps, one row per Ledger::DAILY_CAP reason ---------------
        // A reason with no row here (best_answer.awarded, guide.published,
        // guide.sourced, import.legacy, badge.earned) was never capped and
        // stays that way: best answers and guides are moderated or reviewed
        // before they pay, so volume is already bounded by a human.
        'cap.postCreated'           => [40, 'int'],
        'cap.reactionGiven'         => [60, 'int'],
        'cap.reactionReceived'      => [200, 'int'],
        'cap.postReadThrough'       => [150, 'int'],
        'cap.threadHeld'            => [60, 'int'],

        // --- streaks ---------------------------------------------------------
        // Below this, a post is a one-word reply and cannot hold a streak
        // (Streaks::MIN_LENGTH before this file existed).
        'streak.minLength'          => [80, 'int'],
        // Every Nth consecutive day pays the weekly bonus on top of the daily
        // one (Streaks::pay(), `$current % 7 === 0`).
        'streak.weekEvery'          => [7, 'int'],
        // Comma-separated streak LENGTHS that pay a one-time celebration
        // bonus the first time an account reaches them. Ordered ascending is
        // not required — cast:'csv' below just filters blanks — but the UI
        // reads better that way. See Streaks::pay().
        'streak.milestones'         => ['7,30,100,365', 'csv'],

        // --- reactions --------------------------------------------------------
        // How many times one account can pay the same author in 24h
        // (AwardReaction::PAIR_DAILY_CAP before this file existed) — the limit
        // that actually stops a reciprocal like-ring, which a global daily cap
        // cannot touch. See that file's header for why.
        'reaction.pairDailyCap'     => [6, 'int'],
        // A reaction on a post older than this pays the author nothing
        // (AwardReaction::MAX_POST_AGE_DAYS).
        'reaction.maxPostAgeDays'   => [90, 'int'],

        // --- post effort weighting --------------------------------------------
        // AwardPost's multiplier is `max(min, min(max, length / divisor))`.
        // These three numbers were a hardcoded 0.25 / 2.0 / 400 with no
        // explanation of why an operator could not move them; now they can.
        'post.minMultiplier'        => [0.25, 'float'],
        'post.maxMultiplier'        => [2.0, 'float'],
        'post.multiplierDivisor'    => [400, 'int'],

        // --- quests -----------------------------------------------------------
        // See src/Quests.php for the full design. Which quests EXIST is code
        // (Quests::DEFS), same as which award reasons exist — but every target
        // and every reward is a setting, same pattern as award.* and cap.*
        // above, so a content push can retune "post 3 times" without a deploy.
        // `quest.enabled` is the kill switch: false hides the whole feature
        // (State::state() returns an empty list) without touching any other
        // award path.
        // --- ruleta -----------------------------------------------------------
        // Un giro por cuenta y dia. Ver src/Wheel.php: los premios y sus pesos
        // viven en codigo porque cambiarlos altera el valor esperado de la
        // economia entera, y eso no debe poder hacerse desde un formulario.
        'wheel.enabled'                    => [true, 'bool'],
        // Giros de pago, despues del gratuito del dia. 50 puntos contra un
        // valor esperado de 33.25 devuelve el 66%: 62% de los giros no dan
        // nada y el bote son 2500, cincuenta veces el coste. Pocos aciertos
        // y premios gordos, que es lo pedido. El tope diario existe para que
        // nadie se funda el saldo entero en una sesion.
        'wheel.cost'                       => [50, 'int'],
        'wheel.paid_max'                   => [5, 'int'],

        'quest.enabled'                    => [true, 'bool'],
        'quest.daily.post3.target'         => [3, 'int'],
        'quest.daily.post3.reward'         => [8, 'int'],
        'quest.daily.give3.target'         => [3, 'int'],
        'quest.daily.give3.reward'         => [5, 'int'],
        'quest.daily.received1.target'     => [1, 'int'],
        'quest.daily.received1.reward'     => [5, 'int'],
        'quest.daily.streak.target'        => [1, 'int'],
        'quest.daily.streak.reward'        => [6, 'int'],
        'quest.weekly.post15.target'       => [15, 'int'],
        'quest.weekly.post15.reward'       => [30, 'int'],
        'quest.weekly.thread1.target'      => [1, 'int'],
        'quest.weekly.thread1.reward'      => [20, 'int'],
        'quest.weekly.reactions25.target'  => [25, 'int'],
        'quest.weekly.reactions25.reward'  => [35, 'int'],
        'quest.weekly.streak7.target'      => [7, 'int'],
        'quest.weekly.streak7.reward'      => [40, 'int'],

        // --- early-adopter boost ---------------------------------------------
        // A launch-window earn multiplier that stacks on top of the membership
        // tier and any store boost (applied together in Ledger::tierModifiers()),
        // to reward the founding cohort while a young forum is still cold. It is
        // a no-op by default: `multiplier` 1.0 changes nothing, so shipping this
        // code alters not one award until an operator opens the setting — same
        // promise the rest of this file makes. It is gated two ways so it can
        // never run forever: an account qualifies only if it registered on or
        // before `until` (empty string = feature off, nobody qualifies), and —
        // when `windowDays` > 0 — only during its first N days after joining
        // (0 = no per-account time limit, the founding cohort keeps it).
        'earlyAdopter.multiplier'   => [1.0, 'float'],
        'earlyAdopter.until'        => ['', 'raw'],
        'earlyAdopter.windowDays'   => [30, 'int'],

        // --- founding member -------------------------------------------------
        // A one-time status program: the first `cap` accounts to register on or
        // before `until` are put in the Fundador group (`groupId`, a native
        // Flarum group so the badge needs no code) and paid a one-off `bonus`
        // as spendable credit that does NOT count toward rank. Off by default —
        // `enabled` false and `until` empty both stop it — because on a board
        // whose 30k accounts were imported from 2018 onward, "founder" means the
        // relaunch cohort, so the operator, not a join-date guess, sets the
        // window. See Listeners/FoundingMember.php. `count` below is not read as
        // a cap source (the listener counts live group members, which is
        // race-free and self-correcting); it exists only so the front page can
        // show "312 / 500 fundadores" without a query.
        'founding.enabled'          => [false, 'bool'],
        'founding.until'            => ['', 'raw'],
        'founding.cap'              => [500, 'int'],
        'founding.bonus'            => [100, 'int'],
        'founding.groupId'          => [0, 'int'],

        // --- referral loop ---------------------------------------------------
        // A member shares looksmax.lat/?ref=<their user id>; the ref rides a
        // cookie (InjectRefCapture) until the referee registers, at which point
        // the edge is recorded (lmx_referrals) and BOTH sides are paid. Off by
        // default (`enabled` false), so the migration can ship without the
        // program running. Two payouts, both countsForRank:false so a referral
        // ring cannot inflate rank: `joinBonus` immediately on signup, and the
        // larger `qualifyBonus` only once the referee posts `qualifyPosts`
        // times — the throwaway-account gate. Paid once each per referee via
        // the ledger's unique-ref idempotency (refs refj:/refq:<refereeId>).
        'referral.enabled'          => [false, 'bool'],
        'referral.joinBonus'        => [15, 'int'],
        'referral.qualifyBonus'     => [50, 'int'],
        'referral.qualifyPosts'     => [3, 'int'],
    ];

    public static function all(SettingsRepositoryInterface $settings): array
    {
        $out = [];

        foreach (self::KEYS as $key => [$default, $cast]) {
            $out[$key] = self::get($settings, $key);
        }

        return $out;
    }

    /** One key, resolved. Used directly by Ledger/Streaks so they need not re-read the whole map on every award. */
    public static function get(SettingsRepositoryInterface $settings, string $key)
    {
        [$default, $cast] = self::KEYS[$key] ?? [null, 'raw'];
        $raw = $settings->get('economy.' . $key);

        return ($raw === null || $raw === '') ? $default : self::cast($raw, $cast, $default);
    }

    /** Comma-separated streak milestone lengths, parsed to ints, sorted, deduped. */
    public static function streakMilestones(SettingsRepositoryInterface $settings): array
    {
        $csv = (string) self::get($settings, 'streak.milestones');
        $vals = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
        $vals = array_values(array_unique(array_filter($vals, fn ($n) => $n > 0)));
        sort($vals);

        return $vals;
    }

    private static function cast($raw, string $cast, $default)
    {
        if ($cast === 'int') {
            return (int) $raw;
        }
        if ($cast === 'float') {
            return (float) $raw;
        }
        if ($cast === 'bool') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $default;
        }
        if ($cast === 'csv') {
            $list = array_values(array_filter(array_map('trim', explode(',', (string) $raw))));

            return $list ? implode(',', $list) : $default;
        }

        return $raw;
    }
}
