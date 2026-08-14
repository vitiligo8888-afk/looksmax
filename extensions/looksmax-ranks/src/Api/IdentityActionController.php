<?php

namespace Local\Ranks\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Local\Economy\Ledger;
use Local\Ranks\Catalog;
use Local\Ranks\Standing;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Write side: buy, equip, retitle, showcase.
 *
 * Every one of these re-derives entitlement from the database. The store UI
 * hides what you cannot afford and greys out what your tier cannot equip, and
 * none of that is load-bearing — the UI is a hint, this file is the rule.
 *
 * Purchases are ordered so a crash cannot produce a paid-for-nothing: the ledger
 * debit happens first and, if the inventory insert then fails, the debit is
 * refunded in the same request. The inventory insert is idempotent, so the
 * failure mode of a double-submit is "already owned", not "charged twice".
 */
class IdentityActionController implements RequestHandlerInterface
{
    public function __construct(
        protected Standing $standing,
        protected Ledger $ledger,
        protected ConnectionInterface $db
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest()) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.guest')], 401);
        }

        $body = (array) $request->getParsedBody();
        $action = (string) ($request->getAttribute('routeParameters')['action'] ?? '');

        try {
            return match ($action) {
                'buy' => $this->buy($actor, $body),
                'equip' => $this->equip($actor, $body),
                'tier' => $this->buyTier($actor, $body),
                'title' => $this->title($actor, $body),
                'showcase' => $this->showcase($actor, $body),
                'accent' => $this->accent($actor, $body),
                default => new JsonResponse(['error' => Catalog::trans('forum.error.unknown_action')], 404),
            };
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.failed'), 'detail' => $e->getMessage()], 500);
        }
    }

    // ------------------------------------------------------------------- buy

    private function buy($actor, array $body): ResponseInterface
    {
        $type = (string) ($body['type'] ?? '');
        $slug = (string) ($body['item'] ?? '');

        $item = $type === 'frame' ? Catalog::frame($slug) : Catalog::style($slug);
        if (!$item || ($type === 'style' && $item['kind'] === 'rank')) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.no_such_item')], 404);
        }
        if ((int) $item['price'] === 0) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.not_for_sale', ['item' => $item['name']])], 403);
        }
        if ($this->standing->owns((int) $actor->id, $type, $slug)) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.already_owned')], 409);
        }

        $price = $this->standing->priceFor($actor, $item);
        $spent = $this->ledger->spend((int) $actor->id, $price, 'store.purchase', $type . ':' . $slug);

        if ($spent === 0) {
            $short = $price - (int) $actor->points;

            return new JsonResponse([
                'error' => Catalog::trans('forum.error.not_enough_points'),
                'short' => max(0, $short),
                'price' => $price,
            ], 402);
        }

        if (!$this->standing->grant((int) $actor->id, $type, $slug, 'purchase', $price)) {
            // refund rather than leave someone charged for nothing. credit(),
            // not award(): award() would apply the buyer's tier earn bonus and
            // hand back more than was taken.
            $this->ledger->credit((int) $actor->id, $price, 'store.refund', $type . ':' . $slug, false);

            return new JsonResponse(['error' => Catalog::trans('forum.error.grant_failed')], 500);
        }

        // buying something you can wear should put it on
        $this->standing->equip($actor->refresh(), $type, $slug);

        return new JsonResponse([
            'ok' => true,
            'bought' => $slug,
            'paid' => $price,
            'balance' => (int) $this->db->table('users')->where('id', $actor->id)->value('points'),
        ]);
    }

    // ----------------------------------------------------------------- equip

    private function equip($actor, array $body): ResponseInterface
    {
        $type = (string) ($body['type'] ?? '');
        $item = $body['item'] ?? null;
        $item = ($item === null || $item === '' || $item === 'none') ? null : (string) $item;

        $error = $this->standing->equip($actor, $type, $item);
        if ($error) {
            return new JsonResponse(['error' => $error], 403);
        }

        return new JsonResponse(['ok' => true, 'equipped' => $item, 'type' => $type]);
    }

    // ------------------------------------------------------------------ tier

    private function buyTier($actor, array $body): ResponseInterface
    {
        $slug = (string) ($body['tier'] ?? '');
        $tier = Catalog::tier($slug);

        if ($tier['slug'] !== $slug) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.no_such_tier')], 404);
        }
        if ((int) $tier['price'] === 0) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.tier_granted_only', ['tier' => $tier['name']])], 403);
        }

        $current = $this->standing->activeTier($actor);
        if ($current['rank'] > $tier['rank']) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.tier_downgrade', ['current' => $current['name'], 'target' => $tier['name']])], 409);
        }

        $price = (int) $tier['price'];
        // The ref carries the purchase instant, not just the tier. A membership
        // is renewable by design, and a ref of just the slug would collide with
        // the buyer's own first purchase on the ledger's uniqueness constraint —
        // making every renewal after the first impossible.
        $spent = $this->ledger->spend((int) $actor->id, $price, 'tier.purchase', $slug . '@' . time());
        if ($spent === 0) {
            return new JsonResponse([
                'error' => Catalog::trans('forum.error.not_enough_points'),
                'short' => max(0, $price - (int) $actor->points),
                'price' => $price,
            ], 402);
        }

        $this->standing->grantTier((int) $actor->id, $slug, 'purchase', $price);

        return new JsonResponse([
            'ok' => true,
            'tier' => $slug,
            'days' => $tier['days'],
            'balance' => (int) $this->db->table('users')->where('id', $actor->id)->value('points'),
        ]);
    }

    // ----------------------------------------------------------------- title

    /**
     * Custom user title.
     *
     * Length is bounded by tier, control characters and the zero-width padding
     * trick are stripped — the source board's data has 70 accounts whose entire
     * title is U+200E and 21 whose title is U+2800, used to fake a blank line
     * and shove their own name up the post header. Not here.
     */
    private function title($actor, array $body): ResponseInterface
    {
        $tier = $this->standing->activeTier($actor);
        if ($tier['titleLen'] === 0) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.title_needs_tier')], 403);
        }

        $raw = (string) ($body['title'] ?? '');
        $title = $this->cleanTitle($raw);

        if ($title !== '' && mb_strlen($title) > $tier['titleLen']) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.title_too_long', ['count' => (int) $tier['titleLen']])], 422);
        }
        if ($raw !== '' && $title === '') {
            return new JsonResponse(['error' => Catalog::trans('forum.error.title_empty')], 422);
        }

        $color = (string) ($body['color'] ?? '');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null;
        if ($color && !$tier['accent']) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.title_colour_needs_tier')], 403);
        }

        $this->db->table('users')->where('id', $actor->id)->update([
            'custom_title' => $title ?: null,
            'title_color' => $color,
        ]);

        return new JsonResponse(['ok' => true, 'title' => $title, 'color' => $color]);
    }

    private function cleanTitle(string $s): string
    {
        // strip zero-width, bidi controls, braille blank, and every C0/C1 control
        $s = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{2800}\x{3164}\x{115F}\x{1160}\x{17B4}\x{17B5}]/u', '', $s);
        $s = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $s);
        $s = preg_replace('/\s+/u', ' ', (string) $s);

        return trim((string) $s);
    }

    // -------------------------------------------------------------- showcase

    /** Pin badges to the trophy case, bounded by the tier's slot count. */
    private function showcase($actor, array $body): ResponseInterface
    {
        $tier = $this->standing->activeTier($actor);
        $slugs = array_values(array_unique(array_map('strval', (array) ($body['badges'] ?? []))));

        if (count($slugs) > $tier['showcase']) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.showcase_too_many', ['count' => (int) $tier['showcase']])], 422);
        }

        $owned = $this->db->table('identity_badges')->where('user_id', $actor->id)->pluck('badge')->all();
        $unknown = array_diff($slugs, $owned);
        if ($unknown) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.showcase_unearned', ['badges' => implode(', ', $unknown)])], 403);
        }

        $this->db->table('identity_badges')->where('user_id', $actor->id)
            ->update(['showcased' => 0, 'slot' => 0]);

        foreach ($slugs as $i => $slug) {
            $this->db->table('identity_badges')->where('user_id', $actor->id)->where('badge', $slug)
                ->update(['showcased' => 1, 'slot' => $i]);
        }

        return new JsonResponse(['ok' => true, 'showcase' => $slugs]);
    }

    // ---------------------------------------------------------------- accent

    private function accent($actor, array $body): ResponseInterface
    {
        $tier = $this->standing->activeTier($actor);
        if (!$tier['accent']) {
            return new JsonResponse(['error' => Catalog::trans('forum.error.accent_needs_tier')], 403);
        }

        $color = (string) ($body['color'] ?? '');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null;

        $this->db->table('users')->where('id', $actor->id)->update(['profile_accent' => $color]);

        return new JsonResponse(['ok' => true, 'accent' => $color]);
    }
}
