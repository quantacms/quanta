<?php
namespace Quanta\Common;

/**
 * Class JobsFactory
 *
 * This Factory class contains static methods for queuing and processing Jobs.
 */
class JobsFactory {
  
  /**
   * Queue a new job.
   * 
   * @param Environment $env
   *   The Environment.
   * @param string $type
   *   The type of the job (e.g., 'post_book').
   * @param string $source
   *   The source of the job (e.g., 'gyg').
   * @param array $data
   *   The payload/data of the job.
   * 
   * @return Job
   *   The generated job node.
   */
  public static function queueJob(Environment $env, $type, $source, $data, $target_node = '') {
    $job_name = time() . '-' .  $type . '-' . substr(md5(uniqid('', true)), 0, 8);
    $job_name = \Quanta\Common\Api::normalizePath($job_name);

    $job_data = array(
      'title' => 'Job ' . $job_name,
      'type' => $type,
      'source' => $source,
      'payload' => $data,
      'attempts' => array(),
      'completed' => NULL,
      'target_node' => $target_node
    );
    
    // Create new node using NodeFactory inside _jobs_todo
    $job_node = NodeFactory::buildNode($env, $job_name, Job::DIR_TODO, $job_data);

    // Create logs child for this job
    NodeFactory::buildNode($env, $job_node->name . '-logs', $job_node->name);
    
    return new Job($env, $job_name, Job::DIR_TODO);
  }
  
  /**
   * Process the job queue by reading the _jobs_todo node.
   * 
   * @param Environment $env
   *   The Environment.
   */
  public static function processQueue(Environment $env, $exclusion = []) {
    $todo_node = NodeFactory::load($env, Job::DIR_TODO);

    if (!$todo_node->exists) {
      new Message($env, 'Error: ' . Job::DIR_TODO . ' node does not exist. Please run doctor update.', Message::MESSAGE_ERROR);
      return;
    }
    
    // The _jobs_todo children (which are nodes), from the index when it can
    // answer. DIR_DIRS is expressible against it; the '.' and 'data' guard
    // below is kept because the legacy scan can still run underneath.
    $dirs = $env->scanNodeDirectory($todo_node->path, Job::DIR_TODO, array('type' => Environment::DIR_DIRS));
    
    foreach ($dirs as $dir) {      
      // Ignore hidden folders, 'data', or other non-node components
      if (substr($dir, 0, 1) != '.' && $dir != 'data') {
        // Load the job from _jobs_todo itself, not by name. The listing can
        // trail the disk, and a name resolves to wherever the job is NOW: a
        // job another worker already finished would be found in _jobs_done
        // and run again.
        $job = new Job($env, $dir, Job::DIR_TODO, NULL, $todo_node->path . '/' . $dir);
        if (!$job->exists) {
          continue;
        }
        $type = isset($job->json->type) ? $job->json->type : '';
        // Skip sync jobs as they are processed by a dedicated cron (processSyncQueue)
        if (in_array($type, $exclusion)) {
          continue;
        }
        // One failing job must not stop the rest of the queue. It keeps its
        // recorded attempt and is retried on the next run.
        try {
          self::runJob($job);
        }
        catch (\Throwable $e) {
          Logger::get('jobs')->error('Job {job} failed with an exception', array('job' => $dir, 'exception' => $e));
        }
      }
    }
  }
  
  /**
   * Run a specific job.
   * 
   * @param Job $job
   *   The job to run.
   * 
   * @return bool
   *   TRUE if completed, FALSE otherwise.
   */
  public static function runJob(Job $job) {
    return $job->run();
  }

}
