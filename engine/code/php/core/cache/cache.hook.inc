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

  /**
   * Cache wiki page. Implements hook_cron
   */
  function cache_cron($vars) {
    cachewiki($vars);
  }

  /**
   * @param $vars
   * @param string $base
   */
  function cachewiki($vars, $base = '') {
    $docroot = $vars['env']->dir['docroot'];
    $wikiroot = $vars['env']->dir['wiki'];
    $scan = $vars['env']->scanDirectory($docroot . '/' . $base, array(
      'exclude_dirs' => DIR_ALL,
      'type' => DIR_DIRS,
    ));
    foreach ($scan as $folder) {
      $this_folder = $docroot . '/' . $base . '/' . $folder;
      if (is_dir($this_folder) && !is_link($this_folder)) {
        cachewiki($vars, $base . '/' . $folder);
        $symlink = $wikiroot . '/' . $folder;
        if (!file_exists($symlink)) {
          symlink($this_folder, $symlink);
          $node = new Node($vars['env'], $folder);
          if ($node->exists) {
            touch($symlink, $node->getTimestamp());
          }
        }
      }
    }
  }
  /**
   * Implementation of hook_node_save.
   * Add a node to the cached wiki folder.
   * @param $vars
   */
  function cache_node_save($vars) {
    $node = $vars['node'];
    if (!file_exists($node->path)) {
      symlink($node->realpath, $node->path);
      touch($node->path, $node->getTimestamp());
    }
  }
