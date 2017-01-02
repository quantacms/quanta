<?php

/**
 * Temporary function to regenerate all json files in the system. Handle with care!
 * @param $vars
 */
function cache_regenerate_json($vars) {
  $wikiroot = $vars['env']->dir['wiki'];
  $scan = $vars['env']->scanDirectory($wikiroot, array(
    'exclude_dirs' => DIR_ALL,
    'type' => DIR_DIRS,
  ));
  foreach ($scan as $k => $scanned) {
    $node = new Node($vars['env'], $scanned);
    if (!$node->isEmpty()) {
      $node->save();
    }
  }
}

/**
 * Cache wiki page. Implements hook_boot
 */
function cache_boot($vars) {
  $vars['env']->setData('cache', new Cache($vars['env']));
  if (isset($_GET['cleanupjson'])) {
    cache_regenerate_json($vars);
  }
}
