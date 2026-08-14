#!/usr/bin/env bash
#
# Deploy extension changes to looksmax-prod, safely.
#
# TWO failures this exists to prevent, both of which already happened:
#
#  1. 1,284 lines of new code went straight to the live extension directory,
#     fatally errored during the WEB request only (php -l and `php flarum` both
#     passed, because the fault never surfaced in CLI), and served 500s until it
#     was rolled back by hand.
#
#  2. The FIRST version of this script opened one SSH connection per health
#     probe. Seven rapid connections tripped the box's fail2ban jail mid-deploy,
#     so the automatic rollback could not connect and the site stayed down.
#     THAT is why everything below runs in exactly TWO connections: one to
#     upload, one to do all the work remotely. Never add a per-probe ssh call.
#
# Flow, entirely inside connection 2:
#   snapshot -> swap -> lint -> migrate -> rebuild -> probe -> rollback on fail
#             -> re-probe to PROVE the rollback restored service
#
# Usage:  tools/deploy.sh looksmax-economy [looksmax-store ...]
set -uo pipefail

SSH=(ssh -o BatchMode=yes -o ConnectTimeout=45 -o ControlMaster=no -o ControlPath=none looksmax)
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TS="$(date +%Y%m%dT%H%M%SZ)"

if [ $# -lt 1 ]; then
  echo "usage: $0 <extension-name> [...]" >&2
  exit 2
fi

EXTS=("$@")
PATHS=()
for e in "${EXTS[@]}"; do
  [ -d "$REPO/extensions/$e" ] || { echo "no such extension: $e" >&2; exit 2; }
  PATHS+=("extensions/$e")
done

echo "==> deploying: ${EXTS[*]}"

# ---- connection 1: stage the payload OUTSIDE the live tree ----------------
# Staged to /tmp first so the live directory is never in a half-written state,
# and so a transport failure costs nothing.
( cd "$REPO" && tar cf - "${PATHS[@]}" ) \
  | "${SSH[@]}" "rm -rf /tmp/deploy-stage-$TS && mkdir -p /tmp/deploy-stage-$TS && tar xf - -C /tmp/deploy-stage-$TS && echo '    staged'" \
  || { echo "!! upload failed, nothing changed" >&2; exit 1; }

# ---- connection 2: everything else ---------------------------------------
"${SSH[@]}" "bash -s" <<REMOTE
set -u
APP=/srv/looksmax/app
STAGE=/tmp/deploy-stage-$TS
ROLLBACK=/tmp/deploy-rollback-$TS
EXTS="${EXTS[*]}"
PROBES="/ /all /t/mejores-guias /d/30660-lip-lift-results-2-weeks-post-op-pictures-included /store"

probe_all() {
  local bad=""
  for p in \$PROBES; do
    code=\$(curl -s -o /dev/null -w '%{http_code}' -m 25 -H 'Host: looksmax.lat' "http://127.0.0.1:80\$p")
    printf '    %-64s %s\n' "\$p" "\$code"
    case "\$code" in 2*|3*|401|403) ;; *) bad="\$bad \$p(\$code)";; esac
  done
  echo "\$bad" > /tmp/probe-bad-$TS
}

restore() {
  for e in \$EXTS; do
    [ -d "\$ROLLBACK/\$e" ] && { rm -rf "\$APP/extensions/\$e"; cp -a "\$ROLLBACK/\$e" "\$APP/extensions/"; }
  done
  chown -R root:root "\$APP/extensions"
  docker exec flarum-app php flarum cache:clear >/dev/null 2>&1
  sleep 2
}

echo "==> snapshot -> \$ROLLBACK"
mkdir -p "\$ROLLBACK"
for e in \$EXTS; do cp -a "\$APP/extensions/\$e" "\$ROLLBACK/" 2>/dev/null; done

echo "==> swapping in"
for e in \$EXTS; do
  rm -rf "\$APP/extensions/\$e"
  cp -a "\$STAGE/extensions/\$e" "\$APP/extensions/"
done
chown -R root:root "\$APP/extensions"

echo "==> linting"
LINTBAD=0
for e in \$EXTS; do
  while read -r f; do
    rel=\${f#\$APP/extensions/}
    out=\$(docker exec flarum-app php -l "/flarum/extensions/\$rel" 2>&1 | tail -1)
    case "\$out" in *"No syntax errors"*) ;; *) echo "    LINT \$rel :: \$out"; LINTBAD=1;; esac
  done < <(find "\$APP/extensions/\$e" -name '*.php' -type f)
done
if [ "\$LINTBAD" = "1" ]; then
  echo "==> lint failed; rolling back"
  restore
  probe_all
  exit 1
fi

echo "==> migrate + rebuild"
docker exec flarum-app php flarum migrate 2>&1 | grep -viE 'nothing to migrate|^migrating extension' | tail -3
docker exec flarum-app php flarum cache:clear >/dev/null 2>&1
sleep 2

# BUST THE CDN, or the deploy is invisible.
#
# Flarum serves /assets/forum.css?v=<rev> with Cache-Control max-age=2592000 —
# thirty days — and Cloudflare honours it. That is correct ONLY if <rev> changes
# when the content does, and measured here it does NOT: a CSS edit recompiled
# the file while rev-manifest.json kept the same hash, so the URL was identical
# and Cloudflare kept serving a stale stylesheet (cf-cache-status HIT, Age 1758)
# with the old rules in it. The fix had shipped to the origin and no reader
# could see it.
#
# Rewriting the manifest hash changes the query string, which is a new URL to
# the CDN and therefore a guaranteed miss. Cheap, and it makes "I deployed CSS
# and nothing changed" impossible.
NEWREV=\$(date +%s%N | md5sum | cut -c1-8)
docker exec -e NEWREV="\$NEWREV" flarum-app php -r '
\$f = "/flarum/app/public/assets/rev-manifest.json";
if (!is_file(\$f)) { exit; }
\$m = json_decode(file_get_contents(\$f), true) ?: [];
if (isset(\$m["forum.css"])) { \$m["forum.css"] = getenv("NEWREV"); }
file_put_contents(\$f, json_encode(\$m));
echo "    css rev -> " . getenv("NEWREV") . PHP_EOL;
' 2>/dev/null

echo "==> probing"
probe_all
BAD=\$(cat /tmp/probe-bad-$TS)

if [ -n "\$(echo \$BAD | tr -d ' ')" ]; then
  echo "==> FAILED:\$BAD"

  # WHY it failed, before we throw the evidence away by rolling back.
  #
  # A LESS compile error is the highest-frequency cause of "every page 500s
  # with an empty body and nothing in any log": Flarum compiles the stylesheet
  # during a WEB request, so php -l passes, \`php flarum\` passes, and the
  # exception escapes before the error handler can render or log anything.
  # The tell is forum.css: on a failed compile it is never rewritten, so it is
  # either missing or older than the deploy. Checking it here turns a
  # multi-hour bisect into one line of output.
  #
  # Known trigger in this repo: an unescaped calc()/env() the PHP port of LESS
  # tries to evaluate as arithmetic. Wrap the value in ~"..." to pass it
  # through untouched.
  echo "==> diagnosing"
  docker exec flarum-app sh -lc '
    f=/flarum/app/public/assets/forum.css
    if [ ! -f "\$f" ]; then
      echo "    forum.css MISSING -> LESS compile failed. Suspect an unescaped calc()/env() in the LESS you just deployed; wrap it in ~\"...\"."
    else
      age=\$(( \$(date +%s) - \$(stat -c %Y "\$f") ))
      echo "    forum.css age \${age}s, size \$(stat -c %s "\$f")"
      [ "\$age" -gt 120 ] && echo "    forum.css is STALE -> LESS compile failed. Suspect an unescaped calc()/env(); wrap it in ~\"...\"."
    fi
  ' 2>/dev/null
  docker exec flarum-app sh -lc 'tail -5 /flarum/app/storage/logs/flarum-$(date +%Y-%m-%d).log 2>/dev/null | head -5' 2>/dev/null

  echo "==> rolling back"
  restore
  echo "==> verifying rollback"
  probe_all
  echo "==> snapshot kept at \$ROLLBACK"
  exit 1
fi

echo "==> deployed clean (rollback snapshot: \$ROLLBACK)"
REMOTE
