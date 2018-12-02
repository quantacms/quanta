<?php
namespace Quanta\Qtags;
/**
 * Statistics (only for admin) of the current page load.
 */
class Stats extends Qtag {
  /**
   * @return string
   *   The rendered Qtag.
   */
  public function render() {
    $stats = array();
    $stats[] = 'Node loaded: ' . $this->env->getData(STATS_NODE_LOADED);
    $stats[] = 'Node built: ' . $this->env->getData(STATS_NODE_BUILT);
    $stats[] = 'Node built from cache: ' . $this->env->getData(STATS_NODE_LOADED_CACHE);
    $ttime = $this->env->getData(STATS_PAGE_COMPLETE_TIME) - $this->env->getData(STATS_PAGE_BOOT_TIME);
    $stats[] = 'Total time: ' . $ttime . 'ms';
    if ($this->env->getData(STATS_NODE_BUILT) > 0) {
      $stats[] = 'Node build time: ' .  doubleval($ttime / $this->env->getData(STATS_NODE_BUILT)) . 'ms';
    }
    $stats[] = 'Node list: ' .  implode(' ', $this->env->getData(STATS_NODE_LIST));
    $stats[] = 'Loaded tags (only first level - to fix): ' .  $this->env->getData(STATS_QTAG_LOADED);

    $string ='<div id="stats" style="border: 1px solid black; padding: 8px; background: #eee;">' . implode('<br>', $stats) . '</div>';
    return $string;
  }
}
