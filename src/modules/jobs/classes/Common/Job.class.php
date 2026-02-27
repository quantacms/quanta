<?php
namespace Quanta\Common;

/**
 * Class Job
 * This class represents a Job node, capable of being processed and managed.
 */
class Job extends Node {
  
  const DIR_TODO = '_jobs_todo';
  const DIR_DONE = '_jobs_done';
  const DIR_JOBS = 'jobs';
  const TYPE_UNKNOWN = 'unknown';
  
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
    if (!isset($this->json->logs)) {
      $this->json->logs = array();
    }
    
    // Record the attempt
    $this->json->attempts[] = (string) time();
    $this->save();
    
    // Invoke the hook to run the job
    $vars = array('job' => &$this);
    
    // Other modules can implement hook_job_run_[type] and set $vars['completed'] = true
    $this->env->hook('job_run_' . $type, $vars); 
    

    // Check if the job was marked as completed by the hook
    if (isset($vars['completed']) && $vars['completed']) {
      $this->json->completed = time();
      $this->json->logs[] = array(
        'timestamp' => time(),
        'message' => 'Job completed successfully: ' . (isset($vars['log']) ? $vars['log'] : 'No extra log provided'),
      );
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
        
        exec("mv \"$sourceFile\" \"$destinationFile\"", $output, $return);
        
        if ($return != 0) {
          new Message($this->env, 'Warning: Could not move job ' . $this->getName() . ' to ' . self::DIR_DONE, Message::MESSAGE_WARNING);
        }
      }
      
      return true;
    } else {
      $this->json->logs[] = array(
        'timestamp' => time(),
        'message' => 'Job failed: ' . (isset($vars['log']) ? $vars['log'] : 'Unknown error'),
      );
      $this->save();
      return false;
    }
  }

}
