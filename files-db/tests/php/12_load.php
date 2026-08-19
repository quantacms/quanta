<?php
/**
 * QuantaDb::load() — the composed read behind Node::loadJSON() — and the
 * image-walking `where` filter, with the document image ON (the default).
 *
 * The contract claims here are behavioural (see _read_cases.php) plus the two
 * things that are only true of the image path: reads are served from the image,
 * and `where` no longer has to decode a document to test a field.
 *
 * 13_image_off.php runs the same assertions with quanta_db.image=0, which is
 * how "the fallback path answers identically" is checked rather than assumed.
 */
require __DIR__ . '/_harness.php';
require __DIR__ . '/_read_cases.php';

$root = fresh_env();
seed_read_fixture($root);

$s = QuantaDb::stats();
ok(in_array($s['image'], ['on', 'abi-mismatch'], true), 'image path not disabled');

assert_load_contract($root);
assert_where_contract($root);

// --- The image actually served these reads. ---------------------------------
// Without this the whole file could be passing on the fallback path, which
// 13_image_off.php already covers — the point of this file is the fast one.
if (qdb_daemon_mode() && $s['image'] === 'on') {
    $before = QuantaDb::stats();
    QuantaDb::load('l-node');
    $after = QuantaDb::stats();
    ok($after['img_serves'] > $before['img_serves'], 'load() is served from the image');
    eq($after['img_invalid'], 0, 'no image failed validation');

    // A `where` pass reads one image per candidate and decodes nothing: the
    // parse cache is what used to hold a full Rc<Value> per node just to test
    // one field, so the interesting counter is that img_serves moves at all
    // while the filter runs.
    $before = QuantaDb::stats();
    eq(QuantaDb::find(['father' => 'w-box', 'where' => ['status' => 'paid']]),
        ['w-1', 'w-3'], 'where filter still correct');
    $after = QuantaDb::stats();
    ok($after['img_serves'] > $before['img_serves'], 'where is evaluated from the image');
}

// --- Repeated loads of many nodes in one epoch keep their own documents. -----
// The same failure mode 10_object_reads.php pins for getObject(): image offsets
// are image-relative, so anything memoised by offset hands one node another
// node's content. load() materialises through the same walk, so it is exposed
// to it too, and only shows it when several distinct documents are live at once.
$held = [];
foreach (['l-node', 'l-lang', 'l-neutral', 'l-rootarr'] as $n) {
    $held[$n] = QuantaDb::load($n);
}
eq($held['l-node']['json']->title, 'N1', 'l-node kept its own document');
eq($held['l-lang']['json']->which, 'neutral', 'l-lang kept its own document');
eq($held['l-rootarr']['json']->{'0'}, 1, 'l-rootarr kept its own document');

// Retained results must survive the mapping being replaced under them.
QuantaDb::reindex();
QuantaDb::load('l-node');
eq($held['l-node']['json']->permissions->node_view, 'anonymous',
    'retained document still readable after an epoch flip');
ok(strlen(json_encode($held['l-node']['json'])) > 0, 'retained document still encodable');

finish();
