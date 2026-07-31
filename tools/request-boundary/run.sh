#!/usr/bin/env bash
#
# Multi-request gate: starts one php-cgi FastCGI worker and drives N real requests
# through it, asserting that the persisted object is initialized exactly once per
# worker and that its frozen state survives every RINIT/RSHUTDOWN boundary.
#
# Usage: bash tools/request-boundary/run.sh [requests]

set -euo pipefail

REQUESTS="${1:-100}"
HOST=127.0.0.1
PORT=9765
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GATE="$ROOT/tools/request-boundary/gate.php"
OUT="$(mktemp)"

command -v cgi-fcgi >/dev/null || { echo "cgi-fcgi not found (install libfcgi-bin)"; exit 1; }
command -v php-cgi >/dev/null || { echo "php-cgi not found"; exit 1; }

# One worker, recycled far beyond the request count so it never restarts mid-test
PHP_FCGI_CHILDREN=0 PHP_FCGI_MAX_REQUESTS=$((REQUESTS * 5)) \
    php-cgi -b "$HOST:$PORT" -d ffi.enable=1 -d display_errors=1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

# Wait for the socket
for _ in $(seq 1 50); do
    if (exec 3<>"/dev/tcp/$HOST/$PORT") 2>/dev/null; then exec 3>&- ; break; fi
    sleep 0.1
done

for i in $(seq 1 "$REQUESTS"); do
    SCRIPT_FILENAME="$GATE" REQUEST_METHOD=GET QUERY_STRING="i=$i" \
        cgi-fcgi -bind -connect "$HOST:$PORT" | tr -d '\r' | grep -E '^(RESULT|ERROR)' >> "$OUT" || {
            echo "Request $i produced no RESULT line"; cat "$OUT" | tail -5; exit 1;
        }
done

echo "--- last responses ---"
tail -3 "$OUT"

ERRORS=$(grep -c '^ERROR' "$OUT" || true)
RESULTS=$(grep -c '^RESULT' "$OUT" || true)
PIDS=$(grep -oE 'pid=[0-9]+' "$OUT" | sort -u | wc -l)
INITS=$(grep -c 'init=1' "$OUT" || true)
BAD_MARKERS=$(grep -vc 'marker=persistent-marker' "$OUT" || true)
BAD_PORTS=$(grep -vc 'port=5432' "$OUT" || true)

echo "requests=$REQUESTS results=$RESULTS errors=$ERRORS workers=$PIDS inits=$INITS bad_markers=$BAD_MARKERS bad_ports=$BAD_PORTS"

[ "$ERRORS" -eq 0 ] || { echo "FAIL: errors reported by the gate"; grep '^ERROR' "$OUT" | head -3; exit 1; }
[ "$RESULTS" -eq "$REQUESTS" ] || { echo "FAIL: lost responses"; exit 1; }
[ "$PIDS" -eq 1 ] || { echo "FAIL: worker recycled mid-test ($PIDS pids), gate inconclusive"; exit 1; }
[ "$INITS" -eq 1 ] || { echo "FAIL: object initialized $INITS times - persistence across requests is broken"; exit 1; }
[ "$BAD_MARKERS" -eq 0 ] || { echo "FAIL: a request observed mutated state - frozen rollback is broken"; exit 1; }
[ "$BAD_PORTS" -eq 0 ] || { echo "FAIL: persisted array corrupted across requests"; exit 1; }

echo "GATE OK: object survived $((REQUESTS - 1)) real request boundaries in one worker"
