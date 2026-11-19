<?php
namespace Quanta\Common;

/**
 * Class Job
 * This class represents a Job node, capable of being processed and managed.
 */
class Job extends Node {
  
  const DIR_TODO = '_jobs_todo';
  const DIR_DONE = '_jobs_done';
  const DIR_UNKNOWN = '_jobs_unknown';
  const DIR_JOBS = 'jobs';
  const TYPE_UNKNOWN = 'unknown';

  /**
   * Where a job's claim lock lives, relative to the directory holding the job.
   * Dot-prefixed, so neither the node index nor any queue listing treats it
   * as a node, and outside the job's own directory, so creating a lock file
   * can never race a move that is deleting that directory.
   */
  const DIR_LOCKS = '.locks';

  /**
   * The open, flock()ed lock file while this object holds the job's claim.
   * @var resource|null
   */
  private $claim_handle = NULL;

  /**
   * Path of the lock file behind $claim_handle.
   * @var string|null
   */
  private $claim_path = NULL;

  /**
   * Safely move a job folder, preventing race-condition nesting and duplicate folder conflicts.
   *
   * Two concurrent workers (e.g. run_jobs cron + sync-gyg-availability cron)
   * can finish the same job seconds apart. POSIX `mv src dest` silently moves
   * src *inside* dest when dest is an existing directory, creating
   * dest/<name>/<name>. Using `mv -T` treats dest as the exact target name,
   * so the second mv fails instead of nesting.
   *
   * @param string $sourceFile              Absolute path of the job folder to move.
   * @param string $destinationFile         Absolute path of the intended destination.
   * @param bool   $removeDuplicateIfExist  If true and destination directory exists, remove source directory to prevent duplicate folder integrity warnings.
   * @param Environment|null $env           Environment, to route the move through the node database.
   * @param string|null $name               The job's node name, required with $env.
   *
   * @return bool TRUE if the job ended up at the destination (moved or already there).
   */
  public static function safeMove($sourceFile, $destinationFile, $removeDuplicateIfExist = false, $env = NULL, $name = NULL) {
    // Source already gone — another worker moved it first.
    if (!is_dir($sourceFile)) {
      return true;
    }
    // Destination already exists — another worker completed or archived this job.
    if (is_dir($destinationFile)) {
      if ($removeDuplicateIfExist) {
        $real_source = realpath($sourceFile);
        $real_dest = realpath($destinationFile);
        if ($real_source && $real_dest && $real_source !== $real_dest && is_dir($real_dest)) {
          // Stays a hard delete: the contract has no hard delete and no
          // trashbin management (api-contract.md §11), and this runs on every
          // duplicate a job cron produces — trashing them would only move the
          // churn somewhere that never gets emptied.
          exec("rm -rf " . escapeshellarg($real_source));
        }
      }
      return true;
    }

    // The node database's move: under the node's lock and with every inbound
    // symlink re-pointed where an index is serving (a bare `mv` leaves each
    // container membership of the job dangling), and an `mv -T` where one is
    // not. The destination is checked to be a plain rename into a new father —
    // that is all the call sites do — and an occupied destination was already
    // handled above, so the default if_exists => 'error' cannot fire here.
    if ($env !== NULL && !empty($name) && basename($destinationFile) === $name) {
      try {
        if ($env->db()->move($name, basename(dirname($destinationFile)), array('at' => $sourceFile))) {
          return true;
        }
      }
      catch (\Quanta\Common\FilesDbException $e) {
        // A losing racer: the winner moved the job out from under this one, so
        // the destination filled or the source vanished between the checks
        // above and the move. Both are success for this caller.
        if (!is_dir($sourceFile) || is_dir($destinationFile)) {
          return true;
        }
        // Anything else and the job is still sitting in its old father, so this
        // is NOT done — fall through to the `mv -T` below rather than reporting
        // a failure the callers can only log.
        //
        // The failure this actually catches is EXDEV. A deployment that wants
        // its job queues durable typically gives each job father its own
        // volume, so the fathers are separate mounts, and rename(2) refuses to
        // cross a mount boundary even when both sides
        // live on the same filesystem. `mv -T` copies and unlinks instead, so
        // it is the only thing here that can complete such a move; the index
        // picks the job up at its new father from the father's dir watch.
      }
    }

    // No $env (the cron entry points call it that way), a name that is not the
    // destination's basename, which is not a plain rename, or a node-database
    // move that could not complete (see the catch above).
    // -T treats destination as exact name, not parent directory (prevents nesting).
    exec("mv -T " . escapeshellarg($sourceFile) . " " . escapeshellarg($destinationFile) . " 2>/dev/null", $output, $return);
    // Success, or source is gone (another worker won the race).
    return ($return == 0 || !is_dir($sourceFile));
  }

  /**
   * Take the job's claim: an exclusive, non-blocking flock() on a lock file
   * next to the job.
   *
   * Every runner of a job — an inline JobsFactory::runJob() in a request, a
   * queue pass in a cron or worker — goes through this, so at most one of
   * them executes the job at a time, also across processes and containers
   * that share the job directories (flock is per inode and held by the
   * kernel, so it is released when the holder dies, however it dies).
   *
   * On success the job is re-read from disk: what this object loaded may
   * have been changed by the runner that held the claim before. When the
   * job is no longer where it was loaded from, the claim is given back and
   * FALSE returned, because the job has moved on (finished, parked, moved).
   *
   * Callers that change a queued job outside run() (merging new data into
   * it) take the claim too, so they never write under a running job.
   *
   * @return bool
   *   TRUE if this object now holds the claim. FALSE if another runner holds
   *   it or the job is gone.
   */
  public function claim() {
    if ($this->claim_handle !== NULL) {
      return true;
    }
    if (empty($this->path) || !is_dir($this->path)) {
      return false;
    }

    $lock_dir = dirname($this->path) . '/' . self::DIR_LOCKS;
    $lock_path = $lock_dir . '/' . basename($this->path) . '.lock';

    if (!is_dir($lock_dir)) {
      // World-writable and sticky, like /tmp: whoever creates it first (a
      // root CLI run included), every runner must be able to add its own
      // lock files.
      if (@mkdir($lock_dir, 0777)) {
        @chmod($lock_dir, 01777);
      }
    }

    // The lock file is unlinked on release, so the file opened here can be one
    // a releasing runner has just unlinked. Locking that orphan proves
    // nothing; detect it (the path no longer names the inode that was locked)
    // and start over on the file that is there now.
    for ($try = 0; $try < 3; $try++) {
      $handle = @fopen($lock_path, 'c');
      if ($handle === FALSE) {
        // The file exists but is not writable by this user. flock() works on
        // a read-only handle too.
        $handle = @fopen($lock_path, 'r');
      }
      if ($handle === FALSE) {
        // No lock can be taken here at all. Refusing to run would stop the
        // whole queue on a permission problem, so run unclaimed, as before
        // claims existed, and say so.
        Logger::get('jobs')->warning('Job {job}: cannot open its lock file {lock}, running it without a claim', array('job' => $this->getName(), 'lock' => $lock_path));
        return $this->stillQueued();
      }
      if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        Logger::get('jobs')->info('Job {job} is claimed by another runner, skipped', array('job' => $this->getName()));
        return false;
      }
      clearstatcache(true, $lock_path);
      $on_disk = @stat($lock_path);
      $locked = fstat($handle);
      if ($on_disk !== FALSE && $on_disk['ino'] === $locked['ino'] && $on_disk['dev'] === $locked['dev']) {
        $this->claim_handle = $handle;
        $this->claim_path = $lock_path;
        break;
      }
      flock($handle, LOCK_UN);
      fclose($handle);
    }
    if ($this->claim_handle === NULL) {
      return false;
    }

    if (!$this->stillQueued()) {
      $this->release();
      return false;
    }
    return true;
  }

  /**
   * Give back the claim taken by claim(). Safe to call without one.
   */
  public function release() {
    if ($this->claim_handle === NULL) {
      return;
    }
    // Unlink while still holding the lock, so a runner that opened this file
    // and gets the lock after us sees it was unlinked (see claim()). A failed
    // unlink (someone else's file in the sticky dir) only leaves the file to
    // be reused.
    @unlink($this->claim_path);
    flock($this->claim_handle, LOCK_UN);
    fclose($this->claim_handle);
    $this->claim_handle = NULL;
    $this->claim_path = NULL;
  }

  /**
   * A claim never outlives the object holding it.
   */
  public function __destruct() {
    $this->release();
  }

  /**
   * Whether the job is still where it was loaded from, refreshing this
   * object's document from disk if so.
   *
   * Read straight from data.json, not through the node index: the previous
   * holder of the claim may have written it from another container, whose
   * write this container's index can still be catching up with.
   *
   * @return bool
   */
  private function stillQueued() {
    if (!is_dir($this->path)) {
      return false;
    }
    $raw = @file_get_contents($this->path . '/data.json');
    $doc = ($raw === FALSE) ? NULL : json_decode($raw);
    if (!is_object($doc)) {
      // A directory without a readable document is a job half-way through a
      // move (a copy into the next queue, then a delete). Leave it alone.
      return false;
    }
    foreach (get_object_vars($doc) as $key => $value) {
      $this->json->{$key} = $value;
    }
    return true;
  }

  /**
   * Run the job, under its claim (@see Job::claim()).
   *
   * @return bool
   *   TRUE if the job was successfully completed, FALSE otherwise — including
   *   when another runner holds the claim, which is left to finish it.
   */
  public function run() {
    $held = ($this->claim_handle !== NULL);
    if (!$held && !$this->claim()) {
      return false;
    }
    try {
      return $this->runClaimed();
    }
    finally {
      if (!$held) {
        $this->release();
      }
    }
  }

  /**
   * The body of run(), with the claim held.
   *
   * @return bool
   */
  private function runClaimed() {
    if (!is_dir($this->path)) {
      return false;
    }

    // Already completed: the handler must not run again, it would repeat its
    // side effects. A completed job still in _jobs_todo is one whose move to
    // _jobs_done failed or has not happened yet, so finish that move. Anywhere
    // else (a job loaded by name resolves wherever it is now) it has nothing
    // left to do.
    if (!empty($this->json->completed)) {
      if (basename(dirname($this->path)) == self::DIR_TODO) {
        $this->moveToDone();
      }
      return true;
    }

    $type = isset($this->json->type) ? $this->json->type : self::TYPE_UNKNOWN;
    
    if (!isset($this->json->attempts)) {
      $this->json->attempts = array();
    }
    $max_retries = $this->env->getData('JOB_MAX_RETRIES');
    if (empty($max_retries)) {
      $max_retries = 10;
    }

    if (count($this->json->attempts) >= $max_retries) {
      if (is_dir($this->path)) {
        $logs_data = array(
          'timestamp' => time(),
          'message' => 'Lavoro fallito: raggiunto il limite massimo di tentativi (' . $max_retries . '). Spostato tra i job falliti.',
        );
        // Create logs child for this job
        NodeFactory::buildNode($this->env, $this->name . '-log-' . time(), $this->name . '-logs', $logs_data);
      }
            
      // Move to _jobs_unknown
      $unknown_father = NodeFactory::load($this->env, self::DIR_UNKNOWN);
      if ($unknown_father->exists) {
        $sourceFile = $this->path;
        $destinationFile = $unknown_father->path . '/' . $this->getName();
        
        if (!$this->safeMove($sourceFile, $destinationFile, true, $this->env, $this->getName())) {
          new Message($this->env, 'Warning: Could not move job ' . $this->getName() . ' to ' . self::DIR_UNKNOWN, Message::MESSAGE_WARNING);
        }
      }
      
      return false;
    }
    
    // Record the attempt
    $this->json->attempts[] = (string) time();
    if (is_dir($this->path)) {
      $this->save();
    }
    
    // Invoke the hook to run the job
    $vars = array('job' => &$this);
    
    // Other modules can implement hook_job_run_[type] and set $vars['completed'] = true
    $hooked = $this->env->hook('job_run_' . $type, $vars);
    
    if (!$hooked) {
      if (is_dir($this->path)) {
        $logs_data = array(
          'timestamp' => time(),
          'message' => 'Job failed: No hook available for job type ' . $type . '. Moved to unknown jobs.',
        );
        // Create logs child for this job
        NodeFactory::buildNode($this->env, $this->name . '-log-' . time(), $this->name . '-logs', $logs_data);
      }
            
      // Move to _jobs_unknown
      $unknown_father = NodeFactory::load($this->env, self::DIR_UNKNOWN);
      if ($unknown_father->exists) {
        $sourceFile = $this->path;
        $destinationFile = $unknown_father->path . '/' . $this->getName();
        
        if (!$this->safeMove($sourceFile, $destinationFile, true, $this->env, $this->getName())) {
          new Message($this->env, 'Warning: Could not move job ' . $this->getName() . ' to ' . self::DIR_UNKNOWN, Message::MESSAGE_WARNING);
        }
      }
      
      return false;
    }

    // Check if the job was marked as completed by the hook
    if (isset($vars['completed']) && $vars['completed'] == true) {
      $this->json->completed = time();
      if (is_dir($this->path)) {
        $logs_data = array(
          'timestamp' => time(),
          'message' => 'Job completed successfully: ' . (isset($vars['log']) ? $vars['log'] : 'No extra log provided'),
        );
        // Create logs child for this job
        NodeFactory::buildNode($this->env, $this->name . '-log-' . time(), $this->name . '-logs', $logs_data);
      }
      if(isset($vars['response'])){
        $this->setAttributeJSON('response', $vars['response']);
      }
      if (is_dir($this->path)) {
        $this->save();
      }

      $this->moveToDone();

      return true;
    } else {
      if (is_dir($this->path)) {
        $logs_data = array(
          'timestamp' => time(),
          'message' => 'Job failed: ' . (isset($vars['log']) ? $vars['log'] : 'Unknown error'),
        );
        // Create logs child for this job
        NodeFactory::buildNode($this->env, $this->name . '-log-' . time(), $this->name . '-logs', $logs_data);
      }
      return false;
    }
  }

  /**
   * Move the job to _jobs_done.
   */
  private function moveToDone() {
    $done_father = NodeFactory::load($this->env, self::DIR_DONE);
    if ($done_father->exists) {
      $sourceFile = $this->path;
      $destinationFile = $done_father->path . '/' . $this->getName();

      if (!$this->safeMove($sourceFile, $destinationFile, true, $this->env, $this->getName())) {
        new Message($this->env, 'Warning: Could not move job ' . $this->getName() . ' to ' . self::DIR_DONE, Message::MESSAGE_WARNING);
      }
    }
  }

}
