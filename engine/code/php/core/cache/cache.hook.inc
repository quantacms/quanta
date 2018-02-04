<?php

/**
 * TODO: deprecated but maybe still useful.
 * Temporary function to regenerate all json files in the system. Handle with care!
 * @param $vars

function cache_regenerate_json($env, $vars) {
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
function cache_boot($env, $vars) {
  $env->tmpdir('cache', CACHE_FOLDER);
}
