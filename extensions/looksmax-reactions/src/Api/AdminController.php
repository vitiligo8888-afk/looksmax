<?php

namespace Local\Reactions\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Reactions\Reaction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Admin control over the catalogue: which reactions exist, which are on, and
 * in what order.
 *
 *   GET    /api/lmx/reactions              every reaction, enabled or not
 *   PATCH  /api/lmx/reactions/{id}         enable/disable, rename, retint, repoint
 *   POST   /api/lmx/reactions/order        {"order": ["happy","jfl", ...]}
 *   DELETE /api/lmx/reactions/{id}         only when it has no rows
 *
 * DELETE refuses on a reaction that has ever been used. fof/reactions has this
 * exact operation wired to ON DELETE CASCADE with no guard, which is why
 * reordering there (delete + recreate, its only reordering mechanism) destroys
 * history. Here ordering is a column, so deletion is never needed to reorder,
 * and a delete that would destroy rows is refused with the count that would
 * have been lost. Disable is what the admin actually wants and it is lossless.
 */
class AdminController implements RequestHandlerInterface
{
    // Constructor-injected connection, NOT the DB facade. Flarum does not boot
    // Laravel's facade layer, so Illuminate\Support\Facades\DB::table() throws
    // "A facade root has not been set" — a 500 that only appears on the code
    // path that uses it, which is why this one survived a green deploy and was
    // caught by the e2e run instead.
    public function __construct(private \Illuminate\Database\ConnectionInterface $db)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $method = $request->getMethod();
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $body = (array) ($request->getParsedBody() ?: []);
        $path = $request->getUri()->getPath();

        if ($method === 'GET') {
            return new JsonResponse(['reactions' => Reaction::query()
                ->orderBy('position')->get()->map(fn ($r) => $r->toArray())->all()]);
        }

        if ($method === 'POST' && str_ends_with($path, '/order')) {
            $order = (array) Arr::get($body, 'order', []);
            $pos = 0;
            foreach ($order as $slug) {
                Reaction::query()->where('slug', $slug)->update(['position' => $pos += 10]);
            }

            return new JsonResponse(['ok' => true, 'ordered' => count($order)]);
        }

        if ($method === 'PATCH') {
            $r = Reaction::query()->findOrFail($id);
            foreach (['display', 'tint', 'grp', 'points', 'position', 'enabled'] as $f) {
                if (array_key_exists($f, $body)) {
                    $r->$f = $body[$f];
                }
            }
            $r->save();

            return new JsonResponse(['reaction' => $r->toArray()]);
        }

        if ($method === 'DELETE') {
            $r = Reaction::query()->findOrFail($id);
            $used = $this->db->table('post_reactions')
                ->where('reaction_id', $id)->count();
            $legacy = $this->db->table('legacy_post_reactions')
                ->where('reaction_id', $id)->count();
            if ($used || $legacy) {
                return new JsonResponse([
                    'error' => 'in-use',
                    'native' => $used,
                    'legacy' => $legacy,
                    'hint' => resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)
                        ->trans('local-looksmax-reactions.admin.error.in_use_hint'),
                ], 409);
            }
            $r->delete();

            return new JsonResponse(['ok' => true]);
        }

        return new JsonResponse(['error' => 'unsupported'], 405);
    }
}
