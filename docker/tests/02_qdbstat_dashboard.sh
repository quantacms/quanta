#!/bin/sh
# Conformance tests for docker/qdbstat-dashboard.sh.
#
# The wrapper is the whole opt-in mechanism for the qdb web dashboard, and
# it has to get two things right that only show up in a running container:
#
#   * with QUANTA_DB_DASHBOARD_LISTEN unset it must exit 0, because supervisord
#     has no conditional start -- a non-zero exit would put the program in a
#     restart loop on every pod that never asked for a dashboard;
#   * with it set it must exec qdbstat with exactly that address, since a
#     dashboard that quietly bound something else would be an exposure bug.
#
# `qdbstat` is stubbed on PATH, so these run anywhere without the binary.
#
#   sh docker/tests/02_qdbstat_dashboard.sh
set -u

SCRIPT="${QUANTA_DASHBOARD_BIN:-$(cd "$(dirname "$0")/.." && pwd)/qdbstat-dashboard.sh}"
TMP="${TMPDIR:-/tmp}/qdbstat-dashboard-test.$$"
mkdir -p "$TMP/bin"
trap 'rm -rf "$TMP"' EXIT

# The stub prints its argv and exits 0, so a test can assert on what the
# wrapper would have run without needing a segment or a port.
cat > "$TMP/bin/qdbstat" <<'STUB'
#!/bin/sh
echo "qdbstat $*"
STUB
chmod +x "$TMP/bin/qdbstat"
PATH="$TMP/bin:$PATH"
export PATH

fail=0
pass=0

ok() { pass=$((pass + 1)); }
bad() {
    fail=$((fail + 1))
    echo "  FAIL: $1"
    [ $# -gt 1 ] && echo "        $2"
}

# run <env assignments...> -- captures stdout+stderr and the exit status.
run() {
    out=$(env "$@" sh "$SCRIPT" 2>&1)
    status=$?
}

# --- the opt-out ----------------------------------------------------------

run QUANTA_DB_DASHBOARD_LISTEN=
if [ "$status" -eq 0 ]; then ok; else bad "unset must exit 0, got $status"; fi
case "$out" in
    *disabled*) ok ;;
    *) bad "unset must say why it did nothing" "$out" ;;
esac
case "$out" in
    *"qdbstat --dashboard"*) bad "unset must not start the server" "$out" ;;
    *) ok ;;
esac

# The word forms an operator reaches for when turning something off. Each has
# to mean off, not "bind to a host called false".
for word in off OFF 0 false FALSE no NO; do
    run "QUANTA_DB_DASHBOARD_LISTEN=$word"
    if [ "$status" -eq 0 ]; then ok; else bad "$word must exit 0, got $status"; fi
    case "$out" in
        *"qdbstat --dashboard"*) bad "$word must not start the server" "$out" ;;
        *) ok ;;
    esac
done

# --- the opt-in -----------------------------------------------------------

run QUANTA_DB_DASHBOARD_LISTEN=127.0.0.1:9110
case "$out" in
    *"qdbstat --dashboard 127.0.0.1:9110"*) ok ;;
    *) bad "the address must be passed through verbatim" "$out" ;;
esac

# --- the exposure warning -------------------------------------------------

# Loopback with no token is the documented default and must stay quiet, or the
# warning becomes noise that nobody reads when it matters.
run QUANTA_DB_DASHBOARD_LISTEN=127.0.0.1:9110
case "$out" in
    *WARNING*) bad "loopback with no token must not warn" "$out" ;;
    *) ok ;;
esac

# Anything wider, without a token, is the case worth a line in the log: this
# port serves document content, not counters.
run QUANTA_DB_DASHBOARD_LISTEN=0.0.0.0:9110
case "$out" in
    *WARNING*) ok ;;
    *) bad "a wide bind with no token must warn" "$out" ;;
esac
case "$out" in
    *"qdbstat --dashboard 0.0.0.0:9110"*) ok ;;
    *) bad "the warning must not stop it from serving" "$out" ;;
esac

# With a token set there is nothing to warn about.
run QUANTA_DB_DASHBOARD_LISTEN=0.0.0.0:9110 QUANTA_DB_DASHBOARD_TOKEN=s3cret
case "$out" in
    *WARNING*) bad "a wide bind WITH a token must not warn" "$out" ;;
    *) ok ;;
esac

echo "  $pass passed, $fail failed"
[ "$fail" -eq 0 ] || exit 1
