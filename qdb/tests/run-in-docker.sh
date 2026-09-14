#!/bin/sh
# Run both suites against a REAL build of the extension, in Docker.
#
# run-tests.sh and run-quanta-tests.sh both need things a developer machine
# usually does not have: the cdylib built against this exact PHP ABI (which
# needs PHP headers), and for the parity suite a full Quanta checkout with its
# vendor tree. That gap is not academic: without this, a change can be written,
# reviewed and unit-tested while `fallback` and `daemon` mode have never run at
# all. This script is the missing step, so nobody has to reconstruct the
# invocation again.
#
# Two images, because the two suites need different things:
#
#   conformance (run-tests.sh)   -> the qdb-builder stage alone. Compiles the
#                                   crate and nothing else, so it is the fast
#                                   loop while iterating on Rust.
#   parity      (run-quanta-tests.sh) -> the full runtime image, because the
#                                   suite loads the actual Quanta source.
#
# Usage:
#   sh qdb/tests/run-in-docker.sh              # build what is missing, run both
#   sh qdb/tests/run-in-docker.sh conformance  # just the fast one
#   sh qdb/tests/run-in-docker.sh parity
#   QDB_REBUILD=1 sh qdb/tests/run-in-docker.sh   # force a rebuild first
#
# To compare against unmodified code -- the check that tells a regression apart
# from a pre-existing failure, and the one worth doing before believing any
# suite result:
#
#   mkdir /tmp/base && git archive HEAD qdb | tar -x -C /tmp/base
#
# then point QDB_SRC at it. Assertion counts should match file by file; a
# difference is the change, and a failure present in both is not.
set -eu

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="${QDB_SRC:-$REPO}"
BUILDER_IMG="${QDB_BUILDER_IMG:-quanta-qdb-builder:php8.5}"
FULL_IMG="${QDB_FULL_IMG:-quanta-local:php8.5}"

# 256m matches the chart's dshm emptyDir. The daemon keeps one predecessor
# segment across a compaction, so Docker's default 64m is not enough to run
# 08_compaction.php honestly.
SHM="${QDB_SHM_SIZE:-256m}"

what="${1:-both}"

have() { docker image inspect "$1" >/dev/null 2>&1; }

build_if_needed() {
    img="$1"; target="$2"
    if [ "${QDB_REBUILD:-0}" = 1 ] || ! have "$img"; then
        echo "==> building $img${target:+ (target $target)}"
        # shellcheck disable=SC2086
        docker build ${target:+--target "$target"} -t "$img" "$SRC"
    else
        echo "==> reusing $img (QDB_REBUILD=1 to rebuild)"
    fi
}

run_conformance() {
    build_if_needed "$BUILDER_IMG" qdb-builder
    echo "==> conformance suite (fallback + daemon)"
    docker run --rm --shm-size="$SHM" -v "$SRC/qdb/tests:/tests:ro" "$BUILDER_IMG" \
        sh -c 'QDB_EXT=/out/quanta_db.so QDBD_BIN=/out/qdbd sh /tests/run-tests.sh'
}

run_parity() {
    build_if_needed "$FULL_IMG" ""
    echo "==> parity suite (noext + fallback + daemon)"
    docker run --rm --shm-size="$SHM" -v "$SRC/qdb/tests:/tests:ro" "$FULL_IMG" \
        sh /tests/run-quanta-tests.sh
    # Free, and both were shipped unchecked once: nginx -t cannot run on a host
    # without nginx, and php -l is only meaningful against the PHP the image
    # actually runs.
    echo "==> config and syntax checks in the image"
    docker run --rm --entrypoint sh "$FULL_IMG" -c '
        nginx -t &&
        php-fpm -t &&
        php -l /var/www/quanta/src/modules/list/classes/Common/FastDirList.class.php &&
        php -l /var/www/quanta/src/modules/environment/classes/Common/FilesDb.class.php'
}

case "$what" in
    conformance) run_conformance ;;
    parity)      run_parity ;;
    both)        run_conformance; run_parity ;;
    *) echo "usage: $0 [conformance|parity|both]" >&2; exit 2 ;;
esac
