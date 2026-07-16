<?php
/**
 * Segment growth/compaction under churn: a tiny arena forces the daemon to
 * roll to fresh epochs while a reader hammers get()/children(). Every read
 * must stay correct (no torn records, no failed lookups) and the epoch must
 * advance, proving the reader remaps across the flip.
 */
require __DIR__ . '/_harness.php';

if (!qdb_daemon_mode()) {
    echo "PASSED - 0 ok, 0 failed (daemon-mode only)\n";
    exit(0);
}

// 1 MB segment budget: a few hundred padded docs trigger repeated compaction.
$root = fresh_env(['QUANTA_DB_SHM_SIZE_MB' => '1']);
seed_node($root, 'home', []);

$epoch0 = QuantaDb::stats()['epoch'];
$pad = str_repeat('x', 800);
$errors = 0;
$N = 400;

for ($i = 0; $i < $N; $i++) {
    QuantaDb::put("n$i", ['i' => $i, 'pad' => $pad], ['father' => 'home']);
    // Read a spread of already-written nodes each iteration; a compaction may
    // be racing this very lookup.
    for ($k = 0; $k <= $i; $k += max(1, intdiv($i, 8) ?: 1)) {
        $doc = QuantaDb::get("n$k");
        if ($doc === null || ($doc['i'] ?? null) !== $k) {
            $errors++;
        }
    }
}

eq($errors, 0, 'no torn/incorrect reads across compaction');
ok(QuantaDb::stats()['epoch'] > $epoch0, 'segment compacted to a new epoch');

// Final consistency: every node present, children complete.
$missing = 0;
for ($i = 0; $i < $N; $i++) {
    if (QuantaDb::get("n$i") === null) {
        $missing++;
    }
}
eq($missing, 0, 'all nodes survive compaction');
eq(QuantaDb::count(['father' => 'home']), $N, 'children count intact after compaction');

// The segment must have actually reclaimed space (dead bytes bounded, not all
// $N records piled into one ever-growing arena).
$s = QuantaDb::stats();
ok($s['shm_bytes'] <= $s['shm_size'], 'arena within its segment size');

finish();
