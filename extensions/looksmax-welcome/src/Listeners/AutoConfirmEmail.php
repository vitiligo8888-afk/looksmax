<?php

namespace Local\Welcome\Listeners;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Registered;
use Local\Welcome\Config;

/**
 * Mark a new account's email confirmed at registration, when — and only when —
 * `welcome.autoConfirm` is on.
 *
 * Why this exists is in Config::KEYS next to the setting: on this install
 * outbound mail cannot be delivered at all (busybox sendmail relaying to a
 * 127.0.0.1:25 that nothing listens on), so the confirmation link never
 * arrives, the account never leaves the unconfirmed state, and Flarum keeps it
 * out of the Member group — which is what actually blocks posting. The forum
 * had open registration and no reachable way to finish it.
 *
 * `is_email_confirmed` is the single flag that gate turns on: HANDOFF-STORE.md
 * §7 recorded the same mechanism from the other direction, where 1,410 imported
 * accounts could not react to a post because they were unconfirmed and so were
 * not Members. Setting it here is the same one-column fix, applied at the
 * moment the account is created rather than in a batch afterwards.
 *
 * Guarded three ways, because a listener that throws during registration would
 * turn a broken signup into a 500:
 *
 *   - the setting is read per event, so switching it off takes effect on the
 *     next registration with no deploy and no restart;
 *   - an account that somehow arrives already confirmed is left alone rather
 *     than written to a second time;
 *   - anything unexpected is swallowed. A failure here must cost verification,
 *     never the account — the user has already been created by the time this
 *     runs, and the registration must be allowed to complete.
 */
class AutoConfirmEmail
{
    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    public function handle(Registered $event): void
    {
        if (! Config::all($this->settings)['autoConfirm']) {
            return;
        }

        $user = $event->user ?? null;
        if (! $user || ! $user->id || $user->is_email_confirmed) {
            return;
        }

        try {
            $user->is_email_confirmed = true;
            $user->save();
        } catch (\Throwable $e) {
            // Never break a registration over this. Worst case the account
            // exists unconfirmed, which is exactly where it would have been
            // without this listener.
        }
    }
}
