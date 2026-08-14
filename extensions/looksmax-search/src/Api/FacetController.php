<?php

namespace Local\Search\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Search\Meili\MeiliException;
use Local\Search\Search\Engine;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Search WITHIN a facet's values.
 *
 * The reason this is a separate endpoint rather than a bigger
 * `facetDistribution`: the author facet on this corpus has 200,000 values and
 * the tag facet will have thousands once prefixes multiply. Shipping the
 * distribution and filtering it in the browser works at 47 tags and collapses
 * at 200,000 authors. `/facet-search` searches the values themselves, inside
 * the current result set, so "type a few letters to narrow the facet" is a
 * constant-cost interaction no matter how large the vocabulary gets.
 */
class FacetController implements RequestHandlerInterface
{
    private const ALLOWED = ['tag_names', 'prefixes', 'author', 'lang', 'group_names'];

    public function __construct(private Engine $engine)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $p = $request->getQueryParams();

        $field = (string) ($p['field'] ?? '');
        if (!in_array($field, self::ALLOWED, true)) {
            return new JsonResponse(['error' => 'unknown facet', 'allowed' => self::ALLOWED], 400);
        }

        try {
            $values = $this->engine->facetValues(
                $field,
                (string) ($p['partial'] ?? ''),
                (string) ($p['q'] ?? ''),
                $actor,
                in_array($p['type'] ?? '', ['discussions', 'posts', 'users'], true) ? $p['type'] : 'discussions'
            );
        } catch (MeiliException $e) {
            return new JsonResponse(['error' => 'engine-unavailable', 'values' => []], 200);
        }

        return new JsonResponse(['field' => $field, 'values' => $values]);
    }
}
