<?php

namespace Local\UserInfo;

use Flarum\Database\AbstractModel;

/**
 * The carried-over and locally-computed standing for one account.
 *
 * Deliberately dumb: no accessors that derive display values, because the same
 * derivation has to happen in the browser for users the SPA already holds and
 * two copies of a rule drift. Derivation lives in Presenter, once.
 */
class Profile extends AbstractModel
{
    protected $table = 'userinfo_profiles';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'legacy_posts' => 'int',
        'legacy_reactions' => 'int',
        'legacy_threads' => 'int',
        'reactions_here' => 'int',
        'posts_here' => 'int',
        'discussions_here' => 'int',
        'best_post_score' => 'int',
    ];

    /** @return string[] */
    public function banners(): array
    {
        $raw = $this->legacy_banners;
        if (!$raw) {
            return [];
        }
        $out = json_decode((string) $raw, true);

        return is_array($out) ? array_values(array_filter(array_map('strval', $out))) : [];
    }

    /** @return array<string,int> */
    public function mix(): array
    {
        $raw = $this->reaction_mix;
        if (!$raw) {
            return [];
        }
        $out = json_decode((string) $raw, true);

        return is_array($out) ? $out : [];
    }
}
