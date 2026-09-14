#!/bin/sh
# Container-config test suite. These cover the shell that runs at container
# start -- the code with the least room to fail safely, since a bad pool
# fragment or a bad entrypoint takes every pod down at once.
#
#   sh docker/tests/run-tests.sh
set -u

DIR="$(cd "$(dirname "$0")" && pwd)"
fail=0

for t in "$DIR"/[0-9]*.sh; do
    echo "==== $(basename "$t")"
    sh "$t" || fail=1
done

if [ "$fail" -eq 0 ]; then
    echo "ALL TEST FILES PASSED"
else
    echo "SOME TESTS FAILED"
    exit 1
fi
