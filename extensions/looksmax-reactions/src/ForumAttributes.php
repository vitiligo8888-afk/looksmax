<?php

namespace Local\Reactions;

use Flarum\Api\Serializer\ForumSerializer;
use Flarum\Http\UrlGenerator;

/**
 * The catalogue, serialised once onto the forum payload.
 *
 * This is the single query that makes the whole feature free at read time: the
 * client boots holding every enabled reaction with its display name, tint,
 * group, ordering and resolved asset URLs, so drawing a strip or opening the
 * picker costs no request at all.
 */
class ForumAttributes
{
    public function __construct(private UrlGenerator $url)
    {
    }

    public function __invoke(ForumSerializer $serializer, $model, array $attributes): array
    {
        // ROOT-RELATIVE, deliberately, not $this->url->to('forum')->path('').
        //
        // Flarum's configured url is a single absolute origin and this install
        // is reached through several: 127.0.0.1:8888 for the deploy gate, a
        // Cloudflare quick tunnel for review, and https://looksmax.lat once
        // config.php was pointed at it. The moment the configured origin stops
        // matching the one the browser is on, every absolute asset URL 404s and
        // every icon in the strip renders as a broken image -- observed live,
        // with the site's own logo broken alongside them.
        //
        // A root-relative path is correct under all of them and costs nothing.
        $base = '';

        $attributes['lmxReactions'] = Reaction::query()
            ->where('enabled', true)
            ->orderBy('position')
            ->get()
            ->map(fn (Reaction $r) => [
                'id' => (int) $r->id,
                'slug' => $r->slug,
                'display' => $r->display ?: $r->slug,
                'type' => $r->type,
                'tint' => $r->tint,
                'group' => $r->grp,
                'position' => (int) $r->position,
                'points' => (int) $r->points,
                'urls' => $r->urls($base),
            ])
            ->values()
            ->all();

        $attributes['lmxReactionsAssetBase'] = rtrim($base, '/') . '/' . Catalog::ASSET_PREFIX;
        $attributes['canReact'] = $serializer->getActor()->hasPermission('lmxreactions.react');

        return $attributes;
    }
}
