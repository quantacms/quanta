<?php
/**
 * What the degraded path COSTS — the half of the contract every other test
 * leaves unasserted.
 *
 * The incident this suite grew out of was not a correctness failure. Every read
 * returned the right answer the whole way through; the fallback path simply
 * paid a full walk of the docroot per unresolved name, per worker, and a tree
 * large enough turned that into an outage. Nothing here could have caught it:
 * the daemon-failure tests run on trees of three to six nodes, where a walk is
 * free and cost is therefore invisible.
 *
 * So this file asserts the cost contract directly, on a tree big enough for a
 * walk to be worth avoiding:
 *
 *   1. Resolution is amortised. Any number of names that exist cost ONE walk,
 *      and repeats cost none.
 *   2. Absence is bounded. N misses cost O(1) walks, not O(N) — the
 *      self-heal coalescing window — and repeats cost none (negative cache).
 *   3. While the daemon is down but its last segment is still on disk, names
 *      that exist cost NO walk at all.
 *
 * Two things make the assertions portable rather than a tuned benchmark.
 * Structurally, they count walks, which is a property of the code and not of
 * the machine — `snap_walks` moves once per `cache::snap_build`, whatever that
 * walk cost. Where wall-clock is genuinely the thing under test, the budget is
 * expressed in units of a walk this run actually measured, so a slow CI box
 * moves both sides of the comparison.
 *
 * Tunable for a heavier run: QDB_COST_NODES (tree size), QDB_COST_NAMES
 * (lookups per pass). The defaults are chosen to stay well inside the inotify
 * watch ceiling the daemon needs in daemon mode, and to keep the file under a
 * second.
 */
require __DIR__ . '/_harness.php';

$NODES = max(200, (int) (getenv('QDB_COST_NODES') ?: 2000));
$NAMES = max(20, (int) (getenv('QDB_COST_NAMES') ?: 400));

/** Full docroot walks this worker has paid for so far. */
function walks(): int
{
    return (int) QuantaDb::stats()['snap_walks'];
}

function counter(string $k): int
{
    return (int) QuantaDb::stats()[$k];
}

/**
 * A tree of $n node directories, two levels deep, written straight to disk.
 *
 * Deliberately NOT seed_node(): that waits for the daemon to observe each node
 * one inotify event at a time, which at this size is the slowest thing in the
 * suite. Daemon mode instead restarts the daemon once at the end, so the tree
 * is indexed by a single build_from_disk.
 */
function build_tree(string $root, int $n): array
{
    $names = [];
    $per_group = 50;
    mkdir("$root/home", 0777, true);
    for ($i = 0; count($names) < $n; $i++) {
        $group = "g$i";
        mkdir("$root/home/$group", 0777, true);
        $names[] = $group;
        for ($j = 0; $j < $per_group && count($names) < $n; $j++) {
            $name = "n{$i}_{$j}";
            mkdir("$root/home/$group/$name", 0777, true);
            file_put_contents("$root/home/$group/$name/data.json", '{"v":1}');
            $names[] = $name;
        }
    }
    return $names;
}

/** $count names spread evenly across the tree rather than clustered. */
function spread(array $all, int $count): array
{
    $step = max(1, intdiv(count($all), $count));
    $out = [];
    for ($i = 0; count($out) < $count && $i < count($all); $i += $step) {
        $out[] = $all[$i];
    }
    return $out;
}

$root = fresh_env();
$all = build_tree($root, $NODES);
$existing = spread($all, $NAMES);
$absent = [];
for ($i = 0; $i < $NAMES; $i++) {
    $absent[] = "no-such-node-$i";
}

if (qdb_daemon_mode()) {
    // The daemon was spawned by fresh_env() against an empty root. Restart it
    // over the finished tree so one build_from_disk indexes the lot.
    qdb_daemon_stop();
    qdb_daemon_start();
    ok_eventually(fn() => QuantaDb::path(end($existing)) !== null, 'tree indexed after restart');

    // --- The healthy path does not walk. Ever. ------------------------------
    // The baseline the two degraded sections below are measured against.
    $w = walks();
    foreach ($existing as $n) {
        QuantaDb::path($n);
    }
    eq(walks() - $w, 0, "$NAMES coherent lookups cost no walk");
}

// ---------------------------------------------------------------------------
// The pure fallback path: no daemon, no segment, the snapshot walk and nothing
// else. This is fallback mode by construction; in daemon mode the last
// published segment is still on disk and answers first, which is its own
// section further down.
// ---------------------------------------------------------------------------
if (!qdb_daemon_mode()) {
    eq(QuantaDb::stats()['mode'], 'fallback', 'running on the fallback path');

    // --- 1. Every name that exists costs ONE walk between them. -------------
    $w = walks();
    $found = 0;
    foreach ($existing as $n) {
        $found += QuantaDb::path($n) !== null ? 1 : 0;
    }
    eq($found, $NAMES, 'every seeded name resolved');
    eq(walks() - $w, 1, "$NAMES existing names cost exactly one walk");

    // Everything below is judged against the cost of that walk, as this machine
    // measured it. A worst case of "one walk per name" is the pre-fix
    // behaviour, so a tenth of that is a wide margin around the real cost
    // (which is ~one walk) and still an order of magnitude short of the bug.
    $walk_ms = counter('snap_walk_ns_max') / 1e6;
    ok($walk_ms > 0, sprintf('the walk was measured (%.1f ms for %d nodes)', $walk_ms, $NODES));
    $budget_ms = 0.1 * $NAMES * $walk_ms;

    // --- 2. Repeats are free. ----------------------------------------------
    $w = walks();
    $t0 = microtime(true);
    foreach ($existing as $n) {
        QuantaDb::path($n);
    }
    $warm_ms = (microtime(true) - $t0) * 1000;
    eq(walks() - $w, 0, 'a second pass over the same names walks zero times');
    ok(
        $warm_ms < $budget_ms,
        sprintf('warm pass cost %.1f ms, under the %.1f ms budget', $warm_ms, $budget_ms)
    );

    // --- 3. Absence costs O(1) walks, not O(misses). ------------------------
    // The self-heal is per-miss by design — it is what finds a node created out
    // of band — so without coalescing this loop is one full walk per name, and
    // that is precisely what took production down.
    $w = walks();
    $t0 = microtime(true);
    $wrong = 0;
    foreach ($absent as $n) {
        $wrong += QuantaDb::path($n) === null ? 0 : 1;
    }
    $miss_ms = (microtime(true) - $t0) * 1000;
    $miss_walks = walks() - $w;
    eq($wrong, 0, 'every absent name still answered absent');
    ok(
        $miss_walks * 10 < $NAMES,
        "$NAMES misses cost $miss_walks walks, not one each"
    );
    ok(
        $miss_ms < $budget_ms,
        sprintf('miss pass cost %.1f ms, under the %.1f ms budget', $miss_ms, $budget_ms)
    );

    // --- 4. Repeat absence is free: the negative cache. ---------------------
    $w = walks();
    $neg = counter('neg_hits');
    foreach ($absent as $n) {
        QuantaDb::path($n);
    }
    eq(walks() - $w, 0, 'the same absences again cost no walk');
    eq(counter('neg_hits') - $neg, $NAMES, 'they were served from the negative cache');

    // --- 5. The shape of a real request: hits and misses interleaved. -------
    // A render resolves both, and the miss is the expensive one. Fresh absent
    // names so the negative cache cannot flatter the result.
    $w = walks();
    for ($i = 0; $i < $NAMES; $i++) {
        QuantaDb::path($existing[$i]);
        QuantaDb::path("mixed-absent-$i");
    }
    $mixed_walks = walks() - $w;
    ok(
        $mixed_walks * 10 < $NAMES,
        "a request mixing $NAMES hits and $NAMES misses cost $mixed_walks walks"
    );
}

// ---------------------------------------------------------------------------
// Degraded with the last published segment still on disk. The correctness of
// this path is 14_stale_segment.php; what it is FOR is the cost.
// ---------------------------------------------------------------------------
if (qdb_daemon_mode()) {
    qdb_daemon_stop();
    ok_eventually(fn() => !QuantaDb::coherent(), 'coherence cleared on SIGTERM');

    $w = walks();
    $stale = counter('stale_hits');
    $found = 0;
    foreach ($existing as $n) {
        $found += QuantaDb::path($n) !== null ? 1 : 0;
    }
    eq($found, $NAMES, 'every name still resolved with the daemon gone');
    eq(walks() - $w, 0, "$NAMES degraded lookups of existing names cost no walk");
    eq(counter('stale_hits') - $stale, $NAMES, 'all of them came from the last published segment');

    // Absence is the part the segment cannot answer: it can prove a name is
    // there, never that it is not. So a miss still falls through to a walk, and
    // the only thing standing between a render and one walk per missing name is
    // the same coalescing asserted above. On a production-sized tree this is
    // what a degraded render still pays.
    $w = walks();
    foreach ($absent as $n) {
        QuantaDb::path($n);
    }
    $miss_walks = walks() - $w;
    ok(
        $miss_walks * 10 < $NAMES,
        "$NAMES degraded misses cost $miss_walks walks, not one each"
    );

    // --- Recovery: the counters are per-worker, the fix is not. -------------
    qdb_daemon_start();
    ok(QuantaDb::coherent(), 'coherent after restart');
    $w = walks();
    foreach ($existing as $n) {
        QuantaDb::path($n);
    }
    eq(walks() - $w, 0, 'back on the index, still no walks');
}

finish();
