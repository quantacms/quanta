<?php
/**
 * The degraded fast path: with the daemon gone, the last segment it published
 * is still on disk and still valid, so a name resolves from it instead of
 * costing this worker a walk of the whole tree.
 *
 * What is asserted here is not "it is faster" — it is the three properties that
 * make consulting an unmaintained index safe at all:
 *
 *   1. it only ever ADDS answers (a name it does not hold still falls through
 *      to the walk, so an absence is never invented);
 *   2. every hit is confirmed on disk (a node deleted while the daemon was down
 *      is not served from the segment's memory of it);
 *   3. it is inert while the daemon is coherent.
 */
require __DIR__ . '/_harness.php';

if (!qdb_daemon_mode()) {
    echo "PASSED - 0 ok, 0 failed (daemon-mode only)\n";
    exit(0);
}

$root = fresh_env();
seed_node($root, 'home', []);
seed_node($root, 'home/kept', ['v' => 1]);
seed_node($root, 'home/gone', ['v' => 2]);

$path_coherent = QuantaDb::path('kept');
ok($path_coherent !== null, 'resolves while coherent');

// --- Property 3: inert while coherent. --------------------------------------
$s_coherent = QuantaDb::stats()['stale_hits'];
QuantaDb::path('kept');
QuantaDb::get('kept');
eq(QuantaDb::stats()['stale_hits'], $s_coherent, 'coherent mode never reads the stale path');

// --- The daemon stops. It removes its socket and nothing else, so the
//     segment it published is still there. -----------------------------------
$before = QuantaDb::stats();
qdb_daemon_stop();
ok_eventually(fn() => !QuantaDb::coherent(), 'coherence cleared on SIGTERM');
eq(QuantaDb::stats()['mode'], 'fallback', 'stats.mode = fallback');

// The point of the whole change: same answer, no walk.
eq(QuantaDb::path('kept'), $path_coherent, 'same path from the last published segment');
ok(
    QuantaDb::stats()['stale_hits'] > $before['stale_hits'],
    'the lookup was served from the segment, not a walk'
);
eq(QuantaDb::get('kept'), ['v' => 1], 'document still correct while degraded');

// children() resolves its father and then reads that one directory, so it
// rides on the same fix rather than needing its own.
eq(QuantaDb::children('home'), ['gone', 'kept'], 'children listed while degraded');

// --- Property 1: a name the segment never held is still found. ---------------
// Created behind the daemon's back, so nothing published it. If a stale segment
// were allowed to answer absence, this node would be invisible until the daemon
// came back.
mkdir("$root/home/fresh", 0777, true);
file_put_contents("$root/home/fresh/data.json", json_encode(['v' => 3]));
ok_eventually(fn() => QuantaDb::path('fresh') !== null, 'node created while down is still found');
eq(QuantaDb::get('fresh'), ['v' => 3], 'and reads correctly');

// --- Property 2: a hit the filesystem does not confirm is not an answer. -----
$u_before = QuantaDb::stats()['stale_unconfirmed'];
exec('rm -rf ' . escapeshellarg("$root/home/gone"));
ok_eventually(fn() => QuantaDb::path('gone') === null, 'node deleted while down is not served from the segment');
ok(
    QuantaDb::stats()['stale_unconfirmed'] > $u_before,
    'the unconfirmed hit was counted, not silently dropped'
);

// --- Recovery: the daemon reconciles both offline changes from disk. ---------
qdb_daemon_start();
ok(QuantaDb::coherent(), 'coherent after restart');
eq(QuantaDb::get('fresh'), ['v' => 3], 'offline create indexed after restart');
eq(QuantaDb::path('gone'), null, 'offline delete stays deleted after restart');
eq(QuantaDb::children('home'), ['fresh', 'kept'], 'children consistent after restart');

finish();
