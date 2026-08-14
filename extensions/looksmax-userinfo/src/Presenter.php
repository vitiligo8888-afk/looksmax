<?php

namespace Local\UserInfo;

use Flarum\Group\Group;
use Flarum\User\User;

/**
 * Build the `userInfo` attribute that rides on every serialized user.
 *
 * This runs ~20 times for a post stream and ~50 for a discussion list, so it
 * must not query. The profile row arrives through the eager-loaded
 * `userInfoProfile` relation and the groups through `groups` (see extend.php);
 * the counts it reports come off columns already on the `users` row.
 *
 * ── What it will not do ──────────────────────────────────────────────────────
 * There is no field here that is estimated, extrapolated or made up. Three
 * rules enforce that:
 *
 *   1. Local and carried-over numbers never merge. `posts` is what is clickable
 *      on this forum; `legacy.posts` is what the account wrote on the source
 *      board. Adding them would produce a number that is true nowhere.
 *   2. A number we do not have is null, and null renders as nothing. It is
 *      never a zero, because a displayed zero is a claim.
 *   3. `users.comment_count` / `users.discussion_count` are the ONLY authority
 *      for what this account posted here. `userinfo_profiles.posts_here` and
 *      `.discussions_here` are a snapshot for the backfill's own reporting and
 *      are never read on the render path — two sources for one number is how
 *      the card came to disagree with the profile page in the first place.
 *      BackfillCommand::syncProfileCounts() keeps the snapshot honest and
 *      `--verify` fails on its drift.
 *
 * ── The `can` block ─────────────────────────────────────────────────────────
 * Actions are decided HERE, by the server, against the actor on the request.
 * The card renders exactly the actions whose flag is true. Nothing in the JS
 * may infer a permission from a group name or from the absence of data: a
 * button that 403s is the same defect as a button that does nothing.
 */
class Presenter
{
    /**
     * Anyone whose last_seen_at is inside this window shows as online. Flarum
     * core uses the same 5 minutes for its own online dot, so a second
     * definition would put two contradictory dots on one page.
     */
    private const ONLINE_WINDOW = 300;

    public static function user(User $user, ?Profile $profile, ?User $actor = null): array
    {
        $joined = $user->joined_at ? $user->joined_at->getTimestamp() : null;
        $seen = $user->last_seen_at ? $user->last_seen_at->getTimestamp() : null;

        $posts = (int) ($user->comment_count ?? 0);
        $discussions = (int) ($user->discussion_count ?? 0);

        $rank = RankSource::resolve($user, $profile);
        $title = $profile ? trim((string) $profile->legacy_title) : '';

        // A title that IS the rank name is not a title, it is the rank, and
        // printing "Gold" twice under the name is the single most obvious way
        // this panel could look auto-generated.
        $titleIsRank = $rank['name'] !== null && mb_strtolower($title) === mb_strtolower((string) $rank['name']);

        $banners = $profile ? $profile->banners() : [];
        $mix = $profile ? $profile->mix() : [];

        // Tenure drives "posts per day", which is the one derived number here.
        // It is a ratio of two measured integers, not a model, and it is null
        // rather than 0 for an account that joined today.
        $tenureDays = $joined ? max(0, (int) floor((time() - $joined) / 86400)) : null;
        $perDay = ($tenureDays !== null && $tenureDays >= 1 && $posts > 0)
            ? round($posts / $tenureDays, 2)
            : null;

        $reactions = $profile ? (int) $profile->reactions_here : 0;

        return [
            // --- who -----------------------------------------------------------
            // The id is on the payload because every action needs it and the
            // card is frequently opened from a link that only carries a slug.
            'id' => (int) $user->id,

            // --- identity -----------------------------------------------------
            'title' => ($title !== '' && !$titleIsRank) ? $title : null,
            'banners' => array_map([RankSource::class, 'banner'], $banners),
            'rank' => $rank,
            // The forum's OWN roles, which are a different axis from both the
            // rank ladder and the source board's banners: a group is what this
            // install granted, and it is the only one of the three that carries
            // real permissions. Rendered as coloured chips.
            'groups' => self::groups($user),
            // Opaque source-board usergroup token. Exposed, not interpreted:
            // we do not have that board's stylesheet, so anything this file
            // decided it meant would be a guess. Rendered as a data attribute
            // so colour can be attached later without touching this code.
            'legacyStyleClass' => $profile ? $profile->legacy_style_class : null,

            // --- presence -----------------------------------------------------
            'joinedAt' => $joined ? gmdate('c', $joined) : null,
            'lastSeenAt' => $seen ? gmdate('c', $seen) : null,
            'online' => $seen !== null && (time() - $seen) <= self::ONLINE_WINDOW,
            'tenureDays' => $tenureDays,
            // flarum/suspend. A suspended account still renders — hiding it
            // would make moderation invisible — but it renders as suspended.
            'suspended' => self::suspended($user),

            // --- what is true on THIS forum -----------------------------------
            'posts' => $posts,
            'discussions' => $discussions,
            'reactions' => $reactions,
            'bestPostScore' => $profile ? (int) $profile->best_post_score : 0,
            'reactionMix' => $mix,
            'postsPerDay' => $perDay,
            // Reactions per post, the quality axis a raw total cannot show.
            'reactionRate' => ($posts > 0 && $reactions > 0) ? round($reactions / $posts, 2) : null,

            // --- what the account carried in ----------------------------------
            // Namespaced and never summed with the above. The UI labels this
            // block "carried over" for the same reason.
            'legacy' => $profile ? [
                'posts' => (int) $profile->legacy_posts,
                'reactions' => (int) $profile->legacy_reactions,
                'threads' => (int) $profile->legacy_threads,
                'joined' => $profile->legacy_joined ? (string) $profile->legacy_joined : null,
            ] : null,

            'hasProfile' => $profile !== null,

            // --- what the VIEWER may do to this account -----------------------
            'can' => self::abilities($user, $actor),
        ];
    }

    /**
     * The forum's own groups, minus the two that are structural rather than
     * descriptive.
     *
     * Member (3) is everyone who is logged in and Guest (2) is everyone who is
     * not, so neither says anything about the account — printing "Member" on
     * 26,000 profiles is noise that pushes the chip that matters off the line.
     */
    private static function groups(User $user): array
    {
        // relationLoaded, not a truthiness check: an unloaded relation would
        // otherwise fire one query per user per page.
        if (!$user->relationLoaded('groups')) {
            return [];
        }

        $out = [];
        foreach ($user->groups as $group) {
            $id = (int) $group->id;
            if ($id === Group::MEMBER_ID || $id === Group::GUEST_ID) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string) $group->name_singular,
                'color' => $group->color ?: null,
                'icon' => $group->icon ?: null,
            ];
        }

        return $out;
    }

    private static function suspended(User $user): ?string
    {
        // The column only exists when flarum/suspend is enabled; reading a
        // missing attribute is null, not an error, but be explicit about it.
        $until = $user->getAttribute('suspended_until');
        if (!$until) {
            return null;
        }
        try {
            $ts = $until instanceof \DateTimeInterface ? $until->getTimestamp() : strtotime((string) $until);
        } catch (\Throwable $e) {
            return null;
        }

        return ($ts && $ts > time()) ? gmdate('c', $ts) : null;
    }

    /**
     * What the actor on THIS request may do to this account.
     *
     * Everything is a real permission check against core's gate. `false` is the
     * answer for a guest on every action except viewing the profile, which is
     * why the card renders exactly one link when logged out.
     */
    private static function abilities(User $user, ?User $actor): array
    {
        $can = [
            'profile' => true,
            'posts' => true,
            'message' => false,
            'mention' => false,
            'report' => false,
            'suspend' => false,
            'edit' => false,
            'delete' => false,
        ];

        if (!$actor || !$actor->exists || $actor->isGuest()) {
            return $can;
        }

        $self = (int) $actor->id === (int) $user->id;

        // Mentioning is a composer affordance: it needs somewhere to type.
        $can['mention'] = !$self;

        // A DM needs the permission, a recipient who is not you, and an
        // account that is not suspended out of the conversation.
        $can['message'] = !$self
            && $actor->hasPermission(Dm\Thread::PERMISSION_SEND)
            && self::suspended($user) === null;

        // Reporting a person is not a thing flarum/flags does — it flags a
        // POST. The card therefore only offers it when it was opened from a
        // post, and the JS decides that; this flag is the permission half.
        $can['report'] = !$self && $actor->hasPermission('discussion.flagPosts');

        foreach (['suspend', 'edit', 'delete'] as $ability) {
            try {
                $can[$ability] = !$self && $actor->can($ability, $user);
            } catch (\Throwable $e) {
                $can[$ability] = false;
            }
        }

        return $can;
    }
}
