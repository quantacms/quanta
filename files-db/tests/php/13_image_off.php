<?php
/**
 * The same read-path contract as 12_load.php, with the pre-decoded document
 * image disabled.
 *
 * `load()` and the `where` filter both have an image fast path and a
 * raw-bytes-plus-parse fallback, and the fallback is not a rare corner: it is
 * what every read does when images are off, when the PHP ABI check failed, and
 * for any document too large to image. "The two paths agree" is the claim that
 * makes the fast path safe, so it is checked rather than assumed.
 *
 * quanta_db.image is read once per process, at the first call into the
 * extension, so it has to be set before fresh_env() touches anything.
 */
require __DIR__ . '/_harness.php';
require __DIR__ . '/_read_cases.php';

ini_set('quanta_db.image', '0');

$root = fresh_env();
eq(QuantaDb::stats()['image'], 'off', 'image path is disabled for this process');

seed_read_fixture($root);
assert_load_contract($root);
assert_where_contract($root);

if (qdb_daemon_mode()) {
    // Where the bytes come from DID change, and this is the one place that
    // records it. Since layout v3 the segment stores a document's image OR its
    // raw JSON, never both, and the daemon here has images enabled — so the
    // records carry images and no raw. A reader whose own image path is off
    // therefore has nothing in shared memory it will use, and every read falls
    // to the filesystem.
    //
    // The results above are still exactly right (that is what the 70-odd
    // assertions before this block prove); it is the cost that changed, from a
    // parse to a syscall. quanta_db.image=0 was a fast-path kill switch and is
    // now a switch that takes the database out of shared memory — so turning it
    // off in production is a deployment decision, not a per-process one: give
    // the DAEMON QUANTA_DB_IMAGE=0 too and it goes back to shipping raw bytes,
    // which restores the in-memory fallback this test used to assert.
    $before = QuantaDb::stats();
    QuantaDb::load('l-node');
    QuantaDb::find(['father' => 'w-box', 'where' => ['status' => 'paid']]);
    $after = QuantaDb::stats();
    eq($after['img_serves'], $before['img_serves'], 'no image serve with images off');
    ok($after['fallback_reads'] > $before['fallback_reads'],
        'reads fall to the filesystem when only the READER has images off');
}

finish();
