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

# Crash-rate backoff.
#
# supervisord cannot throttle this itself: `startsecs = 0` (needed so the
# opt-out above settles in EXITED rather than FATAL) means every spawn counts as
# a successful start, so `startretries` resets each time and can never be
# exhausted. A daemon that cannot stay up therefore respawns forever at full
# speed, and each respawn rebuilds the whole segment from a full tree walk --
# on a large tree that pegs a CPU core and starves the very requests that are
# already falling back.
#
# So the throttle lives here, where it can see how recently we last started.
# Restarts stay immediate while they are occasional; a tight loop backs off to
# QDBD_BACKOFF_MAX_SECS. The fallback path keeps serving throughout either way.
QDBD_BACKOFF_MAX_SECS="${QDBD_BACKOFF_MAX_SECS:-30}"
QDBD_BACKOFF_MIN_UPTIME="${QDBD_BACKOFF_MIN_UPTIME:-60}"

# The state lives with the rest of qdbd's derived, per-pod data — deliberately
# NOT under QUANTA_DB_ROOT, which is the *site content* root (the Dockerfile
# sets it to sites/localhost): the tree qdbd indexes and watches with inotify,
# and the one directory a deployment is likely to mount shared between pods or
# read-only. Scratch state does not belong in the data being served, and either
# of those mounts would quietly break the throttle — writes here fail silently
# by design, so a shared file would let one pod's crashes delay another's
# restarts, and a read-only one would disable the backoff entirely, precisely
# when it is needed. docker-entrypoint.sh creates
# /tmp/quanta_db and chowns it to www-data (the user supervisord runs this as)
# before it execs supervisord, and nothing ever clears it.
_state_dir=/tmp/quanta_db
_stamp="$_state_dir/.qdbd-last-start"
_streak_file="$_state_dir/.qdbd-crash-streak"
_now="$(date +%s)"
_prev="$(cat "$_stamp" 2>/dev/null || echo 0)"
case "$_prev" in *[!0-9]*|'') _prev=0 ;; esac
_streak="$(cat "$_streak_file" 2>/dev/null || echo 0)"
case "$_streak" in *[!0-9]*|'') _streak=0 ;; esac

if [ "$_prev" -gt 0 ] && [ "$((_now - _prev))" -lt "$QDBD_BACKOFF_MIN_UPTIME" ]; then
    _streak=$((_streak + 1))
    # Clamp before shifting. `1 << n` past the width of a long is undefined and
    # in practice wraps: the image's /bin/sh (dash) answers `1 << 69` with 32,
    # so an unclamped streak would quietly collapse a 25-hour crash loop's
    # backoff back to seconds, and `1 << 63` is negative — `sleep` rejects it,
    # and under `set -e` that kills the wrapper without ever starting qdbd,
    # turning the throttle into a second failure. 30 is far past the point where
    # the cap below takes over.
    if [ "$_streak" -gt 30 ]; then
        _streak=30
    fi
    # 2,4,8,16... capped: the first crash of a streak is never slept on (see
    # the `-gt 1` guard below), so the 1s step the shift starts at is skipped
    # and a one-off crash restarts instantly.
    _sleep=$(( 1 << (_streak - 1) ))
    if [ "$_sleep" -gt "$QDBD_BACKOFF_MAX_SECS" ]; then
        _sleep="$QDBD_BACKOFF_MAX_SECS"
    fi
    if [ "$_streak" -gt 1 ]; then
        echo "qdbd: died after $((_now - _prev))s (crash #$_streak); backing off ${_sleep}s" >&2
        sleep "$_sleep"
    fi
else
    # A run that lasted decays the streak by one rather than clearing it. A hard
    # reset makes the throttle amnesiac: a daemon that alternates a few instant
    # crashes with one run just over the minimum returns to full speed every
    # time, which is the loop this exists to bound. Decaying keeps the memory of
    # it and still returns to instant restarts after a few clean runs — and the
    # first crash after a healthy run is instant either way, since the sleep
    # only happens on the fast path and only from the second crash on.
    #
    # A loop that is *entirely* slow — every crash above
    # QDBD_BACKOFF_MIN_UPTIME — is deliberately never slept on: a qdbd that
    # stays up a full minute spends most of that minute serving the index, and
    # a rebuilding daemon beats a sleeping one. Lower QDBD_BACKOFF_MIN_UPTIME if
    # a site's rebuild is slow enough to disagree.
    if [ "$_streak" -gt 0 ]; then
        _streak=$((_streak - 1))
    fi
fi
# Subshells so a read-only or missing state dir stays silent: the shell reports
# a failed redirection itself, before any 2>/dev/null on the command applies.
( printf '%s' "$_streak" > "$_streak_file" ) 2>/dev/null || true
( printf '%s' "$(date +%s)" > "$_stamp" ) 2>/dev/null || true

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
