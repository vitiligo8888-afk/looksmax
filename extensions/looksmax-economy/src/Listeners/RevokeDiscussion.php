<?php

namespace Local\Economy\Listeners;

use Flarum\Discussion\Event\Deleted;
use Local\Economy\Ledger;

/**
 * The farming bug this closes, stated exactly: RevokePost only ever listened
 * for `Flarum\Post\Event\Deleted` and only ever reversed `post.created`
 * (post.number > 1). Nothing reversed AwardDiscussion's payout — 5 points for
 * `discussion.started`, 25 for `guide.published` (posting into a `guide`,
 * `method` or `best-of-the-best` tag). Start a discussion, or better, start
 * one in a guide tag, then delete it: the points stayed, and the delete could
 * be repeated on a new discussion immediately after, for the same points,
 * forever — a materially worse farm than anything post.created ever exposed,
 * because 25 points beats even the effort-weighted ceiling of a single post
 * (2 base x 2.0 max multiplier = 4).
 *
 * The fix does not re-derive which reason was paid from the discussion's
 * current tags: by the time `Deleted` fires, the `discussion_tag` pivot rows
 * may already be gone (FK cascade on the discussion's own deletion, ahead of
 * this listener running), which would make a tag-based guess default to
 * "discussion.started" even for a guide that really paid the 25. Instead it
 * tries revoking BOTH possible reasons against the same ref unconditionally.
 * AwardDiscussion pays exactly one of them per discussion, so exactly one of
 * these two calls ever finds a matching ledger row; Ledger::revoke() is a
 * documented no-op when it finds none (it queries, gets zero rows, and its
 * DELETE affects zero rows too) — so the "wrong" call costs one harmless
 * query and never touches a balance it should not.
 */
class RevokeDiscussion
{
    public function __construct(protected Ledger $ledger)
    {
    }

    public function handle(Deleted $event): void
    {
        $discussion = $event->discussion;
        if (!$discussion || !$discussion->user_id) {
            return;
        }

        $userId = (int) $discussion->user_id;
        $ref = 'discussion:' . $discussion->id;

        $this->ledger->revoke($userId, 'discussion.started', $ref);
        $this->ledger->revoke($userId, 'guide.published', $ref);
    }
}
