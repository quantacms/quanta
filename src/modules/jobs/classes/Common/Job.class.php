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

    // quanta_db extension: move under the node's lock, with every inbound
    // symlink re-pointed (files-db/docs/api-contract.md §9). A bare `mv` leaves
    // each container membership of the job dangling, and the index does not
    // learn of the move until the watcher notices. The destination is checked
    // to be a plain rename into a new father — that is all the call sites do —
    // and an occupied destination was already handled above, so the contract's
    // default if_exists => 'error' cannot fire here.
    if ($env !== NULL && !empty($name)
        && basename($destinationFile) === $name
        && $env->db()->resolvesTo($name, $sourceFile)) {
      $moved = $env->db()->move($name, basename(dirname($destinationFile)));
      if ($moved) {
        return true;
      }
    }

    // -T treats destination as exact name, not parent directory (prevents nesting).
    exec("mv -T " . escapeshellarg($sourceFile) . " " . escapeshellarg($destinationFile) . " 2>/dev/null", $output, $return);
    // Success, or source is gone (another worker won the race).
    return ($return == 0 || !is_dir($sourceFile));
  }

  /**
   * Run the job.
   * 
   * @return bool
   *   TRUE if the job was successfully completed, FALSE otherwise.
   */
  public function run() {
    if (!is_dir($this->path)) {
      return false;
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
      
      // Move to _jobs_done
      // First, get the destination path for _jobs_done folder
      $done_father = NodeFactory::load($this->env, self::DIR_DONE);
      if ($done_father->exists) {
        $sourceFile = $this->path;
        $destinationFile = $done_father->path . '/' . $this->getName();
        
        if (!$this->safeMove($sourceFile, $destinationFile, true, $this->env, $this->getName())) {
          new Message($this->env, 'Warning: Could not move job ' . $this->getName() . ' to ' . self::DIR_DONE, Message::MESSAGE_WARNING);
        }
      }
      
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

}
