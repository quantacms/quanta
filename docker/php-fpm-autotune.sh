#!/bin/sh
#
# ── php-fpm pool sizing, derived from the container's own limits ──────────────
# Writes a php-fpm pool fragment to stdout, sized from the cgroup CPU and memory
# limits the container was actually given. The entrypoint redirects it to
# /usr/local/etc/php-fpm.d/zzy-autotune.conf, which loads after the image's
# zz-quanta.conf and before any zzz-*.conf a deployment mounts — so this is a
# default a deployment can still override, not a value that fights one.
#
# Why derive it at all: pm.max_children is the only pool value whose right
# setting is a function of the resources the container was given, and one static
# number cannot be right for two containers of different sizes. Ship a single
# default and it is wrong in both directions at once — too large for a
# fractional-CPU container, where the workers each get a slice of quota too thin
# to finish a request so the container throttles instead of queueing; and blind
# to the memory ceiling on a large one, where enough concurrent requests near
# the per-request PHP cap outrun the limit and the kernel OOM-kills the whole
# container. Both are the same mistake: a number chosen without reference to the
# machine it lands on.
#
# The formula takes the smaller of two arms:
#
#   CPU arm     QUANTA_FPM_PER_CPU x cpus
#               Concurrency worth having: enough quota per worker that a typical
#               render finishes inside its scheduling period. Below roughly an
#               eighth of a core each, workers stop being able to do that.
#
#   memory arm  container memory limit / php's memory_limit
#               php.ini memory_limit is a per-request ceiling, so this arm is
#               the count at which every worker simultaneously at the cap still
#               fits the container. Above it, enough concurrent heavy requests
#               OOM-kill the container — which kills every in-flight request
#               rather than failing the greedy one.
#
# then clamps to [QUANTA_FPM_MIN_CHILDREN, QUANTA_FPM_CEILING]. The floor exists
# because on a small container the memory arm alone lands at one or two workers,
# and below about four a single stalled request plus a page's own concurrent
# sub-requests head-of-line block the pool. A bounded over-commit is the right
# trade there and the wrong one on a large container — which is why the arm that
# binds differs by size. That is the whole point of deriving it.
#
# Environment:
#   QUANTA_FPM_AUTOTUNE      off/0/false/no -> emit nothing, image default stands
#   QUANTA_FPM_MAX_CHILDREN  pin pm.max_children outright, skipping derivation
#   QUANTA_FPM_PER_CPU       workers per CPU for the CPU arm       (default 8)
#   QUANTA_FPM_WORKER_MB     per-worker memory budget for the memory arm
#                            (default: php's own memory_limit, else 128)
#   QUANTA_FPM_MIN_CHILDREN  floor                                 (default 4)
#   QUANTA_FPM_CEILING       ceiling                               (default 64)
#   QUANTA_CGROUP_ROOT       cgroup mount to read      (default /sys/fs/cgroup)
set -u

case "${QUANTA_FPM_AUTOTUNE:-on}" in
    off|OFF|0|false|FALSE|no|NO) exit 0 ;;
esac

CG="${QUANTA_CGROUP_ROOT:-/sys/fs/cgroup}"
PER_CPU="${QUANTA_FPM_PER_CPU:-8}"
MIN_CHILDREN="${QUANTA_FPM_MIN_CHILDREN:-4}"
CEILING="${QUANTA_FPM_CEILING:-64}"

# A memory limit at or above this is the kernel's "no limit" sentinel rather
# than a real allocation (cgroup v1 reports LONG_MAX rounded to a page).
UNLIMITED_BYTES=9007199254740992

log() { echo "php-fpm-autotune: $*" >&2; }

# ── CPU quota, in millicores ─────────────────────────────────────────────────
# cgroup v2 states it as "<quota> <period>" with the literal "max" for no limit;
# v1 splits it across two files and spells no limit as -1. Either way an absent
# or unlimited quota means the pod may use the whole machine, so fall back to
# the visible CPU count -- note nproc reports the *node*, which is why it is the
# last resort and not the primary source (a one-core container on a four-core
# host sees four).
cpu_milli=""
cpu_source=""
if [ -r "$CG/cpu.max" ]; then
    read -r _quota _period < "$CG/cpu.max" || true
    if [ "${_quota:-max}" != "max" ] && [ "${_period:-0}" -gt 0 ] 2>/dev/null; then
        cpu_milli=$(( _quota * 1000 / _period ))
        cpu_source="cgroup v2 cpu.max"
    fi
elif [ -r "$CG/cpu/cpu.cfs_quota_us" ] && [ -r "$CG/cpu/cpu.cfs_period_us" ]; then
    _quota=$(cat "$CG/cpu/cpu.cfs_quota_us" 2>/dev/null || echo -1)
    _period=$(cat "$CG/cpu/cpu.cfs_period_us" 2>/dev/null || echo 0)
    if [ "${_quota:-0}" -gt 0 ] 2>/dev/null && [ "${_period:-0}" -gt 0 ] 2>/dev/null; then
        cpu_milli=$(( _quota * 1000 / _period ))
        cpu_source="cgroup v1 cpu.cfs_quota_us"
    fi
fi
if [ -z "$cpu_milli" ] || [ "$cpu_milli" -le 0 ]; then
    cpu_milli=$(( $(nproc 2>/dev/null || echo 1) * 1000 ))
    cpu_source="no cpu quota, nproc"
fi

# ── Memory limit, in MiB ─────────────────────────────────────────────────────
mem_mb=""
mem_source=""
for _f in "$CG/memory.max" "$CG/memory/memory.limit_in_bytes"; do
    [ -r "$_f" ] || continue
    _bytes=$(cat "$_f" 2>/dev/null || echo max)
    [ "$_bytes" = "max" ] && continue
    [ "$_bytes" -gt 0 ] 2>/dev/null || continue
    [ "$_bytes" -ge "$UNLIMITED_BYTES" ] && continue
    mem_mb=$(( _bytes / 1048576 ))
    mem_source="$_f"
    break
done
if [ -z "$mem_mb" ] || [ "$mem_mb" -le 0 ]; then
    mem_mb=$(( $(awk '/^MemTotal:/ {print $2; exit}' /proc/meminfo 2>/dev/null || echo 1048576) / 1024 ))
    mem_source="no memory limit, /proc/meminfo"
fi

# ── Per-worker memory budget ─────────────────────────────────────────────────
# php.ini memory_limit is the per-request ceiling, so it is the honest figure
# for "how much can one worker demand". Read it from PHP itself rather than
# restating it here, so the two can never drift.
worker_mb="${QUANTA_FPM_WORKER_MB:-}"
if [ -z "$worker_mb" ]; then
    _raw=$(php -r 'echo ini_get("memory_limit");' 2>/dev/null || echo "")
    case "$_raw" in
        *G|*g) worker_mb=$(( ${_raw%[Gg]} * 1024 )) ;;
        *M|*m) worker_mb=${_raw%[Mm]} ;;
        *K|*k) worker_mb=$(( ${_raw%[Kk]} / 1024 )) ;;
        ''|-1) worker_mb=128 ;;
        *)     worker_mb=$(( _raw / 1048576 )) 2>/dev/null || worker_mb=128 ;;
    esac
    [ "${worker_mb:-0}" -gt 0 ] 2>/dev/null || worker_mb=128
fi

# ── The two arms ─────────────────────────────────────────────────────────────
if [ -n "${QUANTA_FPM_MAX_CHILDREN:-}" ]; then
    children="$QUANTA_FPM_MAX_CHILDREN"
    reason="pinned by QUANTA_FPM_MAX_CHILDREN"
else
    cpu_arm=$(( PER_CPU * cpu_milli / 1000 ))
    mem_arm=$(( mem_mb / worker_mb ))

    if [ "$cpu_arm" -le "$mem_arm" ]; then
        children="$cpu_arm"; reason="cpu arm (${PER_CPU}/cpu x ${cpu_milli}m)"
    else
        children="$mem_arm"; reason="memory arm (${mem_mb}Mi / ${worker_mb}Mi per worker)"
    fi

    if [ "$children" -lt "$MIN_CHILDREN" ]; then
        children="$MIN_CHILDREN"; reason="$reason, raised to floor"
    elif [ "$children" -gt "$CEILING" ]; then
        children="$CEILING"; reason="$reason, capped at ceiling"
    fi
fi

# ── Derived spare-server bounds ──────────────────────────────────────────────
# Ratios, not independent knobs: php-fpm refuses to start on an inconsistent
# set, and hand-maintaining four numbers per environment is how they drift.
start_servers=$(( children / 4 ));     [ "$start_servers" -lt 2 ] && start_servers=2
min_spare=$(( children / 8 ));         [ "$min_spare" -lt 1 ] && min_spare=1
max_spare=$(( children / 2 ));         [ "$max_spare" -lt 3 ] && max_spare=3
[ "$max_spare" -gt "$children" ] && max_spare="$children"
[ "$start_servers" -gt "$max_spare" ] && start_servers="$max_spare"

log "cpu=${cpu_milli}m ($cpu_source) memory=${mem_mb}Mi ($mem_source) worker=${worker_mb}Mi"
log "pm.max_children=$children -- $reason"

cat <<EOF
; Generated at container start by php-fpm-autotune.sh -- DO NOT EDIT.
;
; Sized from this container's own cgroup limits:
;   cpu    ${cpu_milli}m  ($cpu_source)
;   memory ${mem_mb}Mi  ($mem_source)
;   worker ${worker_mb}Mi per request (php.ini memory_limit)
; Chosen by the $reason.
;
; This file loads after zz-quanta.conf and before any zzz-*.conf, so a pool
; value set by the deployment still wins. To override, mount a zzz-*.conf of
; your own; to pin the value here instead, set QUANTA_FPM_MAX_CHILDREN; to
; disable this file entirely, QUANTA_FPM_AUTOTUNE=off.
[www]
pm = dynamic
pm.max_children = $children
pm.start_servers = $start_servers
pm.min_spare_servers = $min_spare
pm.max_spare_servers = $max_spare
EOF
