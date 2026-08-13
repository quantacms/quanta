<?php
/** Contract §10 scenarios 1, 5 (in-process), 11 + error codes + fidelity. */
require __DIR__ . '/_harness.php';

$root = fresh_env();

// Class + exception shape.
ok(class_exists('QuantaDb'), 'QuantaDb class is registered');
ok(!function_exists('quanta_db_path'), 'procedural functions are gone (class-only surface)');
ok(class_exists('QuantaDbException'), 'QuantaDbException is registered');
ok(is_subclass_of('QuantaDbException', 'RuntimeException'), 'extends RuntimeException');
eq(QuantaDbException::IO, 1, 'const IO');
eq(QuantaDbException::LOCK_TIMEOUT, 2, 'const LOCK_TIMEOUT');
eq(QuantaDbException::EXISTS, 3, 'const EXISTS');
eq(QuantaDbException::BAD_ARGS, 4, 'const BAD_ARGS');
eq(QuantaDbException::CORRUPT_JSON, 5, 'const CORRUPT_JSON');

eq(QuantaDb::version(), 'ext/1.3', 'version string');

// Nodes seeded directly on the filesystem are found (index self-heal, §4.3).
seed_node($root, 'home', ['title' => 'Home']);
seed_node($root, 'home/businesses', ['title' => 'Businesses']);

eq(QuantaDb::exists('businesses'), true, 'fs-seeded node exists');
ok(str_ends_with((string) QuantaDb::path('businesses'), '/home/businesses'), 'path resolves');
eq(QuantaDb::get('home'), ['title' => 'Home'], 'get returns decoded document');

// Create via put + father (atomic name reservation).
ok(QuantaDb::put('acme', ['title' => 'Acme', 'status' => 'active'], ['father' => 'businesses']), 'create node');
eq(QuantaDb::get('acme'), ['title' => 'Acme', 'status' => 'active'], 'created node readable');
ok(is_dir("$root/home/businesses/acme"), 'node dir created under father');

$meta = QuantaDb::meta('acme');
eq($meta['father'], 'businesses', 'meta.father');
ok($meta['generation'] >= 1, 'meta.generation set');
eq($meta['langs'], [''], 'meta.langs neutral only');
ok(is_int($meta['mtime']) && $meta['mtime'] > 0, 'meta.mtime');

// Duplicate create -> EXISTS, even under a different father.
throws(
    fn() => QuantaDb::put('acme', ['x' => 1], ['father' => 'home']),
    QuantaDbException::EXISTS,
    'duplicate create throws EXISTS'
);

// Update-in-place bumps generation (§4.1: generation, not mtime).
$g1 = QuantaDb::meta('acme')['generation'];
QuantaDb::put('acme', ['title' => 'Acme 2']);
eq(QuantaDb::get('acme'), ['title' => 'Acme 2'], 'put replaces document wholesale');
ok(QuantaDb::meta('acme')['generation'] > $g1, 'generation bumped by put');

// Same-second double write is observed (scenario 5, single process).
QuantaDb::put('acme', ['title' => 'A']);
QuantaDb::put('acme', ['title' => 'B']);
eq(QuantaDb::get('acme')['title'], 'B', 'second same-second write visible');

// Error cases.
throws(fn() => QuantaDb::put('newnode', [], ['father' => 'nope']), QuantaDbException::BAD_ARGS, 'unknown father');
throws(fn() => QuantaDb::put('newnode2', []), QuantaDbException::BAD_ARGS, 'new node without father');
throws(fn() => QuantaDb::get('a/b'), QuantaDbException::BAD_ARGS, 'path-like name rejected');
throws(fn() => QuantaDb::put('acme', [], ['bogus' => 1]), QuantaDbException::BAD_ARGS, 'unknown opts key rejected');

// Language documents.
QuantaDb::put('acme', ['titolo' => 'Acme IT'], ['lang' => 'it']);
eq(QuantaDb::get('acme', 'it'), ['titolo' => 'Acme IT'], 'language doc readable');
eq(QuantaDb::get('acme')['title'], 'B', 'neutral doc untouched by lang put');
eq(QuantaDb::get('acme', 'de'), null, 'missing language -> null (no fallback)');
eq(QuantaDb::meta('acme')['langs'], ['', 'it'], 'meta.langs lists both');

// Not-found is never an exception.
eq(QuantaDb::get('missing-node'), null, 'get missing -> null');
eq(QuantaDb::path('missing-node'), null, 'path missing -> null');
eq(QuantaDb::exists('missing-node'), false, 'exists missing -> false');
eq(QuantaDb::meta('missing-node'), null, 'meta missing -> null');

// Corrupt JSON -> CORRUPT_JSON. (Out-of-band write: in daemon mode the
// corrupt-flagged record arrives via inotify, hence the eventual variant.)
mkdir("$root/home/corrupt");
file_put_contents("$root/home/corrupt/data.json", '{invalid');
throws_eventually(fn() => QuantaDb::get('corrupt'), QuantaDbException::CORRUPT_JSON, 'corrupt data.json');

// update(): locked read-modify-write.
QuantaDb::put('counter', ['n' => 0], ['father' => 'home']);
$ret = QuantaDb::update('counter', function ($cur) {
    $cur['n']++;
    return $cur;
});
eq($ret, ['n' => 1], 'update returns new document');
eq(QuantaDb::get('counter'), ['n' => 1], 'update persisted');
$ret = QuantaDb::update('counter', fn($cur) => null);
eq($ret, null, 'update abort returns null');
eq(QuantaDb::get('counter'), ['n' => 1], 'update abort does not write');
throws(
    fn() => QuantaDb::update('no-such-node', fn($c) => ['x' => 1]),
    QuantaDbException::BAD_ARGS,
    'update cannot create nodes'
);

// Value fidelity round trip (arrays at the boundary, json_decode(..., true) shape).
$data = [
    'i' => 5,
    'f' => 1.5,
    'b' => true,
    'nul' => null,
    'list' => [1, 2, 3],
    'obj' => ['a' => ['b' => 'c']],
    's' => "üñïçødé \"quoted\"",
];
QuantaDb::put('types', $data, ['father' => 'home']);
eq(QuantaDb::get('types'), $data, 'types round trip exactly');

// The file on disk is plain JSON (files stay the source of truth).
$onDisk = json_decode((string) file_get_contents("$root/home/types/data.json"), true);
eq($onDisk, $data, 'data.json on disk matches');

// delete() -> trashbin, links dropped, second delete false.
eq(QuantaDb::delete('types'), true, 'delete returns true');
clearstatcache(); // fs changes were made by the extension, not by PHP
eq(QuantaDb::exists('types'), false, 'deleted node gone');
ok(!is_dir("$root/home/types"), 'node dir removed');
$trash = glob($GLOBALS['__qdb_base'] . '/trash/*/types');
eq(count($trash), 1, 'node moved to trashbin');
eq(QuantaDb::delete('types'), false, 'second delete -> false');

finish();
