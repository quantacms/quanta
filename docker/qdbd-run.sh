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

# The safety-net reconcile walks the whole tree on a timer whether or not
# anything changed, so its cost scales with the node count and is paid forever,
# on an idle pod as much as a busy one. inotify already carries real changes;
# the walk only covers events the watcher could have missed. 60s is the right
# default for a tree that is written to constantly, and far too eager for a
# test environment that is mostly idle — hence the knob.
RESYNC="${QUANTA_DB_RESYNC_SECS:-}"
if [ -n "$RESYNC" ]; then
    exec qdbd --resync-secs "$RESYNC" "$@"
fi

exec qdbd "$@"
