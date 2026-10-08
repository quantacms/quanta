#!/bin/sh
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
python3 "$ROOT/tests/search/mock_es.py" >/tmp/quanta-search-mock.log 2>&1 &
PID=$!
trap 'kill "$PID" 2>/dev/null || true' EXIT INT TERM
sleep 1
php "$ROOT/tests/search/integration.php"
php "$ROOT/tests/search/smoke.php"
