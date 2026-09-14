<?php
/**
 * Contract §3 writes: putRaw, deleteDoc, move (contract 1.3).
 *
 * These three complete the write surface — with them every node mutation a site
 * performs (create, replace, drop one translation, relocate, rename, delete) is
 * expressible through the API, so nothing has to reach for the filesystem and
 * wait for the daemon to notice.
 */
require __DIR__ . '/_harness.php';

$root = fresh_env();

seed_node($root, 'home', ['title' => 'Home']);
seed_node($root, 'home/a', ['title' => 'A']);
seed_node($root, 'home/b', ['title' => 'B']);
seed_node($root, 'home/cats', ['title' => 'Cats']);

// ---------------------------------------------------------------------------
// putRaw — the bytes the caller supplies are the bytes on disk
// ---------------------------------------------------------------------------

// PHP escapes '/' and non-ASCII; serde_json escapes neither. Round-tripping a
// document through put() therefore rewrites it, which is exactly what putRaw
// exists to avoid.
$doc = ['u' => 'http://a/b', 't' => 'città', 'n' => 1];
$raw = json_encode($doc);
ok(str_contains($raw, '\\/') && str_contains($raw, '\\u00e0'), 'PHP encodes escaped slashes + unicode');

ok(QuantaDb::putRaw('rawdoc', $raw, ['father' => 'a']), 'putRaw creates with father');
eq(QuantaDb::getRaw('rawdoc'), $raw, 'putRaw stores bytes verbatim');
eq(QuantaDb::get('rawdoc'), $doc, 'verbatim bytes still decode to the document');
eq((array) QuantaDb::getObject('rawdoc'), $doc, 'getObject agrees with the raw bytes');

// The same document through put(): equal value, different bytes. Asserting the
// inequality (not serde's exact output) is what keeps this a contract test.
ok(QuantaDb::put('putdoc', $doc, ['father' => 'a']), 'put creates the same document');
eq(QuantaDb::get('putdoc'), $doc, 'put round trips by value');
ok(QuantaDb::getRaw('putdoc') !== $raw, 'put re-serializes (bytes differ from json_encode)');

// Validated before the write, never after: a document the daemon cannot parse
// would be latched CORRUPT_JSON and every later read of the node would throw.
throws(fn() => QuantaDb::putRaw('rawdoc', '{nope'), QuantaDbException::BAD_ARGS, 'putRaw rejects invalid JSON');
eq(QuantaDb::getRaw('rawdoc'), $raw, 'rejected putRaw left the document intact');
throws(fn() => QuantaDb::putRaw('rawdoc', ''), QuantaDbException::BAD_ARGS, 'putRaw rejects empty payload');

// Create intent behaves exactly as put()'s.
throws(
    fn() => QuantaDb::putRaw('rawdoc', $raw, ['father' => 'b']),
    QuantaDbException::EXISTS,
    'duplicate putRaw create throws EXISTS'
);
throws(fn() => QuantaDb::putRaw('nofather', '{}'), QuantaDbException::BAD_ARGS, 'putRaw new node without father');
throws(fn() => QuantaDb::putRaw('rawdoc', '{}', ['bogus' => 1]), QuantaDbException::BAD_ARGS, 'putRaw unknown opts key');

// Non-object roots are legal JSON documents and must survive byte-for-byte.
ok(QuantaDb::putRaw('listdoc', '[1,2,3]', ['father' => 'a']), 'putRaw a list root');
eq(QuantaDb::getRaw('listdoc'), '[1,2,3]', 'list root stored verbatim');
eq(QuantaDb::get('listdoc'), [1, 2, 3], 'list root decodes');

// ---------------------------------------------------------------------------
// deleteDoc — drop one translation, keep the node
// ---------------------------------------------------------------------------

QuantaDb::put('trans', ['title' => 'neutral'], ['father' => 'b']);
QuantaDb::put('trans', ['titolo' => 'italiano'], ['lang' => 'it']);
QuantaDb::put('trans', ['titel' => 'deutsch'], ['lang' => 'de']);
eq(QuantaDb::meta('trans')['langs'], ['', 'de', 'it'], 'three languages present');

eq(QuantaDb::deleteDoc('trans', 'de'), true, 'deleteDoc removes a language');
eq(QuantaDb::meta('trans')['langs'], ['', 'it'], 'langs shrank');
eq(QuantaDb::get('trans', 'de'), null, 'deleted language reads null');
eq(QuantaDb::get('trans', 'it'), ['titolo' => 'italiano'], 'sibling language untouched');
eq(QuantaDb::get('trans'), ['title' => 'neutral'], 'neutral document untouched');
clearstatcache();
ok(!file_exists("$root/home/b/trans/data_de.json"), 'data_de.json gone from disk');

eq(QuantaDb::deleteDoc('trans', 'de'), false, 'second deleteDoc -> false');
eq(QuantaDb::deleteDoc('no-such-node', 'it'), false, 'deleteDoc on missing node -> false');

// A node with no documents at all is a legal state: it still resolves, it just
// has nothing to read.
eq(QuantaDb::deleteDoc('trans', 'it'), true, 'deleteDoc the last translation');
eq(QuantaDb::deleteDoc('trans'), true, 'deleteDoc the neutral document');
eq(QuantaDb::meta('trans')['langs'], [], 'no languages left');
eq(QuantaDb::get('trans'), null, 'document-less node reads null');
eq(QuantaDb::exists('trans'), true, 'document-less node still exists');
ok(QuantaDb::path('trans') !== null, 'document-less node still resolves to a path');

throws(fn() => QuantaDb::deleteDoc('trans', 'nope/x'), QuantaDbException::BAD_ARGS, 'deleteDoc invalid language code');

// ---------------------------------------------------------------------------
// move — new father, new name, or both
// ---------------------------------------------------------------------------

QuantaDb::put('mover', ['title' => 'Mover'], ['father' => 'a']);
QuantaDb::put('moverkid', ['title' => 'Kid'], ['father' => 'mover']);
ok(QuantaDb::link('mover', 'cats'), 'mover linked into cats');
eq(QuantaDb::links('mover'), ['cats'], 'inbound link recorded');

eq(QuantaDb::move('missing-node', 'b'), false, 'move of a missing node -> false');
throws(fn() => QuantaDb::move('mover', 'nope'), QuantaDbException::BAD_ARGS, 'move to unknown father');
throws(fn() => QuantaDb::move('mover', 'b', ['if_exists' => 'x']), QuantaDbException::BAD_ARGS, 'invalid if_exists');
throws(fn() => QuantaDb::move('mover', 'moverkid'), QuantaDbException::BAD_ARGS, 'move into own subtree rejected');
eq(QuantaDb::move('mover', 'a'), true, 'move to the same place is a no-op');

// Destination path occupied: EXISTS, and 'replace' clears it (to the trashbin,
// not with rm -rf — the displaced content stays recoverable).
file_put_contents("$root/home/b/mover", 'in the way');
throws(fn() => QuantaDb::move('mover', 'b'), QuantaDbException::EXISTS, 'occupied destination throws EXISTS');
eq(QuantaDb::move('mover', 'b', ['if_exists' => 'replace']), true, 'if_exists=replace moves anyway');
clearstatcache();
ok(is_dir("$root/home/b/mover"), 'node dir now under the new father');
ok(!file_exists("$root/home/a/mover"), 'old location gone');
eq(count(glob($GLOBALS['__qdb_base'] . '/trash/*/mover')), 1, 'displaced obstruction went to the trashbin');

// The whole subtree travelled, and the index agrees about both ends.
eq(QuantaDb::meta('mover')['father'], 'b', 'meta.father updated');
ok(str_ends_with((string) QuantaDb::path('mover'), '/home/b/mover'), 'path updated');
ok(str_ends_with((string) QuantaDb::path('moverkid'), '/home/b/mover/moverkid'), 'descendant path updated');
eq(QuantaDb::get('moverkid'), ['title' => 'Kid'], 'descendant document still readable');
ok(!in_array('mover', QuantaDb::children('a'), true), 'old father no longer lists it');
ok(in_array('mover', QuantaDb::children('b'), true), 'new father lists it');

// The regression this operation exists to avoid: links are stored as ABSOLUTE
// symlinks, so a rename without re-pointing leaves every membership dangling.
eq(QuantaDb::links('mover'), ['cats'], 'inbound link survived the move');
clearstatcache();
ok(is_dir("$root/home/cats/mover"), 'the symlink still resolves to a directory');
eq(realpath("$root/home/cats/mover"), realpath("$root/home/b/mover"), 'symlink points at the new location');

// Rename in place (no father given) — including the link's own filename.
eq(QuantaDb::move('mover', null, ['name' => 'renamed']), true, 'rename in place');
clearstatcache();
eq(QuantaDb::exists('mover'), false, 'old name no longer resolves');
ok(str_ends_with((string) QuantaDb::path('renamed'), '/home/b/renamed'), 'new name resolves');
eq(QuantaDb::get('renamed'), ['title' => 'Mover'], 'document survived the rename');
ok(str_ends_with((string) QuantaDb::path('moverkid'), '/home/b/renamed/moverkid'), 'descendant followed the rename');
eq(QuantaDb::links('renamed'), ['cats'], 'inbound link followed the rename');
ok(is_dir("$root/home/cats/renamed"), 'link renamed to match the target');
ok(!file_exists("$root/home/cats/mover"), 'stale link filename removed');

// A rename onto a name used anywhere else in the tree would make both
// unresolvable: names are the global key.
throws(
    fn() => QuantaDb::move('renamed', null, ['name' => 'putdoc']),
    QuantaDbException::EXISTS,
    'rename onto a taken name throws EXISTS'
);
throws(fn() => QuantaDb::move('renamed', null, ['name' => 'a/b']), QuantaDbException::BAD_ARGS, 'invalid new name');

// Move and rename in one call.
eq(QuantaDb::move('renamed', 'a', ['name' => 'final']), true, 'move + rename together');
ok(str_ends_with((string) QuantaDb::path('final'), '/home/a/final'), 'both applied');
eq(QuantaDb::meta('final')['father'], 'a', 'father applied');

// Cross-process read-your-writes: a fresh worker sees the relocation without
// any polling, because the daemon acks only after publishing (contract §4.1).
$h = spawn_worker('meta_once.php', ['final']);
[$code, $out] = wait_worker($h);
eq($code, 0, 'worker exited cleanly');
$seen = json_decode((string) $out, true);
ok(is_array($seen) && str_ends_with((string) $seen['path'], '/home/a/final'), 'another process sees the new path');
eq($seen['father'] ?? null, 'a', 'another process sees the new father');

// Counters for the new operations. The arena is per-test (fresh metrics_path)
// and only the extension bumps these, so the counts are exact — which is worth
// asserting, because it pins down that rejected calls and no-ops do NOT count:
// of the 11 move() calls above only 3 relocated anything, and of the 7 putRaw()
// calls only 2 wrote.
$stats = QuantaDb::stats();
if (isset($stats['moves'])) {
    eq($stats['moves'], 3, 'stats.moves counts only moves that happened');
    eq($stats['doc_deletes'], 3, 'stats.doc_deletes counts only files removed');
    eq($stats['raw_writes'], 2, 'stats.raw_writes counts only accepted payloads');
}

finish();
