#!/usr/bin/env bash
#
# Deploy looksmax-reactions and prove the forum still boots.
#
# The rule this encodes: a deploy is not "the files copied". Another lane's
# extender took this forum to HTTP 500 an hour before this one was written, and
# nothing about the edit looked wrong. So every step that can break the site is
# followed by a check that would catch it, and the LAST thing this script does
# on any failure is disable this extension and re-verify that the site came
# back — a broken reaction strip is a bug, a broken forum is an outage.
#
#   bin/deploy.sh            sync, enable, migrate, verify
#   bin/deploy.sh --off      disable and verify
#
set -uo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="${DEPLOY_HOST:-osprey}"
REMOTE="${DEPLOY_PATH:-/work/flarum}"
BASE="${FORUM_URL:-http://127.0.0.1:8888}"
EXT="local-looksmax-reactions"

r() { ssh "$HOST" "$*" </dev/null; }
dex() { ssh "$HOST" "docker exec flarum-app sh -c '$*'" </dev/null; }
say() { printf '  %-8s %s\n' "$1" "$2"; }

status() { r "curl -s -o /dev/null -w '%{http_code}' '$BASE/'"; }

panic() {
  printf '\n  FAIL   %s\n' "$*"
  printf '  ---- disabling %s and re-checking ----\n' "$EXT"
  dex "cd /flarum/app && php flarum extension:disable $EXT" >/dev/null 2>&1
  dex "cd /flarum/app && php flarum cache:clear" >/dev/null 2>&1
  say "after" "GET / -> $(status)"
  dex "grep -o '^\[2026[^#]*' /flarum/app/storage/logs/flarum-\$(date +%Y-%m-%d).log | tail -5"
  exit 1
}

BEFORE=$(status)
say "before" "GET / -> $BEFORE"
[ "$BEFORE" = "200" ] || { echo "  site was ALREADY down before this deploy — not touching it"; exit 1; }

if [ "${1:-}" = "--off" ]; then
  dex "cd /flarum/app && php flarum extension:disable $EXT"
  dex "cd /flarum/app && php flarum cache:clear"
  say "after" "GET / -> $(status)"
  exit 0
fi

rsync -a --exclude 'proof/' --exclude '.git' \
  "$REPO/" "$HOST:$REMOTE/extensions/looksmax-reactions/" || panic "rsync"
say "sync" "extensions/looksmax-reactions"

# Syntax first: a parse error in an extender is a 500 with no useful message.
BAD=$(dex 'for f in $(find /flarum/extensions/looksmax-reactions -name "*.php"); do php -l $f | grep -v "No syntax errors"; done')
[ -z "$BAD" ] || panic "php -l: $BAD"
say "lint" "php -l clean"

OUT=$(dex "cd /flarum/app && php flarum extension:enable $EXT 2>&1")
echo "$OUT" | grep -qi 'fatal\|exception\|error' && { echo "$OUT"; panic "extension:enable"; }
say "enable" "$(echo "$OUT" | tail -1)"

OUT=$(dex "cd /flarum/app && php flarum migrate 2>&1")
echo "$OUT" | grep -qi 'fatal\|exception\|sqlstate' && { echo "$OUT"; panic "migrate"; }
say "migrate" "$(echo "$OUT" | grep -c 'Migrat') migration line(s)"

# assets:publish is what copies assets/ to public/assets/extensions/<vendor>-<pkg>.
# Skipping it gives 13 reaction icons that 404 while every other check is green.
dex "cd /flarum/app && php flarum assets:publish" >/dev/null 2>&1
say "assets" "published"

OUT=$(dex "cd /flarum/app && php flarum cache:clear 2>&1")
echo "$OUT" | grep -qi 'fatal\|exception' && { echo "$OUT"; panic "cache:clear"; }
say "cache" "cleared"

AFTER=$(status)
say "after" "GET / -> $AFTER"
[ "$AFTER" = "200" ] || panic "GET / returned $AFTER"

# forum.css is written by the LESS compiler on the first request after a cache
# clear. If a colour function got a var(), forum.js still builds and this file
# silently does not exist — the exact failure that has taken this site down
# three times, and it is invisible to a 200 on the JSON API.
HASCSS=$(dex "ls /flarum/app/public/assets/ | grep -c '^forum.css$'")
[ "$HASCSS" = "1" ] || panic "forum.css was never written — a LESS colour function almost certainly got a var()"
say "css" "forum.css present"

# Our own rules must actually be IN that stylesheet, not just the file present.
# grep -c counts LINES; forum.css is minified to one, so -c returns 1 whether
# our rules compiled or not. Count occurrences.
NRULES=$(dex "grep -o 'LmxRx' /flarum/app/public/assets/forum.css | wc -l")
[ "$NRULES" -gt 20 ] || panic "forum.css exists but carries only $NRULES LmxRx rules — our LESS did not compile in"
say "css" "$NRULES LmxRx rules compiled"

# The icons: in public/ is not the same as reachable over HTTP.
for f in chrigger/24/happy.webp chrigger/48/rich.png emoji/1f44d.svg; do
  CODE=$(r "curl -s -o /dev/null -w '%{http_code}' '$BASE/assets/extensions/local-looksmax-reactions/$f'")
  [ "$CODE" = "200" ] || panic "asset $f -> HTTP $CODE"
done
say "assets" "icon spot-checks 200"

INFO=$(dex "cd /flarum/app && php flarum info 2>&1 | grep -c 'looksmax-reactions'")
[ "$INFO" -ge 1 ] || panic "php flarum info does not list the extension"
say "info" "listed by php flarum info"

printf '\n  PASS   looksmax-reactions deployed and the forum still answers 200\n'
