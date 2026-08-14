<?php

namespace Local\UserInfo\Api;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\UserInfo\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Save the acting user's own post signature.
 *
 * POST /api/lmx-signature  { "signature": "..." }
 *
 * ── the actor is the subject, always ────────────────────────────────────────
 * There is deliberately no user id in the route or the body. A signature
 * renders under every post its author has written, so "edit someone else's
 * signature" is "put words in their mouth on thousands of pages" — a capability
 * with no legitimate caller. Staff who need to remove an abusive signature
 * clear the column directly or suspend the account; that path is auditable and
 * this one would not be.
 *
 * ── every limit is enforced HERE, on write ──────────────────────────────────
 * Presenter::signature() gates whether a saved signature RENDERS (feature flag,
 * account age). This gates what may be STORED. Both are needed and they are not
 * the same check: a signature saved while the feature was on must stop showing
 * when it is turned off, and lowering sigMaxLength must not silently corrupt
 * text somebody already saved.
 *
 * ── why tags are stripped rather than sanitised ─────────────────────────────
 * The column is plain text by contract (see the migration) and the decorator
 * writes it with textContent. Stripping on write means the stored value cannot
 * carry markup even if a future renderer is careless — defence at rest, not
 * only at render. strip_tags is not an XSS filter and is not being used as one;
 * it is here so the stored value matches the plain-text contract.
 */
class SignatureController implements RequestHandlerInterface
{
    public function __construct(
        protected ConnectionInterface $db,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            return new JsonResponse(['error' => 'unauthenticated'], 401);
        }

        try {
            $cfg = Config::all($this->settings);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'misconfigured'], 500);
        }

        if (empty($cfg['sigEnabled'])) {
            return new JsonResponse(['error' => 'disabled'], 404);
        }

        $body = (array) $request->getParsedBody();
        $raw = (string) ($body['signature'] ?? '');

        // Normalise newlines BEFORE counting lines, or a CRLF client is charged
        // two lines per break and silently fails a limit a LF client passes.
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $text = trim(strip_tags($text));

        $maxLen = max(0, (int) ($cfg['sigMaxLength'] ?? 280));
        $maxLines = max(1, (int) ($cfg['sigMaxLines'] ?? 4));

        // mb_strlen, not strlen: this forum is majority non-ASCII (Spanish,
        // Russian, Turkish, Arabic). Counting bytes would give an accented
        // signature roughly half the allowance of an ASCII one.
        if (mb_strlen($text) > $maxLen) {
            return new JsonResponse([
                'error' => 'too_long',
                'max' => $maxLen,
                'was' => mb_strlen($text),
            ], 422);
        }

        if (substr_count($text, "\n") + 1 > $maxLines && $text !== '') {
            return new JsonResponse([
                'error' => 'too_many_lines',
                'max' => $maxLines,
            ], 422);
        }

        // Empty is a valid value: it is how a user REMOVES their signature.
        // Stored as null rather than '' so Presenter's truthiness check and a
        // never-set row behave identically.
        $value = $text === '' ? null : $text;

        // upsert: a profile row is not guaranteed to exist. Accounts that never
        // came through the importer have no userinfo_profiles row at all, and
        // an UPDATE against a missing row would report success having written
        // nothing — the exact failure that looks like "saving does nothing".
        $this->db->table('userinfo_profiles')->updateOrInsert(
            ['user_id' => (int) $actor->id],
            ['signature' => $value]
        );

        return new JsonResponse([
            'signature' => $value,
            'max' => $maxLen,
            'maxLines' => $maxLines,
        ]);
    }
}
