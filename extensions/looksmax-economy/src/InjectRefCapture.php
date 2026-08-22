<?php

namespace Local\Economy;

use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Referral capture, client side.
 *
 * When a visitor arrives on any looksmax.lat URL carrying ?ref=<id>, drop the
 * value into a first-party cookie that survives until they register. The server
 * reads it at the Registered event (Listeners\ReferralCapture) — a cookie is
 * used, not the registration payload, precisely because this extension does not
 * (and must not) override core's SignUpModal, so there is no form field to
 * carry the value; the cookie rides the eventual /register POST for free.
 *
 * Shipped as its own tiny inline <script> for the same isolation reason every
 * other injector here documents: a throw must not take neighbours down. The
 * script is a few bytes, guarded, and does nothing at all when ?ref is absent.
 * It is only emitted when referral.enabled is on, so the default install adds
 * literally nothing to the page.
 */
class InjectRefCapture
{
    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        if (! (bool) Config::get($this->settings, 'referral.enabled')) {
            return;
        }

        // Only digits are ever accepted (the ref is a user id); the regex both
        // finds the param and sanitises it, so nothing user-controlled reaches
        // document.cookie unescaped. Overwrite an existing lmx_ref only when a
        // fresh, valid ?ref is present — a plain page load must not clobber a
        // ref captured on the landing visit.
        $js = <<<'JS'
try{var m=location.search.match(/[?&]ref=(\d{1,12})(?:&|$)/);if(m){document.cookie="lmx_ref="+m[1]+";path=/;max-age=2592000;samesite=lax";}}catch(e){}
JS;

        $document->head[] = '<script data-lmx-ref>' . $js . '</script>';
    }
}
