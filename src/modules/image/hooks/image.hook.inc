<?php
namespace Quanta\Common;

/**
 * Implements hook_clear_cache().
 *
 * Clears all thumbnails.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function image_doctor_clear_cache($env, $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  $doctor->op('Deleting all thumbnails...');
  array_map('unlink', glob($env->dir['thumbs'] . "/*"));
  $doctor->ok('Done!');
}

/**
 * Implements hook_boot().
 *
 * React to robots.txt requests.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function image_boot($env, $vars) {
  $env->tmpdir('thumbs', 'thumbs');
}
