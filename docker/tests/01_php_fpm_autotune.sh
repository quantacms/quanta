#!/bin/sh
# Conformance tests for docker/php-fpm-autotune.sh.
#
# The script under test reads the container's cgroup CPU and memory limits and
# writes a php-fpm pool fragment to stdout. Every input it reads is redirectable
# (QUANTA_CGROUP_ROOT), so these cases feed it synthetic cgroup trees rather
# than needing a real container with the shape under test.
#
#   sh docker/tests/01_php_fpm_autotune.sh
set -u

SCRIPT="${QUANTA_FPM_AUTOTUNE_BIN:-$(cd "$(dirname "$0")/.." && pwd)/php-fpm-autotune.sh}"
TMP="${TMPDIR:-/tmp}/fpm-autotune-test.$$"
mkdir -p "$TMP"
trap 'rm -rf "$TMP"' EXIT

fail=0
pass=0

ok() { pass=$((pass + 1)); }
bad() {
    fail=$((fail + 1))
    echo "  FAIL: $1"
    [ $# -gt 1 ] && echo "        $2"
}

# assert_kv <conf> <directive> <expected>
assert_kv() {
    _got=$(printf '%s\n' "$1" | sed -n "s/^$2 *= *//p" | tr -d ' ')
    if [ "$_got" = "$3" ]; then ok; else bad "$2 = $_got, expected $3"; fi
}

# cgroup_v2 <dir> <cpu.max contents> <memory.max contents>
cgroup_v2() {
    rm -rf "$1"; mkdir -p "$1"
    printf '%s\n' "$2" > "$1/cpu.max"
    printf '%s\n' "$3" > "$1/memory.max"
}

# cgroup_v1 <dir> <quota_us> <period_us> <memory bytes>
cgroup_v1() {
    rm -rf "$1"; mkdir -p "$1/cpu" "$1/memory"
    printf '%s\n' "$2" > "$1/cpu/cpu.cfs_quota_us"
    printf '%s\n' "$3" > "$1/cpu/cpu.cfs_period_us"
    printf '%s\n' "$4" > "$1/memory/memory.limit_in_bytes"
}

run() { QUANTA_CGROUP_ROOT="$1" sh "$SCRIPT" 2>/dev/null; }

# ------------------------------------------------------- large, memory-bound
# 4 CPU / 6Gi with a 512M per-request PHP cap: the memory arm binds at
# 6144/512 = 12, below the CPU arm's 8 x 4 = 32. The memory arm has to win here,
# because exceeding it is what turns a burst of heavy requests into an OOM kill.
echo "== large container (4 CPU, 6Gi, 512M worker) -> 12"
cgroup_v2 "$TMP/large" "400000 100000" "6442450944"
conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/large")
assert_kv "$conf" "pm.max_children" 12

# -------------------------------------------------------- small, floor-bound
# 1 CPU / 1Gi: the memory arm alone gives 1024/512 = 2, which is too few to
# serve a page plus its concurrent sub-requests without head-of-line blocking.
# The floor is what saves it.
echo "== small container (1 CPU, 1Gi, 512M worker) -> floor of 4"
cgroup_v2 "$TMP/small" "100000 100000" "1073741824"
conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/small")
assert_kv "$conf" "pm.max_children" 4

# ------------------------------------------------------------ fractional CPU
# A sub-core quota is the case a static default handles worst: 8 x 0.45 = 3.6
# workers, floored to 3, then raised to the minimum of 4.
echo "== fractional CPU (450m) -> floor of 4"
cgroup_v2 "$TMP/frac" "45000 100000" "8589934592"
conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/frac")
assert_kv "$conf" "pm.max_children" 4

# ------------------------------------------------------------------- CPU arm
# 2 CPU with memory far too large to bind: 8 x 2 = 16.
echo "== CPU arm binds (2 CPU, 64Gi) -> 16"
cgroup_v2 "$TMP/cpubound" "200000 100000" "68719476736"
conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/cpubound")
assert_kv "$conf" "pm.max_children" 16

# ------------------------------------------------------------------- ceiling
echo "== ceiling clamp (32 CPU, 256Gi) -> 64"
cgroup_v2 "$TMP/big" "3200000 100000" "274877906944"
conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/big")
assert_kv "$conf" "pm.max_children" 64

# ---------------------------------------------------------------- cgroup v1
# The same container shape as the large case, expressed the v1 way. Must agree.
echo "== cgroup v1 (4 CPU, 6Gi) -> 12, same as v2"
cgroup_v1 "$TMP/v1" "400000" "100000" "6442450944"
conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/v1")
assert_kv "$conf" "pm.max_children" 12

# --------------------------------------------------------- unlimited (v2/v1)
# "max" and -1 mean no limit. Fall back to the machine, never to zero workers.
echo "== unlimited CPU falls back to nproc, still under the ceiling"
cgroup_v2 "$TMP/nocpu" "max 100000" "68719476736"
conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/nocpu")
# nproc reports the node, not the pod, so on a big build host the fallback
# lands above the ceiling -- which is exactly when the ceiling has to hold.
expected=$(( 8 * $(nproc) ))
[ "$expected" -gt 64 ] && expected=64
assert_kv "$conf" "pm.max_children" "$expected"

echo "== unlimited memory (v1 sentinel) still yields a usable pool"
cgroup_v1 "$TMP/nomem" "100000" "100000" "9223372036854771712"
conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/nomem")
children=$(printf '%s\n' "$conf" | sed -n 's/^pm.max_children *= *//p' | tr -d ' ')
if [ "${children:-0}" -ge 4 ]; then ok; else bad "expected >= 4 workers, got '$children'"; fi

# ------------------------------------------------------------- no cgroup fs
echo "== missing cgroup tree does not crash and still emits a pool"
rm -rf "$TMP/none"; mkdir -p "$TMP/none"
conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/none")
if printf '%s\n' "$conf" | grep -q '^pm.max_children'; then ok; else bad "no pm.max_children emitted"; fi

# ----------------------------------------------------------------- kill switch
echo "== QUANTA_FPM_AUTOTUNE=off emits nothing"
cgroup_v2 "$TMP/off" "400000 100000" "6442450944"
conf=$(QUANTA_FPM_AUTOTUNE=off QUANTA_FPM_WORKER_MB=512 run "$TMP/off")
if [ -z "$conf" ]; then ok; else bad "expected empty output, got: $conf"; fi

# -------------------------------------------------------------- env overrides
echo "== QUANTA_FPM_PER_CPU override is honoured"
cgroup_v2 "$TMP/percpu" "400000 100000" "68719476736"
conf=$(QUANTA_FPM_PER_CPU=2 QUANTA_FPM_WORKER_MB=512 run "$TMP/percpu")
assert_kv "$conf" "pm.max_children" 8

echo "== QUANTA_FPM_MAX_CHILDREN pins the value outright"
cgroup_v2 "$TMP/pin" "400000 100000" "6442450944"
conf=$(QUANTA_FPM_MAX_CHILDREN=25 QUANTA_FPM_WORKER_MB=512 run "$TMP/pin")
assert_kv "$conf" "pm.max_children" 25

# -------------------------------------------------------- php-fpm invariants
# php-fpm refuses to start if the spare-server bounds are inconsistent, and a
# bad fragment here would take the pool down on every container at once.
echo "== spare-server invariants hold across the whole range"
for shape in "45000 100000 1073741824" "100000 100000 1073741824" \
             "400000 100000 6442450944" "800000 100000 17179869184" \
             "3200000 100000 274877906944"; do
    set -- $shape
    cgroup_v2 "$TMP/inv" "$1 $2" "$3"
    conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/inv")
    kids=$(printf '%s\n' "$conf" | sed -n 's/^pm.max_children *= *//p' | tr -d ' ')
    strt=$(printf '%s\n' "$conf" | sed -n 's/^pm.start_servers *= *//p' | tr -d ' ')
    mins=$(printf '%s\n' "$conf" | sed -n 's/^pm.min_spare_servers *= *//p' | tr -d ' ')
    maxs=$(printf '%s\n' "$conf" | sed -n 's/^pm.max_spare_servers *= *//p' | tr -d ' ')
    if [ -z "$kids" ] || [ -z "$strt" ] || [ -z "$mins" ] || [ -z "$maxs" ]; then
        bad "shape '$shape': incomplete fragment"
        continue
    fi
    if [ "$mins" -ge 1 ] && [ "$mins" -lt "$maxs" ] && [ "$maxs" -le "$kids" ] \
       && [ "$strt" -ge "$mins" ] && [ "$strt" -le "$maxs" ]; then
        ok
    else
        bad "shape '$shape': children=$kids start=$strt min_spare=$mins max_spare=$maxs"
    fi
done

# ------------------------------------------------------------- pool section
echo "== fragment targets the [www] pool"
cgroup_v2 "$TMP/pool" "400000 100000" "6442450944"
conf=$(QUANTA_FPM_WORKER_MB=512 run "$TMP/pool")
if printf '%s\n' "$conf" | grep -q '^\[www\]'; then ok; else bad "missing [www] section header"; fi

echo
echo "passed: $pass  failed: $fail"
[ "$fail" -eq 0 ] || exit 1
echo "ALL TESTS PASSED"
