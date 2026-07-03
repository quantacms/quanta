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
  public static function processQueue(Environment $env) {
    $todo_node = NodeFactory::load($env, Job::DIR_TODO);

    if (!$todo_node->exists) {
      new Message($env, 'Error: ' . Job::DIR_TODO . ' node does not exist. Please run doctor update.', Message::MESSAGE_ERROR);
      return;
    }
    
    // Scan the _jobs_todo directory for sub-folders (which are nodes)
    $dirs = $env->scanDirectory($todo_node->path, array('type' => Environment::DIR_DIRS));
    
    foreach ($dirs as $dir) {      
      // Ignore hidden folders, 'data', or other non-node components
      if (substr($dir, 0, 1) != '.' && $dir != 'data') {
        $job = new Job($env, $dir, Job::DIR_TODO); 
        $type = isset($job->json->type) ? $job->json->type : '';
        // Skip sync jobs as they are processed by a dedicated cron (processSyncQueue)
        if ($type == 'hsw_sync_gyg_availability') {
            continue;
        }
        self::runJob($job);
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
