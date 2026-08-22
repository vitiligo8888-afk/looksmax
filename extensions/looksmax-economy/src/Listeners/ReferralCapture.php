<?php

namespace Local\Economy\Listeners;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\Registered;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Local\Economy\Config;
use Local\Economy\Ledger;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Referral capture, server side.
 *
 * At the one moment a referee's account comes into being, read the lmx_ref
 * cookie the landing script (InjectRefCapture) left, record the edge, and pay
 * the referrer their immediate join bonus. The larger qualify bonus waits for
 * proof of life — see ReferralQualify.
 *
 * Every branch here fails open: a missing cookie, a self-referral, a ref that
 * is not a real user, a request that cannot be resolved, or a referee already
 * attributed all end the handler quietly. A referral must never be able to
 * fail a registration.
 */
class ReferralCapture
{
    public function __construct(
        protected Ledger $ledger,
        protected SettingsRepositoryInterface $settings,
        protected ConnectionInterface $db,
    ) {
    }

    public function handle(Registered $event): void
    {
        if (! (bool) Config::get($this->settings, 'referral.enabled')) {
            return;
        }

        $referee = $event->user;
        $refereeId = (int) $referee->id;
        if ($refereeId <= 0) {
            return;
        }

        // The request (and thus the cookie) is per-request bound; resolving it
        // in an event is best-effort, so guard it.
        $referrerId = 0;
        try {
            $request = Container::getInstance()->make(ServerRequestInterface::class);
            $raw = $request->getCookieParams()['lmx_ref'] ?? '';
            $referrerId = (int) preg_replace('/\D/', '', (string) $raw);
        } catch (\Throwable $e) {
            return;
        }

        if ($referrerId <= 0 || $referrerId === $refereeId) {
            return; // no ref, or a self-referral
        }

        // The referrer has to be a real, non-suspended account. A ref pointing
        // at a deleted/guest id pays nobody.
        $referrer = $this->db->table('users')->where('id', $referrerId)->first(['id']);
        if (! $referrer) {
            return;
        }

        // One referrer per referee, first ref wins. The unique index on
        // referee_id makes the insert the lock; a duplicate throws and we stop
        // — the referee was already attributed, so nothing is paid twice.
        try {
            $this->db->table('lmx_referrals')->insert([
                'referrer_id' => $referrerId,
                'referee_id' => $refereeId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            return;
        }

        // countsForRank:false — a referral must not buy rank. Idempotent by ref
        // (refj:<refereeId>): even if this fired twice, the ledger pays once.
        $join = (int) Config::get($this->settings, 'referral.joinBonus');
        if ($join > 0) {
            $this->ledger->credit($referrerId, $join, 'referral.joined', 'refj:' . $refereeId, false);
        }
    }
}
