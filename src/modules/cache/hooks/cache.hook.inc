<?php
namespace Quanta\Common;

/**
 * TODO: deprecated but maybe still useful.
 * Temporary function to regenerate all json files in the system. Handle with care!
 * @param $vars

function cache_regenerate_json(Environment $env, array $vars) {
  $wikiroot = $env->dir['wiki'];
  $scan = $env->scanDirectory($wikiroot, array(
    'exclude_dirs' => DIR_ALL,
    'type' => DIR_DIRS,
  ));
  foreach ($scan as $k => $scanned) {
    $node = new Node($env, $scanned);
    if (!$node->isEmpty()) {
      $node->save();
    }
  }
}
 */

/**
 * Cache wiki page. Implements hook_boot
 *
 * @param Environment $env
 *   The Environment.
 * @param $vars
 *   An array of variables.
 */
function cache_boot(Environment $env, array $vars) {
  $env->tmpdir('cache', Cache::DIR_CACHE);
}

/**
 * Implements hook_clear_cache().
 *
 * Clears all cached node paths.
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function cache_doctor_clear_cache(Environment $env, array $vars) {
  /** @var Doctor $doctor */
  $doctor = $vars['doctor'];
  $doctor->op(t('Running clear cache hooks...'));
  $doctor->op(t('Deleting all cached paths...'));
  exec('rm -r ' . $env->dir['cache'] . '/*');
  $doctor->ok(t('Done!'));
}
