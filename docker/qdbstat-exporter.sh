#!/bin/sh
# supervisord wrapper for the qdbstat Prometheus exporter.
#
# `qdbstat --listen` serves the same counters the interactive dashboard shows,
# as a /metrics endpoint a scraper can reach on the pod IP.
#
# It runs INSIDE this container rather than as a sidecar, and that is not a
# packaging preference: the metrics arena lives under /tmp/quanta_db, which is
# the container's own filesystem and not one of the shared volumes. A sidecar
# would see an empty directory. (The data segments in /dev/shm are shared, but
# they carry no counters -- only sizes and cardinality.)
#
# Off unless QUANTA_DB_METRICS_LISTEN is set, because this image is a base for
# deployments that never asked to open a port. supervisord has no conditional
# start, so the opt-out is expressed the same way qdbd-run.sh expresses its
# own: exit 0 and let `autorestart = unexpected` leave the program in EXITED
# rather than restart-looping it.
set -e

LISTEN="${QUANTA_DB_METRICS_LISTEN:-}"

case "$LISTEN" in
    ''|off|OFF|0|false|FALSE|no|NO)
        echo "qdbstat-exporter: disabled (set QUANTA_DB_METRICS_LISTEN=0.0.0.0:9109 to enable)" >&2
        exit 0
        ;;
esac

# The exporter reads a shared-memory arena and a read-only mapping of the
# segment; it never writes either, and it holds no lock. A scrape costs a few
# atomic loads and a statvfs, so there is no throttle here of the kind
# qdbd-run.sh needs -- a crash loop would be a bug, not a capacity problem, and
# supervisord's own restart is the right response to it.
exec qdbstat --listen "$LISTEN" "$@"
