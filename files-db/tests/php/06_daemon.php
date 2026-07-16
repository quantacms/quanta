<?php
/**
 * Daemon-mode behavior: SHM serving, cross-process read-your-writes through
 * the UDS ack, getRaw parity, out-of-band propagation, children/links parity
 * against read_dir ground truth, reindex over the socket.
 */
require __DIR__ . '/_harness.php';

if (!qdb_daemon_mode()) {
    echo "PASSED - 0 ok, 0 failed (daemon-mode only)\n";
    exit(0);
}

$root = fresh_env();
seed_node($root, 'home', ['title' => 'Home']);

// Coherence handshake + stats surface.
ok(QuantaDb::coherent(), 'daemon coherent after start');
$s = QuantaDb::stats();
eq($s['mode'], 'shm', 'stats.mode = shm');
ok($s['epoch'] >= 1, 'stats.epoch exposed');
ok($s['daemon_pid'] > 0, 'stats.daemon_pid exposed');

// Read-your-writes ACROSS processes, immediately after put returns (§4.1
// scenario 2): the ack guarantees the segment already carries the write.
QuantaDb::put('rw1', ['v' => 'first'], ['father' => 'home']);
$h = spawn_worker('get_once.php', ['rw1']);
[$code, $out] = wait_worker($h);
eq($code, 0, 'reader worker exited cleanly');
eq(json_decode($out, true), ['v' => 'first'], 'fresh process sees the write instantly');

QuantaDb::put('rw1', ['v' => 'second']);
$h = spawn_worker('get_once.php', ['rw1']);
[, $out] = wait_worker($h);
eq(json_decode($out, true), ['v' => 'second'], 'overwrite visible cross-process instantly');

// getRaw: exact bytes + parity with get().
eq(json_decode((string) QuantaDb::getRaw('rw1'), true), QuantaDb::get('rw1'), 'getRaw parity with get');
eq(QuantaDb::getRaw('rw1', 'de'), null, 'getRaw missing lang -> null');
eq(QuantaDb::getRaw('no-such-node'), null, 'getRaw missing node -> null');
QuantaDb::put('rw1', ['tit' => 'IT'], ['lang' => 'it']);
eq(json_decode((string) QuantaDb::getRaw('rw1', 'it'), true), ['tit' => 'IT'], 'getRaw lang doc');

// Out-of-band doc edit propagates via inotify (contract §4.2 watch bound).
file_put_contents("$root/home/rw1/data.json", json_encode(['v' => 'external']));
eq_eventually(fn() => QuantaDb::get('rw1'), ['v' => 'external'], 'external edit reaches shm');

// children/links parity against read_dir ground truth, including an
// out-of-band symlink the daemon only sees through inotify.
QuantaDb::put('box', [], ['father' => 'home']);
QuantaDb::put('m-a', ['x' => 1], ['father' => 'box']);
QuantaDb::put('_m-hidden', [], ['father' => 'box']);
QuantaDb::put('m-b', ['x' => 2], ['father' => 'home']);
QuantaDb::link('m-b', 'box');
symlink("$root/home/rw1", "$root/home/box/rw1"); // out-of-band membership
ok_eventually(
    fn() => QuantaDb::children('box', ['type' => 'links']) === ['m-b', 'rw1'],
    'out-of-band symlink observed'
);
$ground = [];
foreach (scandir("$root/home/box") as $e) {
    if ($e[0] === '.' || in_array($e, ['files', 'assets'], true)) continue;
    if (!is_dir("$root/home/box/$e")) continue;
    $ground[] = $e;
}
sort($ground);
eq(QuantaDb::children('box', ['include_hidden' => true]), $ground, 'children parity with read_dir');
eq_eventually(fn() => QuantaDb::links('rw1'), ['box'], 'links() sees out-of-band symlink');

// Reindex over the socket: full rebuild counts + a fresh epoch.
$epoch0 = QuantaDb::stats()['epoch'];
$r = QuantaDb::reindex();
// Tree: home, rw1, box, m-a, _m-hidden, m-b.
eq($r['nodes'], 6, 'daemon reindex counts nodes');
eq($r['links'], 2, 'daemon reindex counts links');
ok(QuantaDb::stats()['epoch'] > $epoch0, 'full reindex published a fresh epoch');
eq(QuantaDb::get('m-a'), ['x' => 1], 'reads fine after reindex epoch flip');

finish();
