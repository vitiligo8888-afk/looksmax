<?php

namespace Local\Uploads;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\User\AvatarValidator;

class UploadsProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->bind(AvatarValidator::class, AvatarFormats::class);
    }
}
