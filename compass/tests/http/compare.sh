#!/bin/bash
# Compare two tests/http/run.sh output directories, masking values that change
# on every run: session cookies (the cookie driver also sets one named after
# the random session id) and their expiry, IndieAuth state, random
# tokens and share URLs, multipart boundaries and tempnam() upload names, and the calendar's "today".
#
#   tests/http/compare.sh <baseline-out> <new-out> [--ignore REGEX ...]
#
# Set ACCEPT_L12=1 when comparing the Lumen 5.1 baseline with Laravel 12. It
# applies normalize-l12.py, which evens out formatting that changed with the
# framework without changing behavior. Everything else still has to match.
DIR=$(cd "$(dirname "$0")" && pwd)
A=$1; B=$2; shift 2
if [ "${ACCEPT_L12:-}" = "1" ]; then
  TMP=$(mktemp -d)
  trap 'rm -rf "$TMP"' EXIT
  for side in A B; do
    mkdir -p "$TMP/$side"
    cp -r "${!side}/responses" "$TMP/$side/responses"
    python3 -I "$DIR/normalize-l12.py" "$TMP/$side/responses"
  done
  A=$TMP/A; B=$TMP/B
fi
IGNORE=(
  --ignore '(laravel_session=)[^;]+'
  --ignore '\b[A-Za-z0-9]{40}=[^;]+'
  --ignore '(expires=)[^;]+'
  --ignore '(state=)[A-Za-z0-9_%-]+'
  --ignore '(boundary=)[A-Za-z0-9_.-]+'
  --ignore '(/s/)[A-Za-z0-9]{15}'
  --ignore '(\\/s\\/)[A-Za-z0-9]{15}'
  --ignore '("name":"compass)[A-Za-z0-9]+"'
  --ignore '(token=)[A-Za-z0-9]{15}\b'
  --ignore '(Date: )[^\\]+'
  --ignore '(Max-Age=)[0-9]+'
  --ignore '(id="share_token" value=")[A-Za-z0-9]{15}'
  --ignore '(data-today=")[0-9-]+'
  --ignore '(embed/world/)[A-Za-z0-9]{24}'
  --ignore '(embed\\/world\\/)[A-Za-z0-9]{24}'
  --ignore '([?]v=)[0-9]+'
  --ignore '(code_challenge=)[A-Za-z0-9_-]+'
  --ignore '(code_verifier=)[A-Za-z0-9_-]+'
  # The built-in server stopped echoing the request's Host header in PHP 8.6
  --drop '(?mi)^host: [^\n]*\n'
)
status=0
for sub in . get; do
  [ -d "$A/responses/$sub" ] || continue
  python3 -I "$DIR/replay.py" compare "$A/responses/$sub" "$B/responses/$sub" "${IGNORE[@]}" "$@" | sed "s#^#[$sub] #"
  [ "${PIPESTATUS[0]}" = "0" ] || status=1
done
exit $status
