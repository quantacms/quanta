<?php
/**
 * Node / NodeFactory call sites wired to the node database.
 *
 * Node::loadJSON, hasTranslation, hasChild(ren), getCategories and
 * JSONDataContainer::saveJSON (both the create and the update path).
 */
require __DIR__ . '/_bootstrap.php';

use Quanta\Common\Node;
use Quanta\Common\NodeFactory;
use Quanta\Common\Localization;

list($env, $site, $db) = quanta_env();

seed($db, 'home', array('title' => 'Home'));
seed($db, 'home/businesses', array('title' => 'Businesses'));
seed($db, 'home/businesses/acme', array(
    'title' => 'Acme',
    'permissions' => array('edit' => 'admin', 'view' => 'all'),
));
seed($db, 'home/businesses/acme', array('title' => 'Acme IT'), 'it');
seed($db, 'home/businesses/beta', array('title' => 'Beta'));
seed($db, 'home/businesses/acme/acme-shifts', array('title' => 'Shifts'));
seed($db, 'home/cats', array('title' => 'Cats'));
symlink("$db/home/businesses/acme", "$db/home/cats/acme");
if (qdb_daemon_mode()) {
    settle(fn() => \QuantaDb::links('acme') === array('cats'));
}

// ── loadJSON: the document, and nested shapes ────────────────────────────────
$acme = NodeFactory::load($env, 'acme');
ok($acme->exists, 'node loads');
eq($acme->getTitle(), 'Acme', 'title read from the document');
ok(is_object($acme->json->permissions),
    'nested JSON objects stay objects (getObject, not (object) get)');
eq($acme->json->permissions->edit, 'admin', 'nested object field readable');

$missing = NodeFactory::load($env, 'no-such-node-at-all');
eq($missing->exists, FALSE, 'a missing node does not exist');

// ── hasTranslation ───────────────────────────────────────────────────────────
eq($acme->hasTranslation('it'), TRUE, 'hasTranslation finds a translation');
eq($acme->hasTranslation('de'), FALSE, 'hasTranslation rejects a missing language');
$beta = NodeFactory::load($env, 'beta');
eq($beta->hasTranslation('it'), FALSE, 'hasTranslation on a neutral-only node');
eq($acme->hasTranslation(''), FALSE, 'hasTranslation with an empty language is FALSE');

// ── hasChildren / hasChild ───────────────────────────────────────────────────
eq($acme->hasChildren(), TRUE, 'hasChildren sees a subnode');
eq($beta->hasChildren(), FALSE, 'hasChildren is FALSE for a leaf');
eq($acme->hasChild('acme-shifts'), TRUE, 'hasChild finds a subnode');
eq($acme->hasChild('nope'), FALSE, 'hasChild rejects a missing child');
eq($acme->hasChild(''), FALSE, 'hasChild with an empty name is FALSE');

$cats = NodeFactory::load($env, 'cats');
eq($cats->hasChildren(), TRUE, 'hasChildren counts a symlinked member');
eq($cats->hasChild('acme'), TRUE, 'hasChild finds a symlinked member');

// ── getCategories ────────────────────────────────────────────────────────────
// The legacy `find -samefile` also matched the node's own directory, so the
// father is part of the answer; links() + the father must reproduce that.
$cat_names = array();
foreach ($acme->getCategories() as $cat) {
    $cat_names[] = $cat->getName();
}
sort($cat_names);
eq($cat_names, array('businesses', 'cats'),
    'getCategories returns the containers plus the father');

// ── saveJSON: update an existing node ────────────────────────────────────────
// The document a save lands in. Empty and LANGUAGE_NEUTRAL both mean the
// neutral document — the same rule saveJSON and loadJSON apply.
$lang = Localization::getLanguage($env);
$neutral = (empty($lang) || $lang == Localization::LANGUAGE_NEUTRAL);
$doc = $neutral ? 'data.json' : "data_$lang.json";
eq($doc, 'data.json', 'the harness runs in the neutral language');

$g1 = qdb_daemon_mode() ? \QuantaDb::meta('acme')['generation'] : 0;
// The title carries a '/' and a non-ASCII character on purpose: they are what
// tells the extension's serializer apart from PHP's json_encode on disk.
$acme->setTitle('Acme updated http://acme.example/città');
$acme->save();
clearstatcache(TRUE);

$raw_doc = (string) file_get_contents("$db/home/businesses/acme/$doc");
$on_disk = json_decode($raw_doc, TRUE);
eq($on_disk['title'] ?? NULL, 'Acme updated http://acme.example/città',
    "save() persisted the document ($doc)");
eq(NodeFactory::load($env, 'acme')->getTitle(), 'Acme updated http://acme.example/città',
    'reload sees the new title');
ok_ext(fn() => written_by_extension($raw_doc),
    'saveJSON update went through the extension (bytes are not json_encode-escaped)');
ok_daemon(fn() => \QuantaDb::meta('acme')['generation'] > $g1,
    'saveJSON update bumped the generation');

// The update must not have destroyed the other language.
eq($env->db()->data('acme', 'it'), array('title' => 'Acme IT'),
    'updating one language leaves the other alone');

// ── saveJSON: create a new node ──────────────────────────────────────────────
// This is the path that used to be a bare mkdir + unlocked fopen for every
// node Quanta ever created.
$new_name = 'acme-created-' . bin2hex(random_bytes(3));
$node = new Node($env, $new_name, 'businesses');
$node->setTitle('Created http://new.example/città');
$node->save();
clearstatcache(TRUE);

ok(is_dir("$db/home/businesses/$new_name"), 'create made the node directory under its father');
ok(is_file("$db/home/businesses/$new_name/$doc"), 'create wrote the document');
$created = NodeFactory::load($env, $new_name);
ok($created->exists, 'created node loads');
eq($created->getTitle(), 'Created http://new.example/città', 'created node has its title');
ok_ext(fn() => written_by_extension((string) file_get_contents("$db/home/businesses/$new_name/$doc")),
    'create went through the extension (put with father, not a bare mkdir + fopen)');
ok_ext(fn() => \QuantaDb::meta($new_name) !== NULL,
    'create is known to the extension index');
eq_ext(fn() => \QuantaDb::meta($new_name)['father'], 'businesses',
    'create landed under the right father in the index');

// A second create with the same name must not produce a second node. With the
// extension this is EXISTS (swallowed, so the legacy mkdir runs and behaves as
// it always did); either way the original node must still resolve to one place.
$dup = new Node($env, $new_name, 'cats');
$dup->setTitle('Duplicate attempt');
$dup->save();
clearstatcache(TRUE);
$resolved = $env->nodePath($new_name);
ok($resolved !== FALSE && is_dir((string) $resolved), 'the name still resolves after a duplicate create');

// ── deleting through the shim ────────────────────────────────────────────────
$victim = 'acme-victim-' . bin2hex(random_bytes(3));
seed($db, "home/businesses/$victim", array('title' => 'Victim'));
$vnode = NodeFactory::load($env, $victim);
ok($vnode->exists, 'victim node exists before delete');
$vnode->delete();
clearstatcache(TRUE);
$env->nodePath($victim, FALSE, TRUE);
ok(!is_dir("$db/home/businesses/$victim"), 'delete removed the node directory');
eq_ext(fn() => \QuantaDb::exists($victim), FALSE, 'delete removed it from the index too');

finish();
