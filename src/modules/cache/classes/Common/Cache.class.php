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
    // Mutate the cache array in place. Pulling it out into a local variable and
    // writing it back would copy-on-write duplicate the entire (growing) cache
    // on every set, turning a list of N nodes into O(n^2) array churn.
    if (!isset($env->data['cached'])) {
      $env->data['cached'] = array();
    }
    $env->data['cached'][$type][$item] = $value;
  }

  /**
   * Store a link to a node in the cached folder.
   * This useful functions allows fast retrieving of node paths
   * without having to use each time the greedy UNIX find function.
   * @see Cache::getStoredNodePath()
   * @see Cache::nodePathFolder()
   *
   * Note on who still fills this cache: FilesDb::path() — the node resolver
   * Environment::nodePath() wraps — only writes here on its filesystem path.
   * When a quanta_db index is coherent, FilesDbExt resolves a name from the
   * extension and never touches the shard tree, because there the symlink
   * would only be guarding a lookup that is already syscall-free. The other
   * callers — Node::save(), User::save(),
   * NodeFactory::fastLoadFromRealPath(), FastDirList — write unconditionally,
   * in every mode.
   *
   * @param $env
   *   The Environment
   * @param null $nodepath
   *   A full path to a node
   */
  public static function storeNodePath($env, $nodepath = NULL, $overwrite = false, $node_name = NULL) {
    if ($node_name == NULL) {
      $exp = explode('/', $nodepath);
      $node_name = $exp[count($exp) - 1];
    }

    // build=FALSE: work out the shard path without creating it. Both early
    // returns below are the common case and neither needs the directory to
    // exist — if the link is there, its parents are too. Building costs three
    // is_dir() calls on three different paths, and PHP's stat cache keeps one
    // entry, so they are three real stats on every call, including the calls
    // that turn out to have nothing to write. The shard is created further
    // down, at the point where this call is actually about to write.
    $cache_folder = Cache::nodePathFolder($env, $node_name, FALSE);
    $link = $cache_folder . '/' . $node_name;

    // Keep an existing link unless we were asked to replace it. $overwrite is
    // tested first because it is a boolean and is_link() is an lstat: the
    // caller that always overwrites (NodeFactory::fastLoadFromRealPath, once
    // per row of an admin list) should not pay a syscall to ask a question
    // whose answer it discards.
    if (!$overwrite && is_link($link)) {
      return $link;
    }

    // $overwrite means "replace a STALE link", not "rewrite an identical one".
    // On a warm request the link almost always already points at $nodepath, and
    // the symlink()+rename() pair below is then pure syscall waste — one
    // readlink, served from the dentry cache, buys it back. Guarded here rather
    // than at the call sites, one of which passes $overwrite unconditionally
    // and put storeNodePath at ~13% of a list-heavy page.
    if (@readlink($link) === $nodepath) {
      return $link;
    }

    // Past both guards, so this call is going to write: now the shard
    // directories have to exist.
    Cache::nodePathFolder($env, $node_name, TRUE);

    // Publish the link atomically: create it under a unique temporary name and
    // rename() it into place, which replaces whatever is there in one step.
    // The old unlink()+symlink() pair left a window in which a concurrent
    // worker's symlink() failed with EEXIST — and it died mid-render, so on a
    // cold cache under 5-way concurrency 4 of 5 requests returned a truncated
    // body with HTTP 200.
    $tmp = $link . '.tmp.' . getmypid() . '.' . mt_rand();
    if (!@symlink($nodepath, $tmp)) {
      // A full disk, a read-only cache dir, a shard folder that could not be
      // created: this cache is an optimisation, so a missing entry costs the
      // next lookup a search and nothing more. It must never fail a request.
      return $link;
    }
    if (!@rename($tmp, $link)) {
      @unlink($tmp);
    }

    return $link;
  }

  /**
   * Check if a link to the given node name has been stored
   * in the caching system.
   *
   * Returns the LINK, not its target: callers readlink() it themselves
   * (FilesDb::path(), FastDirList).
   *
   * This is a cache with no guarantee behind it, so a caller that has no
   * fallback for FALSE is a bug. FileFactory::checkFile() was one — it built
   * "<link>/<filename>" and served the file straight off the symlink — and it
   * broke silently once the resolver stopped writing here in coherent mode.
   * It asks Environment::nodePath() now. The remaining writers
   * are Node::save(), User::save(), NodeFactory::fastLoadFromRealPath() and
   * FastDirList, which write in every mode.
   *
   * @param Environment $env
   *  The environment.
   * @param string $node_name
   *  The name of the node.
   * @return bool|string
   *  The path of the cache symlink to the node, or FALSE when there is none.
   */
  public static function getStoredNodePath($env, $node_name, $build = FALSE) {
    $cache_folder = Cache::nodePathFolder($env, $node_name, $build);

    $node_link = $cache_folder . '/' . $node_name;

    if (!is_link($node_link)) {
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
        // Concurrent workers build the same shard; losing the race is normal.
        @mkdir($cache_folder, 0755, TRUE);
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

