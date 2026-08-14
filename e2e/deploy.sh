#!/usr/bin/env bash
# Deploy this repo's extensions to osprey and PROVE the result renders.
#
# This exists because of one failure mode that has now taken the forum down
# three times in a day, and that nothing about the edit looks wrong for:
#
#   Flarum compiles LESS through wikimedia/less.php. Every colour function
#   (fade, darken, lighten, mix) is evaluated at COMPILE time, so passing it a
#   CSS custom property — fade(var(--x), 40%), darken(var(--surface-1), 2%) —
#   aborts compilation. Same for `+` inside calc(): calc(var(--a) + var(--b))
#   is "Operation on an invalid type". And each()/range() are not implemented.
#
#   When that happens forum.js STILL BUILDS. forum.css is simply never written,
#   and depending on the route the browser gets either an unstyled page or the
#   compiler's stack trace where the HTML should be. rsync succeeded, the file
#   is on disk, the container is up, `docker ps` is green.
#
# So a deploy is not "the files copied". A deploy is: the app answers 200, the
# stylesheet exists, and a token that only our theme declares actually resolves
# in a real browser. All three are checked here and the exit code means it.
#
#   e2e/deploy.sh                      # everything this lane owns
#   e2e/deploy.sh looksmax-theme       # one extension, fast loop
#
# Only ever rsyncs the directories named, never --delete: generated data lives
# under extensions/*/data on the box and is not in the repo.
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="${DEPLOY_HOST:-osprey}"
REMOTE="${DEPLOY_PATH:-/work/flarum}"
BASE="${FORUM_URL:-http://127.0.0.1:8888}"

DEFAULT_EXTS=(looksmax-theme looksmax-userinfo looksmax-ranks looksmax-icons
              looksmax-index looksmax-chat looksmax-guides looksmax-search
              flarum-analytics)
EXTS=("$@")
[ ${#EXTS[@]} -eq 0 ] && EXTS=("${DEFAULT_EXTS[@]}")

fail() { printf '\n  FAIL  %s\n' "$*"; exit 1; }

for e in "${EXTS[@]}"; do
  [ -d "$REPO/extensions/$e" ] || { echo "  skip  $e (not in repo)"; continue; }
  rsync -a --exclude 'data/' "$REPO/extensions/$e/" "$HOST:$REMOTE/extensions/$e/" \
    || fail "rsync $e"
  echo "  sync  $e"
done
rsync -a "$REPO/e2e/" "$HOST:$REMOTE/e2e/" || fail "rsync e2e"
echo "  sync  e2e"

# cache:clear drops the compiled bundles; the next request rebuilds them, which
# is when LESS actually runs. Both steps matter: clearing alone proves nothing.
CLEAR=$(ssh "$HOST" "docker exec flarum-app php flarum cache:clear 2>&1" </dev/null)
echo "$CLEAR" | grep -qi 'fatal\|Exception\|Error' && { echo "$CLEAR" | head -20; fail "cache:clear errored"; }

STATUS=$(ssh "$HOST" "curl -s -o /dev/null -w '%{http_code}' '$BASE/'" </dev/null)
[ "$STATUS" = "200" ] || {
  ssh "$HOST" "docker exec flarum-app sh -c 'grep -o \"^\[2026[^#]*\" /flarum/app/storage/logs/flarum-\$(date +%Y-%m-%d).log | tail -3'" </dev/null
  fail "GET / returned $STATUS"
}

HASCSS=$(ssh "$HOST" "docker exec flarum-app ls /flarum/app/public/assets/ | grep -c '^forum.css$'" </dev/null)
[ "$HASCSS" = "1" ] || fail "forum.css was not written — a LESS colour function or calc() almost certainly got a var()"

# The sentinel. forum.css can exist and still be a stale build from before the
# edit, so check a token in a real browser rather than trusting the file.
SENTINEL=$(ssh "$HOST" "export PATH=/root/.bun/bin:\$PATH; cd $REMOTE && timeout 120 bun e2e/visual/sentinel.ts 2>&1 | tail -1" </dev/null)
echo "  token $SENTINEL"
case "$SENTINEL" in
  ok\ *) ;;
  *) fail "theme tokens did not resolve in the browser: $SENTINEL" ;;
esac

printf '\n  PASS  deployed: %s\n' "${EXTS[*]}"
