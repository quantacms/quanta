<?php
namespace Quanta\Common;


/**
 * Implements hook_doctor_setup().
 *
 * Create jobs directories in the host project if they don't exist yet.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function jobs_doctor_setup($env, $vars) {
  // Create jobs base folder directly
  if (!is_dir($env->dir['docroot'] . '/' . Job::DIR_JOBS)) {
    mkdir($env->dir['docroot'] . '/' . Job::DIR_JOBS, 0755, true);
  }

  // Initialize todo and done statuses.
  $jobs_nodes = array(
    Job::DIR_TODO => array('title' => 'Jobs To Do', 'father' => Job::DIR_JOBS),
    Job::DIR_DONE => array('title' => 'Jobs Done', 'father' => Job::DIR_JOBS),
  );

  foreach ($jobs_nodes as $node_name => $nodedata) {
    if (!is_dir($env->dir['docroot'] . '/' . Job::DIR_JOBS . '/' . $node_name)) {
      NodeFactory::buildNode($env, $node_name, $nodedata['father'], $nodedata);
    }
  }

}
