<?php

namespace Local\Chat;

use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;

/**
 * The shoutbox state, in one place.
 *
 * Both the API (`GET /api/chat`) and the server-rendered bootstrap
 * (`RenderChat`) return the identical shape from here. When they were built
 * separately the first paint and the first poll disagreed about the cursor and
 * the client appended the same rows twice — the duplicate that shipped. One
 * builder means one cursor rule.
 */
class Payload
{
    /** Messages held in the box. Older ones are pruned on write. */
    public const KEEP = 500;

    /** How many rows a cold client is given. */
    public const PAGE = 40;

    /** How many a warm client can catch up on in one poll before it reloads. */
    public const CATCHUP = 60;

    /** A presence row older than this is not "here" any more. */
    public const PRESENCE_WINDOW = 90;

    /**
     * How many anonymous viewers one source address may contribute.
     *
     * A cap is needed because a guest identity is a random id the client mints,
     * so without one anybody can loop and inflate the number. 8 rather than a
     * smaller figure because a household, an office or a campus NAT legitimately
     * puts several real readers behind one address, and a cap that bites in
     * normal use under-reports instead of protecting anything.
     */
    public const PRESENCE_IP_CAP = 8;

    /** Longest message accepted, in characters. */
    public const MAX_LEN = 400;

    /** Minimum gap between two messages from one author. */
    public const RATE_SECONDS = 2;

    /** …and no more than this many in a rolling minute. */
    public const BURST_PER_MINUTE = 12;

    public function __construct(protected ConnectionInterface $db)
    {
    }

    /**
     * @param int $since cursor: the highest message id the client already has
     */
    public function state(User $actor, int $since, bool $withDeletions = true): array
    {
        $cold = $since <= 0;

        $rows = $this->db->table('chat_messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->whereNull('m.deleted_at')
            ->when(! $cold, fn ($q) => $q->where('m.id', '>', $since))
            ->orderByDesc('m.id')
            ->limit(($cold ? self::PAGE : self::CATCHUP) + 1)
            ->get(['m.id', 'm.user_id', 'm.username', 'm.body', 'm.created_at', 'u.avatar_url', 'u.username as account'])
            ->reverse()
            ->values();

        // One more than the page size was asked for: if it came back, the
        // client is further behind than one poll can carry and needs to reset
        // its log rather than splice a hole into it.
        $truncated = $rows->count() > ($cold ? self::PAGE : self::CATCHUP);
        if ($truncated) {
            $rows = $rows->slice(1)->values();
        }

        $messages = $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'userId' => $r->user_id === null ? null : (int) $r->user_id,
            'username' => $r->account ?: $r->username,
            'avatarUrl' => self::avatarUrl($r->avatar_url),
            'body' => $r->body,
            'createdAt' => self::iso($r->created_at),
        ])->all();

        // The cursor only ever moves to a row the client was actually handed.
        // Deriving it from MAX(id) instead would skip anything the page limit
        // cut off, which is how a message silently disappears.
        $cursor = $messages ? (int) end($messages)['id'] : $since;

        $presence = $this->presence();

        $state = [
            'messages' => $messages,
            'cursor' => $cursor,
            'truncated' => $truncated,
            'online' => $presence['online'],
            'members' => $presence['members'],
            'guests' => $presence['guests'],
            'canPost' => $this->canPost($actor),
            'canModerate' => self::canModerate($actor),
            'you' => $actor->isGuest() ? null : $actor->username,
            'youId' => $actor->isGuest() ? null : (int) $actor->id,
            'maxLength' => self::MAX_LEN,
            'serverTime' => self::iso(date('Y-m-d H:i:s')),
        ];

        if ($withDeletions && ! $cold) {
            // A message deleted after the client already rendered it has an id
            // below the cursor, so no `id > since` query can ever return it.
            // Deletions are reported separately, for a bounded recent window.
            $state['deleted'] = $this->db->table('chat_messages')
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '>', date('Y-m-d H:i:s', time() - 900))
                ->pluck('id')
                ->map(fn ($i) => (int) $i)
                ->all();
        }

        return $state;
    }

    /**
     * Who is here.
     *
     * Members count once each however many tabs they have open. Guests are
     * counted per browser (a client id in localStorage), capped per source
     * address so one script cannot inflate the number by minting ids — the
     * count is a signal, and a signal anyone can forge is decoration.
     */
    private function presence(): array
    {
        $window = date('Y-m-d H:i:s', time() - self::PRESENCE_WINDOW);

        $members = $this->db->table('chat_presence')
            ->where('seen_at', '>', $window)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('username', 'user_id')
            ->filter()
            ->values()
            ->all();

        $perIp = $this->db->table('chat_presence')
            ->where('seen_at', '>', $window)
            ->whereNull('user_id')
            ->selectRaw('ip, COUNT(*) AS c')
            ->groupBy('ip')
            ->pluck('c', 'ip');

        $guests = 0;
        foreach ($perIp as $c) {
            $guests += min((int) $c, self::PRESENCE_IP_CAP);
        }

        return [
            'online' => count($members) + $guests,
            'members' => array_slice($members, 0, 20),
            'guests' => $guests,
        ];
    }

    private function canPost(User $actor): bool
    {
        if ($actor->isGuest()) {
            return false;
        }

        // flarum/suspend is installed: a suspended account keeps its session
        // and would otherwise keep shouting.
        if (isset($actor->suspended_until) && $actor->suspended_until && strtotime((string) $actor->suspended_until) > time()) {
            return false;
        }

        return true;
    }

    public static function canModerate(User $actor): bool
    {
        return ! $actor->isGuest() && ($actor->isAdmin() || $actor->hasPermission('discussion.hide'));
    }

    /**
     * Turn the raw `users.avatar_url` column into something a browser can load.
     *
     * The column holds a bare filename for uploaded avatars and a full URL for
     * imported ones; the avatars disk knows the difference and where it is
     * published, and guessing the path instead is how avatars 404 while the
     * markup looks correct.
     */
    public static function avatarUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        try {
            return resolve(FilesystemFactory::class)->disk('flarum-avatars')->url($value);
        } catch (\Throwable $e) {
            return '/assets/avatars/' . ltrim($value, '/');
        }
    }

    private static function iso(?string $sql): ?string
    {
        if (! $sql) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
