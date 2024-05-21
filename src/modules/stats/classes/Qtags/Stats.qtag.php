<?php
namespace Quanta\Qtags;
/**
 * Statistics (only for admin) of the current page load.
 */
class Stats extends Qtag {
  /**
   * TODO: this is not anymore a real QTAG as before.
   * Should be refactored and this function changed.
   */
  public static function printStats($env) {
    $stats = array();
    $stats[] = 'Node loaded: ' . $env->getData(STATS_NODE_LOADED);
    $stats[] = 'Node built: ' . $env->getData(STATS_NODE_BUILT);
    $stats[] = 'Node built from cache: ' . $env->getData(STATS_NODE_LOADED_CACHE);
    $ttime = $env->getData(STATS_PAGE_COMPLETE_TIME) - $env->getData(STATS_PAGE_BOOT_TIME);
    $stats[] = 'Total time: ' . $ttime . 'ms';
    if ($env->getData(STATS_NODE_BUILT) > 0) {
      $stats[] = 'Node build time: ' .  doubleval($ttime / $env->getData(STATS_NODE_BUILT)) . 'ms';
    }
    $stats[] = 'Loaded tags: ' .  $env->getData(STATS_QTAG_LOADED);
    $stats[] = 'Loaded tags from cache: ' .  $env->getData(STATS_QTAG_LOADED_CACHE);
    $stats[] = 'Node list:<br/>' .  implode(' ', $env->getData(STATS_NODE_LIST));

    $string ='<div id="stats" style="border: 1px solid black; padding: 8px; background: #eee;">' . implode('<br>', $stats) . '</div>';
    return $string;
  }
}
