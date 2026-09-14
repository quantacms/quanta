<?php
/**
 * Container membership and relocation: NodeFactory::linkNodes / unlinkNodes
 * (including the 'override' repair mode), the relink primitive, Job::safeMove
 * and Environment::getCandidatePath.
 */
require __DIR__ . '/_bootstrap.php';

use Quanta\Common\Environment;
use Quanta\Common\Job;
use Quanta\Common\NodeFactory;

// NOTE the clearstatcache(TRUE) calls below: the argument is load-bearing.
// Without it PHP keeps its REALPATH cache, and the extension removes and
// re-creates symlinks behind PHP's back — so a plain clearstatcache() leaves
// symlink() failing with "File exists" on a path the directory no longer
// contains. Short-lived web requests never notice; a long test process does.

list($env, $site, $db) = quanta_env();

seed($db, 'home', array('title' => 'Home'));
seed($db, 'home/businesses', array('title' => 'Businesses'));
seed($db, 'home/businesses/acme', array('title' => 'Acme'));
seed($db, 'home/cats', array('title' => 'Cats'));
seed($db, 'home/cats2', array('title' => 'Cats 2'));

$acme_dir = "$db/home/businesses/acme";

// ── linkNodes / unlinkNodes ──────────────────────────────────────────────────
NodeFactory::linkNodes($env, 'acme', 'cats', array('if_exists' => 'ignore'));
clearstatcache(TRUE);
ok(is_link("$db/home/cats/acme"), 'linkNodes created the symlink');
eq(realpath("$db/home/cats/acme"), realpath($acme_dir), 'the symlink points at the node');
eq_ext(fn() => \QuantaDb::links('acme'), array('cats'),
    'linkNodes went through the extension (index row exists)');

// Linking twice with 'ignore' is a no-op, not an error.
NodeFactory::linkNodes($env, 'acme', 'cats', array('if_exists' => 'ignore'));
clearstatcache(TRUE);
ok(is_link("$db/home/cats/acme"), 'a duplicate ignore-link leaves the symlink alone');

// The membership is visible through the listing layer in every mode.
eq($env->db()->children('cats', array('symlinks' => 'only')), array('acme'),
    'the member shows up in children(symlinks=only)');

NodeFactory::unlinkNodes($env, 'acme', 'cats', array('if_not_exists' => 'ignore'));
clearstatcache(TRUE);
ok(!is_link("$db/home/cats/acme"), 'unlinkNodes removed the symlink');
eq_ext(fn() => \QuantaDb::links('acme'), array(),
    'unlinkNodes went through the extension (index row gone)');
eq($env->db()->children('cats', array('symlinks' => 'only')), array(),
    'the member is gone from children(symlinks=only)');

// Unlinking what is not there is not an error under 'ignore'.
NodeFactory::unlinkNodes($env, 'acme', 'cats', array('if_not_exists' => 'ignore'));
ok(TRUE, 'unlinking a missing member with ignore does not blow up');

// ── linkNodes 'override' repairs a dangling link ─────────────────────────────
// This is what Doctor::checkBrokenLinks does. A link whose target has gone
// must end up pointing at the real node again — the case where
// link(if_exists => 'ignore') would have called the broken entry "present"
// and left it broken.
symlink("$db/home/businesses/gone-away", "$db/home/cats/acme");
clearstatcache(TRUE);
ok(is_link("$db/home/cats/acme") && !file_exists("$db/home/cats/acme"),
    'a dangling link is in place before the repair');

NodeFactory::linkNodes($env, 'acme', 'cats', array(
    'symlink_name' => 'acme',
    'if_exists' => 'override',
));
clearstatcache(TRUE);
ok(is_link("$db/home/cats/acme"), 'override left a link behind');
eq(realpath("$db/home/cats/acme"), realpath($acme_dir),
    'override re-pointed the dangling link at the real node');

// ── relink: atomic membership change ─────────────────────────────────────────
if (qdb_ext()) {
    \QuantaDb::relink('acme', 'cats', 'cats2');
    clearstatcache(TRUE);
    ok(!file_exists("$db/home/cats/acme"), 'relink left the old container');
    ok(is_link("$db/home/cats2/acme"), 'relink joined the new container');
    eq(\QuantaDb::links('acme'), array('cats2'), 'relink is reflected in the index');
} else {
    // Without the extension BookingFactory-style code does unlink + link.
    NodeFactory::unlinkNodes($env, 'acme', 'cats', array('if_not_exists' => 'ignore'));
    NodeFactory::linkNodes($env, 'acme', 'cats2', array('if_exists' => 'ignore'));
    clearstatcache(TRUE);
    ok(!file_exists("$db/home/cats/acme"), 'unlink+link left the old container');
    ok(is_link("$db/home/cats2/acme"), 'unlink+link joined the new container');
    $GLOBALS['__skip']++;
    echo "  skip - relink is reflected in the index (no extension)\n";
}

// ── Job::safeMove ────────────────────────────────────────────────────────────
seed($db, 'home/_jobs_todo', array('title' => 'Todo'));
seed($db, 'home/_jobs_done', array('title' => 'Done'));
$job_name = 'job-' . bin2hex(random_bytes(3));
seed($db, "home/_jobs_todo/$job_name", array('type' => 'test'));
// A container holding the job, to prove inbound links survive the move.
NodeFactory::linkNodes($env, $job_name, 'cats', array('if_exists' => 'ignore'));
clearstatcache(TRUE);

$src = "$db/home/_jobs_todo/$job_name";
$dst = "$db/home/_jobs_done/$job_name";
eq(Job::safeMove($src, $dst, TRUE, $env, $job_name), TRUE, 'safeMove reports success');
clearstatcache(TRUE);
$env->nodePath($job_name, FALSE, TRUE);
ok(!is_dir($src), 'safeMove emptied the source');
ok(is_dir($dst), 'safeMove filled the destination');
eq_ext(fn() => realpath((string) \QuantaDb::path($job_name)), realpath($dst),
    'the index followed the move');
// move() re-points inbound links; the legacy `mv` does not, so this is the
// assertion that separates them. Only claimed where move() actually ran.
ok_ext(fn() => realpath("$db/home/cats/$job_name") === realpath($dst),
    'move() re-pointed the inbound link at the new location');

// Moving again is a no-op: the source is gone.
eq(Job::safeMove($src, $dst, TRUE, $env, $job_name), TRUE, 'safeMove on a vanished source is a success');

// A move the node database RAISES on, for a reason that is not a lost race,
// must still finish on the filesystem.
//
// In production that reason is EXDEV: a deployment that wants its job queues
// durable gives each job father its own volume, and rename(2) refuses to cross
// a mount boundary even when both sides live on one filesystem. A test cannot
// mount anything, so it reaches the same branch with
// a father the database can no longer resolve — a raise either way, with the
// job still sitting in _jobs_todo afterwards and `mv -T` the only thing left
// that can move it.
//
// Regression: while this branch answered FALSE instead of falling through, no
// job ever left _jobs_todo again on any deployment. Job::run() reported that
// with a Message, which on a non-production site serializes itself — $env
// included — into the session, and $env reaches FilesDb::$last_error, by then
// holding the very \QuantaDbException the move had raised. PHP refuses to
// serialize an exception, so a logged warning became an uncaught fatal and
// every job-running API endpoint answered 500.
seed($db, 'home/_jobs_stuck', array('title' => 'Stuck'));
$job2 = 'job-' . bin2hex(random_bytes(3));
seed($db, "home/_jobs_todo/$job2", array('type' => 'test'));
clearstatcache(TRUE);
// Leave the father's name known but unresolvable: its directory moves out of
// the tree the database owns and a symlink takes its place. Neither a walk nor
// an index can call that a node, so move() raises whoever is serving — while
// the path still works for anything that just follows the link, which is what
// the fallback does.
$stuck_real = sys_get_temp_dir() . '/qdbq-stuck-' . bin2hex(random_bytes(4));
exec('mv -T ' . escapeshellarg("$db/home/_jobs_stuck") . ' ' . escapeshellarg($stuck_real));
symlink($stuck_real, "$db/home/_jobs_stuck");
clearstatcache(TRUE);

$src2 = "$db/home/_jobs_todo/$job2";
$dst2 = "$db/home/_jobs_stuck/$job2";
eq(Job::safeMove($src2, $dst2, TRUE, $env, $job2), TRUE,
    'safeMove falls back to the filesystem when the database move raises');
clearstatcache(TRUE);
ok(!is_dir($src2), 'the fallback emptied the source');
ok(is_dir("$stuck_real/$job2"), 'the fallback filled the destination');
exec('rm -rf ' . escapeshellarg($stuck_real));

// ── getCandidatePath ─────────────────────────────────────────────────────────
$free = $env->getCandidatePath('a-name-nobody-has-taken');
eq($free, 'a-name-nobody-has-taken', 'a free name is returned unchanged');
$taken = $env->getCandidatePath('acme');
ok($taken !== 'acme' && strpos($taken, 'acme-') === 0,
    'a taken name is suffixed until it is free');
// FALSE in every mode: exists() no longer has a third state to fall back on,
// because a miss it cannot confirm from an index it confirms from the disk.
eq($env->db()->exists($taken), FALSE, 'the candidate name really is free');

finish();
