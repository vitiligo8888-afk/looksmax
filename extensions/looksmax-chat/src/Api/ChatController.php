<?php

namespace Local\Chat\Api;

use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Chat\Payload;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One endpoint for the shoutbox: GET returns messages after a cursor plus who
 * is here, POST appends a message, DELETE removes one for moderators.
 *
 * Polling rather than websockets on purpose. Flarum has no realtime layer, and
 * adding one means running and supervising a separate server. A 3s poll that
 * returns only what changed after a cursor costs a few hundred bytes and is the
 * right trade until there is a reason to pay for sockets.
 *
 * Failures answer with a status code and a machine-readable `error`, never with
 * a silent 200. The version this replaces dropped guest posts, over-long posts
 * and rate-limited posts on the floor and returned the message list, so the
 * client could not tell "sent" from "swallowed" and neither could a test.
 */
class ChatController implements RequestHandlerInterface
{
    public function __construct(protected ConnectionInterface $db, protected Payload $payload)
    {
    }

    /**
     * A plain handler, not an AbstractShowController. The JSON:API controllers
     * want a model and a serializer; this endpoint returns a small ad-hoc
     * payload, and forcing it through ForumSerializer meant resolving a
     * 'flarum.forum' binding that does not exist.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $method = strtoupper($request->getMethod());
        $query = $request->getQueryParams();
        $body = (array) ($request->getParsedBody() ?: []);

        $cid = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($query['cid'] ?? $body['cid'] ?? ''));
        $this->touchPresence($request, $actor, substr($cid, 0, 24));

        if ($method === 'DELETE') {
            return $this->delete($request, $actor);
        }

        if ($method === 'POST') {
            $error = $this->post($body, $actor);
            if ($error !== null) {
                return new JsonResponse($error[1], $error[0]);
            }
        }

        $since = (int) ($query['since'] ?? $body['since'] ?? 0);

        return new JsonResponse($this->payload->state($actor, $since));
    }

    /**
     * @return array{0:int,1:array}|null null on success, [status, body] on refusal
     */
    private function post(array $body, User $actor): ?array
    {
        if ($actor->isGuest()) {
            return [403, ['error' => 'guest', 'detail' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-chat.forum.api.guest')]];
        }

        if (isset($actor->suspended_until) && $actor->suspended_until && strtotime((string) $actor->suspended_until) > time()) {
            return [403, ['error' => 'suspended', 'detail' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-chat.forum.api.suspended')]];
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) ($body['body'] ?? '')));
        if ($text === '') {
            return [422, ['error' => 'empty', 'detail' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-chat.forum.api.empty')]];
        }
        if (mb_strlen($text) > Payload::MAX_LEN) {
            return [422, ['error' => 'too_long', 'detail' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-chat.forum.api.too_long', ['max' => Payload::MAX_LEN])]];
        }

        $recent = $this->db->table('chat_messages')
            ->where('user_id', $actor->id)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 60))
            ->orderByDesc('id')
            ->get(['id', 'body', 'created_at']);

        $last = $recent->first();
        if ($last && ($gap = time() - strtotime($last->created_at)) < Payload::RATE_SECONDS) {
            return [429, ['error' => 'rate', 'retryAfter' => Payload::RATE_SECONDS - $gap, 'detail' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-chat.forum.api.rate')]];
        }
        if ($recent->count() >= Payload::BURST_PER_MINUTE) {
            return [429, ['error' => 'burst', 'retryAfter' => 20, 'detail' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-chat.forum.api.burst')]];
        }
        if ($last && $last->body === $text) {
            return [429, ['error' => 'duplicate', 'retryAfter' => 0, 'detail' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-chat.forum.api.duplicate')]];
        }

        $this->db->table('chat_messages')->insert([
            'user_id' => $actor->id,
            'username' => $actor->username,
            'body' => $text,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // keep the table small; the shoutbox is not an archive
        $cutoff = $this->db->table('chat_messages')->orderByDesc('id')->skip(Payload::KEEP)->take(1)->value('id');
        if ($cutoff) {
            $this->db->table('chat_messages')->where('id', '<', $cutoff)->delete();
        }

        return null;
    }

    private function delete(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        // route parameters are merged into the query params by
        // Flarum\Http\RouteHandlerFactory::toController(), so /chat/{id} lands here
        $id = (int) ($request->getQueryParams()['id'] ?? 0);
        if (! $id) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $row = $this->db->table('chat_messages')->where('id', $id)->first();
        if (! $row) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        // an author may retract their own line; a moderator may remove anyone's
        $own = ! $actor->isGuest() && (int) $row->user_id === (int) $actor->id;
        if (! $own && ! Payload::canModerate($actor)) {
            return new JsonResponse(['error' => 'forbidden'], 403);
        }

        $this->db->table('chat_messages')->where('id', $id)->update([
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => $actor->isGuest() ? null : $actor->id,
        ]);

        return new JsonResponse(['ok' => true, 'id' => $id]);
    }

    /**
     * Record that this viewer is here.
     *
     * Keyed on the account for members and on the client id for guests, with
     * the source address kept beside it only so the guest count can be capped
     * per address.
     *
     * Both halves of that were measured, not assumed:
     *
     *   - address-only keys made every anonymous reader behind the tunnel share
     *     one REMOTE_ADDR, so "here" was stuck at 1 and could never move;
     *   - (address, client id) keys split ONE browser into several, because the
     *     box reaches Cloudflare over IPv4 and IPv6 and the forwarded address
     *     alternates per connection. Observed: 6 guest rows, two address
     *     hashes, three browsers.
     *
     * A viewer who logs in also has to stop being counted as the guest they
     * were a minute ago, which is what the delete below is for.
     */
    private function touchPresence(ServerRequestInterface $request, User $actor, string $cid): void
    {
        $server = $request->getServerParams();
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        $ip = trim(explode(',', $forwarded !== '' ? $forwarded : (string) ($server['REMOTE_ADDR'] ?? ''))[0]);
        $ipKey = substr(hash('sha256', $ip . '|' . date('Y-m-d')), 0, 16);

        $guestKey = 'g' . substr(hash('sha256', $cid !== ''
            ? 'cid|' . $cid
            : 'ua|' . $ipKey . '|' . ($server['HTTP_USER_AGENT'] ?? '')), 0, 30);

        $key = $actor->isGuest() ? $guestKey : 'u' . $actor->id;

        if (! $actor->isGuest()) {
            // this browser was here as a guest until it logged in
            $this->db->table('chat_presence')->where('key', $guestKey)->delete();
        }

        $this->db->table('chat_presence')->updateOrInsert(
            ['key' => $key],
            [
                'user_id' => $actor->isGuest() ? null : $actor->id,
                'username' => $actor->isGuest() ? null : $actor->username,
                'ip' => $ipKey,
                'seen_at' => date('Y-m-d H:i:s'),
            ]
        );

        // 1-in-20 sweep: aging out presence on every request is a write per
        // poll per viewer, and the read side already filters by the window.
        if (random_int(1, 20) === 1) {
            $this->db->table('chat_presence')
                ->where('seen_at', '<', date('Y-m-d H:i:s', time() - 3600))
                ->delete();
        }
    }
}
