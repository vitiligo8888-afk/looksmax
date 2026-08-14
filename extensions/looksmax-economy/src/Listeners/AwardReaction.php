<?php

namespace Local\Economy\Listeners;

use Flarum\Likes\Event\PostWasLiked;
use Flarum\Likes\Event\PostWasUnliked;
use Illuminate\Database\ConnectionInterface;
use Local\Economy\Ledger;

/**
 * Reactions pay, with the three limits that stop them being a printing press.
 *
 * The ledger has carried a `reaction.received` rate since it was written and
 * nothing ever fired it: 3,589 of those rows exist and every one came from the
 * import backfill. Live reactions paid nobody, which meant the only way to earn
 * on this forum was to post — exactly the volume-over-value incentive the
 * economy was written to avoid. The source board's own numbers are the argument:
 * 241,002 threads with no prefix average 13 replies, while 8,273 guide threads
 * average 3,262 views.
 *
 * The three limits, in order of how quickly somebody would otherwise find them:
 *
 *   1. NO SELF-LIKES. Free, unlimited, and the first thing anybody tries.
 *
 *   2. A CAP PER PAIR. One account can pay another at most six times a day, no
 *      matter how many posts it likes. Reciprocal like-rings and alt accounts
 *      are the standard farm on every forum that has ever had reputation, and a
 *      global daily cap does not touch them — two accounts liking each other
 *      200 times a day each stay under any sane global ceiling. This is the
 *      limit that actually bites, and it is why the ledger records `actor_id`.
 *
 *   3. NOTHING FOR ANCIENT POSTS. A reaction on a post older than 90 days pays
 *      the author nothing. Necro-liking a thousand old posts is otherwise a
 *      quiet way to hand somebody a balance, and it is invisible in every
 *      "reactions today" chart because the reaction is new even though the
 *      content is not.
 *
 * Removing a like reverses both movements, so a like/unlike loop nets to zero
 * rather than paying per cycle.
 */
class AwardReaction
{
    /** How many times one account can pay the same author in 24 hours. */
    private const PAIR_DAILY_CAP = 6;

    /** Reactions on posts older than this pay nothing. */
    private const MAX_POST_AGE_DAYS = 90;

    public function __construct(
        protected Ledger $ledger,
        protected ConnectionInterface $db
    ) {
    }

    public function liked(PostWasLiked $event): void
    {
        $post = $event->post;
        $liker = $event->user;

        if (!$post || !$liker || !$post->user_id) {
            return;
        }

        $authorId = (int) $post->user_id;
        $likerId = (int) $liker->id;

        // A self-like earns nothing at all, on either side.
        //
        // The give-side award used to be paid before this check, on the
        // reasoning that it is only worth one point. It is not: award() applies
        // the tier multiplier and any bought boost, so a VIP with a 2x boost
        // collected 3 for liking their own post, and unliking revokes the
        // ledger row — which frees the ref to be awarded again. Like, unlike,
        // repeat: free points up to the daily give cap, from one post, alone.
        if ($authorId === $likerId) {
            return;
        }

        // The liker earns a token amount for participating. Small on purpose:
        // giving should be encouraged, not farmed.
        $this->ledger->award($likerId, 'reaction.given', 'like:' . $post->id . ':' . $likerId, $authorId);

        if ($this->tooOld($post)) {
            return;
        }

        if ($this->pairCapped($authorId, $likerId)) {
            return;
        }

        $this->ledger->award(
            $authorId,
            'reaction.received',
            'like:' . $post->id . ':' . $likerId,
            $likerId
        );
    }

    public function unliked(PostWasUnliked $event): void
    {
        $post = $event->post;
        $liker = $event->user;

        if (!$post || !$liker) {
            return;
        }

        $ref = 'like:' . $post->id . ':' . $liker->id;

        $this->ledger->revoke((int) $liker->id, 'reaction.given', $ref);

        if ($post->user_id) {
            $this->ledger->revoke((int) $post->user_id, 'reaction.received', $ref);
        }
    }

    private function tooOld($post): bool
    {
        $created = $post->created_at ?? null;

        if (!$created) {
            return false;
        }

        $ts = $created instanceof \DateTimeInterface ? $created->getTimestamp() : strtotime((string) $created);

        return $ts > 0 && $ts < time() - self::MAX_POST_AGE_DAYS * 86400;
    }

    private function pairCapped(int $authorId, int $likerId): bool
    {
        $count = $this->db->table('economy_transactions')
            ->where('user_id', $authorId)
            ->where('actor_id', $likerId)
            ->where('reason', 'reaction.received')
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 86400))
            ->count();

        return $count >= self::PAIR_DAILY_CAP;
    }
}
