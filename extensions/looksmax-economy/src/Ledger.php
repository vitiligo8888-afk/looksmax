<?php

namespace Local\Economy;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Award rules, spending, and the ladder the two feed.
 *
 * The source board runs a flat "Reputation" number that is really just a
 * reaction tally, which is trivially farmed by posting high volumes of low
 * effort replies. Its own data shows the problem: 241,002 of 461,662 threads
 * carry no prefix and average 13 replies, while 8,273 Guide threads average
 * 3,262 views. Volume and value are not the same thing, so awards here are
 * weighted by what the content actually earned, and repeated cheap actions
 * decay within a day.
 *
 * Two invariants this file exists to hold:
 *
 *   1. `points` is a spendable balance, `lifetime_points` is a record. Rank is
 *      computed from lifetime_points ONLY. Buying a name colour must never
 *      demote you, and a store that costs you standing is a store nobody uses.
 *   2. Every movement is a row. A balance that cannot be explained line by line
 *      cannot be defended when somebody accuses somebody else of farming.
 */
class Ledger
{
    /**
     * Base award per reason — the DEFAULTS, now. The numbers themselves live
     * in Config::KEYS under `economy.award.*` and are editable from the admin
     * panel without a deploy; this constant is what a fresh install (or a
     * setting nobody has touched) falls back to, and it is the thing
     * Config::KEYS's comments cite when explaining each default's provenance.
     * See AWARD_KEYS below for the reason -> setting-key mapping.
     */
    public const AWARDS = [
        'discussion.started'  => 5,
        'post.created'        => 2,
        'reaction.received'   => 4,
        'reaction.given'      => 1,   // small, so giving is encouraged but not farmable
        'best_answer.awarded' => 40,
        'guide.published'     => 25,  // posting into a guide tag, reviewed

        // Quality signals from the analytics stream. These are the reason this
        // is not just another vote counter: a post can be liked a thousand
        // times for one-liners and never once be read to the end, and only the
        // dwell/read-depth events can tell the two apart.
        'post.read_through'   => 3,   // a distinct reader reached the bottom of your thread
        'thread.held'         => 6,   // a reader spent 3+ minutes in your thread
        'guide.sourced'       => 15,  // a claim in your guide carries a real source

        // Habit. Small, capped, and impossible to farm because they are
        // per-day, not per-action.
        'streak.day'          => 5,
        'streak.week'         => 40,

        // Carried in from the source board at import. See identity-notes.
        'import.legacy'       => 1,
        'badge.earned'        => 1,
    ];

    /** Ledger reason -> Config::KEYS suffix under `award.*`. */
    private const AWARD_KEYS = [
        'discussion.started'  => 'discussionStarted',
        'post.created'        => 'postCreated',
        'reaction.received'   => 'reactionReceived',
        'reaction.given'      => 'reactionGiven',
        'best_answer.awarded' => 'bestAnswerAwarded',
        'guide.published'     => 'guidePublished',
        'post.read_through'   => 'postReadThrough',
        'thread.held'         => 'threadHeld',
        'guide.sourced'       => 'guideSourced',
        'streak.day'          => 'streakDay',
        'streak.week'         => 'streakWeek',
        'import.legacy'       => 'importLegacy',
        'badge.earned'        => 'badgeEarned',
    ];

    /**
     * Fallback ladder.
     *
     * The real ladder lives in Local\Ranks\Catalog, which owns the identity
     * layer and versions the ladder alongside the CSS that paints it. This copy
     * exists only so the economy still resolves a rank if the identity
     * extension is not installed — it is never the source of truth when it is.
     */
    public const RANKS = [
        ['slug' => 'greycel',  'name' => 'Greycel',  'min' => 0],
        ['slug' => 'iron',     'name' => 'Iron',     'min' => 150],
        ['slug' => 'bronze',   'name' => 'Bronze',   'min' => 700],
        ['slug' => 'silver',   'name' => 'Silver',   'min' => 2500],
        ['slug' => 'gold',     'name' => 'Gold',     'min' => 7500],
        ['slug' => 'platinum', 'name' => 'Platinum', 'min' => 20000],
        ['slug' => 'diamond',  'name' => 'Diamond',  'min' => 45000],
        ['slug' => 'master',   'name' => 'Master',   'min' => 90000],
        ['slug' => 'luminary', 'name' => 'Luminary', 'min' => 125000],
        ['slug' => 'ascended', 'name' => 'Ascended', 'min' => 250000],
    ];

    /**
     * Awards of the same reason beyond this many in 24h are worth nothing.
     * Defaults; see DAILY_CAP_KEYS and Config::KEYS's `cap.*` entries.
     */
    private const DAILY_CAP = [
        'post.created' => 40,
        'reaction.given' => 60,
        'reaction.received' => 200,
        'post.read_through' => 150,
        'thread.held' => 60,
    ];

    /** Ledger reason -> Config::KEYS suffix under `cap.*`. */
    private const DAILY_CAP_KEYS = [
        'post.created' => 'postCreated',
        'reaction.given' => 'reactionGiven',
        'reaction.received' => 'reactionReceived',
        'post.read_through' => 'postReadThrough',
        'thread.held' => 'threadHeld',
    ];

    /** Reasons that are seeded in bulk and must not be rate limited. */
    private const UNCAPPED = ['import.legacy', 'badge.earned', 'streak.day', 'streak.week'];

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    /**
     * Credit a user. Idempotent on (user, reason, ref) so replaying an import
     * or re-firing an event cannot inflate a balance.
     *
     * The membership tier's earn multiplier and cap boost are applied here,
     * which is the single place they can be applied without every call site
     * having to remember to.
     */
    public function award(int $userId, string $reason, ?string $ref = null, ?int $actorId = null, float $multiplier = 1.0): int
    {
        $key = self::AWARD_KEYS[$reason] ?? null;
        $base = $key !== null ? (int) Config::get($this->settings, 'award.' . $key) : 0;
        if ($base === 0 || $userId <= 0) {
            return 0;
        }

        [$earn, $capBoost] = $this->tierModifiers($userId);

        if ($this->overDailyCap($userId, $reason, $capBoost)) {
            return 0;
        }

        $delta = (int) round($base * $multiplier * $earn);
        if ($delta === 0) {
            return 0;
        }

        try {
            $this->db->table('economy_transactions')->insert([
                'user_id' => $userId,
                'delta' => $delta,
                'reason' => $reason,
                'ref' => $ref,
                'actor_id' => $actorId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return 0; // unique violation: already awarded for this ref
        }

        $this->db->table('users')->where('id', $userId)->update([
            'points' => $this->db->raw("points + {$delta}"),
            'lifetime_points' => $this->db->raw('lifetime_points + ' . max(0, $delta)),
        ]);

        $this->refreshRank($userId);

        return $delta;
    }

    /**
     * Debit a balance. Returns the negative delta written, or 0 if the user
     * could not afford it.
     *
     * Deliberately NOT the inverse of award(): a spend does not reduce
     * lifetime_points, so it cannot cost you a rank, and it is checked against
     * the live balance inside a transaction so two tabs cannot buy the same
     * thing twice with one balance.
     */
    public function spend(int $userId, int $amount, string $reason, ?string $ref = null): int
    {
        $amount = (int) abs($amount);
        if ($amount === 0) {
            return 0;
        }

        return (int) $this->db->transaction(function () use ($userId, $amount, $reason, $ref) {
            $balance = (int) $this->db->table('users')->where('id', $userId)
                ->lockForUpdate()->value('points');

            if ($balance < $amount) {
                return 0;
            }

            try {
                $this->db->table('economy_transactions')->insert([
                    'user_id' => $userId,
                    'delta' => -$amount,
                    'reason' => $reason,
                    'ref' => $ref,
                    'actor_id' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                // The ledger is UNIQUE on (user, reason, ref), which is what
                // makes awards idempotent. A spend that reuses a ref hits it and
                // used to escape as a 500 — buying VIP a second time to renew it
                // returned "Integrity constraint violation" to the buyer. A
                // caller that means "again" must vary the ref; a caller that
                // means "once" gets a clean refusal here instead of a stack
                // trace, and no money moves either way.
                return 0;
            }

            $this->db->table('users')->where('id', $userId)
                ->update(['points' => $this->db->raw("points - {$amount}")]);

            return -$amount;
        });
    }

    /**
     * Credit an exact amount, bypassing the reason table, the tier multiplier
     * and the daily caps.
     *
     * For refunds and for badge payouts, where the number is already decided
     * and running it through award() would silently multiply it by the buyer's
     * tier bonus — a refund of 20,000 that returns 28,000 is a money printer.
     */
    public function credit(int $userId, int $amount, string $reason, ?string $ref = null, bool $countsForRank = true): int
    {
        $amount = (int) $amount;
        if ($amount === 0 || $userId <= 0) {
            return 0;
        }

        try {
            $this->db->table('economy_transactions')->insert([
                'user_id' => $userId,
                'delta' => $amount,
                'reason' => $reason,
                'ref' => $ref,
                'actor_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return 0; // already credited for this ref
        }

        $this->db->table('users')->where('id', $userId)->update([
            'points' => $this->db->raw("points + {$amount}"),
            'lifetime_points' => $this->db->raw(
                'lifetime_points + ' . ($countsForRank ? max(0, $amount) : 0)
            ),
        ]);

        if ($countsForRank) {
            $this->refreshRank($userId);
        }

        return $amount;
    }

    /** Reverse an award, used when a post is deleted or a reaction removed. */
    public function revoke(int $userId, string $reason, string $ref): void
    {
        $rows = $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->where('reason', $reason)
            ->where('ref', $ref)
            ->get();

        foreach ($rows as $row) {
            $this->db->table('users')->where('id', $userId)
                ->update([
                    'points' => $this->db->raw("points - {$row->delta}"),
                    // a revoked award must also come off the lifetime record,
                    // otherwise deleting a farmed post leaves the rank it bought
                    'lifetime_points' => $this->db->raw('GREATEST(0, lifetime_points - ' . max(0, (int) $row->delta) . ')'),
                ]);
        }

        $this->db->table('economy_transactions')
            ->where('user_id', $userId)->where('reason', $reason)->where('ref', $ref)->delete();

        $this->refreshRank($userId);
    }

    /** Recent movements for a user, newest first. Powers the points history UI. */
    public function history(int $userId, int $limit = 40): array
    {
        return $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->orderByDesc('id')->limit($limit)
            ->get(['delta', 'reason', 'ref', 'created_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * The ladder, resolved once per worker process rather than once per call.
     *
     * `class_exists()` itself is cheap, but this method is now called from
     * UserSerializer's attribute callback — a callback that runs once per
     * user on any page that lists more than one (member list, leaderboard,
     * chat, mentions), and unlike the `identity` short-circuit above, WHICH
     * rank of two competing extenders runs first on a given user is decided
     * by extension load order, not by this file, so the fallback branch
     * cannot be assumed dead code just because looksmax-ranks is installed.
     * A ladder that is 10 fixed arrays never changes within a request — or
     * within a worker's lifetime, since neither RANKS constant is mutable —
     * so resolving it once and reusing the same reference is strictly safer
     * than repeating the class_exists() + property lookup per row for no
     * behavioural difference.
     */
    private static ?array $ladderCache = null;

    public static function ladder(): array
    {
        if (self::$ladderCache === null) {
            self::$ladderCache = class_exists(\Local\Ranks\Catalog::class)
                ? \Local\Ranks\Catalog::RANKS
                : self::RANKS;
        }

        return self::$ladderCache;
    }

    public function ranks(): array
    {
        return self::ladder();
    }

    public function rankFor(int $points): array
    {
        return self::rankForPoints($points);
    }

    public static function rankForPoints(int $points): array
    {
        $ranks = self::ladder();
        $rank = $ranks[0];
        foreach ($ranks as $r) {
            if ($points >= $r['min']) {
                $rank = $r;
            }
        }

        return $rank;
    }

    /**
     * How close an account is to the NEXT rank, for the progress bar this
     * extension previously had no way to draw — every other surface (the
     * store's tier cards, the identity layer's own richer `identity`
     * attribute) already shows a member what they own; nothing showed them
     * what they were working toward, which is the more motivating number for
     * the 95% of accounts who own nothing yet. Only used as the FALLBACK on
     * UserSerializer, same rule as points/lifetimePoints/rankSlug above: if
     * looksmax-ranks is installed its own `identity` attribute already
     * carries next-rank data (Standing.php `nextRank`) and this is not
     * duplicated (when it runs first — see ladder()'s comment on why that is
     * not guaranteed).
     *
     * STATIC, and deliberately touches neither $this->db nor $this->settings:
     * every input is a plain int and every output is derived from ladder(),
     * which is pure and memoized. This is what makes it safe to call directly
     * from UserSerializer's attribute callback — see extend.php — without
     * resolving a full Ledger instance (and its ConnectionInterface /
     * SettingsRepositoryInterface dependencies) once per user on the page.
     *
     * @return array{rankSlug:string,nextRankSlug:?string,pointsToNextRank:?int,rankProgressPct:int}
     */
    public static function progressFor(int $lifetimePoints): array
    {
        $ranks = self::ladder();
        $current = self::rankForPoints($lifetimePoints);

        $idx = null;
        foreach ($ranks as $i => $r) {
            if ($r['slug'] === $current['slug']) {
                $idx = $i;
                break;
            }
        }

        $next = $idx !== null && isset($ranks[$idx + 1]) ? $ranks[$idx + 1] : null;

        if ($next === null) {
            // Top of the ladder. 100%, not 0/0 — a progress bar that reads
            // "0 of 0 points to go" at the top rank looks broken; full and
            // still is the honest picture.
            return [
                'rankSlug' => $current['slug'],
                'nextRankSlug' => null,
                'pointsToNextRank' => null,
                'rankProgressPct' => 100,
            ];
        }

        $span = max(1, $next['min'] - $current['min']);
        $into = max(0, $lifetimePoints - $current['min']);
        $pct = (int) min(100, max(0, round($into / $span * 100)));

        return [
            'rankSlug' => $current['slug'],
            'nextRankSlug' => $next['slug'],
            'pointsToNextRank' => max(0, $next['min'] - $lifetimePoints),
            'rankProgressPct' => $pct,
        ];
    }

    /** Instance wrapper kept for existing call sites (e.g. SummaryController); delegates to the static, container-free progressFor(). */
    public function progress(int $lifetimePoints): array
    {
        return self::progressFor($lifetimePoints);
    }

    /**
     * Rank follows lifetime_points, never the spendable balance.
     *
     * This was a real defect in the first cut: it read `points`, so the moment
     * a store existed, buying anything would silently demote the buyer.
     *
     * Also where a rank-up is celebrated. The bonus is paid with credit(),
     * NOT award(): countsForRank is explicitly false, because a bonus for
     * reaching a rank must not itself count toward lifetime_points — that
     * would risk the bonus pushing the account across the NEXT threshold too,
     * which would pay another bonus, which could push it across a third. The
     * ref is `rank:<slug>`, so it can only ever be paid once per rank per
     * account, ever — worth noting for the shape of what this can be worth:
     * with ten rungs on the ladder (Ledger::RANKS) and a default of 20 per
     * rung, the absolute ceiling this adds to any single account, under any
     * sequence of events, is 200 points. That bound is deliberate: it is why
     * this does not also try to claw the bonus back if a later post deletion
     * drops the account back below the rank it celebrated — writing that
     * clawback correctly would mean revoke() learning to distinguish a
     * countsForRank:false credit from a normal award (today it does not, and
     * teaching it wrong would corrupt lifetime_points for every OTHER
     * countsForRank:false credit already in production — store.credits,
     * store.refund, admin.adjust). A bounded, well-understood 200-point
     * worst case is a better trade than a lifetime_points bug in the ledger
     * every real balance on this install depends on.
     */
    public function refreshRank(int $userId): void
    {
        $row = $this->db->table('users')->where('id', $userId)->first(['lifetime_points', 'rank_slug']);
        $lifetime = (int) ($row->lifetime_points ?? 0);
        $before = (string) ($row->rank_slug ?? '');
        $after = $this->rankFor($lifetime)['slug'];

        if ($after !== $before) {
            $this->db->table('users')->where('id', $userId)->update(['rank_slug' => $after]);
        }

        if ($after !== $before && $this->isHigherRank($after, $before)) {
            $bonus = (int) Config::get($this->settings, 'award.rankUp');
            if ($bonus > 0) {
                $this->credit($userId, $bonus, 'rank.milestone', 'rank:' . $after, false);
            }
        }
    }

    /** True if $after sits strictly above $before on the ladder. An unrecognised slug (including '', a brand new account) is treated as the bottom rung, not as "higher than everything" — see refreshRank(). */
    private function isHigherRank(string $after, string $before): bool
    {
        $order = array_column(self::ladder(), 'slug');
        $a = array_search($after, $order, true);
        $b = array_search($before, $order, true);

        return ($a === false ? 0 : $a) > ($b === false ? 0 : $b);
    }

    /**
     * [earn multiplier, daily cap boost] for this user right now: their
     * membership tier, multiplied by any boost they bought in the store.
     *
     * The two multiply rather than taking the larger. A boost is a thing
     * somebody paid for on top of a membership they also paid for, and making
     * the second purchase do nothing for the people most likely to make it is
     * how a store loses its best customers. The ceiling that keeps this safe is
     * the daily cap, not the multiplier — see overDailyCap().
     */
    private function tierModifiers(int $userId): array
    {
        $earn = 1.0;
        $cap = 1.0;

        if (class_exists(\Local\Ranks\Catalog::class)) {
            $row = $this->db->table('users')->where('id', $userId)->first(['tier_slug', 'tier_expires_at']);
            $live = $row && $row->tier_slug && $row->tier_slug !== 'standard'
                && !($row->tier_expires_at && strtotime((string) $row->tier_expires_at) < time());

            if ($live) {
                $tier = \Local\Ranks\Catalog::tier($row->tier_slug);
                $earn = (float) $tier['earn'];
                $cap = (float) $tier['capBoost'];
            }
        }

        // Store boosts. Read through the store's own class so "still in force"
        // has one definition; if the store is not installed this contributes
        // nothing and the tier alone applies.
        if (class_exists(\Local\Store\Entitlements::class)) {
            try {
                /** @var \Local\Store\Entitlements $entitlements */
                $entitlements = \Illuminate\Container\Container::getInstance()
                    ->make(\Local\Store\Entitlements::class);
                $boost = $entitlements->boosts($userId);
                $earn *= (float) ($boost['earn'] ?? 1.0);
                $cap *= (float) ($boost['capBoost'] ?? 1.0);
            } catch (\Throwable $e) {
                // A broken neighbour must not stop somebody earning.
            }
        }

        return [$earn, $cap];
    }

    private function overDailyCap(int $userId, string $reason, float $boost = 1.0): bool
    {
        if (in_array($reason, self::UNCAPPED, true)) {
            return false;
        }

        $key = self::DAILY_CAP_KEYS[$reason] ?? null;
        if ($key === null) {
            return false;
        }

        $cap = (int) round((int) Config::get($this->settings, 'cap.' . $key) * $boost);

        $count = $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->where('reason', $reason)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 86400))
            ->count();

        return $count >= $cap;
    }
}
