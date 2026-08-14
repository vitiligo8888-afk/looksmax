<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Search\Semantic;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `POST /api/looksmax/search/duplicates`  `{"title": "...", "body": "..."}`
 *
 * "Has this been asked before?" — for a draft, from the composer, before
 * anything has been posted. This is the one discovery surface that has to work
 * on text that does not exist in the index yet, which is why it embeds the
 * draft directly instead of using `/similar` on a document id.
 *
 * POST rather than GET for one reason: a thread body does not fit in a query
 * string, and silently truncating it would make the check quietly useless on
 * exactly the long, effortful posts where a duplicate matters most.
 *
 * Each result carries `semanticScore`, `keywordScore` and a `confidence` band.
 * The band exists so a UI does not have to invent a threshold: `high` is worth
 * interrupting the writer for, `low` belongs in a collapsed "similar threads"
 * list. Interrupting somebody mid-post with a bad guess is how this feature
 * gets switched off.
 *
 * No rate limiting is applied here beyond Flarum's own. It is an authenticated
 * write-shaped endpoint doing one embedding call and two engine queries; if it
 * turns out to need throttling, the honest place is Flarum's throttler rather
 * than a counter invented in this class.
 */
class DuplicatesController implements RequestHandlerInterface
{
    public function __construct(private Semantic $semantic)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = json_decode((string) $request->getBody(), true) ?: [];
        }
        // Accept both a flat body and a JSON:API-ish `data.attributes` wrapper,
        // because Flarum's own JS client sends the latter by habit.
        $attrs = $body['data']['attributes'] ?? $body;

        $title = trim((string) ($attrs['title'] ?? ''));
        $content = trim((string) ($attrs['body'] ?? $attrs['content'] ?? ''));

        if ($title === '' && $content === '') {
            return new JsonResponse([
                'error' => 'title or body required',
                'usage' => 'POST /api/looksmax/search/duplicates {"title": "...", "body": "..."}',
            ], 422);
        }

        $out = $this->semantic->duplicates($title, $content, $actor, (int) ($attrs['limit'] ?? 5));
        $out['available'] = $this->semantic->available();

        return new JsonResponse($out);
    }
}
