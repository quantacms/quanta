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
   * Safely move a job folder, preventing race-condition nesting.
   *
   * Two concurrent workers (e.g. run_jobs cron + sync-gyg-availability cron)
   * can finish the same job seconds apart. POSIX `mv src dest` silently moves
   * src *inside* dest when dest is an existing directory, creating
   * dest/<name>/<name>. Using `mv -T` treats dest as the exact target name,
   * so the second mv fails instead of nesting.
   *
   * @param string $sourceFile      Absolute path of the job folder to move.
   * @param string $destinationFile Absolute path of the intended destination.
   *
   * @return bool TRUE if the job ended up at the destination (moved or already there).
   */
  private function safeMove($sourceFile, $destinationFile) {
    // Source already gone — another worker moved it first.
    if (!is_dir($sourceFile)) {
      return true;
    }
    // Destination already exists — another worker completed this job.
    if (is_dir($destinationFile)) {
      return true;
    }
    // -T treats destination as exact name, not parent directory (prevents nesting).
    exec("mv -T \"$sourceFile\" \"$destinationFile\" 2>/dev/null", $output, $return);
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
    $type = isset($this->json->type) ? $this->json->type : self::TYPE_UNKNOWN;
    
    if (!isset($this->json->attempts)) {
      $this->json->attempts = array();
    }
    $max_retries = $this->env->getData('JOB_MAX_RETRIES');
    if (empty($max_retries)) {
      $max_retries = 10;
    }

    if (count($this->json->attempts) >= $max_retries) {
      $logs_data = array(
        'timestamp' => time(),
        'message' => 'Lavoro fallito: raggiunto il limite massimo di tentativi (' . $max_retries . '). Spostato tra i job falliti.',
      );
      // Create logs child for this job
      NodeFactory::buildNode($this->env, $this->name . '-log-' . time(), $this->name . '-logs', $logs_data);
            
      // Move to _jobs_unknown
      $unknown_father = NodeFactory::load($this->env, self::DIR_UNKNOWN);
      if ($unknown_father->exists) {
        $sourceFile = $this->path;
        $destinationFile = $unknown_father->path . '/' . $this->getName();
        
        if (!$this->safeMove($sourceFile, $destinationFile)) {
          new Message($this->env, 'Warning: Could not move job ' . $this->getName() . ' to ' . self::DIR_UNKNOWN, Message::MESSAGE_WARNING);
        }
      }
      
      return false;
    }
    
    // Record the attempt
    $this->json->attempts[] = (string) time();
    $this->save();
    
    // Invoke the hook to run the job
    $vars = array('job' => &$this);
    
    // Other modules can implement hook_job_run_[type] and set $vars['completed'] = true
    $hooked = $this->env->hook('job_run_' . $type, $vars);
    
    if (!$hooked) {
      $logs_data = array(
        'timestamp' => time(),
        'message' => 'Job failed: No hook available for job type ' . $type . '. Moved to unknown jobs.',
      );
      // Create logs child for this job
      NodeFactory::buildNode($this->env, $this->name . '-log-' . time(), $this->name . '-logs', $logs_data);
            
      // Move to _jobs_unknown
      $unknown_father = NodeFactory::load($this->env, self::DIR_UNKNOWN);
      if ($unknown_father->exists) {
        $sourceFile = $this->path;
        $destinationFile = $unknown_father->path . '/' . $this->getName();
        
        if (!$this->safeMove($sourceFile, $destinationFile)) {
          new Message($this->env, 'Warning: Could not move job ' . $this->getName() . ' to ' . self::DIR_UNKNOWN, Message::MESSAGE_WARNING);
        }
      }
      
      return false;
    }

    // Check if the job was marked as completed by the hook
    if (isset($vars['completed']) && $vars['completed'] == true) {
      $this->json->completed = time();
      $logs_data = array(
        'timestamp' => time(),
        'message' => 'Job completed successfully: ' . (isset($vars['log']) ? $vars['log'] : 'No extra log provided'),
      );
      // Create logs child for this job
      NodeFactory::buildNode($this->env, $this->name . '-log-' . time(), $this->name . '-logs', $logs_data);
      if(isset($vars['response'])){
        $this->setAttributeJSON('response', $vars['response']);
      }
      $this->save();
      
      // Move to _jobs_done
      // First, get the destination path for _jobs_done folder
      $done_father = NodeFactory::load($this->env, self::DIR_DONE);
      if ($done_father->exists) {
        $sourceFile = $this->path;
        $destinationFile = $done_father->path . '/' . $this->getName();
        
        if (!$this->safeMove($sourceFile, $destinationFile)) {
          new Message($this->env, 'Warning: Could not move job ' . $this->getName() . ' to ' . self::DIR_DONE, Message::MESSAGE_WARNING);
        }
      }
      
      return true;
    } else {
      $logs_data = array(
        'timestamp' => time(),
        'message' => 'Job failed: ' . (isset($vars['log']) ? $vars['log'] : 'Unknown error'),
      );
       // Create logs child for this job
      NodeFactory::buildNode($this->env, $this->name . '-log-' . time(), $this->name . '-logs', $logs_data);
      return false;
    }
  }

}
