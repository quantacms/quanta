<?php
/** Contract §10 scenarios 2, 3, 4, 5, 12: multi-process concurrency. */
require __DIR__ . '/_harness.php';

$root = fresh_env();
ini_set('quanta_db.lock_timeout_ms', '800');
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
    [$code, , $err] = wait_worker($h);
    if ($code !== 0) {
        $allOk = false;
        echo "    worker stderr: $err\n";
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
[$code, , $err] = wait_worker($writer);
eq($code, 0, 'writer worker exited cleanly' . ($code ? " ($err)" : ''));
ok($reads > 50, "reader observed many snapshots ($reads reads)");
eq($torn, 0, 'no torn reads observed');
$final = QuantaDb::get('pair');
eq([$final['a'], $final['b']], [399, 399], 'final document consistent');

// --- Scenario 2: read-your-writes across processes. -------------------------
QuantaDb::put('rw', ['v' => 'first'], ['father' => 'home']);
QuantaDb::put('rw', ['v' => 'second']);
[$code, $out] = wait_worker(spawn_worker('get_once.php', ['rw']));
eq($code, 0, 'get worker exited cleanly');
eq(json_decode($out, true)['v'], 'second', 'other process sees the last write');

// --- Scenario 5: two same-second writes both observed cross-process. --------
QuantaDb::put('fast', ['x' => 1], ['father' => 'home']);
QuantaDb::put('fast', ['x' => 2]);
[, $out] = wait_worker(spawn_worker('get_once.php', ['fast']));
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
