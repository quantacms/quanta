<?php
/** Contract §10 scenarios 2, 3, 4, 5, 12: multi-process concurrency. */
require __DIR__ . '/_harness.php';

// Must precede fresh_env(): in daemon mode fresh_env() awaits coherence, which
// resolves config (bound once per process, contract §6) — a later ini_set on
// the timeout would be ignored.
ini_set('quanta_db.lock_timeout_ms', '800');
$root = fresh_env();
seed_node($root, 'home', []);

// --- Scenario 4: concurrent locked increments lose nothing. -----------------
QuantaDb::put('counter', ['n' => 0], ['father' => 'home']);
$workers = [];
$W = 4;
$N = 150;
for ($i = 0; $i < $W; $i++) {
    $workers[] = spawn_worker('increment.php', ['counter', $N]);
}
$allOk = true;
foreach ($workers as $h) {
    [$code, $out, $err] = wait_worker($h);
    if ($code !== 0) {
        $allOk = false;
        echo '    worker: ' . worker_failure($code, $out, $err) . "\n";
    }
}
ok($allOk, 'all increment workers exited cleanly');
eq(QuantaDb::get('counter')['n'], $W * $N, 'no lost increments (' . ($W * $N) . ')');

// --- Scenario 3: torn reads are impossible during rapid rewrites. -----------
QuantaDb::put('pair', ['a' => 0, 'b' => 0, 'pad' => ''], ['father' => 'home']);
$writer = spawn_worker('writer_pairs.php', ['pair', 400]);
$reads = 0;
$torn = 0;
while (worker_running($writer)) {
    $doc = QuantaDb::get('pair');
    if ($doc !== null) {
        $reads++;
        if ($doc['a'] !== $doc['b']) {
            $torn++;
        }
    }
}
[$code, $out, $err] = wait_worker($writer);
eq($code, 0, 'writer worker exited cleanly' . ($code ? ' [' . worker_failure($code, $out, $err) . ']' : ''));
ok($reads > 50, "reader observed many snapshots ($reads reads)");
eq($torn, 0, 'no torn reads observed');
$final = QuantaDb::get('pair');
eq([$final['a'], $final['b']], [399, 399], 'final document consistent');

// --- Scenario 2: read-your-writes across processes. -------------------------
QuantaDb::put('rw', ['v' => 'first'], ['father' => 'home']);
QuantaDb::put('rw', ['v' => 'second']);
$w = spawn_worker('get_once.php', ['rw']);
[$code, $out] = wait_worker($w);
eq($code, 0, 'get worker exited cleanly');
eq(json_decode($out, true)['v'], 'second', 'other process sees the last write');

// --- Scenario 5: two same-second writes both observed cross-process. --------
QuantaDb::put('fast', ['x' => 1], ['father' => 'home']);
QuantaDb::put('fast', ['x' => 2]);
$w = spawn_worker('get_once.php', ['fast']);
[, $out] = wait_worker($w);
eq(json_decode($out, true)['x'], 2, 'generation (not mtime) invalidates');

// --- Lock timeout raises LOCK_TIMEOUT. ---------------------------------------
QuantaDb::put('lockee', ['x' => 0], ['father' => 'home']);
$ready = $GLOBALS['__qdb_base'] . '/ready1';
$holder = spawn_worker('hold_lock.php', ['lockee', 3000, $ready]);
$deadline = microtime(true) + 5;
while (!file_exists($ready) && microtime(true) < $deadline) {
    usleep(10000);
}
ok(file_exists($ready), 'holder acquired the lock');
throws(
    fn() => QuantaDb::put('lockee', ['x' => 1]),
    QuantaDbException::LOCK_TIMEOUT,
    'concurrent write times out while lock is held'
);
wait_worker($holder);
eq(QuantaDb::get('lockee')['x'], 0, 'holder update won, timed-out write not applied');

// --- move() under concurrent readers: never observed half-applied. ----------
// A move is the only operation that invalidates a whole subtree's paths at
// once, so the thing to prove is that a reader either sees the old location or
// the new one — and that the node stays resolvable throughout.
// The loop is long on purpose: the daemon settles a rename it could not pair
// inside one inotify read after a grace period, so a wrong verdict there is only
// reachable while moves are still in flight when the grace expires. Sixty moves
// finish first on a fast machine and the whole class of bug goes unobserved —
// which is exactly how one shipped, failing only on a loaded CI runner.
seed_node($root, 'home/left', []);
seed_node($root, 'home/right', []);
QuantaDb::put('shuttle', ['v' => 1], ['father' => 'left']);
QuantaDb::put('shuttlekid', ['v' => 2], ['father' => 'shuttle']);
$mover = spawn_worker('move_loop.php', ['shuttle', 'left', 'right', 1000]);
$seen = 0;
$lost = 0;
$run = 0;
$max_run = 0;
$stray = 0;
while (worker_running($mover)) {
    $p = QuantaDb::path('shuttle');
    if ($p === null) {
        $lost++;
        $max_run = max($max_run, ++$run);
        continue;
    }
    $run = 0;
    $seen++;
    if (!str_ends_with($p, '/left/shuttle') && !str_ends_with($p, '/right/shuttle')) {
        $stray++;
    }
}
[$code, $out, $err] = wait_worker($mover);
eq($code, 0, 'move worker exited cleanly' . ($code ? ' [' . worker_failure($code, $out, $err) . ']' : ''));
ok($seen > 20, "reader observed many relocations ($seen reads)");
eq($stray, 0, 'node was never at a third location');

// What "never disappeared" means differs by mode, and the difference is the
// contract's, not the test's.
if (qdb_daemon_mode()) {
    // The index is authoritative: a null here is a *definitive* absence that
    // callers act on (Environment::nodePath skips its legacy find on one). So
    // the daemon must never publish a window where the node is neither at its
    // old location nor its new one.
    eq($lost, 0, 'authoritative index never reported the node absent mid-move');
} else {
    // No daemon, so coherent() is false and a null only means "the fast path
    // doesn't know" — the caller still falls back to its own lookup. A miss is
    // legitimate here: the self-heal walk can race a rename and genuinely fail
    // to see the node. What must NOT happen is that verdict latching in the
    // negative cache and blinding this worker for the whole TTL.
    ok($max_run < 50, "absent verdict never latched (longest run $max_run of $lost)");
}
eq(QuantaDb::get('shuttlekid'), ['v' => 2], 'descendant survived the shuttling');
ok(str_contains((string) QuantaDb::path('shuttlekid'), '/shuttle/shuttlekid'), 'descendant still under its father');
eq(QuantaDb::stats()['shm_invalid'] ?? 0, 0, 'no invalid segment reads during the moves');

// --- Scenario 12: kill -9 while holding the lock leaves the node writable. --
$ready = $GLOBALS['__qdb_base'] . '/ready2';
$holder = spawn_worker('hold_lock.php', ['lockee', 10000, $ready]);
$deadline = microtime(true) + 5;
while (!file_exists($ready) && microtime(true) < $deadline) {
    usleep(10000);
}
ok(file_exists($ready), 'second holder acquired the lock');
exec('kill -9 ' . worker_pid($holder));
wait_worker($holder);
QuantaDb::put('lockee', ['x' => 99]);
eq(QuantaDb::get('lockee')['x'], 99, 'node writable after holder crash (flock released)');

finish();
