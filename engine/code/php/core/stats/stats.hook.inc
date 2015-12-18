<?php
define("STATS_PAGE_BOOT_TIME", 'stats_page_boot_time');
define("STATS_PAGE_COMPLETE_TIME", 'stats_page_complete_time');
define("STATS_NODE_LOADED", 'stats_node_loaded');
define("STATS_NODE_LOADED_CACHE", 'stats_node_loaded_cache');
define("STATS_NODE_BUILT", 'stats_node_built');
define("STATS_NODE_LIST", 'stats_node_list');
define("STATS_WIKITAG_LOADED", 'stats_wikitag_loaded');


/**
 * Implements hook_boot().
 * @param $vars
 */
function stats_boot($vars) {
  $vars['env']->setData(STATS_PAGE_BOOT_TIME, round(microtime(TRUE) * 1000));
}

/**
 * Implements hook_complete().
 * @param $vars
 */
function stats_page_init($vars) {
  $vars['env']->setData(STATS_PAGE_COMPLETE_TIME, round(microtime(TRUE) * 1000));
}


/**
 * Implements hook_node_build().
 * @param $vars
 */
function stats_node_build($vars) {
  $vars['env']->setData(STATS_NODE_BUILT, ($vars['env']->getData(STATS_NODE_BUILT, 0) + 1));
  $list = $vars['env']->getData(STATS_NODE_LIST, array());
  $list[] = $vars['node']->name;
  $vars['env']->setData(STATS_NODE_LIST, $list);

}

/**
 * Implements hook_node_build().
 * @param $vars
 */
function stats_node_load($vars) {
  $vars['env']->setData(STATS_NODE_LOADED, ($vars['env']->getData(STATS_NODE_LOADED, 0) + 1));
}

/**
 * Implements hook_node_build().
 * @param $vars
 */
function stats_node_load_cache($vars) {
  $vars['env']->setData(STATS_NODE_LOADED_CACHE, ($vars['env']->getData(STATS_NODE_LOADED_CACHE, 0) + 1));
}

/**
 * Implements hook_wikitag().
 * @param $vars
 */
function stats_wikitag($vars) {
  $vars['env']->setData(STATS_WIKITAG_LOADED, ($vars['env']->getData(STATS_WIKITAG_LOADED, 0) + 1));
}