#!/bin/sh
# supervisord wrapper for the qdb web dashboard.
#
# `qdbstat --dashboard` serves a read-only browser for the index: every node,
# its place in the tree, its links, and the document files themselves, rendered
# out of the shared-memory segment. It answers "what is in the DB" where
# /metrics answers "is the DB healthy".
#
# It runs INSIDE this container for the same reason the exporter does: the
# metrics arena is under /tmp/quanta_db, on the container's own filesystem, so
# a sidecar would see an empty directory.
#
# Off unless QUANTA_DB_DASHBOARD_LISTEN is set. supervisord has no conditional
# start, so the opt-out is expressed the way qdbd-run.sh and
# qdbstat-exporter.sh express theirs: exit 0 and let `autorestart = unexpected`
# leave the program in EXITED rather than restart-looping it.
#
# UNLIKE THE EXPORTER, THIS SERVES SITE CONTENT -- node paths and document
# bodies, not counters. Two consequences:
#
#   * the default address is loopback (127.0.0.1:9110), reachable with
#     `kubectl port-forward` but not from the cluster. Binding 0.0.0.0 is
#     possible and has to be typed;
#   * QUANTA_DB_DASHBOARD_TOKEN, if set, is required on every request. Set it
#     for anything wider than loopback -- an address is not access control.
#
# QUANTA_DB_DASHBOARD_BASE_PATH is read by qdbstat itself (not here) and names
# the URL prefix the dashboard is reached at, for an ingress that routes a
# sub-path to this port without stripping it.
set -e

LISTEN="${QUANTA_DB_DASHBOARD_LISTEN:-}"

case "$LISTEN" in
    ''|off|OFF|0|false|FALSE|no|NO)
        echo "qdbstat-dashboard: disabled (set QUANTA_DB_DASHBOARD_LISTEN=127.0.0.1:9110 to enable)" >&2
        exit 0
        ;;
esac

case "$LISTEN" in
    127.0.0.1:*|localhost:*|[::1]:*) ;;
    *)
        if [ -z "${QUANTA_DB_DASHBOARD_TOKEN:-}" ]; then
            echo "qdbstat-dashboard: WARNING -- listening on $LISTEN with no" \
                 "QUANTA_DB_DASHBOARD_TOKEN. Anything that can reach this port can read" \
                 "every document in the site." >&2
        fi
        ;;
esac

# Read-only throughout: a read-only mapping of the segment, no route that
# mutates, and no lock held. Like the exporter, a crash loop here would be a
# bug rather than a capacity problem, so supervisord's own restart is the right
# response and there is no throttle of the kind qdbd-run.sh needs.
exec qdbstat --dashboard "$LISTEN" "$@"
