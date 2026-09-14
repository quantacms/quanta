<?php
/**
 * Daemon failure modes: clean stop (coherence cleared), crash (kill -9 +
 * write-poison), fallback correctness while down, recovery on restart with
 * generation monotonicity.
 */
require __DIR__ . '/_harness.php';

if (!qdb_daemon_mode()) {
    echo "PASSED - 0 ok, 0 failed (daemon-mode only)\n";
    exit(0);
}

$root = fresh_env();
seed_node($root, 'home', []);
QuantaDb::put('a', ['v' => 1], ['father' => 'home']);
ok(QuantaDb::coherent(), 'coherent while daemon runs');
$g_pre = QuantaDb::meta('a')['generation'];

// --- Clean stop: SIGTERM clears the coherence flag on the way out. ----------
qdb_daemon_stop(); // SIGTERM + reap
ok_eventually(fn() => !QuantaDb::coherent(), 'coherence cleared on SIGTERM');
eq(QuantaDb::get('a'), ['v' => 1], 'reads correct in fallback');
eq(QuantaDb::stats()['mode'], 'fallback', 'stats.mode = fallback');

// Writes while down: durable on disk, ack failure tolerated.
ok(QuantaDb::put('b', ['v' => 2], ['father' => 'home']), 'write succeeds without daemon');
eq(QuantaDb::get('b'), ['v' => 2], 'own write readable in fallback');
eq(json_decode((string) file_get_contents("$root/home/b/data.json"), true), ['v' => 2], 'write hit the disk');

// --- Restart: daemon rebuilds from files, including the offline write. ------
qdb_daemon_start();
ok(QuantaDb::coherent(), 'coherent after restart');
eq(QuantaDb::get('b'), ['v' => 2], 'offline write served from shm after restart');
eq(QuantaDb::stats()['mode'], 'shm', 'stats.mode back to shm');
$g_mid = QuantaDb::meta('a')['generation'];
ok($g_mid >= $g_pre, 'generation never went backwards across restart');
QuantaDb::put('a', ['v' => 3]);
ok(QuantaDb::meta('a')['generation'] > $g_pre, 'post-restart write bumps past pre-restart generation');

// --- Crash: kill -9 leaves a fresh-looking heartbeat; the first failed write
// poisons coherence so every worker flips to fallback immediately. ----------
qdb_daemon_stop(9); // SIGKILL: no cleanup, flag stays set until poisoned
ok(QuantaDb::put('c', ['v' => 4], ['father' => 'home']), 'write survives daemon crash');
ok(!QuantaDb::coherent(), 'failed ack poisoned coherence');
eq(QuantaDb::get('c'), ['v' => 4], 'crash-window write readable (fallback)');
eq(QuantaDb::get('a'), ['v' => 3], 'older data still correct (fallback)');

// --- Second restart picks everything up again. -------------------------------
qdb_daemon_start();
ok(QuantaDb::coherent(), 'coherent after crash restart');
eq(QuantaDb::get('c'), ['v' => 4], 'crash-window write served from shm');
eq(QuantaDb::count(['father' => 'home']), 3, 'home children a, b, c consistent');

finish();
