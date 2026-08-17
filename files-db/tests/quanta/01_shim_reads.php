<?php
/**
 * FilesDb (the $env->db() shim) read surface.
 *
 * Every method here must return the same value with the extension serving it
 * and with the shim on its legacy body. The nodes are seeded the legacy way
 * (mkdir + file_put_contents), so this also covers the index self-heal.
 */
require __DIR__ . '/_bootstrap.php';

list($env, $site, $db) = quanta_env();
$fdb = $env->db();

seed($db, 'home', array('title' => 'Home'));
seed($db, 'home/businesses', array('title' => 'Businesses'));
seed($db, 'home/businesses/acme', array(
    'title' => 'Acme',
    'status' => 'active',
    'owner' => array('name' => 'Ada', 'country' => 'IT'),
));
seed($db, 'home/businesses/acme', array('title' => 'Acme IT'), 'it');
seed($db, 'home/businesses/_hidden', array('title' => 'Hidden'));
seed($db, 'home/businesses/beta', array('title' => 'Beta', 'status' => 'draft'));
seed($db, 'home/cats', array('title' => 'Cats'));

// Symlink membership. Created here, with the rest of the fixtures, and NOT
// later among the assertions: in fallback mode the extension answers from a
// per-process filesystem walk taken at its first call, so a symlink created
// after that first call is invisible for the life of the process.
symlink("$db/home/businesses/acme", "$db/home/cats/acme");
if (qdb_daemon_mode()) {
    settle(fn() => \QuantaDb::links('acme') === array('cats'));
}

// ── path / exists / resolvesTo ───────────────────────────────────────────────
$acme_path = "$db/home/businesses/acme";
$p = $fdb->path('acme');
ok($p === FALSE || $p === NULL || realpath((string) $p) === realpath($acme_path),
    'path() agrees with the real location when it answers');
ok_ext(fn() => realpath((string) $fdb->path('acme')) === realpath($acme_path),
    'path() resolves a legacy-seeded node (index self-heal)');

eq($fdb->resolvesTo('acme', $acme_path), qdb_ext(),
    'resolvesTo() is TRUE exactly when the extension can confirm the path');
eq($fdb->resolvesTo('acme', "$db/home/cats"), FALSE,
    'resolvesTo() rejects a name that lives somewhere else');
eq($fdb->resolvesTo('', $acme_path), FALSE, 'resolvesTo() rejects an empty name');
eq($fdb->resolvesTo('acme', ''), FALSE, 'resolvesTo() rejects an empty path');
eq($fdb->resolvesTo('no-such-node', "$db/home/nope"), FALSE,
    'resolvesTo() rejects a node that does not exist');

// ── data / object / raw ──────────────────────────────────────────────────────
// These have legacy bodies inside the shim, so they must answer in every mode.
eq($fdb->data('acme'), array(
    'title' => 'Acme',
    'status' => 'active',
    'owner' => array('name' => 'Ada', 'country' => 'IT'),
), 'data() returns the decoded neutral document');
eq($fdb->data('acme', 'it'), array('title' => 'Acme IT'), 'data() reads a translation');
eq($fdb->data('acme', 'de'), NULL, 'data() does not fall back between languages');
eq($fdb->data('no-such-node'), NULL, 'data() on a missing node is NULL');

$o = $fdb->object('acme');
ok(is_object($o), 'object() returns an object');
eq($o->title, 'Acme', 'object() top-level field');
ok(is_object($o->owner), 'object() keeps NESTED objects as objects, not arrays');
eq($o->owner->country, 'IT', 'object() nested field');
eq($fdb->object('no-such-node'), NULL, 'object() on a missing node is NULL');

$raw = $fdb->raw('acme');
ok(is_string($raw) && json_decode($raw, TRUE) === $fdb->data('acme'),
    'raw() returns bytes that decode to the same document');

// ── value ────────────────────────────────────────────────────────────────────
eq($fdb->value('acme', 'title'), 'Acme', 'value() reads a top-level field');
eq($fdb->value('acme', 'owner.country'), 'IT', 'value() walks a dot path');
eq($fdb->value('acme', 'owner.missing'), NULL, 'value() on a missing leaf is NULL');
eq($fdb->value('acme', 'title.nope'), NULL, 'value() through a scalar is NULL');
eq($fdb->value('no-such-node', 'title'), NULL, 'value() on a missing node is NULL');

// ── langs ────────────────────────────────────────────────────────────────────
$langs = $fdb->langs('acme');
sort($langs);
eq($langs, array('', 'it'), "langs() lists the neutral document as '' plus translations");
eq($fdb->langs('beta'), array(''), 'langs() on a neutral-only node');
eq($fdb->langs('no-such-node'), array(), 'langs() on a missing node is empty');

// ── children ─────────────────────────────────────────────────────────────────
// Both branches of FilesDb::children() are exercised: DIR_DIRS is expressible
// against the index, DIR_ALL is not and always takes the scan.
$kids = $fdb->children('businesses', array('type' => \Quanta\Common\Environment::DIR_DIRS));
sort($kids);
eq($kids, array('acme', 'beta'), "children() hides '_'-prefixed nodes by default");

$kids_all = $fdb->children('businesses', array(
    'type' => \Quanta\Common\Environment::DIR_DIRS,
    'exclude_dirs' => '',
));
sort($kids_all);
eq($kids_all, array('_hidden', 'acme', 'beta'), "children() includes '_' names when asked");

eq($fdb->children('no-such-node', array('type' => \Quanta\Common\Environment::DIR_DIRS)),
    array(), 'children() of a missing node is empty, not an error');

// ── links (symlink membership) ───────────────────────────────────────────────
eq($fdb->children('cats', array('symlinks' => 'only')), array('acme'),
    'children(symlinks=only) lists symlinked members');
eq($fdb->children('cats', array(
    'type' => \Quanta\Common\Environment::DIR_DIRS,
    'symlinks' => 'no',
)), array(), 'children(DIR_DIRS, symlinks=no) excludes symlinked members');

// symlinks=no WITHOUT a type means "everything that is not a symlink", which
// includes the plain files in the node directory. The index cannot express
// that, so the shim must stay on the scan and still return data.json.
eq($fdb->children('cats', array('symlinks' => 'no')), array('data.json'),
    'children(symlinks=no) with no type keeps returning plain files');

eq_ext(fn() => $fdb->links('acme'), array('cats'), 'links() names the containers');
eq($fdb->links('acme'), qdb_ext() ? array('cats') : NULL,
    'links() is NULL when the extension cannot answer (never a wrong empty list)');

// ── meta ─────────────────────────────────────────────────────────────────────
$meta = $fdb->meta('acme');
if (qdb_ext()) {
    eq($meta['father'], 'businesses', 'meta().father');
    ok(realpath($meta['path']) === realpath($acme_path), 'meta().path');
    ok(is_int($meta['mtime']) && $meta['mtime'] > 0, 'meta().mtime is set');
    $mlangs = $meta['langs'];
    sort($mlangs);
    eq($mlangs, array('', 'it'), 'meta().langs');
} else {
    eq($meta, NULL, 'meta() is NULL without the extension');
}

// ── find / count are extension-only, and say so rather than answering wrong ──
eq_ext(fn() => $fdb->find(array('father' => 'businesses', 'where' => array('status' => 'active'))),
    array('acme'), 'find() filters on the document');
// 3, not 2: find()/count() do not hide '_'-prefixed names — only children()
// applies Quanta's DIR_INACTIVE convention (files-db/docs/usage.md §6).
eq_ext(fn() => $fdb->count(array('father' => 'businesses')), 3,
    "count() counts the match set, '_' names included");
if (!qdb_ext()) {
    eq($fdb->find(array('father' => 'businesses')), NULL,
        'find() returns NULL (not an empty list) without the extension');
    eq($fdb->count(array('father' => 'businesses')), NULL,
        'count() returns NULL without the extension');
}

finish();
