<?php

namespace Local\Uploads;

use Flarum\User\AvatarValidator;

/**
 * Accept the image formats people actually have.
 *
 * Flarum 1.8 allows jpeg, jpg, png, bmp and gif for avatars. Upload a .webp —
 * which is what Chrome saves images as by default, and what most of the web now
 * serves — and you get "The avatar must be a file of type: jpeg, jpg, png, bmp,
 * gif", which reads to a user as "that isn't an image". That is exactly how it
 * was reported.
 *
 * Two halves are needed and only one is obvious:
 *   1. this allowlist, and
 *   2. GD compiled --with-webp --with-avif. Without it `AvatarValidator` gets
 *      past the mime check and then fails inside `imageManager->make()` with a
 *      NotReadableException, which surfaces as the SAME unhelpful message. The
 *      container's GD had `WebP Support =>` empty and `AVIF Support =>` empty;
 *      the Dockerfile now builds both, and the running container was patched.
 *
 * `getAllowedTypes()` is the extension point, not the `$rules` array — 1.8's
 * validator does not use Laravel rules for this at all, it hand-rolls the mime
 * check against this list, so overriding `$rules` changes nothing (it looks
 * like it works, because the error message starts coming from your namespace).
 */
class AvatarFormats extends AvatarValidator
{
    protected function getAllowedTypes(): array
    {
        return array_merge(parent::getAllowedTypes(), ['webp', 'avif']);
    }
}
