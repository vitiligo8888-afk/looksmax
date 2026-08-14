<?php

use Flarum\Extend;
use Flarum\User\AvatarValidator;
use Local\Uploads\UploadsProvider;

return [
    // Replacing the validator outright rather than extending its rules: the
    // rule set is a protected property, not a mutable list, so Extend\Validator
    // cannot reach it in 1.8.
    (new Extend\ServiceProvider())->register(UploadsProvider::class),
];
