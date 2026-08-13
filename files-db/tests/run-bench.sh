#!/bin/sh
# Legacy-vs-extension parity tests + performance benchmark.
#   QDB_EXT=/path/to/quanta_db.so sh tests/run-bench.sh
# From the built image:
#   docker run --rm quanta-db sh /ext/tests/run-bench.sh
#
# Runs in DAEMON mode by default: that is the deployed configuration, and it is
# the only one in which reads are served from shared memory. Benchmarking the
# fallback path against legacy compares two ways of reading the same files and
# tells you nothing about the index — which is what this script used to do,
# because it never set QDB_MODE and qdb_daemon_mode() defaults to false.
#   QDB_MODE=fallback sh tests/run-bench.sh   # measure the degraded path
set -u

EXT="${QDB_EXT:-/usr/local/lib/php/extensions/quanta_db.so}"
DIR="$(cd "$(dirname "$0")" && pwd)"
MODE="${QDB_MODE:-daemon}"

# Default qdbd: next to the extension (cargo target dir layout), else PATH.
if [ -z "${QDBD_BIN:-}" ]; then
    ext_dir=$(dirname "$EXT" 2>/dev/null || echo .)
    if [ -x "$ext_dir/qdbd" ]; then
        QDBD_BIN="$ext_dir/qdbd"
    else
        QDBD_BIN="qdbd"
    fi
fi

echo "== bench mode: $MODE"
QDB_EXT="$EXT" QDB_MODE="$MODE" QDBD_BIN="$QDBD_BIN" \
    php -n -d extension="$EXT" "$DIR/bench/bench.php" "$@"
