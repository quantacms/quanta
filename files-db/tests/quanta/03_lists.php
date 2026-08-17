<?php
/**
 * The listing layer: Environment::scanNodeDirectory / scanDirectoryDeep, and
 * the DirList / FastDirList classes every list in the CMS renders through.
 *
 * These must return the same names, in the same order, with the same array
 * shape, whether the index or the directory scan answered.
 */
require __DIR__ . '/_bootstrap.php';

use Quanta\Common\DirList;
use Quanta\Common\Environment;
use Quanta\Common\FastDirList;

list($env, $site, $db) = quanta_env();

seed($db, 'home', array('title' => 'Home'));
seed($db, 'home/businesses', array('title' => 'Businesses'));
seed($db, 'home/businesses/acme', array('title' => 'Acme'));
seed($db, 'home/businesses/beta', array('title' => 'Beta'));
seed($db, 'home/businesses/gamma', array('title' => 'Gamma'));
seed($db, 'home/businesses/_hidden', array('title' => 'Hidden'));
seed($db, 'home/businesses/acme/acme-shifts', array('title' => 'Shifts'));
seed($db, 'home/businesses/acme/acme-shifts/acme-shift-1', array('title' => 'Shift 1'));
seed($db, 'home/cats', array('title' => 'Cats'));
symlink("$db/home/businesses/acme", "$db/home/cats/acme");
// A plain file in a node directory: payload, never a node.
file_put_contents("$db/home/businesses/readme.txt", 'not a node');
if (qdb_daemon_mode()) {
    settle(fn() => \QuantaDb::links('acme') === array('cats'));
}

$bpath = "$db/home/businesses";

// ── scanNodeDirectory ────────────────────────────────────────────────────────
$dirs = $env->scanNodeDirectory($bpath, 'businesses', array('type' => Environment::DIR_DIRS));
eq($dirs, array('acme', 'beta', 'gamma'),
    'scanNodeDirectory(DIR_DIRS) lists child nodes, sorted, without hidden ones');
eq(array_keys($dirs), array(0, 1, 2),
    'scanNodeDirectory returns a LIST — no holes from the scan branch');
ok(!in_array('readme.txt', $dirs, TRUE), 'DIR_DIRS excludes plain files');

// An unknown name must degrade to the scan of the path it was given, not to
// an empty answer.
$by_path = $env->scanNodeDirectory($bpath, NULL, array('type' => Environment::DIR_DIRS));
eq($by_path, array('acme', 'beta', 'gamma'), 'scanNodeDirectory with no name still scans');

// A name that does not belong to this path must not be trusted.
$mismatch = $env->scanNodeDirectory($bpath, 'cats', array('type' => Environment::DIR_DIRS));
eq($mismatch, array('acme', 'beta', 'gamma'),
    'scanNodeDirectory ignores a name that resolves elsewhere and scans the path');

// Hidden names on request.
$with_hidden = $env->scanNodeDirectory($bpath, 'businesses', array(
    'type' => Environment::DIR_DIRS,
    'exclude_dirs' => '',
));
eq($with_hidden, array('_hidden', 'acme', 'beta', 'gamma'),
    "scanNodeDirectory can include '_' names");

// ── scanDirectoryDeep ────────────────────────────────────────────────────────
// Depth >= 1 of the walk is indexed; the whole tree must still come back.
$deep = $env->scanDirectoryDeep($bpath, '', array(), array(
    'exclude_dirs' => Environment::DIR_INACTIVE,
    'type' => Environment::DIR_DIRS,
    'level' => 'tree',
));
$names = array();
foreach ($deep as $item) {
    $names[] = $item['name'];
}
sort($names);
ok(in_array('acme', $names, TRUE), 'deep walk reaches depth 1');
ok(in_array('acme-shifts', $names, TRUE), 'deep walk reaches depth 2');
ok(in_array('acme-shift-1', $names, TRUE), 'deep walk reaches depth 3');
ok(!in_array('_hidden', $names, TRUE), "deep walk still hides '_' names");
foreach ($deep as $item) {
    if ($item['name'] === 'acme-shift-1') {
        eq(realpath($item['path']), realpath("$bpath/acme/acme-shifts/acme-shift-1"),
            'deep walk reports the real path of a nested node');
    }
}

// ── DirList ──────────────────────────────────────────────────────────────────
$list = new DirList($env, 'businesses', NULL, array(), 'list');
$items = array();
foreach ($list->getItems() as $item) {
    $items[] = $item->getName();
}
sort($items);
eq($items, array('acme', 'beta', 'gamma'), 'DirList lists the child nodes');
ok(!in_array('readme.txt', $items, TRUE), 'DirList does not list plain files');
ok(!in_array('_hidden', $items, TRUE), "DirList hides '_' names");

// A container node lists its symlinked members too.
$catlist = new DirList($env, 'cats', NULL, array(), 'list');
$cat_items = array();
foreach ($catlist->getItems() as $item) {
    $cat_items[] = $item->getName();
}
eq($cat_items, array('acme'), 'DirList lists a symlinked member');

// An empty node lists nothing, and is not an error.
$leaflist = new DirList($env, 'beta', NULL, array(), 'list');
eq($leaflist->getItems(), array(), 'DirList of a leaf node is empty');

// ── FastDirList ──────────────────────────────────────────────────────────────
$fast = new FastDirList($env, 'businesses', NULL, array(), 'list');
$fast_items = array();
foreach ($fast->getItems() as $item) {
    $fast_items[] = $item->getName();
}
sort($fast_items);
eq($fast_items, array('acme', 'beta', 'gamma'), 'FastDirList agrees with DirList');

finish();
