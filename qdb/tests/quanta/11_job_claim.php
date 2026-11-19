<?php
/**
 * A job is executed by one runner at a time: Job::claim() / Job::release().
 *
 * "Queued" and "running" are the same state (a directory in _jobs_todo), so a
 * request that runs its job inline and a queue pass started meanwhile used to
 * execute the same job side by side. Every run now holds the job's claim, an
 * flock() on a lock file in _jobs_todo/.locks; a runner that cannot take it
 * skips the job. The claim re-reads the job from disk, because the previous
 * holder may have changed it from another process or container.
 */
namespace Quanta\Common {
    function jobs_job_run_qdbtest_claim($env, &$vars)
    {
        $GLOBALS['__job_runs'][] = $vars['job']->getName();
        $vars['completed'] = TRUE;
    }
}

namespace {
    require __DIR__ . '/_bootstrap.php';

    use Quanta\Common\Job;
    use Quanta\Common\JobsFactory;

    list($env, $site, $db) = quanta_env();

    seed($db, 'jobs', array('title' => 'Jobs'));
    seed($db, 'jobs/_jobs_todo', array('title' => 'Jobs To Do'));
    seed($db, 'jobs/_jobs_done', array('title' => 'Jobs Done'));
    seed($db, 'jobs/_jobs_unknown', array('title' => 'Jobs Unknown'));
    $todo = "$db/jobs/_jobs_todo";
    $done = "$db/jobs/_jobs_done";
    $locks = "$todo/" . Job::DIR_LOCKS;

    $queue = function () use ($env) {
        return JobsFactory::queueJob($env, 'qdbtest_claim', 'test', array())->getName();
    };
    // A second, independent handle on the same queued job, as another runner
    // would load it: from its exact _jobs_todo path.
    $load = function ($name) use ($env, $todo) {
        return new Job($env, $name, Job::DIR_TODO, NULL, "$todo/$name");
    };
    $attempts_on_disk = function ($dir) {
        $doc = json_decode((string) @file_get_contents("$dir/data.json"));
        return is_object($doc) ? count((array) ($doc->attempts ?? array())) : -1;
    };

    // ── a held claim keeps every other runner out ────────────────────────────
    $GLOBALS['__job_runs'] = array();
    $a = $queue();
    $holder = $load($a);
    $other = $load($a);
    ok($holder->claim(), 'claim() takes a free job');
    ok($holder->claim(), 'claim() again by the holder is a no-op');
    ok(is_file("$locks/$a.lock"), 'the claim is a lock file in _jobs_todo/.locks');
    ok(!$other->claim(), 'a second runner cannot claim it');
    eq($other->run(), FALSE, 'run() by a second runner reports not completed');
    clearstatcache(TRUE);
    eq($GLOBALS['__job_runs'], array(), "and does not run the job's handler");
    eq($attempts_on_disk("$todo/$a"), 0, 'nor record an attempt');

    $holder->release();
    clearstatcache(TRUE);
    ok(!file_exists("$locks/$a.lock"), 'release() removes the lock file');
    eq($other->run(), TRUE, 'once released, the job runs');
    clearstatcache(TRUE);
    eq($GLOBALS['__job_runs'], array($a), 'exactly once');
    ok(!is_dir("$todo/$a") && is_dir("$done/$a"), 'and moves to _jobs_done');
    ok(!file_exists("$locks/$a.lock"), 'leaving no lock file behind');

    // ── a queue pass skips the claimed job and runs the rest ─────────────────
    $GLOBALS['__job_runs'] = array();
    $b = $queue();
    $c = $queue();
    $holder = $load($b);
    ok($holder->claim(), 'a job is claimed (as by a request running it inline)');
    JobsFactory::processQueue($env);
    clearstatcache(TRUE);
    eq($GLOBALS['__job_runs'], array($c), 'processQueue runs the other job, not the claimed one');
    ok(is_dir("$todo/$b"), 'the claimed job stays in _jobs_todo');
    eq($attempts_on_disk("$todo/$b"), 0, 'with no attempt recorded by the pass');
    $holder->release();
    JobsFactory::processQueue($env);
    clearstatcache(TRUE);
    eq($GLOBALS['__job_runs'], array($c, $b), 'released, the next pass runs it');

    // ── the claim re-reads the job from disk ─────────────────────────────────
    // Another process finished the job's handler but its move to _jobs_done
    // did not happen: what this runner loaded before is stale.
    $GLOBALS['__job_runs'] = array();
    $d = $queue();
    $stale = $load($d);
    $doc = json_decode((string) file_get_contents("$todo/$d/data.json"));
    $doc->attempts = array((string) time());
    $doc->completed = time();
    file_put_contents("$todo/$d/data.json", json_encode($doc));
    eq($stale->run(), TRUE, 'a job completed by another runner is reported completed');
    clearstatcache(TRUE);
    eq($GLOBALS['__job_runs'], array(), 'its handler is not run again on stale data');
    ok(!is_dir("$todo/$d") && is_dir("$done/$d"), 'the unfinished move to _jobs_done is completed');

    // Moved away between being listed and being claimed: nothing to run.
    $e = $queue();
    $gone = $load($e);
    exec('mv -T ' . escapeshellarg("$todo/$e") . ' ' . escapeshellarg("$done/$e"));
    clearstatcache(TRUE);
    ok(!$gone->claim(), 'a job no longer in _jobs_todo cannot be claimed');
    eq($gone->run(), FALSE, 'and is not run');
    eq($GLOBALS['__job_runs'], array(), 'its handler does not run');

    // ── claims that end without release() ────────────────────────────────────
    // A lock file nobody holds (its holder died before unlinking it) is reused.
    $GLOBALS['__job_runs'] = array();
    $f = $queue();
    touch("$locks/$f.lock");
    eq($load($f)->run(), TRUE, 'a leftover lock file nobody holds does not block the job');
    clearstatcache(TRUE);
    ok(!file_exists("$locks/$f.lock"), 'and is removed by the run');

    // An object that goes away gives its claim back.
    $g = $queue();
    $holder = $load($g);
    ok($holder->claim(), 'a claim is held by an object');
    unset($holder);
    ok($load($g)->claim(), 'once that object is gone, the job can be claimed again');

    // A holder in another process that is killed: the kernel drops its flock.
    $GLOBALS['__job_runs'] = array();
    $h = $queue();
    $lock = "$locks/$h.lock";
    $proc = proc_open(
        array(PHP_BINARY, '-n', '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); echo "held\n"; sleep(60);', $lock),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
        $pipes
    );
    eq(trim((string) fgets($pipes[1])), 'held', 'another process holds the claim');
    eq($load($h)->run(), FALSE, 'the job does not run while that process holds it');
    proc_terminate($proc, 9);
    fclose($pipes[1]);
    proc_close($proc);
    eq($load($h)->run(), TRUE, 'after that process is killed, the job runs');
    clearstatcache(TRUE);
    eq($GLOBALS['__job_runs'], array($h), 'once');
    ok(is_dir("$done/$h"), 'and moves to _jobs_done');

    finish();
}
