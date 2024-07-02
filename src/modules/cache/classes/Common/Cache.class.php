<?php
namespace Quanta\Common;

/**
 * Class Cache.
 *
 * This class manages caching of any items (nodes, etc.) within an environment.
 */
define('DIR_CACHE', 'cache');

/**
 * Class Cache
 */
class Cache extends DataContainer {
  const DIR_CACHE = 'cache';

  /**
   * Returns a cached item.
   *
   * @param Environment $env
   *   The environment.
   * @param $type
   *  The type of cached item.
   * @param $item
   *  The name of the cached item.
   *
   * @return Node|bool
   *
   */
  public static function get($env, $type, $item) {
    $cache = $env->getData('cached', array());
    if (isset($cache[$type][$item])) {
      return $cache[$type][$item];
    }
    else {
      return FALSE;
    }
  }

  /**
   * Sets a cached item in the current .
   *
   * @param Environment $env
   *   The Environment.
   * @param string $type
   *   The type of cache.
   * @param $item
   * @param string $value
   *   The value of the item.
   */
  public static function set($env, $type, $item, $value) {
    // TODO: set lineage with name of nodes only...
    $cache = $env->getData('cached', array());
    $cache[$type][$item] = $value;
    $env->setData('cached', $cache);
  }

  /**
   * Store a link to a node in the cached folder.
   * This useful functions allows fast retrieving of node paths
   * without having to use each time the greedy UNIX find function.
   * @see Cache::getStoredNodePath()
   * @see Cache::nodePathFolder()
   *
   * @param $env
   *   The Environment
   * @param null $nodepath
   *   A full path to a node
   */
  public static function storeNodePath($env, $nodepath = NULL) {
    $exp = explode('/', $nodepath);
    $node_name = $exp[count($exp) - 1];
    $cache_folder = Cache::nodePathFolder($env, $node_name);
    // Remove old link if existing.
    if (is_link($cache_folder . '/' . $node_name)) {
      unlink($cache_folder . '/' . $node_name);
    }

    symlink($nodepath, $cache_folder . '/' . $node_name);

    return $cache_folder . '/' . $node_name;
  }

  /**
   * Check if a link to the given node name has been stored
   * in the caching system.
   *
   * @param Environment $env
   *  The environment.
   * @param string $node_name
   *  The name of the node.
   * @return bool|string
   *  The real path of the node.
   */
  public static function getStoredNodePath($env, $node_name) {
    $cache_folder = Cache::nodePathFolder($env, $node_name, $build = FALSE);

    $node_link = $cache_folder . '/' . $node_name;

    if (!file_exists($node_link)) {
      return false;
    }

    return  ($node_link);
  }

  /**
   * Given a node, build the candidate Node Path Folder.
   * The folder is created in the format of a/b/c/abcnode
   * and will be added to the tmp/cache dir if it
   * does not exist yet.
   *
   * @param Environment $env
   *   The environment.
   * @param $node_name
   *   The node name.
   * @param $build
   *   If true, build the cached path tree.
   * @return string
   *   The candidate foler.
   */
  public static function nodePathFolder($env, $node_name, $build = TRUE) {
    $cache_folder = $env->dir['tmp'] . '/cache';

    for ($i = 0; $i < 3; $i++) {
      $char = substr($node_name, $i, 1);
      $cache_folder = $cache_folder . '/' . $char;
      if ($build && !is_dir($cache_folder)) {
        mkdir($cache_folder, 0755, TRUE);
      }
    }
    return $cache_folder;
  }

  /**
   * Clear all cached paths.
   *
   * @param Environment $env
   */
  public static function clear($env) {
    $cache_dir = $env->dir['cache'];
    // Security check. TODO: secure enough?
    $exp = explode('/', $cache_dir);
    if (array_pop($exp) == DIR_CACHE) {
      exec('rm -R ' . $cache_dir . '/*', $results_arr);
    }
  }
}
