<?php

namespace Local\UserInfo\Api;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Local\UserInfo\Config;
use Local\UserInfo\Dm\Thread;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Direct messages: the whole surface, in one handler.
 *
 * Six routes, one class, dispatched on method + path. That is a deliberate
 * trade: the six operations share the same three invariants (you must be a
 * participant, a thread's tail is denormalised, a read cursor is a timestamp),
 * and splitting them across six files is six places for one of those to be
 * forgotten. The dispatch is on the URI this file's own routes declare, not on
 * a framework attribute, so it cannot silently start matching something else.
 *
 * ── The one rule ────────────────────────────────────────────────────────────
 * Every path that touches a thread goes through `participation()`, which is the
 * ONLY place that answers "may this actor see this thread". It returns the
 * participant row or null; null is a 404, never a 403, because whether a
 * conversation exists is itself information about who is talking to whom.
 *
 * ── What this is not ────────────────────────────────────────────────────────
 * It is not a chat. There is no polling loop, no typing indicator and no
 * websocket: `chat_messages` (local/looksmax-chat) is the realtime surface on
 * this install and duplicating its transport here would put two pollers on
 * every page. The inbox refreshes when it is opened and after a send.
 */
class DmController implements RequestHandlerInterface
{
    /** Messages one account may send in a rolling minute. */
    private const BURST = 10;

    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if (!$actor->exists || $actor->isGuest()) {
            return new JsonResponse(['error' => 'unauthenticated'], 401);
        }

        $config = Config::all($this->settings);
        if (!$config['dmEnabled']) {
            return new JsonResponse(['error' => 'disabled'], 404);
        }

        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        // Flarum's RouteHandlerFactory merges `{id}` into the query params
        // (vendor/flarum/core/src/Http/RouteHandlerFactory.php:36), so one
        // read covers both the path segment and an explicit ?id=.
        $id = (int) Arr::get($request->getQueryParams(), 'id', 0);

        if ($method === 'GET' && str_ends_with($path, '/unread')) {
            return $this->unread($actor);
        }
        if ($method === 'GET' && $id > 0) {
            return $this->show($actor, $id, $request);
        }
        if ($method === 'GET') {
            return $this->index($actor, $request);
        }
        if ($method === 'POST' && str_ends_with($path, '/read')) {
            return $this->markRead($actor, $id);
        }
        if ($method === 'POST' && $id > 0) {
            return $this->reply($actor, $id, $request, $config);
        }
        if ($method === 'POST') {
            return $this->create($actor, $request, $config);
        }

        return new JsonResponse(['error' => 'unsupported'], 405);
    }

    // ------------------------------------------------------------- inbox

    private function index(User $actor, ServerRequestInterface $request): ResponseInterface
    {
        $limit = min(50, max(1, (int) Arr::get($request->getQueryParams(), 'limit', 20)));
        $offset = max(0, (int) Arr::get($request->getQueryParams(), 'offset', 0));

        $rows = $this->db->table('lmx_dm_participants as p')
            ->join('lmx_dm_threads as t', 't.id', '=', 'p.thread_id')
            ->where('p.user_id', $actor->id)
            ->whereNull('p.left_at')
            ->orderByDesc('t.last_message_at')
            ->offset($offset)->limit($limit)
            ->get(['t.id', 't.subject', 't.message_count', 't.last_message_at', 't.last_message_id', 'p.last_read_at']);

        $ids = $rows->pluck('id')->all();

        return new JsonResponse([
            'threads' => $this->hydrate($rows, $ids, $actor),
            'unread' => $this->unreadCount($actor),
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    /**
     * Turn the inbox rows into what the panel draws, in three queries total
     * rather than three per thread.
     */
    private function hydrate($rows, array $ids, User $actor): array
    {
        if (!$ids) {
            return [];
        }

        // participants, everyone, in two queries: the pivot rows, then the
        // users as MODELS. Models rather than a join, because `avatar_url` on
        // the raw row is a bare filename and only User::getAvatarUrlAttribute()
        // knows how to turn that into a URL — a join would put "abc123.png" in
        // an <img src>.
        $pivot = $this->db->table('lmx_dm_participants')
            ->whereIn('thread_id', $ids)
            ->get(['thread_id', 'user_id', 'left_at']);
        $users = User::query()->whereIn('id', $pivot->pluck('user_id')->unique()->all())->get()->keyBy('id');

        $people = [];
        foreach ($pivot as $r) {
            $u = $users->get((int) $r->user_id);
            if ($u) {
                $people[(int) $r->thread_id][] = $this->person($u, $r->left_at !== null);
            }
        }

        // last message of each thread, in one query
        $lastIds = $rows->pluck('last_message_id')->filter()->all();
        $last = [];
        if ($lastIds) {
            foreach ($this->db->table('lmx_dm_messages')->whereIn('id', $lastIds)
                         ->get(['id', 'thread_id', 'user_id', 'content', 'created_at', 'deleted_at']) as $m) {
                $last[(int) $m->thread_id] = [
                    'userId' => (int) $m->user_id,
                    'excerpt' => $m->deleted_at ? null : mb_substr((string) $m->content, 0, 140),
                    'createdAt' => $this->iso($m->created_at),
                ];
            }
        }

        // unread per thread, in one query
        $unread = [];
        foreach ($this->db->table('lmx_dm_messages as m')
                     ->join('lmx_dm_participants as p', function ($j) use ($actor) {
                         $j->on('p.thread_id', '=', 'm.thread_id')->where('p.user_id', '=', $actor->id);
                     })
                     ->whereIn('m.thread_id', $ids)
                     ->whereNull('m.deleted_at')
                     ->where('m.user_id', '!=', $actor->id)
                     ->where(function ($q) {
                         $q->whereNull('p.last_read_at')->orWhereColumn('m.created_at', '>', 'p.last_read_at');
                     })
                     ->groupBy('m.thread_id')
                     ->get(['m.thread_id', $this->db->raw('COUNT(*) as c')]) as $r) {
            $unread[(int) $r->thread_id] = (int) $r->c;
        }

        $out = [];
        foreach ($rows as $r) {
            $tid = (int) $r->id;
            $out[] = [
                'id' => $tid,
                'subject' => $r->subject,
                'messageCount' => (int) $r->message_count,
                'lastMessageAt' => $this->iso($r->last_message_at),
                'participants' => $people[$tid] ?? [],
                'last' => $last[$tid] ?? null,
                'unread' => $unread[$tid] ?? 0,
            ];
        }

        return $out;
    }

    private function person(User $u, bool $left): array
    {
        $seen = $u->last_seen_at ? $u->last_seen_at->getTimestamp() : null;

        return [
            'id' => (int) $u->id,
            'username' => $u->username,
            // Flarum's display name is the nickname when flarum/nicknames is on
            // and that extension IS on here, so a panel that printed `username`
            // would show a different name from every other surface.
            'displayName' => $u->display_name,
            'slug' => $u->slug ?? $u->username,
            'avatarUrl' => $u->avatar_url,
            'online' => $seen !== null && (time() - $seen) <= 300,
            'left' => $left,
        ];
    }

    // ------------------------------------------------------------- thread

    private function show(User $actor, int $id, ServerRequestInterface $request): ResponseInterface
    {
        $me = $this->participation($actor, $id);
        if (!$me) {
            return new JsonResponse(['error' => 'no such thread'], 404);
        }

        $limit = min(200, max(1, (int) Arr::get($request->getQueryParams(), 'limit', 100)));

        $thread = $this->db->table('lmx_dm_threads')->where('id', $id)->first();
        $messages = $this->db->table('lmx_dm_messages')
            ->where('thread_id', $id)
            ->orderByDesc('id')->limit($limit)
            ->get(['id', 'user_id', 'content', 'created_at', 'edited_at', 'deleted_at'])
            ->reverse()->values();

        $pivot = $this->db->table('lmx_dm_participants')->where('thread_id', $id)->get(['user_id', 'left_at']);
        $users = User::query()->whereIn('id', $pivot->pluck('user_id')->all())->get()->keyBy('id');
        $people = $pivot->map(fn ($r) => ($u = $users->get((int) $r->user_id))
            ? $this->person($u, $r->left_at !== null)
            : null)->filter()->values();

        return new JsonResponse([
            'thread' => [
                'id' => $id,
                'subject' => $thread->subject ?? null,
                'messageCount' => (int) ($thread->message_count ?? 0),
                'lastMessageAt' => $this->iso($thread->last_message_at ?? null),
                'participants' => $people->all(),
                'lastReadAt' => $this->iso($me->last_read_at),
            ],
            'messages' => $messages->map(fn ($m) => [
                'id' => (int) $m->id,
                'userId' => (int) $m->user_id,
                'content' => $m->deleted_at ? null : (string) $m->content,
                'deleted' => $m->deleted_at !== null,
                'createdAt' => $this->iso($m->created_at),
                'editedAt' => $this->iso($m->edited_at),
            ])->all(),
        ]);
    }

    // ------------------------------------------------------------- writes

    private function create(User $actor, ServerRequestInterface $request, array $config): ResponseInterface
    {
        if (!$actor->hasPermission(Thread::PERMISSION_SEND)) {
            return new JsonResponse(['error' => 'permission_denied'], 403);
        }

        $body = (array) $request->getParsedBody();
        $content = $this->content($body, $config);
        if ($content === null) {
            return new JsonResponse(['error' => 'empty'], 422);
        }
        if ($over = $this->rateLimited($actor)) {
            return $over;
        }

        $wanted = array_values(array_unique(array_map('intval', (array) Arr::get($body, 'recipients', []))));
        $wanted = array_values(array_filter($wanted, fn ($i) => $i > 0 && $i !== (int) $actor->id));
        if (!$wanted) {
            return new JsonResponse(['error' => 'no_recipients'], 422);
        }
        if (count($wanted) > (int) $config['dmMaxRecipients']) {
            return new JsonResponse(['error' => 'too_many_recipients'], 422);
        }

        // whereVisibleTo, not a bare find: an account the actor cannot see must
        // not become addressable through this endpoint.
        $recipients = User::query()->whereVisibleTo($actor)->whereIn('id', $wanted)->get();
        if ($recipients->count() !== count($wanted)) {
            return new JsonResponse(['error' => 'unknown_recipient'], 404);
        }

        $members = $wanted;
        $members[] = (int) $actor->id;
        sort($members);

        // Reuse rather than fork. Two people who message each other twice from
        // two different hover cards must land in one conversation, or the inbox
        // fills with one-message threads and neither side can find the reply.
        $existing = $this->findThreadWithExactly($members);

        $subject = trim((string) Arr::get($body, 'subject', ''));
        $now = date('Y-m-d H:i:s');

        $threadId = $this->db->transaction(function () use ($existing, $members, $actor, $subject, $now) {
            if ($existing) {
                return $existing;
            }
            $tid = (int) $this->db->table('lmx_dm_threads')->insertGetId([
                'subject' => $subject !== '' ? mb_substr($subject, 0, 190) : null,
                'created_by' => (int) $actor->id,
                'created_at' => $now,
                'message_count' => 0,
            ]);
            foreach ($members as $uid) {
                $this->db->table('lmx_dm_participants')->insert([
                    'thread_id' => $tid,
                    'user_id' => $uid,
                    'joined_at' => $now,
                    // The sender has, by definition, read everything they wrote.
                    'last_read_at' => $uid === (int) $actor->id ? $now : null,
                ]);
            }

            return $tid;
        });

        $message = $this->append($threadId, $actor, $content);

        return new JsonResponse(['threadId' => $threadId, 'message' => $message, 'created' => !$existing], 201);
    }

    private function reply(User $actor, int $id, ServerRequestInterface $request, array $config): ResponseInterface
    {
        if (!$this->participation($actor, $id)) {
            return new JsonResponse(['error' => 'no such thread'], 404);
        }
        if (!$actor->hasPermission(Thread::PERMISSION_SEND)) {
            return new JsonResponse(['error' => 'permission_denied'], 403);
        }

        $content = $this->content((array) $request->getParsedBody(), $config);
        if ($content === null) {
            return new JsonResponse(['error' => 'empty'], 422);
        }
        if ($over = $this->rateLimited($actor)) {
            return $over;
        }

        return new JsonResponse(['threadId' => $id, 'message' => $this->append($id, $actor, $content)], 201);
    }

    /**
     * Insert a message and bring the thread's denormalised tail with it.
     *
     * One transaction, because a message whose thread still points at the
     * previous last_message_id shows the wrong preview in the recipient's inbox
     * and sorts to the wrong place — a visible lie produced by a partial write.
     */
    private function append(int $threadId, User $actor, string $content): array
    {
        $now = date('Y-m-d H:i:s');

        $messageId = $this->db->transaction(function () use ($threadId, $actor, $content, $now) {
            $mid = (int) $this->db->table('lmx_dm_messages')->insertGetId([
                'thread_id' => $threadId,
                'user_id' => (int) $actor->id,
                'content' => $content,
                'created_at' => $now,
            ]);

            $this->db->table('lmx_dm_threads')->where('id', $threadId)->update([
                'last_message_id' => $mid,
                'last_message_at' => $now,
                'message_count' => $this->db->raw('message_count + 1'),
            ]);

            // The sender's own cursor moves with the send; anyone who left the
            // thread is not brought back by it.
            $this->db->table('lmx_dm_participants')
                ->where('thread_id', $threadId)->where('user_id', $actor->id)
                ->update(['last_read_at' => $now]);

            return $mid;
        });

        return [
            'id' => $messageId,
            'userId' => (int) $actor->id,
            'content' => $content,
            'deleted' => false,
            'createdAt' => gmdate('c', strtotime($now)),
            'editedAt' => null,
        ];
    }

    private function markRead(User $actor, int $id): ResponseInterface
    {
        if (!$this->participation($actor, $id)) {
            return new JsonResponse(['error' => 'no such thread'], 404);
        }

        $this->db->table('lmx_dm_participants')
            ->where('thread_id', $id)->where('user_id', $actor->id)
            ->update(['last_read_at' => date('Y-m-d H:i:s')]);

        return new JsonResponse(['unread' => $this->unreadCount($actor)]);
    }

    private function unread(User $actor): ResponseInterface
    {
        return new JsonResponse(['unread' => $this->unreadCount($actor)]);
    }

    // ------------------------------------------------------------- helpers

    /** The actor's participant row, or null. The ONLY visibility check. */
    private function participation(User $actor, int $threadId)
    {
        if ($threadId < 1) {
            return null;
        }

        return $this->db->table('lmx_dm_participants')
            ->where('thread_id', $threadId)->where('user_id', $actor->id)
            ->whereNull('left_at')
            ->first();
    }

    /**
     * The thread whose live participant set is EXACTLY this set of people.
     *
     * Not "a thread containing both of us": that would merge a two-person
     * conversation into a group one the moment a third person was added.
     */
    private function findThreadWithExactly(array $members): ?int
    {
        $n = count($members);

        $row = $this->db->table('lmx_dm_participants')
            ->whereNull('left_at')
            ->groupBy('thread_id')
            ->havingRaw('COUNT(*) = ?', [$n])
            ->havingRaw('SUM(CASE WHEN user_id IN (' . implode(',', array_fill(0, $n, '?')) . ') THEN 1 ELSE 0 END) = ?',
                array_merge($members, [$n]))
            ->orderByDesc('thread_id')
            ->first(['thread_id']);

        return $row ? (int) $row->thread_id : null;
    }

    private function unreadCount(User $actor): int
    {
        return (int) $this->db->table('lmx_dm_messages as m')
            ->join('lmx_dm_participants as p', function ($j) use ($actor) {
                $j->on('p.thread_id', '=', 'm.thread_id')->where('p.user_id', '=', $actor->id);
            })
            ->whereNull('p.left_at')
            ->whereNull('m.deleted_at')
            ->where('m.user_id', '!=', $actor->id)
            ->where(function ($q) {
                $q->whereNull('p.last_read_at')->orWhereColumn('m.created_at', '>', 'p.last_read_at');
            })
            ->count();
    }

    private function content(array $body, array $config): ?string
    {
        $raw = (string) Arr::get($body, 'body', '');
        // Normalise the line endings a textarea submits, then trim. A message
        // of nothing but newlines is empty, not a message.
        $raw = str_replace("\r\n", "\n", $raw);
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        return mb_substr($raw, 0, max(1, (int) $config['dmMaxLength']));
    }

    /**
     * A cheap burst guard, counted from the rows themselves.
     *
     * Not a full rate limiter: this is one endpoint on a forum with a real
     * moderation team, and a counter in the database is one query that cannot
     * be lost to a cache restart the way an in-memory bucket can.
     */
    private function rateLimited(User $actor): ?ResponseInterface
    {
        $recent = (int) $this->db->table('lmx_dm_messages')
            ->where('user_id', $actor->id)
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 60))
            ->count();

        return $recent >= self::BURST
            ? new JsonResponse(['error' => 'rate_limited', 'retryAfter' => 60], 429)
            : null;
    }

    private function iso($v): ?string
    {
        if (!$v) {
            return null;
        }
        $t = $v instanceof \DateTimeInterface ? $v->getTimestamp() : strtotime((string) $v);

        return $t ? gmdate('c', $t) : null;
    }
}
