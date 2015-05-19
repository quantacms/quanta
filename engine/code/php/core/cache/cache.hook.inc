<?php
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
    $scan = $vars['env']->scanDirectory($docroot . '/' . $base);
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
