<?php

namespace Local\Reactions;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property string $slug
 * @property string $type   image | svg
 * @property string $asset
 * @property string $tint
 * @property string $grp
 * @property int $position
 * @property int|null $xf_id
 * @property int $points
 * @property bool $enabled
 */
class Reaction extends AbstractModel
{
    protected $table = 'reactions';

    protected $casts = [
        'enabled' => 'boolean',
        'position' => 'integer',
        'points' => 'integer',
        'xf_id' => 'integer',
    ];

    protected $fillable = [
        'identifier', 'slug', 'type', 'display', 'asset', 'tint',
        'grp', 'position', 'xf_id', 'points', 'enabled',
    ];

    /**
     * The URLs the frontend needs to draw this reaction, resolved server-side
     * so the client never has to know the publish path or the size ladder.
     *
     * An `svg` reaction is one file at any size. An `image` reaction is a
     * ladder, and the caller gets the whole thing: avif first, then webp, then
     * png, so the frontend can emit a <picture> and let the browser pick.
     */
    public function urls(string $base): array
    {
        $p = rtrim($base, '/') . '/' . Catalog::ASSET_PREFIX . '/' . $this->asset;

        if ($this->type === 'svg') {
            return ['svg' => $p . '.svg'];
        }

        $out = [];
        foreach (['avif', 'webp', 'png'] as $fmt) {
            $set = [];
            foreach (Catalog::IMAGE_SIZES as $s) {
                $set[(string) $s] = preg_replace('#/([^/]+)$#', "/$s/\\1", $p) . '.' . $fmt;
            }
            $out[$fmt] = $set;
        }

        return $out;
    }
}
