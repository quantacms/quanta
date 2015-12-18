<?php
/**
 * Wiki Hook for STATS.
 */
function wiki_STATS($userfull, $var, $string, $page) {
  $stats = array();
  $stats[] = 'Node loaded: ' . $page->env->getData(STATS_NODE_LOADED);
  $stats[] = 'Node built: ' . $page->env->getData(STATS_NODE_BUILT);
  $stats[] = 'Node built from cache: ' . $page->env->getData(STATS_NODE_LOADED_CACHE);
  $ttime = $page->env->getData(STATS_PAGE_COMPLETE_TIME) - $page->env->getData(STATS_PAGE_BOOT_TIME);
  $stats[] = 'Total time: ' . $ttime . 'ms';
  $stats[] = 'Node build time: ' .  doubleval($ttime / $page->env->getData(STATS_NODE_BUILT)) . 'ms';
  $stats[] = 'Node list: ' .  implode(' ', $page->env->getData(STATS_NODE_LIST));
  $stats[] = 'Loaded tags (only first level - to fix): ' .  $page->env->getData(STATS_WIKITAG_LOADED);

  $string ='<div id="stats" style="border: 1px solid black; padding: 8px; background: #eee;">' . implode('<br>', $stats) . '</div>';
  return $string;
}
