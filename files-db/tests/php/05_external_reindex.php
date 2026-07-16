<?php
/**
 * Contract §10 scenarios 6, 10, 11: external writes, reindex, stats.
 * External (out-of-band) filesystem changes are observed immediately in
 * fallback mode and within the inotify staleness budget in daemon mode —
 * hence the *_eventually variants around every non-API mutation.
 */
require __DIR__ . '/_harness.php';

$root = fresh_env();
seed_node($root, 'home', []);

// --- Scenario 6: external overwrite observed. --------------------------------
QuantaDb::put('ext1', ['v' => 'api'], ['father' => 'home']);
eq(QuantaDb::get('ext1'), ['v' => 'api'], 'API write readable');
file_put_contents("$root/home/ext1/data.json", json_encode(['v' => 'external-longer-value']));
eq_eventually(fn() => QuantaDb::get('ext1'), ['v' => 'external-longer-value'], 'external overwrite observed');

// --- Scenario 11: node created bypassing the API is found (self-heal). ------
seed_node($root, 'home/ext2', ['v' => 2]);
eq(QuantaDb::get('ext2'), ['v' => 2], 'external node found via fs search');

// External move: derived data heals to the new location.
seed_node($root, 'home/sub', []);
rename("$root/home/ext2", "$root/home/sub/ext2");
ok_eventually(
    fn() => str_ends_with((string) QuantaDb::path('ext2'), '/home/sub/ext2'),
    'moved node re-resolved'
);
eq(QuantaDb::meta('ext2')['father'], 'sub', 'father healed after move');

// External delete: stale derived row cleaned up.
exec('rm -rf ' . escapeshellarg("$root/home/ext1"));
eq_eventually(fn() => QuantaDb::get('ext1'), null, 'externally deleted node -> null');
eq(QuantaDb::exists('ext1'), false, 'exists false after external delete');

// --- Build a small tree for reindex checks. ---------------------------------
QuantaDb::put('ext3', ['v' => 3], ['father' => 'sub'], );
QuantaDb::put('cont', [], ['father' => 'home']);
QuantaDb::link('ext3', 'cont');

// In-process reindex: shape + counts. Tree now: home, sub, ext2, ext3, cont.
$r = QuantaDb::reindex();
ok(is_int($r['nodes']) && is_int($r['links']) && is_float($r['seconds']), 'reindex return shape');
eq($r['nodes'], 5, 'reindex counted all nodes');
eq($r['links'], 1, 'reindex counted the link');

// Queries still correct after full rebuild.
eq(QuantaDb::children('sub'), ['ext2', 'ext3'], 'children after reindex');
eq(QuantaDb::links('ext3'), ['cont'], 'links after reindex');

// Subtree reindex.
$r = QuantaDb::reindex('sub');
eq($r['nodes'], 3, 'subtree reindex counts sub + its children');

// --- Scenario 10: derived data rebuilt from the files alone. -----------------
// A fresh worker process starts with empty per-process caches; its reindex()
// (daemon mode: a full daemon rebuild over the socket) must restore every
// answer purely from the files.
$h = spawn_worker('reindex_fresh.php');
[$code, $out, $err] = wait_worker($h);
eq($code, 0, 'fresh-index worker exited cleanly' . ($code ? " ($err)" : ''));
$res = json_decode($out, true);
eq($res['reindex']['nodes'], 5, 'fresh rebuild: all nodes counted');
eq($res['reindex']['links'], 1, 'fresh rebuild: links counted');
eq($res['children_sub'], ['ext2', 'ext3'], 'fresh rebuild: children query');
eq($res['links_ext3'], ['cont'], 'fresh rebuild: links query');
eq($res['stats_nodes'], 5, 'fresh rebuild: stats.nodes');
eq($res['get_ext3'], ['v' => 3], 'fresh rebuild: documents readable');

// Corrupt docs don't break reindex (node still indexed, doc skipped).
mkdir("$root/home/badjson");
file_put_contents("$root/home/badjson/data.json", '{nope');
$r = QuantaDb::reindex();
eq($r['nodes'], 6, 'reindex tolerates corrupt doc');

// --- stats()/version() shape. ------------------------------------------------
$s = QuantaDb::stats();
eq($s['implementation'], 'ext', 'stats.implementation');
eq($s['contract'], '1.1', 'stats.contract');
eq($s['verify_reads'], 'always', 'stats.verify_reads default');
eq($s['mode'], qdb_daemon_mode() ? 'shm' : 'fallback', 'stats.mode matches run mode');
ok($s['nodes'] >= 6, 'stats.nodes populated');
ok(str_ends_with($s['root'], '/root'), 'stats.root');
eq(QuantaDb::version(), 'ext/1.1', 'version()');

finish();
