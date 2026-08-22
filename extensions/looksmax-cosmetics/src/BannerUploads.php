<?php

namespace Local\Cosmetics;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;

/**
 * Custom profile banner upload: validate, re-render, store, moderate.
 *
 * See migrations/2026_08_15_000000_create_banner_uploads.php for the schema
 * and the reasoning against Defs.php's original "deliberately not
 * user-uploaded" call. The short version of what changed: that call was
 * correct against the alternative of shipping upload with NO moderation path.
 * This class is the moderation path — every safeguard below exists because
 * that gap would otherwise still be open.
 *
 * ── the pipeline, and why each step is there ────────────────────────────────
 *
 *   1. eligibility    tier gate (VIP+, the same tier Catalog::TIERS already
 *                      flags `banner => true` for) AND not banned for repeat
 *                      rejections. Checked again server-side on every write —
 *                      see store()'s own re-check, never trust the caller.
 *   2. size ceiling    8 MB read cap before ANYTHING touches GD, so a hostile
 *                      upload cannot pressure memory before validation even
 *                      starts.
 *   3. real decode     `getimagesize` on the BYTES, never the client's
 *                      Content-Type or filename extension — those are
 *                      attacker-controlled and prove nothing.
 *   4. format allowlist  jpeg/png/webp only. GIF is refused outright: an
 *                      animated banner is a UX problem this store already
 *                      declined to sell (no animated banner exists in
 *                      Defs::banners()) and refusing the format is simpler
 *                      and safer than decoding just the first frame.
 *   5. dimension sanity  reject before decode if the source is absurd
 *                      (>6000px either side) — a decompression-bomb-shaped
 *                      input never reaches imagecreatefromstring().
 *   6. full re-render  ALWAYS resampled into a fresh 1600×400 canvas and
 *                      re-encoded as JPEG from scratch. This is what actually
 *                      neutralises the class of attack a raw pass-through
 *                      upload could not: EXIF payloads, polyglot files,
 *                      steganographic data, anything riding in a metadata
 *                      block — none of it survives being decoded to a raw
 *                      pixel buffer and re-encoded. The OUTPUT format and
 *                      dimensions are fixed regardless of the input's.
 *   7. deterministic filename  `lmx-banner-<user_id>.jpg`, on the SAME
 *                      `flarum-avatars` disk looksmax-ranks already proved
 *                      serves correctly (Api/IdentityController.php:301). One
 *                      file per account: a re-upload overwrites it, so
 *                      storage cannot grow without bound and a moderation
 *                      purge is one delete.
 *
 * ── what happens to an abusive image ────────────────────────────────────────
 *
 *   Two paths reach moderate(): a community report (report(), rate-limited to
 *   one per reporter per target so a pile-on cannot be faked past a threshold
 *   the caller does not need to guess) and an admin/mod action from the
 *   moderation queue (queue(), sorted by report count). moderate() deletes
 *   the file, unequips it (clears cosmetic_loadout.banner if it was 'custom'
 *   — a banner that no longer exists must not be the thing a profile tries to
 *   render), and increments reject_count. Past BAN_AFTER rejections the
 *   account is permanently banned from this feature (eligible() checks the
 *   flag) — the same escalation shape the mission's "what happens to an
 *   abusive image" question is actually asking for: a repeat offender loses
 *   the privilege, not just the one image.
 */
class BannerUploads
{
    public const TARGET_W = 1600;
    public const TARGET_H = 400;
    public const MAX_INPUT_BYTES = 8 * 1024 * 1024; // read/decode ceiling
    public const MAX_SOURCE_DIM = 6000;             // reject before decode past this on either side
    public const MIN_SOURCE_W = 300;
    public const MIN_SOURCE_H = 75;
    public const BAN_AFTER = 3; // rejections before eligible() permanently refuses this account
    public const DISK = 'flarum-avatars'; // reuse the disk already proven to serve — see class docblock

    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(protected ConnectionInterface $db)
    {
    }

    /** VIP+ (same tier Catalog::TIERS already flags `banner => true` for) and not banned. */
    public function eligible(?string $tierSlug, ?string $tierExpires): bool
    {
        if (!class_exists(\Local\Ranks\Catalog::class)) {
            return false; // no tier system installed: no gate to check against, so no upload
        }
        if ($tierExpires !== null && strtotime($tierExpires) < time()) {
            $tierSlug = 'standard';
        }
        if (!in_array($tierSlug, Ownership::tiersAtOrAbove('vip'), true)) {
            return false;
        }

        return true;
    }

    public function banned(int $userId): bool
    {
        return (bool) $this->db->table('cosmetic_banner_uploads')->where('user_id', $userId)->value('banned');
    }

    public function row(int $userId): ?object
    {
        return $this->db->table('cosmetic_banner_uploads')->where('user_id', $userId)->first();
    }

    /** Public URL of an ACTIVE custom banner, or null. Queries the row to confirm it is actually active. */
    public function url(int $userId): ?string
    {
        $row = $this->row($userId);
        if (!$row || $row->status !== 'active') {
            return null;
        }

        return $this->diskUrl($row->filename);
    }

    /**
     * The URL a live custom banner WOULD have, built from the deterministic
     * filename with NO query.
     *
     * Only safe to call where the caller already has another reason to
     * believe the upload is live — see Ownership::live(), whose own comment
     * explains why `cosmetic_loadout.banner === 'custom'` already implies
     * this without a re-check: moderate() and remove() both clear that same
     * loadout column in the same write that invalidates the file, so a
     * per-user query here would be redundant, and Ownership::live() is the
     * hot path Ownership.php's own performance note is about — one call per
     * user in a 50-user discussion listing. Never use this to decide whether
     * to SHOW a banner, only to compute the URL once something else already
     * decided one should render.
     */
    public function urlFor(int $userId): string
    {
        return $this->diskUrl('lmx-banner-' . $userId . '.jpg');
    }

    private function diskUrl(string $filename): string
    {
        return resolve(FilesystemFactory::class)->disk(self::DISK)->url($filename);
    }

    /**
     * Validate, re-render and store one upload. Returns the new row's public
     * data on success, or a translation-key string on refusal — never throws
     * for an ordinary bad upload, only for something GD itself cannot recover
     * from (caught by the controller).
     *
     * @param string $bytes    raw file contents, already size-capped by the caller
     * @return array{filename:string,url:string}|string
     */
    public function store(int $userId, string $bytes, ?string $tierSlug, ?string $tierExpires)
    {
        if (!$this->eligible($tierSlug, $tierExpires)) {
            return 'error.banner_not_eligible';
        }
        if ($this->banned($userId)) {
            return 'error.banner_banned';
        }
        if (strlen($bytes) === 0 || strlen($bytes) > self::MAX_INPUT_BYTES) {
            return 'error.banner_too_large';
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return 'error.banner_not_an_image';
        }

        [$width, $height, $type] = $info;

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return 'error.banner_bad_format';
        }
        if ($width > self::MAX_SOURCE_DIM || $height > self::MAX_SOURCE_DIM) {
            return 'error.banner_too_large';
        }
        if ($width < self::MIN_SOURCE_W || $height < self::MIN_SOURCE_H) {
            return 'error.banner_too_small';
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return 'error.banner_not_an_image'; // getimagesize lied, or a corrupt/truncated file — same refusal either way
        }

        try {
            $dest = $this->coverResample($src, $width, $height);
        } finally {
            imagedestroy($src);
        }

        if ($dest === false) {
            return 'error.banner_failed';
        }

        ob_start();
        imagejpeg($dest, null, 85);
        $out = ob_get_clean();
        imagedestroy($dest);

        if ($out === false || $out === '') {
            return 'error.banner_failed';
        }

        $filename = 'lmx-banner-' . $userId . '.jpg';

        try {
            resolve(FilesystemFactory::class)->disk(self::DISK)->put($filename, $out);
        } catch (\Throwable $e) {
            return 'error.banner_failed';
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('cosmetic_banner_uploads')->updateOrInsert(
            ['user_id' => $userId],
            [
                'filename' => $filename,
                'mime' => 'image/jpeg',
                'width' => self::TARGET_W,
                'height' => self::TARGET_H,
                'bytes' => strlen($out),
                'status' => 'active', // a fresh upload always starts under review-by-report again, not carrying a prior rejection forward
                'uploaded_at' => $now,
                'moderated_by' => null,
                'moderated_at' => null,
                'moderation_reason' => null,
            ]
        );

        return ['filename' => $filename, 'url' => $this->diskUrl($filename)];
    }

    /**
     * Resample source image onto a TARGET_W x TARGET_H canvas, cropping to
     * cover (never stretching) — the same "cover" behaviour the generated
     * banner plates already read as on every profile.
     */
    private function coverResample($src, int $srcW, int $srcH)
    {
        $dest = imagecreatetruecolor(self::TARGET_W, self::TARGET_H);
        if ($dest === false) {
            return false;
        }

        $targetRatio = self::TARGET_W / self::TARGET_H;
        $srcRatio = $srcW / max(1, $srcH);

        if ($srcRatio > $targetRatio) {
            // source is wider than target: crop the sides
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);
            $srcX = (int) round(($srcW - $cropW) / 2);
            $srcY = 0;
        } else {
            // source is taller than target: crop top/bottom
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
            $srcX = 0;
            $srcY = (int) round(($srcH - $cropH) / 2);
        }

        imagecopyresampled(
            $dest, $src,
            0, 0, $srcX, $srcY,
            self::TARGET_W, self::TARGET_H, max(1, $cropW), max(1, $cropH)
        );

        return $dest;
    }

    /** Remove a member's own upload — deletes the file, the row, and unequips it if it was worn. */
    public function remove(int $userId): void
    {
        $row = $this->row($userId);
        if (!$row) {
            return;
        }

        try {
            resolve(FilesystemFactory::class)->disk(self::DISK)->delete($row->filename);
        } catch (\Throwable $e) {
            // a file that is already gone is not a failure worth surfacing
        }

        $this->db->table('cosmetic_banner_uploads')->where('user_id', $userId)->delete();
        $this->unequipIfCustom($userId);
        $this->db->table('cosmetic_banner_reports')->where('user_id', $userId)->delete();
    }

    /** One report per (banner owner, reporter). Returns false if this reporter already reported this banner. */
    public function report(int $bannerOwnerId, int $reporterId, ?string $reason): bool
    {
        if ($bannerOwnerId === $reporterId) {
            return false;
        }

        try {
            $this->db->table('cosmetic_banner_reports')->insert([
                'user_id' => $bannerOwnerId,
                'reporter_id' => $reporterId,
                'reason' => $reason !== null ? mb_substr(trim($reason), 0, 255) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Throwable $e) {
            return false; // already reported by this member — idempotent, not an error
        }
    }

    /**
     * Admin/mod action: reject the current banner. Deletes the file,
     * unequips it, and counts toward the permanent ban threshold.
     */
    public function moderate(int $bannerOwnerId, int $moderatorId, ?string $reason): bool
    {
        $row = $this->row($bannerOwnerId);
        if (!$row) {
            return false;
        }

        try {
            resolve(FilesystemFactory::class)->disk(self::DISK)->delete($row->filename);
        } catch (\Throwable $e) {
        }

        $rejectCount = (int) $row->reject_count + 1;

        $this->db->table('cosmetic_banner_uploads')->where('user_id', $bannerOwnerId)->update([
            'status' => 'rejected',
            'reject_count' => $rejectCount,
            'banned' => $rejectCount >= self::BAN_AFTER,
            'moderated_by' => $moderatorId,
            'moderated_at' => date('Y-m-d H:i:s'),
            'moderation_reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
        ]);

        $this->unequipIfCustom($bannerOwnerId);
        $this->db->table('cosmetic_banner_reports')->where('user_id', $bannerOwnerId)->delete();

        return true;
    }

    private function unequipIfCustom(int $userId): void
    {
        $this->db->table('cosmetic_loadout')->where('user_id', $userId)->where('banner', 'custom')
            ->update(['banner' => null, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * The moderation queue: every ACTIVE custom banner, report count first —
     * an image nobody has flagged sinks to the bottom, which is what makes a
     * two-person moderation team's time go to the right place first.
     */
    public function queue(int $limit = 100): array
    {
        $reports = $this->db->table('cosmetic_banner_reports')
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) c')->pluck('c', 'user_id');

        $rows = $this->db->table('cosmetic_banner_uploads AS b')
            ->join('users AS u', 'u.id', '=', 'b.user_id')
            ->where('b.status', 'active')
            ->orderByDesc('b.uploaded_at')
            ->limit($limit)
            ->get(['b.user_id', 'u.username', 'b.filename', 'b.uploaded_at', 'b.reject_count', 'b.width', 'b.height', 'b.bytes']);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'userId' => (int) $r->user_id,
                'username' => $r->username,
                'url' => $this->diskUrl($r->filename),
                'uploadedAt' => (string) $r->uploaded_at,
                'reportCount' => (int) ($reports[$r->user_id] ?? 0),
                'rejectCount' => (int) $r->reject_count,
                'width' => (int) $r->width,
                'height' => (int) $r->height,
                'bytes' => (int) $r->bytes,
            ];
        }

        usort($out, fn ($a, $b) => $b['reportCount'] <=> $a['reportCount']);

        return $out;
    }
}
