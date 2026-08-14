#!/bin/sh
# Deploy the store to osprey and prove the forum still boots — or take the
# extension back out.
#
# Six agents share one live instance. A boot error in any extender takes the
# WHOLE site to 500 for every visitor, including the other five lanes and the
# operator, so "it probably works" is not an acceptable state to leave behind.
# This script is the gate: nothing stays enabled that has not just answered 200
# on the index, the store and the discussions API in that order.
#
# Usage (from the repo root):  sh extensions/looksmax-store/bin/deploy.sh
set -e

HOST=${HOST:-osprey}
EXT=local-looksmax-store
BASE=http://127.0.0.1:8888

echo "→ syncing"
rsync -rc --delete --exclude e2e/out extensions/looksmax-store/ "$HOST":/work/flarum/extensions/looksmax-store/

echo "→ clearing cache"
ssh "$HOST" "docker exec flarum-app php /flarum/app/flarum cache:clear >/dev/null"

# `flarum info` boots the whole application, so an extender that throws shows up
# here without waiting for an HTTP request and without a visitor seeing it.
echo "→ boot check"
if ! ssh "$HOST" "docker exec flarum-app php /flarum/app/flarum info 2>&1 | grep -q 'Base URL'"; then
  echo "BOOT FAILED — disabling $EXT"
  ssh "$HOST" "docker exec flarum-app php /flarum/app/flarum extension:disable $EXT || true"
  ssh "$HOST" "docker exec flarum-app php /flarum/app/flarum info 2>&1 | tail -30"
  exit 1
fi

echo "→ http check"
FAIL=""
for path in / /all /store "/api/discussions?page%5Blimit%5D=3" /api/store/catalogue; do
  code=$(ssh "$HOST" "curl -s -o /dev/null -w '%{http_code}' '$BASE$path'")
  printf '   %-42s %s\n' "$path" "$code"
  [ "$code" = "200" ] || FAIL="$FAIL $path=$code"
done

if [ -n "$FAIL" ]; then
  echo "HTTP CHECK FAILED:$FAIL — disabling $EXT to restore the site"
  ssh "$HOST" "docker exec flarum-app php /flarum/app/flarum extension:disable $EXT"
  ssh "$HOST" "docker exec flarum-app php /flarum/app/flarum cache:clear >/dev/null"
  code=$(ssh "$HOST" "curl -s -o /dev/null -w '%{http_code}' $BASE/")
  echo "site after disable: $code"
  exit 1
fi

echo "→ ok, all green"
