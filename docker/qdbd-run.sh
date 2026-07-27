#!/bin/sh
# supervisord wrapper for the files-DB daemon.
#
# qdbd is optional: with QUANTA_DB_DAEMON=off the extension serves from its
# walk-snapshot fallback instead. supervisord has no conditional-start, so the
# opt-out is expressed here — exit 0 and let `autorestart=unexpected` leave the
# program in EXITED rather than restart-looping it.
set -e

case "${QUANTA_DB_DAEMON:-on}" in
    off|OFF|0|false|FALSE|no|NO)
        echo "qdbd: disabled via QUANTA_DB_DAEMON=${QUANTA_DB_DAEMON}" >&2
        exit 0
        ;;
esac

exec qdbd "$@"
