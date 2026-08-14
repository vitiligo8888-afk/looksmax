#!/bin/sh
# Run the shoutbox suites in order, once the forum is actually serving.
#
# The stack is shared with other lanes and the LESS compiler has taken it down
# three times today (see HANDOFF-UI.md), so the run waits for a 200 rather than
# reporting a browser failure that is really somebody else's stylesheet. The
# suites are sequential on purpose: two of them assert on exact presence counts
# and on the whole message table, which are process-wide state.
set -u
export PATH=/root/.bun/bin:$PATH
cd /work/chat-e2e || exit 1

BASE_URL=${FORUM_URL:-https://colleague-eligibility-workers-slides.trycloudflare.com}

waited=0
while [ "$waited" -lt 1800 ]; do
  code=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8888/ || echo 000)
  if [ "$code" = "200" ]; then break; fi
  echo "waiting for the forum (HTTP $code, ${waited}s)"
  sleep 20
  waited=$((waited + 20))
done
[ "$code" = "200" ] || { echo "forum never came back (HTTP $code)"; exit 2; }
echo "forum is up after ${waited}s"

# Browser.close() closes the CDP target and SIGTERMs the process, and Chrome
# survives it often enough that four abandoned browsers were still polling the
# forum an hour later — as four extra people in the presence count, which is
# exactly the number the next run then failed to predict. Only this lane's port
# range (219xx) is touched; 21800/21891/21894 belong to other lanes.
cleanup() {
  pkill -f 'user-data-dir=/tmp/visual-219' 2>/dev/null
  sleep 1
  rm -rf /tmp/visual-219* 2>/dev/null
}
cleanup

rc=0
for suite in "$@"; do
  echo ""
  echo "================================================== $suite"
  bun "chat/$suite.ts" || rc=1
  # between suites too: a suite that ends leaves its browsers alive, and the
  # next one then measures the previous one's sessions as people who are here
  cleanup
done
cleanup

echo ""
echo "================================================== done (rc=$rc)"
exit $rc
