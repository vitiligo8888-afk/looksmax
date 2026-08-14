# Store lane: changes needed in files I do not own

Written by the economy/store lane. Everything below is outside
`extensions/looksmax-store/**` and `extensions/looksmax-economy/**`, so it is
recorded here rather than edited. Each item has the file, the line, what is
wrong, and the patch.

Status of the bug that started this lane: **fixed, no edit needed elsewhere.**
`extensions/looksmax-ranks/js/dist/forum.js:386` sets the credits chip's href to
`/store`, which was a 404. `/store` now exists as a real server-side frontend
route (`extensions/looksmax-store/extend.php`), so the chip works as written.
Proven by clicking the chip itself in `extensions/looksmax-store/e2e/browser.ts`:

```
== the credits chip actually goes somewhere ==
  ok   the header carries a credits chip pointing at the store  (/store)
  ok   that URL answers 200 rather than 404  (200)
  ok   clicking the chip lands on the store  (/store | 1)
```

---

## 1. looksmax-ranks: `Standing::refreshTier()` caches the wrong expiry

**File:** `extensions/looksmax-ranks/src/Standing.php`, in `refreshTier()`, the
loop over active membership rows.

**What happens:** the loop keeps `$expires` from the FIRST row it sees at the
highest rank, not the latest one. `grantTier()` inserts one row per purchase
(that table is deliberately a history), so from the second renewal onwards
`users.tier_expires_at` is pinned to the first month's end date.

**Measured**, user 3015 after three purchases of Looksmax+:

```
identity_memberships.expires_at: 2026-09-12, 2026-10-12, 2026-11-11   (all active=1)
users.tier_expires_at:           2026-09-12
```

So a renewal reads to the buyer as a purchase that did nothing, and the tier
lapses two months early.

**Patch:**

```php
 $best = Catalog::tier('standard');
 $expires = null;
 foreach ($rows as $r) {
     $t = Catalog::tier($r->tier);
-    if ($t['rank'] > $best['rank']) {
-        $best = $t;
-        $expires = $r->expires_at;
-    }
+    if ($t['rank'] > $best['rank']) {
+        $best = $t;
+        $expires = $r->expires_at;
+    } elseif ($t['slug'] === $best['slug']) {
+        // Several active rows for the same tier is the normal shape after a
+        // renewal: the membership runs until the LAST of them ends. A null
+        // expiry is a lifetime membership and outranks any date.
+        if ($expires !== null && ($r->expires_at === null || strtotime((string) $r->expires_at) > strtotime((string) $expires))) {
+            $expires = $r->expires_at;
+        }
+    }
 }
```

Until this lands, `Local\Store\Grants\TierGrant::trueExpiry()` writes the correct
value back after every membership purchase. That compensation can be deleted
once the patch is in, and the check in
`extensions/looksmax-store/e2e/api.ts` ("buying a second month extends the
expiry rather than replacing it") will keep it honest either way.

## 2. looksmax-ranks: two stores now sell the same things

**File:** `extensions/looksmax-ranks/src/Api/IdentityActionController.php`,
methods `buy()` and `buyTier()`.

Those endpoints predate this extension and still charge for styles, frames and
memberships directly through the ledger. They work, but they write no order, no
entitlement and no audit row, so anything bought through them is invisible to
the purchase history, to refunds, to `store:reconcile` and to the admin screen —
and if an admin reprices an item in the store, that path keeps charging the old
price from `Catalog`.

**Suggested:** have both delegate to `Local\Store\Purchase::buy()`:

```php
public function __construct(..., protected \Local\Store\Purchase $purchase) {}

private function buy($actor, array $body): ResponseInterface
{
    $sku = ($body['type'] === 'frame' ? 'frame-' : 'style-') . ($body['item'] ?? '');
    $result = $this->purchase->buy($actor, $sku, ['key' => $body['key'] ?? '']);
    $status = $result['status'];
    unset($result['status']);

    return new JsonResponse($result, $status);
}
```

`equip`, `title`, `showcase` and `accent` are identity concerns and should stay
where they are. If you would rather keep the endpoints as they are, say so and
the store will treat `identity_inventory` as a shared table it does not own —
but then a refund of something bought through that path cannot work.

## 3. looksmax-ranks: named VIP colours the source board actually sold

**File:** `extensions/looksmax-ranks/src/Catalog.php` (`STYLES`) plus a rule per
class in `extensions/looksmax-ranks/less/styles.less`.

The scrape has the real list, recovered from posts 4933638, 18287756 and
24166405, and from discussion 329 on the live forum ("Six New Animated VIP
Color's Dropped"): **VIP Blue, Green, Red, Yellow, Cosmic, Purple, Storm, Until
Dawn, Blood, Black, Orange, Pink, Biohazard, Raspberry**, plus the six later
animated ones: **Azure, Winter, Slime, Striped, Ajax, Away**. Full citations in
`extensions/looksmax-store/RESEARCH.md` §2.

The store already sells everything `Catalog::STYLES` declares and picks up new
entries with `php flarum store:sync` — one command, no store change needed. Four
of those names (Azure, Winter, Slime, Striped) already exist under exactly those
names; the other sixteen do not. They are the single highest-value catalogue
addition available, because they are the only cosmetics on this forum with
evidence that people paid real money for them.

## 4. flarum/pusher throws twelve console errors on every page

Not any lane's extension, but it fails the "console errors are defects" bar for
everybody's e2e:

```
Uncaught (in promise) :: You must pass your app key when you instantiate Pusher.
Failed to resolve module specifier '//cdn.jsdelivr.net/npm/pusher-js@7.0.3/...'
```

`flarum-pusher` is enabled with no app key configured. Either configure it or
`php flarum extension:disable flarum-pusher`. The store's browser suite filters
these out by name so they cannot be mistaken for store defects, and prints a
count instead.

## 5. A VIP-only section, which is what the source board actually sold

The membership tiers already project onto real Flarum groups
(`identity_memberships` -> `group_user`, via `Standing::syncGroups`). The source
board's product page (post 4933638) lists "exclusive subforum" as a headline
benefit, and it is the one benefit on that list this forum does not yet have.

It needs no code at all: create a tag, and in the permissions grid give
`VIP`/`Elite`/`Founder` the view permission for it. Whoever owns the tag
taxonomy should decide whether that section exists. Recording it here so it is
not lost.

## 6. Optional: make the credits chip navigate in-app

**File:** `extensions/looksmax-ranks/js/dist/forum.js:386`

The chip is an `<a href="/store">`, so clicking it reloads the whole SPA. It
works (proven above) but costs a full boot. If you want it to route in-app:

```js
 chip = el('a', 'lmx-points');
 chip.href = '/store';
+chip.addEventListener('click', function (e) {
+  // Full page loads are correct as a fallback; the SPA router is faster when
+  // the store route is registered, which it is from the store's head script.
+  if (e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
+  var app = window.flarum && flarum.core && flarum.core.compat['forum/app'];
+  app = app && app.default;
+  if (app && app.routes && app.routes.store && window.m) {
+    e.preventDefault();
+    m.route.set('/store');
+  }
+});
```

Also worth considering, since the chip is the only entry point to the store:
a second header item, or a link in the main nav. The store adds neither, because
the header is the theme lane's surface.

## 7. Import lane: 1,410 of 1,413 accounts cannot react, so they cannot earn

Measured on 2026-08-13:

```sql
select count(*) from users where is_email_confirmed = 1;   -- 3
```

Flarum only puts confirmed accounts in the Member group, and `discussion.likePosts`
belongs to Members. Every like from an imported account therefore answers 403,
which the economy sees as no reaction at all: the new `reaction.received` award
can never fire for the imported population, and neither can anything else that
needs a member permission.

It shows up as a store problem — nobody earns, so nobody can buy — but the fix
belongs wherever accounts are created:

```sql
update users set is_email_confirmed = 1 where is_email_confirmed = 0;
```

or set the flag at import time. Worth deciding deliberately rather than by
default, because it also decides whether those accounts can post.

## 8. Minor: the Striped name style renders as broken text in screenshots

`extensions/looksmax-ranks/less/styles.less`, `.ns-striped`. The barber-pole
animation uses a repeating gradient with `background-clip: text`, and at most
frames the glyphs are cut through the middle — in a still it reads as corrupted
text rather than as a stripe ("l se: ıar ıe" in the store's card preview). Every
other animated style photographs fine. Worth widening the stripe or slowing the
sweep; the store sells it either way, and this is only visible in screenshots
because the animation never stops moving in a live browser.
