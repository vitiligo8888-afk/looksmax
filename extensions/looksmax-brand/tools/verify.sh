#!/usr/bin/env bash
# Verify the brand against the running site, over HTTP.
#
# Everything here is asserted against what the server returned, not against
# what is on disk: an asset that exists in the repo, is published into
# public/assets and is still a 404 over HTTP is the normal failure, and
# checking the filesystem never catches it.
#
#   tools/verify.sh https://example.trycloudflare.com
set -uo pipefail
BASE="${1:?usage: verify.sh <base-url>}"
BASE="${BASE%/}"
A="$BASE/assets/extensions/local-looksmax-brand"
fail=0
pass=0

say() { printf '  %-6s %s\n' "$1" "$2"; }
ok()   { pass=$((pass+1)); say "ok" "$1"; }
bad()  { fail=$((fail+1)); say "FAIL" "$1"; }

echo "== assets: status, content-type, byte size"
check_asset() {
  local url="$1" want_type="$2" min="$3"
  read -r code type size < <(curl -sS -o /dev/null -w '%{http_code} %{content_type} %{size_download}' "$url")
  if [ "$code" != 200 ]; then bad "$url -> HTTP $code"; return; fi
  if [ "${size:-0}" -lt "$min" ]; then bad "$url -> only ${size}B (expected >= ${min})"; return; fi
  case "$type" in
    *"$want_type"*) ok "$(basename "$url")  $code  $type  ${size}B" ;;
    *) bad "$url -> content-type $type, expected $want_type" ;;
  esac
}

check_asset "$A/favicon.ico"            "image"        2000
check_asset "$A/favicon-16.png"         "image/png"     200
check_asset "$A/favicon-32.png"         "image/png"     300
check_asset "$A/icon.svg"               "image/svg"     200
check_asset "$A/apple-touch-icon.png"   "image/png"     600
check_asset "$A/icon-192.png"           "image/png"    1000
check_asset "$A/icon-512.png"           "image/png"    3000
check_asset "$A/icon-maskable-512.png"  "image/png"    1000
check_asset "$A/og.png"                 "image/png"   20000
check_asset "$A/lockup.svg"             "image/svg"    3000
check_asset "$A/email-header.png"       "image/png"    3000
check_asset "$A/site.webmanifest"       "application/manifest+json" 200
check_asset "$A/font/archivo-800.woff2" ""             5000

echo
echo "== manifest"
man=$(curl -sS "$A/site.webmanifest")
for key in '"name"' '"theme_color"' '"background_color"' 'maskable' 'icon-512.png'; do
  if grep -q -- "$key" <<<"$man"; then ok "manifest has $key"; else bad "manifest missing $key"; fi
done
for icon in $(grep -oE '"src": "[^"]+"' <<<"$man" | cut -d'"' -f4); do
  code=$(curl -sS -o /dev/null -w '%{http_code}' "$A/$icon")
  [ "$code" = 200 ] && ok "manifest icon $icon -> 200" || bad "manifest icon $icon -> $code"
done

echo
echo "== locale bundle"
# Not a brand asset, but the brand is invisible next to it: after a
# cache:clear Flarum serves a 154-byte stub for forum-en.js until something
# warms it, and every string on the page — including the forum title in the
# header — renders as a raw translation key. Caught live at 11:00 today.
loc=$(curl -sS "$BASE/" | grep -oE 'assets/forum-en\.js\?v=[a-f0-9]+' | head -1)
if [ -z "$loc" ]; then
  bad "no forum-en.js referenced by the page"
else
  locsize=$(curl -sS -o /dev/null -w '%{size_download}' "$BASE/$loc")
  if [ "$locsize" -gt 20000 ]; then ok "$loc  ${locsize}B"
  else bad "$loc is only ${locsize}B — the page is rendering translation keys; run cache:clear then warm with one request"; fi
fi
if curl -sS "$BASE/" | grep -q 'core\.forum\.'; then
  bad "raw translation keys present in the rendered document"
else
  ok "no raw translation keys in the document"
fi

echo
echo "== document head"
head_html=$(curl -sS "$BASE/" | sed -n '1,/<\/head>/p')
need_head=(
  'rel="icon" type="image/svg+xml"'
  'rel="apple-touch-icon"'
  'rel="manifest"'
  'property="og:image"'
  'property="og:image:width" content="1200"'
  'name="twitter:card" content="summary_large_image"'
  'name="theme-color" content="#0b0e14"'
  'property="og:title"'
  'property="og:description"'
  'name="description"'
)
for n in "${need_head[@]}"; do
  if grep -qF -- "$n" <<<"$head_html"; then ok "head: $n"; else bad "head: missing $n"; fi
done


# The header lockup is not a head tag: Flarum renders it server-side into the
# body as <img class="Header-logo">, and into the JS payload as logoUrl. Both
# are checked, because the noscript render and the booted SPA take different
# paths to it.
doc=$(curl -sS "$BASE/")
if grep -qE '<img[^>]+lockup\.svg[^>]+class="Header-logo"' <<<"$doc"; then
  ok "header lockup rendered as <img class=Header-logo>"
else
  bad "header lockup img not in the document"
fi
if grep -qF 'lockup.svg","faviconUrl' <<<"$doc"; then
  ok "logoUrl in the forum payload"
else
  bad "logoUrl missing from the forum payload"
fi

echo
echo "== stale identity"
body=$(curl -sS "$BASE/")
for s in 'discuss.flarum.org' 'noreply@localhost' 'admin@example.com' 'localhost:8888'; do
  if grep -qF -- "$s" <<<"$body"; then bad "page still contains '$s'"; else ok "no '$s' in the document"; fi
done
if grep -qE '<title>[^<]*Flarum' <<<"$body"; then bad "title still says Flarum"; else ok "title is not stock"; fi

echo
echo "== settings drift"
if command -v docker >/dev/null 2>&1; then
  if docker exec flarum-app php /flarum/extensions/looksmax-brand/tools/apply-identity.php --check >/tmp/brand-drift 2>&1; then
    ok "$(cat /tmp/brand-drift)"
  else
    bad "settings drifted:"; sed 's/^/         /' /tmp/brand-drift
  fi
else
  say "skip" "no docker on this host; run apply-identity.php --check on the app host"
fi
echo
printf '%d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
