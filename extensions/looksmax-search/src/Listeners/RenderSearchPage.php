<?php

namespace Local\Search\Listeners;

use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Local\Search\Search\Engine;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Server-render the results page.
 *
 * Flarum is a single-page app, so the obvious implementation is "boot the app,
 * fetch, draw". That is measurably worse for the one page where it matters
 * most: a search result is the page a reader is most likely to arrive at cold,
 * from a link or a bookmark, and making them watch a spinner while the bundle
 * boots and then a second round trip runs is two waits for something the server
 * could have had ready in one.
 *
 * So the first page of results is rendered into the document, and the script
 * adopts it. Subsequent searches are client-side and never pay for this again.
 * It also means the results page has real content for a crawler, and that the
 * page still shows results if the JS bundle fails entirely — the same
 * degradation posture as the rest of the extension.
 *
 * Nothing here can fail the page: any throw returns silently and leaves the
 * client to fetch, which is the behaviour we would have had anyway.
 */
class RenderSearchPage
{
    public function __construct(private Engine $engine)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $type = (string) ($params['type'] ?? 'all');

        $document->title = $q === ''
            ? resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-search.forum.page.title')
            : resolve(\Symfony\Contracts\Translation\TranslatorInterface::class)->trans('local-looksmax-search.forum.page.title_with_query', ['query' => $q]);

        if ($q === '') {
            $document->head[] = '<script id="lmx-search-preload" type="application/json">'
                . $this->safeJson(['empty' => true]) . '</script>';

            return;
        }

        // A search results page must never be indexed as a content page: it
        // would put an unbounded set of thin, duplicate URLs into an index.
        $document->head[] = '<meta name="robots" content="noindex,follow">';

        try {
            $actor = RequestUtil::getActor($request);
            $result = $this->engine->search($q, $actor, [
                'type' => in_array($type, ['all', 'discussions', 'posts', 'users', 'tags'], true) ? $type : 'all',
                'limit' => 20,
                'offset' => max(0, (int) ($params['offset'] ?? 0)),
                'facets' => true,
            ]);
            if (count($result['results']) === 0) {
                $result['recovery'] = $this->engine->recover($q, $actor);
            }
        } catch (\Throwable $e) {
            return; // the client will fetch; a slow page beats a broken one
        }

        $document->head[] = '<script id="lmx-search-preload" type="application/json">'
            . $this->safeJson($result) . '</script>';
    }

    /**
     * `</script>` inside a JSON string closes the element the JSON is inside.
     * Escaping the slash is the only thing standing between a post title and a
     * script-injection on the results page.
     */
    private function safeJson(array $data): string
    {
        // JSON_HEX_TAG turns every `<` and `>` into \u003C / \u003E, so no
        // string in the payload can close the element that contains it.
        // JSON_HEX_AMP/APOS/QUOT close the remaining HTML-context escapes.
        // JSON_UNESCAPED_UNICODE is deliberately NOT used: U+2028 and U+2029
        // are legal inside a JSON string but are line terminators in
        // JavaScript, and are the classic way a payload that parses as JSON
        // fails to parse as a script.
        return json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '{}';
    }
}
