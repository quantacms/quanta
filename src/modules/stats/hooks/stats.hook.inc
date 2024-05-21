<?php
namespace Quanta\Common;

define("STATS_PAGE_BOOT_TIME", 'stats_page_boot_time');
define("STATS_PAGE_COMPLETE_TIME", 'stats_page_complete_time');
define("STATS_NODE_LOADED", 'stats_node_loaded');
define("STATS_NODE_LOADED_CACHE", 'stats_node_loaded_cache');
define("STATS_NODE_BUILT", 'stats_node_built');
define("STATS_NODE_LIST", 'stats_node_list');
define("STATS_QTAG_LOADED", 'stats_qtag_loaded');
define("STATS_QTAG_LOADED_CACHE", 'stats_qtag_loaded_cache');
define("STATS_QTAG_LIST", 'stats_qtag_list');


/**
 * Implements hook_boot().
 *
 * @param Environment $env
 *   The Environment.
 *
 * @param array $vars
 *   An array of variables.
 */
function stats_boot(Environment $env, array $vars) {
  // Mark page start loading time.
  $env->setData(STATS_PAGE_BOOT_TIME, microtime(TRUE) * 1000);
}

/**
 * Implements hook_page_init().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function stats_complete(Environment $env, array $vars) {
  // Mark page end loading time.
  $env->setData(STATS_PAGE_COMPLETE_TIME, microtime(TRUE) * 1000);
}


/**
 * Implements hook_node_build().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function stats_node_build(Environment $env, array $vars) {
  // Mark node building time.
  $env->setData(STATS_NODE_BUILT, ($env->getData(STATS_NODE_BUILT, 0) + 1));
  $list = $env->getData(STATS_NODE_LIST, array());
  $list[] = $vars['node']->name . '_' . $vars['node']->language;
  $env->setData(STATS_NODE_LIST, $list);

}

/**
 * Implements hook_node_load().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function stats_node_load(Environment $env, array $vars) {
  // Count loaded nodes.
  $env->setData(STATS_NODE_LOADED, ($env->getData(STATS_NODE_LOADED, 0) + 1));
}

/**
 * Implements hook_node_load_cache().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function stats_node_load_cache(Environment $env, array $vars) {
  // Count nodes loaded from cache.
  $env->setData(STATS_NODE_LOADED_CACHE, ($env->getData(STATS_NODE_LOADED_CACHE, 0) + 1));
}

/**
 * Implements hook_qtag().
 *
 * @param Environment $env
 *   The Environment.
 * @param array $vars
 *   An array of variables.
 */
function stats_qtag(Environment $env, array $vars) {
  // Count loaded qtags.
  $env->setData(STATS_QTAG_LOADED, ($env->getData(STATS_QTAG_LOADED, 0) + 1));
}
