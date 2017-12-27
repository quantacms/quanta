<?php
/**
 * Created by PhpStorm.
 * User: aldotripiciano
 * Date: 16/05/15
 * Time: 16:45
 */

class Cache extends DataContainer {
  public function __construct(&$env) {
    $this->env = $env;
  }

  /**
   * Returns a cached item.
   * @param $env
   * @param $type
   * @param $value
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
   * @param $env
   * @param $type
   * @param $value
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
   * @see Cache::nodePathCacheFolder()
   *
   * @param $env
   *   The Environment
   * @param null $nodepath
   *   A full path to a node
   */
  public static function storeNodePath($env, $nodepath = NULL) {
    $exp = explode('/', $nodepath);
    $node_name = $exp[count($exp) - 1];
    $cache_folder = Cache::nodePathCacheFolder($env, $node_name);
    // Remove old link if existing.
    if (is_link($cache_folder . '/' . $node_name)) {
      unlink($cache_folder . '/' . $node_name);
    }

    symlink($nodepath, $cache_folder . '/' . $node_name);
  }

  /**
   * Check if a link to the given node name has been stored
   * in the caching system.
   *
   * @param $env
   *  The environment.
   * @param $node_name
   *  The name of the node.
   * @return bool|string
   *  The real path of the node.
   */
  public static function getStoredNodePath($env, $node_name) {
    $cache_folder = self::nodePathCacheFolder($env, $node_name);
    return (is_link($cache_folder . '/' . $node_name)) ? ($cache_folder . '/' . $node_name) : FALSE;
  }

  /**
   * Given a node, build the candidate Cache Folder.
   * The folder is created in the format of a/b/c/abcnode
   * and will be added to the tmp/cache dir if it
   * does not exist yet.
   *
   * @param Environment $env
   *   The environment.
   * @param $node_name
   *   The node name.
   * @return string
   *   The candidate foler.
   */
  public static function nodePathCacheFolder($env, $node_name) {
    $cache_folder = $env->dir['tmp'] . '/cache/';
    for ($i = 0; $i < 3; $i++) {
        $char = substr($node_name, $i, 1);
        $cache_folder = $cache_folder . '/' . $char;
        if (!is_dir($cache_folder)) {
          mkdir ($cache_folder);
        }
    }
    return $cache_folder;
  }
}