<?php
/**
 * Payload directories (store::PAYLOAD_DIRS = assets/files): walked and indexed
 * so their subtree resolves by name — parity with the legacy
 * Environment::findNodePath() `find`, which only excludes _modules/.git and so
 * happily locates e.g. `node=assets/img`. But their documents are NEVER read
 * into the daemon's shared memory: they hold static/binary payload, not node
 * content. Regression guard for the "missing logo" bug (a coherent index used
 * to answer `path('img')` = absent because `assets` was fully skipped).
 */
declare(strict_types=1);
require __DIR__ . '/_harness.php';

$root = fresh_env();

// A payload subtree: assets/img with a static file AND a stray data.json. The
// data.json exists on disk but must never reach the daemon's memory.
mkdir("$root/assets/img", 0777, true);
file_put_contents("$root/assets/img/logo.png", 'PNGDATA');
file_put_contents("$root/assets/img/data.json", json_encode(['leaked' => true]));

// A normal content node — its document IS loaded.
mkdir("$root/pages/home", 0777, true);
file_put_contents("$root/pages/home/data.json", json_encode(['title' => 'Home']));

// A node whose children include a payload-named dir (must stay hidden from
// children listings — scanDirectory parity, store::list_children).
mkdir("$root/sec/assets", 0777, true);
mkdir("$root/sec/real", 0777, true);

QuantaDb::reindex();
ok_eventually(fn() => QuantaDb::path('img') !== null, 'payload node img is path-resolvable');

// 1. Path resolution parity: the assets subtree resolves by name.
$pimg = QuantaDb::path('img');
ok(is_string($pimg) && str_ends_with($pimg, '/assets/img'), 'path(img) -> assets/img');
$passets = QuantaDb::path('assets');
ok(is_string($passets) && str_ends_with($passets, '/assets'), 'path(assets) resolves');

// 2. A normal node resolves and its document loads.
eq(QuantaDb::get('home'), ['title' => 'Home'], 'normal node doc loads');

// 3. The daemon must NOT hold the payload dir's document in shared memory.
//    (Fallback mode has no persistent daemon memory; it reads on demand, so
//    the guarantee is specifically about the shm-serving path.)
if (qdb_daemon_mode()) {
    eq(QuantaDb::get('img'), null, 'payload doc NOT loaded into shm');
    eq(QuantaDb::getRaw('img'), null, 'payload raw doc NOT in shm');
}

// 4. children() still hides payload-named dirs (scanDirectory parity).
eq(QuantaDb::children('sec'), ['real'], 'children() excludes payload dir');

finish();
