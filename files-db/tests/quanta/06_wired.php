<?php
/**
 * Proof that each wired call site actually REACHES the extension.
 *
 * The parity suites check that behaviour is the same with and without the
 * extension — which, by design, a shim that silently fell back would also
 * pass. This one closes that gap from the other side, using the extension's
 * live operation counters: if a call site were quietly taking its legacy path,
 * the counter for that operation would not move.
 *
 * Nothing here runs without the extension; there is nothing to prove then.
 */
require __DIR__ . '/_bootstrap.php';

use Quanta\Common\DirList;
use Quanta\Common\Environment;
use Quanta\Common\Job;
use Quanta\Common\Node;
use Quanta\Common\NodeFactory;

list($env, $site, $db) = quanta_env();

if (!qdb_ext()) {
    echo "  skip - the whole file (no extension: nothing to attribute)\n";
    $GLOBALS['__skip']++;
    finish();
}

seed($db, 'home', array('title' => 'Home'));
seed($db, 'home/businesses', array('title' => 'Businesses'));
seed($db, 'home/businesses/acme', array('title' => 'Acme', 'status' => 'active'));
seed($db, 'home/businesses/acme', array('title' => 'Acme IT'), 'it');
seed($db, 'home/businesses/beta', array('title' => 'Beta'));
seed($db, 'home/businesses/acme/acme-shifts', array('title' => 'Shifts'));
seed($db, 'home/cats', array('title' => 'Cats'));
seed($db, 'home/cats2', array('title' => 'Cats 2'));
seed($db, 'home/_jobs_todo', array('title' => 'Todo'));
seed($db, 'home/_jobs_done', array('title' => 'Done'));

// Warm the path caches so the counters below attribute the operation under
// test, not the lookups around it.
$env->nodePath('acme');
$env->nodePath('businesses');
$env->nodePath('cats');

// ── Reads come out of the index, not the filesystem ──────────────────────────
$c = counters();
$node = NodeFactory::load($env, 'acme');
eq($node->getTitle(), 'Acme', 'the node loaded');
grew($c, 'reads', 'Node::loadJSON read through the extension');
unchanged($c, 'file_reads', 'Node::loadJSON did NOT touch the filesystem');

$c = counters();
eq($node->hasTranslation('it'), TRUE, 'hasTranslation answered');
// meta() has no counter of its own — it is a lookup, not a document read — so
// the shared-memory probe count is what shows the index answered.
if (qdb_daemon_mode()) {
    grew($c, 'shm_hits', 'Node::hasTranslation resolved through shared memory');
}
unchanged($c, 'file_reads', 'Node::hasTranslation did NOT stat a data_*.json');

// ── Listing goes through children() ──────────────────────────────────────────
$c = counters();
$env->scanNodeDirectory("$db/home/businesses", 'businesses', array('type' => Environment::DIR_DIRS));
grew($c, 'children_ops', 'Environment::scanNodeDirectory used children()');

$c = counters();
new DirList($env, 'businesses', NULL, array(), 'list');
grew($c, 'children_ops', 'DirList used children()');

$c = counters();
$node->hasChildren();
grew($c, 'children_ops', 'Node::hasChildren used children()');

$c = counters();
$node->hasChild('acme-shifts');
grew($c, 'children_ops', 'Node::hasChild used children()');

// ── Container membership ─────────────────────────────────────────────────────
$c = counters();
NodeFactory::linkNodes($env, 'acme', 'cats', array('if_exists' => 'ignore'));
grew($c, 'link_ops', 'NodeFactory::linkNodes used link()');

$c = counters();
$node->getCategories();
grew($c, 'links_ops', 'Node::getCategories used links()');

$c = counters();
NodeFactory::linkNodes($env, 'acme', 'cats', array(
    'symlink_name' => 'acme',
    'if_exists' => 'override',
));
grew($c, 'unlink_ops', 'linkNodes(override) used unlink() first');
grew($c, 'link_ops', 'linkNodes(override) then used link()');

$c = counters();
NodeFactory::unlinkNodes($env, 'acme', 'cats', array('if_not_exists' => 'ignore'));
grew($c, 'unlink_ops', 'NodeFactory::unlinkNodes used unlink()');

// ── Queries ──────────────────────────────────────────────────────────────────
$c = counters();
$env->db()->find(array('father' => 'businesses', 'where' => array('status' => 'active')));
grew($c, 'find_ops', 'FilesDb::find used find()');

$c = counters();
$env->db()->count(array('father' => 'businesses'));
grew($c, 'count_ops', 'FilesDb::count used count()');

// ── Writes ───────────────────────────────────────────────────────────────────
$c = counters();
$node->setTitle('Acme rewritten');
$node->save();
grew($c, 'writes', 'JSONDataContainer::saveJSON used put() on an update');

$c = counters();
$created_name = 'acme-new-' . bin2hex(random_bytes(3));
$created = new Node($env, $created_name, 'businesses');
$created->setTitle('Created');
$created->save();
grew($c, 'writes', 'saveJSON used put(father) to CREATE, not mkdir + fopen');

// ── Moves ────────────────────────────────────────────────────────────────────
$job_name = 'job-' . bin2hex(random_bytes(3));
seed($db, "home/_jobs_todo/$job_name", array('type' => 'test'));
$env->nodePath($job_name);
$c = counters();
Job::safeMove(
    "$db/home/_jobs_todo/$job_name",
    "$db/home/_jobs_done/$job_name",
    TRUE,
    $env,
    $job_name
);
grew($c, 'moves', 'Job::safeMove used move(), not exec(mv)');

// ── Deletes ──────────────────────────────────────────────────────────────────
$victim = 'acme-victim-' . bin2hex(random_bytes(3));
seed($db, "home/businesses/$victim", array('title' => 'Victim'));
$env->nodePath($victim);
$c = counters();
NodeFactory::load($env, $victim)->delete();
grew($c, 'deletes', 'Node::delete used delete(), not exec(mv)');

// ── The integrity repair's byte-stable document moves ────────────────────────
$repair_dir = "$db/home/businesses/acme/acme-repair";
mkdir($repair_dir, 0777, TRUE);
file_put_contents("$repair_dir/data_it.json", json_encode(array('title' => 'Repair')));
if (qdb_daemon_mode()) {
    settle(fn() => \QuantaDb::path('acme-repair') !== NULL);
}
$env->nodePath('acme-repair');
$c = counters();
\Quanta\Common\integrity_check_node(NodeFactory::load($env, 'acme-repair'), $env);
grew($c, 'raw_writes', 'the integrity repair used putRaw()');
grew($c, 'doc_deletes', 'the integrity repair used deleteDoc()');

finish();
