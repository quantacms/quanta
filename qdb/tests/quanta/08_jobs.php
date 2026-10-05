<?php
/**
 * The job queue runs a job once: JobsFactory::processQueue() and Job::run().
 *
 * The _jobs_todo listing can trail the disk (an index that has not caught up
 * with a move, a per-process snapshot in fallback mode), and a job loaded by
 * NAME resolves to wherever it is now, _jobs_done included. Together they ran
 * finished jobs again. So a job is run only from _jobs_todo itself, a completed
 * job's handler never runs again, and a job that throws does not stop the rest
 * of the queue.
 */
namespace Quanta\Common {
    // Handlers for the test job types, which Environment::hook() finds as the
    // jobs module's hook_job_run_<type>().
    function jobs_job_run_qdbtest_count($env, &$vars)
    {
        $GLOBALS['__job_runs'][] = $vars['job']->getName();
        $vars['completed'] = TRUE;
    }

    function jobs_job_run_qdbtest_boom($env, &$vars)
    {
        $GLOBALS['__job_runs'][] = $vars['job']->getName();
        throw new \RuntimeException('handler failure');
    }
}

namespace {
    require __DIR__ . '/_bootstrap.php';

    use Quanta\Common\Job;
    use Quanta\Common\JobsFactory;
    use Quanta\Common\Logger;

    /**
     * A stale listing on demand: while $GLOBALS['__todo_listing'] is set, it is
     * what the node database lists for _jobs_todo, whatever is on disk.
     */
    trait StaleTodoListing
    {
        public function children($father, $attributes = array())
        {
            if ($father === Job::DIR_TODO && isset($GLOBALS['__todo_listing'])) {
                return $GLOBALS['__todo_listing'];
            }
            return parent::children($father, $attributes);
        }
    }

    list($env, $site, $db) = quanta_env();

    // Swap the Environment's node database for the same implementation with
    // the listing above on top. db() loads the implementation classes.
    if (get_class($env->db()) === 'Quanta\Common\FilesDbExt') {
        class TestFilesDb extends \Quanta\Common\FilesDbExt
        {
            use StaleTodoListing;
        }
    } else {
        class TestFilesDb extends \Quanta\Common\FilesDb
        {
            use StaleTodoListing;
        }
    }
    \Closure::bind(function ($files_db) {
        $this->files_db = $files_db;
    }, $env, \Quanta\Common\Environment::class)(new TestFilesDb($env));
    eq(get_class($env->db()), 'TestFilesDb', 'the listing stand-in is in place');

    seed($db, 'jobs', array('title' => 'Jobs'));
    seed($db, 'jobs/_jobs_todo', array('title' => 'Jobs To Do'));
    seed($db, 'jobs/_jobs_done', array('title' => 'Jobs Done'));
    seed($db, 'jobs/_jobs_unknown', array('title' => 'Jobs Unknown'));
    $todo = "$db/jobs/_jobs_todo";
    $done = "$db/jobs/_jobs_done";

    // ── a pending job runs once ──────────────────────────────────────────────
    $GLOBALS['__job_runs'] = array();
    $a = JobsFactory::queueJob($env, 'qdbtest_count', 'test', array())->getName();
    clearstatcache(TRUE);
    ok(is_dir("$todo/$a"), 'queueJob created the job in _jobs_todo');

    JobsFactory::processQueue($env);
    clearstatcache(TRUE);
    eq($GLOBALS['__job_runs'], array($a), 'processQueue ran the pending job');
    ok(!is_dir("$todo/$a") && is_dir("$done/$a"), 'the completed job moved to _jobs_done');

    JobsFactory::processQueue($env);
    eq($GLOBALS['__job_runs'], array($a), 'the next pass does not run it again');

    // ── a listing that trails the disk ───────────────────────────────────────
    // The listing still names the finished job in _jobs_todo. By name it is
    // found in _jobs_done, which is how it used to be run again.
    $env->nodePath($a, FALSE, TRUE);
    $by_name = new Job($env, $a, Job::DIR_TODO);
    ok($by_name->exists && realpath($by_name->path) === realpath("$done/$a"),
        'by name, the finished job resolves to _jobs_done');

    $GLOBALS['__todo_listing'] = array($a);
    JobsFactory::processQueue($env);
    unset($GLOBALS['__todo_listing']);
    eq($GLOBALS['__job_runs'], array($a),
        'a job the listing names but _jobs_todo no longer holds is not run');

    // ── Job::run() on a completed job ────────────────────────────────────────
    // Loaded by name, as a caller holding only the name does: the handler does
    // not run, and the job stays where it is.
    eq($by_name->run(), TRUE, 'run() reports a completed job as completed');
    clearstatcache(TRUE);
    eq($GLOBALS['__job_runs'], array($a), "run() does not run a completed job's handler again");
    ok(is_dir("$done/$a"), 'the completed job stays in _jobs_done');

    // A completed job still in _jobs_todo is one whose move failed: the next
    // pass finishes the move instead of running the handler.
    $job = JobsFactory::queueJob($env, 'qdbtest_count', 'test', array());
    $job->json->completed = time();
    $job->save();
    $b = $job->getName();
    JobsFactory::processQueue($env);
    clearstatcache(TRUE);
    eq($GLOBALS['__job_runs'], array($a), 'a completed job left in _jobs_todo is not run again');
    ok(!is_dir("$todo/$b") && is_dir("$done/$b"), 'and is moved to _jobs_done');

    // ── a job that throws ────────────────────────────────────────────────────
    $log = tempnam(sys_get_temp_dir(), 'qdbq-jobs-log');
    Logger::configure('error', $log);
    $boom = JobsFactory::queueJob($env, 'qdbtest_boom', 'test', array())->getName();
    $c = JobsFactory::queueJob($env, 'qdbtest_count', 'test', array())->getName();
    $GLOBALS['__job_runs'] = array();
    // Listed first, so there is a job after it to stop.
    $GLOBALS['__todo_listing'] = array($boom, $c);
    JobsFactory::processQueue($env);
    unset($GLOBALS['__todo_listing']);
    Logger::configure(NULL, 'stderr');
    clearstatcache(TRUE);
    eq($GLOBALS['__job_runs'], array($boom, $c), 'the job after one that throws still runs');
    ok(is_dir("$todo/$boom"), 'the job that threw stays in _jobs_todo for the next run');
    $boom_job = new Job($env, $boom, Job::DIR_TODO, NULL, "$todo/$boom");
    eq(count((array) $boom_job->json->attempts), 1, 'with its attempt recorded');
    ok(strpos((string) file_get_contents($log), $boom) !== FALSE, 'the exception is logged with the job');
    @unlink($log);

    finish();
}
