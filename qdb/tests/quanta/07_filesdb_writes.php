<?php
/**
 * The $env->db() write surface, and the two-implementation contract itself.
 *
 * Where 01_shim_reads covers the reads, this covers everything that changes the
 * tree — put, putRaw, update, deleteDoc, move, delete, link, unlink, relink —
 * plus the rules that make one API out of two implementations:
 *
 *   - every method ANSWERS: a bool or a value, never NULL for "ask someone
 *     else". The three exceptions (reindex, stats, version) say so here;
 *   - failures are FilesDbException with the contract's codes, from either
 *     implementation, so a call site catches one class;
 *   - 'at' names the directory a call is about, so a container built from an
 *     explicit path is answered about THAT directory and not about wherever its
 *     globally-unique name happens to resolve.
 *
 * All three modes must agree. Where the extension is the only one that can make
 * a guarantee (a locked read-modify-write), the guarantee is not asserted — the
 * observable result is.
 */
require __DIR__ . '/_bootstrap.php';

use Quanta\Common\FilesDbException;

list($env, $site, $db) = quanta_env();
$qdb = $env->db();

seed($db, 'home', array('title' => 'Home'));
seed($db, 'home/businesses', array('title' => 'Businesses'));
seed($db, 'home/businesses/acme', array('title' => 'Acme', 'status' => 'active'));
seed($db, 'home/cats', array('title' => 'Cats'));
seed($db, 'home/cats2', array('title' => 'Cats 2'));

$acme_path = "$db/home/businesses/acme";

// ── the class Environment::db() picked ───────────────────────────────────────
eq(get_class($qdb), qdb_ext() ? 'Quanta\Common\FilesDbExt' : 'Quanta\Common\FilesDb',
    'db() picks the implementation from whether the extension is loaded');
eq($qdb->available(), qdb_ext(), 'available() reports the extension, and nothing branches on it');
eq($qdb->coherent(), qdb_daemon_mode(), 'coherent() reports the daemon');

// The three that legitimately have nothing to say without an index.
if (!qdb_ext()) {
    eq($qdb->version(), NULL, 'version() is NULL — there is no index to version');
    eq($qdb->stats(), NULL, 'stats() is NULL — there are no counters');
    eq($qdb->reindex(), NULL, 'reindex() is NULL — there is no index to rebuild');
}

// ── put: update ──────────────────────────────────────────────────────────────
eq($qdb->put('acme', array('title' => 'Acme 2', 'status' => 'active')), TRUE,
    'put() returns TRUE, never NULL');
eq($qdb->data('acme')['title'], 'Acme 2', 'put() is visible to the next read');
eq($qdb->put('acme', array('title' => 'Acme IT'), array('lang' => 'it')), TRUE,
    'put() writes a translation');
eq($qdb->data('acme', 'it')['title'], 'Acme IT', 'the translation reads back');
eq($qdb->hasLang('acme', 'it'), TRUE, 'and shows up in hasLang()');

// A write to a node that is not there, with no create intent, does nothing.
eq($qdb->put('never-existed-anywhere', array('x' => 1)), FALSE,
    'put() without father on an absent node is FALSE, not an accidental create');
ok(!is_dir("$db/home/never-existed-anywhere"), 'and made no directory');

// ── put: create ──────────────────────────────────────────────────────────────
$fresh = 'fresh-' . bin2hex(random_bytes(3));
eq($qdb->put($fresh, array('title' => 'Fresh'), array('father' => 'businesses')), TRUE,
    'put() with father creates');
clearstatcache(TRUE);
ok(is_dir("$db/home/businesses/$fresh"), 'the directory is under the father');
eq($qdb->data($fresh)['title'], 'Fresh', 'the new node reads back');
eq(realpath((string) $qdb->path($fresh)), realpath("$db/home/businesses/$fresh"),
    'and resolves immediately — the create updated the resolver, it did not just '
    . 'invalidate it');

// ── put: create intent reserves the name TREE-WIDE ──────────────────────────
// Node names are globally unique. A separate fixture name is used here and then
// left alone: the 'ignore' case below deliberately produces a duplicate, and
// re-using a name that exists twice would make every later assertion about it
// depend on which copy the index happened to see last.
$dupe = 'dupe-' . bin2hex(random_bytes(3));
seed($db, "home/businesses/$dupe", array('title' => 'Original'));
try {
    $qdb->put($dupe, array('x' => 1), array('father' => 'cats'));
    ok(FALSE, 'creating a name that is already taken throws');
}
catch (FilesDbException $e) {
    eq($e->getCode(), FilesDbException::EXISTS, 'creating a taken name throws EXISTS');
}
clearstatcache(TRUE);
ok(!file_exists("$db/home/cats/$dupe"), 'and made no second directory');

// 'ignore' is Quanta's historical answer to the same collision: make the
// directory anyway, and let the resolver warn about the duplicate later.
// JSONDataContainer::saveJSON passes it, deliberately — see the comment there.
eq($qdb->put($dupe, array('x' => 1), array('father' => 'cats', 'if_exists' => 'ignore')), TRUE,
    "if_exists => 'ignore' creates the duplicate anyway");
clearstatcache(TRUE);
ok(is_dir("$db/home/cats/$dupe"), 'the duplicate directory is there');

// ── putRaw: byte fidelity ────────────────────────────────────────────────────
// json_encode escapes '/' and non-ASCII; neither implementation may re-encode
// what putRaw was handed.
$bytes = '{"url":"http://a/b","city":"città"}';
eq($qdb->putRaw($fresh, $bytes), TRUE, 'putRaw() returns TRUE');
eq($qdb->raw($fresh), $bytes, 'putRaw() stored the bytes verbatim');
try {
    $qdb->putRaw($fresh, '{not json');
    ok(FALSE, 'putRaw() rejects invalid JSON');
}
catch (FilesDbException $e) {
    eq($e->getCode(), FilesDbException::BAD_ARGS, 'putRaw() throws BAD_ARGS on invalid JSON');
}
eq($qdb->raw($fresh), $bytes, 'a rejected putRaw() wrote nothing');

// ── update ───────────────────────────────────────────────────────────────────
$updated = $qdb->update($fresh, function ($doc) {
    $doc['n'] = ($doc['n'] ?? 0) + 1;
    return $doc;
});
eq($updated['n'], 1, 'update() returns the stored document');
eq($qdb->data($fresh)['n'], 1, 'update() persisted');
eq($qdb->data($fresh)['city'], 'città', 'update() kept the fields it was not given');

// ── deleteDoc ────────────────────────────────────────────────────────────────
eq($qdb->deleteDoc('acme', 'it'), TRUE, 'deleteDoc() removes a translation');
eq($qdb->hasLang('acme', 'it'), FALSE, 'the translation is gone');
eq($qdb->deleteDoc('acme', 'it'), FALSE, 'deleteDoc() of a document that is not there is FALSE');
// A node with no document at all is a legal state.
eq($qdb->deleteDoc('acme'), TRUE, 'deleteDoc() removes the neutral document');
eq($qdb->data('acme'), NULL, 'the node has no document');
ok($qdb->path('acme') !== FALSE, 'and still resolves');
$qdb->put('acme', array('title' => 'Acme 2', 'status' => 'active'));

// ── at: the caller already holds the directory ───────────────────────────────
// A container built from an explicit path must be answered about THAT
// directory. This is what the resolvesTo() guards at nine call sites used to
// protect, moved inside the call.
$qdb->put('acme', array('title' => 'By name'), array('at' => $acme_path));
eq($qdb->data('acme', NULL, array('at' => $acme_path))['title'], 'By name', "put()/data() with 'at'");
eq($qdb->langs('acme', array('at' => "$db/home/cats")), array(''),
    "'at' wins over the name when the two disagree");
eq($qdb->put('acme', array('x' => 1), array('at' => "$db/home/nowhere-at-all")), FALSE,
    "a write at a directory that is not there is FALSE, not a create");

// ── move ─────────────────────────────────────────────────────────────────────
eq($qdb->move($fresh, 'cats'), TRUE, 'move() to a new father');
clearstatcache(TRUE);
ok(is_dir("$db/home/cats/$fresh"), 'the directory moved');
ok(!file_exists("$db/home/businesses/$fresh"), 'and is gone from the old father');
eq(realpath((string) $qdb->path($fresh)), realpath("$db/home/cats/$fresh"),
    'the name resolves to the new location');

$renamed = $fresh . '-renamed';
eq($qdb->move($fresh, NULL, array('name' => $renamed)), TRUE, 'move() renames in place');
clearstatcache(TRUE);
eq(realpath((string) $qdb->path($renamed)), realpath("$db/home/cats/$renamed"), 'the node was renamed');

try {
    $qdb->move($renamed, 'businesses', array('name' => 'acme'));
    ok(FALSE, 'moving onto an occupied destination throws');
}
catch (FilesDbException $e) {
    eq($e->getCode(), FilesDbException::EXISTS, 'an occupied destination throws EXISTS');
}
ok(realpath((string) $qdb->path('acme')) === realpath($acme_path), 'and left the occupant alone');
eq($qdb->move('no-such-node-at-all', 'cats'), FALSE, 'move() of an absent node is FALSE, not NULL');

// ── link / unlink / relink ───────────────────────────────────────────────────
try {
    $qdb->link($renamed, 'no-such-container-at-all');
    ok(FALSE, 'linking into a container that does not exist throws');
}
catch (FilesDbException $e) {
    eq($e->getCode(), FilesDbException::BAD_ARGS, 'an unresolvable container throws BAD_ARGS');
}
eq($qdb->link($renamed, 'cats2'), TRUE, 'link() returns TRUE');
clearstatcache(TRUE);
ok(is_link("$db/home/cats2/$renamed"), 'the symlink is there');
eq($qdb->links($renamed), array('cats2'), 'links() names the container');

try {
    $qdb->link($renamed, 'cats2');
    ok(FALSE, 'a duplicate link throws');
}
catch (FilesDbException $e) {
    eq($e->getCode(), FilesDbException::EXISTS, 'a duplicate link throws EXISTS');
}
eq($qdb->link($renamed, 'cats2', array('if_exists' => 'ignore')), TRUE,
    "if_exists => 'ignore' is a no-op, not an error");

// 'override' is a real unlink + link, which is what repairs a DANGLING entry —
// where 'ignore' would call the broken link present and leave it broken.
unlink("$db/home/cats2/$renamed");
symlink("$db/home/gone-away", "$db/home/cats2/$renamed");
clearstatcache(TRUE);
eq($qdb->link($renamed, 'cats2', array('if_exists' => 'override')), TRUE,
    "if_exists => 'override' repairs a dangling link");
clearstatcache(TRUE);
eq(realpath("$db/home/cats2/$renamed"), realpath("$db/home/cats/$renamed"),
    'the link points at the node again');

// 'cats' is the node's own father, so 'businesses' is the destination here — a
// node cannot be a symlinked member of the directory it already lives in.
eq($qdb->relink($renamed, 'cats2', 'businesses'), TRUE, 'relink()');
clearstatcache(TRUE);
ok(!file_exists("$db/home/cats2/$renamed"), 'relink left the old container');
ok(is_link("$db/home/businesses/$renamed"), 'relink joined the new container');

eq($qdb->unlink($renamed, 'businesses'), TRUE, 'unlink() returns TRUE');
clearstatcache(TRUE);
ok(!file_exists("$db/home/businesses/$renamed"), 'the symlink is gone');
try {
    $qdb->unlink($renamed, 'businesses');
    ok(FALSE, 'unlinking what is not there throws');
}
catch (FilesDbException $e) {
    // IO, not BAD_ARGS: the same code the contract's implementation raises for
    // a removal that did not happen. A caller that needs to tell "there was no
    // link" from "the removal failed" passes 'ignore' and reads the FALSE.
    eq($e->getCode(), FilesDbException::IO, 'unlinking nothing throws IO');
}
eq($qdb->unlink($renamed, 'businesses', array('if_not_exists' => 'ignore')), FALSE,
    "if_not_exists => 'ignore' is FALSE, not an error");

// ── delete ───────────────────────────────────────────────────────────────────
eq($qdb->delete($renamed), TRUE, 'delete() returns TRUE');
clearstatcache(TRUE);
ok(!is_dir("$db/home/cats/$renamed"), 'the node directory is gone');
eq($qdb->delete($renamed), FALSE, 'delete() of an absent node is FALSE, not NULL');

finish();
