#!/bin/sh
# Quanta call-site parity suite.
#
# qdb/tests/php/ proves the extension obeys the contract. This suite proves
# QUANTA obeys itself: every call site wired to the node database gives the same
# answer whether the extension is there or not.
#
# It runs the same tests in three modes:
#   noext    — the .so is not loaded; every shim takes its legacy path
#   fallback — extension loaded, no daemon (per-process filesystem walk)
#   daemon   — qdbd owns the tree in shared memory and is authoritative
#
# Unlike run-tests.sh this needs a real Quanta checkout (src/ + vendor/), so it
# is meant to run inside the image:
#
#   docker run --rm -v "$PWD/qdb/tests:/tests:ro" <image> \
#       sh /tests/run-quanta-tests.sh
#
#   QUANTA_ROOT=/var/www/quanta   where the checkout lives
#   QDB_MODES="noext fallback"    restrict the modes
#   QDBD_BIN=/path/to/qdbd        default: found on PATH
set -u

DIR="$(cd "$(dirname "$0")" && pwd)/quanta"
QUANTA_ROOT="${QUANTA_ROOT:-/var/www/quanta}"
MODES="${QDB_MODES:-noext fallback daemon}"

if [ ! -d "$QUANTA_ROOT/src" ]; then
    echo "QUANTA_ROOT=$QUANTA_ROOT has no src/ — run this inside the image." >&2
    exit 1
fi

# noext mode: PHP has no "disable one extension" flag, so run against a copy of
# conf.d with quanta-db.ini removed. Every other extension (gd, intl, curl…)
# stays loaded — `php -n` would drop those too and test something else entirely.
# This is what QUANTA_DB_ENABLED=0 does in docker-entrypoint.sh.
NOEXT_INI=/tmp/qdb-noext-conf.d
rm -rf "$NOEXT_INI"
mkdir -p "$NOEXT_INI"
for ini in /usr/local/etc/php/conf.d/*.ini; do
    [ -f "$ini" ] && cp "$ini" "$NOEXT_INI/"
done
rm -f "$NOEXT_INI/quanta-db.ini"

fail=0
for mode in $MODES; do
    echo "==== mode: $mode"
    for t in "$DIR"/[0-9]*.php; do
        echo "== $(basename "$t") [$mode]"
        if [ "$mode" = noext ]; then
            PHP_INI_SCAN_DIR="$NOEXT_INI" QUANTA_ROOT="$QUANTA_ROOT" \
                php "$t" || fail=1
        else
            QDB_MODE="$mode" QUANTA_ROOT="$QUANTA_ROOT" \
                QDBD_BIN="${QDBD_BIN:-qdbd}" php "$t" || fail=1
        fi
    done
done

if [ "$fail" -eq 0 ]; then
    echo "ALL QUANTA PARITY TESTS PASSED"
else
    echo "SOME QUANTA PARITY TESTS FAILED"
    exit 1
fi
