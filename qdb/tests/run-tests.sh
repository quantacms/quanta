#!/bin/sh
# Conformance suite runner. Each test file runs as its own PHP process
# (extension config binds once per process), and the whole suite runs twice:
# once in fallback mode (no daemon) and once against a live qdbd serving the
# tree from shared memory.
#   QDB_EXT=/path/to/quanta_db.so [QDBD_BIN=/path/to/qdbd] sh tests/run-tests.sh
#   QDB_MODES=fallback sh tests/run-tests.sh   # restrict to one mode
set -u

EXT="${QDB_EXT:-quanta_db}"
DIR="$(cd "$(dirname "$0")" && pwd)/php"
MODES="${QDB_MODES:-fallback daemon}"

# Default qdbd: next to the extension (cargo target dir layout), else PATH.
if [ -z "${QDBD_BIN:-}" ]; then
    ext_dir=$(dirname "$EXT" 2>/dev/null || echo .)
    if [ -x "$ext_dir/qdbd" ]; then
        QDBD_BIN="$ext_dir/qdbd"
    else
        QDBD_BIN="qdbd"
    fi
fi

fail=0
for mode in $MODES; do
    echo "==== mode: $mode"
    for t in "$DIR"/[0-9]*.php; do
        echo "== $(basename "$t") [$mode]"
        QDB_EXT="$EXT" QDB_MODE="$mode" QDBD_BIN="$QDBD_BIN" \
            php -n -d extension="$EXT" "$t" || fail=1
    done
done

if [ "$fail" -eq 0 ]; then
    echo "ALL TEST FILES PASSED"
else
    echo "SOME TESTS FAILED"
    exit 1
fi
