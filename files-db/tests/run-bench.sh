#!/bin/sh
# Legacy-vs-extension parity tests + performance benchmark.
#   QDB_EXT=/path/to/quanta_db.so sh tests/run-bench.sh
# From the built image:
#   docker run --rm quanta-db sh /ext/tests/run-bench.sh
set -u

EXT="${QDB_EXT:-/usr/local/lib/php/extensions/quanta_db.so}"
DIR="$(cd "$(dirname "$0")" && pwd)"

QDB_EXT="$EXT" php -n -d extension="$EXT" "$DIR/bench/bench.php"
