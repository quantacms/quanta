<?php
namespace Quanta\Common;

/**
 * Implements hook_complete().
 * Run the Garbage collector.
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function environment_complete(Environment $env, array $vars){
  // TODO: include the Garbage collector.
  // $garbage = new Garbage($env);
  // $garbage->collect();
}
