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
}

/**
 * Implements hook_doctor().
 *
 * Delete the cached class map and perform other environment-related operations.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function environment_doctor(Environment $env, $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  $doctor->op('Deleting cached Class Map file...');
  unlink(CLASS_MAP_FILE);
  $doctor->ok('Done!');
}
