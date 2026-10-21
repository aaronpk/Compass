#!/bin/bash
# Compare two tests/http/run.sh output directories, masking values that change
# on every run: session cookies (the cookie driver also sets one named after
# the random session id) and their expiry, IndieAuth state, random
# tokens and share URLs, multipart boundaries and tempnam() upload names, and the calendar's "today".
#
#   tests/http/compare.sh <baseline-out> <new-out> [--ignore REGEX ...]
DIR=$(cd "$(dirname "$0")" && pwd)
A=$1; B=$2; shift 2
IGNORE=(
  --ignore '(laravel_session=)[^;]+'
  --ignore '\b[0-9a-f]{40}=[^;]+'
  --ignore '(expires=)[^;]+'
  --ignore '(state=)[A-Za-z0-9_%-]+'
  --ignore '(boundary=)[A-Za-z0-9_.-]+'
  --ignore '(/s/)[A-Za-z0-9]{15}'
  --ignore '(\\/s\\/)[A-Za-z0-9]{15}'
  --ignore '("name":"compass)[A-Za-z0-9]{6}"'
  --ignore '(token=)[A-Za-z0-9]{15}\b'
  --ignore '(Date: )[^\\]+'
  --ignore '(Max-Age=)[0-9]+'
  --ignore '(id="share_token" value=")[A-Za-z0-9]{15}'
)
status=0
for sub in . get; do
  [ -d "$A/responses/$sub" ] || continue
  python3 -I "$DIR/replay.py" compare "$A/responses/$sub" "$B/responses/$sub" "${IGNORE[@]}" "$@" | sed "s#^#[$sub] #"
  [ "${PIPESTATUS[0]}" = "0" ] || status=1
done
exit $status
